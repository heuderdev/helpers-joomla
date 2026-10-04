# Receitas (código completo; adapte nomes de componente e tabela)

## 1. Excluir com permissão, transação e auditoria

```php
public function excluir()
{
    if (!JSession::checkToken()) {
        return ApiResponseHelper::forbidden('Token inválido.');
    }

    PermissionHelper::setUseApiResponse(true);
    PermissionHelper::require('core.delete', 'com_loja');

    try {
        $id = InputHelper::uint('id', 0, 'post');
        $pedidos = OrmTables::table('#__loja_pedidos', ['softDeletes' => true]);
        $antes = $pedidos->findOrFail($id);

        DbTransactionHelper::run(function () use ($pedidos, $id, $antes) {
            $pedidos->delete($id);
            OrmTables::table('#__loja_estoque')->where('pedido_id', $id)->updateWhere(['reservado' => 0]);
            AuditHelper::deleted('pedido', $id, (array) $antes);
        });

        return ApiResponseHelper::deleted('Pedido excluído.');
    } catch (Throwable $e) {
        if ($e->getCode() === 404) {
            return ApiResponseHelper::notFound('Pedido não encontrado.');
        }

        return ApiResponseHelper::exception($e);
    }
}
```

## 2. Upload de CSV → fila → importação com progresso

```php
// Controller: recebe o arquivo e enfileira
public function importar()
{
    if (!JSession::checkToken()) {
        return ApiResponseHelper::forbidden('Token inválido.');
    }

    require_once JPATH_COMPONENT . '/jobs/jobs.php';

    $r = UploadMaster::upload(UploadMaster::getFileFromInput('arquivo'), 'importacoes', null, [
        'allowed_extensions' => ['csv', 'txt'],
        'max_size' => 50 * 1024 * 1024,
        'base_directory' => 'media/com_loja/privado',
    ]);

    if (!$r['success']) {
        return ApiResponseHelper::error($r['mensagem']);
    }

    $job = QueueHelper::push('importar_clientes', ['arquivo' => $r['data']['relative_path']], [
        'usuario_id' => (int) JFactory::getUser()->id,
        'unico' => ['arquivo'],
    ]);

    return $job['success']
        ? ApiResponseHelper::created('Importação na fila.', ['uuid' => $job['data']['uuid']])
        : ApiResponseHelper::error($job['mensagem']);
}

// Controller: andamento (o navegador consulta a cada 2s até finalizado = true)
public function andamento()
{
    $s = QueueHelper::status(InputHelper::string('uuid', '', 'get'), (int) JFactory::getUser()->id);

    return $s['success'] ? ApiResponseHelper::success('Andamento.', $s['data']) : ApiResponseHelper::notFound($s['mensagem']);
}
```

```php
// jobs/ImportarClientesJob.php
class ImportarClientesJob extends AbstractJob
{
    public function handle()
    {
        $r = CsvHelper::import($this->getPayload('arquivo'), '#__loja_clientes', 'media/com_loja/privado', [
            'rules' => ['documento' => 'required|cpf_cnpj', 'email' => 'nullable|email'],
            'mode' => 'upsert',
            'unique_by' => ['documento'],
            'on_progress' => function ($relatorio) {          // a cada lote: salva as linhas processadas e renova o lock
                $this->progress($relatorio['processed_rows']);
            },
        ]);

        if (!$r['success']) {
            return $this->fail($r['mensagem'], true);   // arquivo inválido: não adianta repetir
        }

        return [
            'inseridos' => $r['data']['inserted_rows'],
            'atualizados' => $r['data']['updated_rows'],
            'erros' => $r['data']['errors_count'],
        ];
    }
}
```
> Para mostrar percentual, conte as linhas do arquivo antes de enfileirar e passe o total no `push()` (`'total' => $linhas`).

## 3. Exportar o resultado filtrado da tela

```php
public function exportar()
{
    PermissionHelper::setUseApiResponse(true);
    PermissionHelper::require('core.manage', 'com_loja');

    $db = JFactory::getDbo();
    $q = $db->getQuery(true)
        ->select($db->quoteName(['p.id', 'c.nome', 'p.total', 'p.created_at']))
        ->from($db->quoteName('#__loja_pedidos', 'p'))
        ->join('INNER', $db->quoteName('#__loja_clientes', 'c') . ' ON c.id = p.cliente_id')
        ->where($db->quoteName('p.status') . ' = ' . $db->quote(InputHelper::cmd('status', 'pago', 'get')))
        ->order('p.id DESC');

    AuditHelper::exported('pedido', ['status' => InputHelper::cmd('status', 'pago', 'get')]);

    ExportHelper::downloadFromQuery('pedidos-' . date('Y-m-d') . '.csv', $q, [
        'id' => 'Pedido',
        'nome' => 'Cliente',
        'total' => ['label' => 'Total', 'callback' => function ($l) { $l = (array) $l; return number_format($l['total'], 2, ',', '.'); }],
        'created_at' => 'Data',
    ], ['delimiter' => ';']);
}
```

## 4. Download de arquivo privado só para o dono

```php
public function baixar()
{
    $doc = OrmTables::table('#__loja_documentos')->find(InputHelper::uint('id', 0, 'get'));

    if (!$doc || (int) $doc->usuario_id !== (int) JFactory::getUser()->id) {
        return ApiResponseHelper::notFound('Documento não encontrado.');   // 404 também para "não é seu"
    }

    AuditHelper::downloaded('documento', $doc->id, ['arquivo' => $doc->caminho]);

    UploadMaster::stream($doc->caminho, $doc->nome_original, ['base_directory' => 'media/com_loja/privado']);
}
```

## 5. Ler de outro banco e gravar no Joomla

```php
DbConnectionHelper::register('erp', [
    'driver' => 'pgsql', 'host' => $params->get('erp_host'), 'user' => $params->get('erp_user'),
    'password' => $params->get('erp_senha'), 'database' => 'erp', 'prefix' => '',
]);

OrmTables::table('clientes', ['connection' => 'erp', 'timestamps' => false])
    ->where('atualizado_em', '>=', $ultimaSincronizacao)
    ->chunk(500, function ($lote) {
        DbTransactionHelper::run(function () use ($lote) {                    // transação no banco do Joomla
            foreach ($lote as $c) {
                OrmTables::table('#__loja_clientes')->updateOrCreate(['erp_id' => $c->id], ['nome' => $c->nome, 'email' => $c->email]);
            }
        });
    });
```
