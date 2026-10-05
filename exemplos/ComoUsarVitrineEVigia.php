<?php

/*
 * Exemplo completo: cadastro de clientes com Vitrine.js (listagem) +
 * Vigia.js (formulário), num componente Joomla 3, 4 ou 5.
 *
 * A tela tem:
 *   - lista paginada no servidor (20 por página, estilo jQuery Paginate),
 *     busca com espera, filtros de status, UF e período, ordenação por coluna,
 *     estado na URL, preferências lembradas e exportação CSV dos filtros atuais;
 *   - formulário num modal para incluir e editar, validado no navegador com
 *     as MESMAS regras do servidor (ValidationHelper::clientConfig), máscaras
 *     e conferência de e-mail repetido antes de enviar (regra remote);
 *   - ações por linha (editar, ativar/desativar, excluir) e em lote (excluir).
 *
 * Arquivos (componente com_loja):
 *   administrator/components/com_loja/helpers/   os helpers:
 *       instalar.sh administrator/components/com_loja/helpers OrmTables InputHelper ValidationHelper ApiResponseHelper PermissionHelper ExportHelper DateHelper FormatHelper
 *   O modal usa o Bootstrap 5 (Joomla 4 e 5). No Joomla 3, troque o modal pelo do template ou por um <div> que você mostra/esconde.
 *   media/com_loja/js/                            axios (ou CDN), vigia.js, vigia.css, vitrine.js, vitrine.css
 *   seção 1 -> sql/clientes.sql
 *   seção 2 -> helpers/ClienteRegras.php
 *   seção 3 -> controllers/clientes.php
 *   seção 4 -> views/clientes/tmpl/default.php
 *
 * Para ver funcionando sem Joomla: abra js/exemplo-vitrine.html no navegador.
 */

defined('_JEXEC') or die;

// =====================================================================
// 1. Tabela (sql/clientes.sql)
// =====================================================================
/*
CREATE TABLE IF NOT EXISTS `#__loja_clientes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `nome` VARCHAR(120) NOT NULL,
    `email` VARCHAR(190) NOT NULL,
    `tipo` CHAR(2) NOT NULL DEFAULT 'pf',
    `documento` VARCHAR(14) NOT NULL,          -- só dígitos (o formulário envia sem máscara)
    `celular` VARCHAR(11) NOT NULL,
    `uf` CHAR(2) NOT NULL,
    `cidade` VARCHAR(100) NOT NULL,
    `limite_credito` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `status` VARCHAR(10) NOT NULL DEFAULT 'ativo',
    `created_at` DATETIME NOT NULL,            -- UTC (OrmBase + DateHelper)
    `updated_at` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_email` (`email`),
    KEY `idx_status_created` (`status`, `created_at`),   -- filtro + ordem padrão da lista
    KEY `idx_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
*/

// =====================================================================
// 2. Regras num lugar só (helpers/ClienteRegras.php)
//    O controller valida com elas; a view as entrega ao Vigia.js.
// =====================================================================

class ClienteRegras
{
    const UFS = 'AC,AL,AP,AM,BA,CE,DF,ES,GO,MA,MT,MS,MG,PA,PB,PR,PE,PI,RJ,RN,RS,RO,RR,SC,SP,SE,TO';

    // Colunas que a lista pode ordenar (lista branca: o nome vira SQL).
    public static function ordenaveis()
    {
        return array('id', 'nome', 'email', 'cidade', 'limite_credito', 'status', 'created_at');
    }

    public static function regras($idAtual = 0)
    {
        return array(
            'nome' => 'required|min_length:3|max_length:120',
            'email' => array(
                'required',
                'email',
                'max_length:190',
                // Só o servidor confere (clientConfig() deixa de fora); no navegador, a regra remote avisa antes.
                'unique' => function ($valor) use ($idAtual) {
                    return !OrmTables::table('#__loja_clientes')
                        ->where('email', $valor)
                        ->where('id', '<>', (int) $idAtual)
                        ->exists();
                },
            ),
            'tipo' => 'required|in:pf,pj',
            'documento' => 'required|cpf_cnpj',
            'celular' => 'required|phone',
            'uf' => 'required|in:' . self::UFS,
            'cidade' => 'required|max_length:100',
            'limite_credito' => 'required|numeric|min:0|max:1000000',
        );
    }

    public static function mensagens()
    {
        return array(
            'email.unique' => 'Já existe um cliente com este e-mail.',
            'limite_credito.max' => 'O limite de crédito pode ser no máximo R$ 1.000.000,00.',
        );
    }

    public static function rotulos()
    {
        return array(
            'documento' => 'CPF ou CNPJ',
            'uf' => 'Estado',
            'limite_credito' => 'Limite de crédito',
        );
    }
}

// =====================================================================
// 3. Controller (controllers/clientes.php)
//    Todas as tasks respondem JSON pelo ApiResponseHelper (format=json).
// =====================================================================

class LojaControllerClientes extends JControllerLegacy
{
    private function tabela()
    {
        return OrmTables::table('#__loja_clientes', array(
            'fillable' => array('nome', 'email', 'tipo', 'documento', 'celular', 'uf', 'cidade', 'limite_credito', 'status'),
            'casts' => array('id' => 'int', 'limite_credito' => 'float'),
        ));
    }

    /*
     * Vitrine.js: GET ?page=&limit=&sort=&direction=&busca=&status[]=&uf=&de=&ate=
     * Devolve SÓ a página pedida (LIMIT/OFFSET) + meta.paginacao.
     */
    public function listar()
    {
        try {
            if (!PermissionHelper::require('core.manage')) {
                return;
            }

            $pagina = InputHelper::pagination(20, 100);
            $ordem = InputHelper::sorting(ClienteRegras::ordenaveis(), 'created_at', 'DESC');

            $query = $this->filtrar($this->tabela()->select('id, nome, email, tipo, documento, celular, uf, cidade, limite_credito, status, created_at'))
                ->orderBy($ordem['field'], $ordem['direction'])
                ->orderBy('id', 'DESC');   // desempate: ordem estável entre páginas

            return ApiResponseHelper::paginated($query->paginate($pagina['limit'], $pagina['page']));
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }

    // Os mesmos filtros servem para a lista e para a exportação.
    private function filtrar($query)
    {
        $filtros = InputHelper::filters(array(
            'busca' => 'string',
            'status' => 'array',
            'uf' => 'cmd',
            'de' => 'string',
            'ate' => 'string',
        ));

        if (!empty($filtros['busca'])) {
            $busca = $filtros['busca'];
            $digitos = preg_replace('/\D+/', '', $busca);

            $query->whereGroup(function ($g) use ($busca, $digitos) {
                $g->whereLike('nome', $busca)->orWhereLike('email', $busca);

                if (strlen($digitos) >= 3) {
                    $g->orWhereLike('documento', $digitos)->orWhereLike('celular', $digitos);
                }
            });
        }

        if (!empty($filtros['status'])) {
            $query->whereIn('status', array_values(array_intersect((array) $filtros['status'], array('ativo', 'inativo'))));
        }

        if (!empty($filtros['uf']) && in_array($filtros['uf'], explode(',', ClienteRegras::UFS), true)) {
            $query->where('uf', $filtros['uf']);
        }

        // Período "de/até" digitado no fuso do usuário -> UTC do banco.
        if (!empty($filtros['de']) && !empty($filtros['ate'])) {
            $periodo = DateHelper::between($filtros['de'], $filtros['ate']);
            $query->whereBetween('created_at', $periodo['start'], $periodo['end']);
        }

        return $query;
    }

    // Vigia.js: inclui (id vazio) ou altera (id > 0).
    public function salvar()
    {
        try {
            if (!PermissionHelper::requireToken()) {
                return;
            }

            $id = InputHelper::int('id', 0, 'post');

            if (!PermissionHelper::require($id > 0 ? 'core.edit' : 'core.create')) {
                return;
            }

            $dados = InputHelper::post(array(
                'nome' => 'string',
                'email' => 'string',
                'tipo' => 'cmd',
                'documento' => 'string',   // o formulário manda sem máscara (data-unmask)
                'celular' => 'string',
                'uf' => 'cmd',
                'cidade' => 'string',
                'limite_credito' => 'string',
            ));
            $dados['email'] = strtolower(trim($dados['email']));

            $validacao = ValidationHelper::validate(
                $dados,
                ClienteRegras::regras($id),
                ClienteRegras::mensagens(),
                ClienteRegras::rotulos()
            );

            if (!$validacao['valid']) {
                return ApiResponseHelper::fromValidation($validacao);   // 422: o Vigia mostra em cada campo
            }

            $dados['documento'] = preg_replace('/\D+/', '', $dados['documento']);
            $dados['celular'] = preg_replace('/\D+/', '', $dados['celular']);
            $dados['limite_credito'] = InputHelper::decimal('limite_credito', 0, 'post');

            if ($id > 0) {
                $this->tabela()->findOrFail($id);
                $cliente = $this->tabela()->update($id, $dados);

                return ApiResponseHelper::updated('Cliente atualizado.', array('id' => (int) $cliente->id));
            }

            $dados['status'] = 'ativo';
            $cliente = $this->tabela()->create($dados);

            return ApiResponseHelper::created('Cliente cadastrado.', array('id' => (int) $cliente->id));
        } catch (Throwable $e) {
            if ((int) $e->getCode() === 404) {
                return ApiResponseHelper::notFound('Este cliente não existe mais.');
            }

            return ApiResponseHelper::exception($e);
        }
    }

    // Vitrine.js: data-action="status" (POST id, status).
    public function alterarStatus()
    {
        try {
            if (!PermissionHelper::requireToken() || !PermissionHelper::require('core.edit.state')) {
                return;
            }

            $id = InputHelper::int('id');
            $status = InputHelper::oneOf('status', array('ativo', 'inativo'));

            if ($id <= 0 || $status === null) {
                return ApiResponseHelper::badRequest('Informe o cliente e o novo status.');
            }

            $this->tabela()->findOrFail($id);
            $this->tabela()->update($id, array('status' => $status));

            return ApiResponseHelper::updated($status === 'ativo' ? 'Cliente ativado.' : 'Cliente desativado.');
        } catch (Throwable $e) {
            return (int) $e->getCode() === 404
                ? ApiResponseHelper::notFound('Este cliente não existe mais.')
                : ApiResponseHelper::exception($e);
        }
    }

    // Vitrine.js: data-action="excluir" (uma linha) e data-batch-action="excluir" (ids[]).
    public function excluir()
    {
        try {
            if (!PermissionHelper::requireToken() || !PermissionHelper::require('core.delete')) {
                return;
            }

            $ids = InputHelper::arrayOfInt('ids');

            if (!$ids && InputHelper::int('id') > 0) {
                $ids = array(InputHelper::int('id'));
            }

            if (!$ids) {
                return ApiResponseHelper::badRequest('Selecione ao menos um cliente.');
            }

            if (count($ids) > 500) {
                return ApiResponseHelper::badRequest('Exclua no máximo 500 clientes por vez.');
            }

            $total = DbTransactionHelper::run(function () use ($ids) {
                $existentes = OrmTables::table('#__loja_clientes')->whereIn('id', $ids)->count();
                OrmTables::table('#__loja_clientes')->whereIn('id', $ids)->deleteWhere();

                return $existentes;
            });

            return ApiResponseHelper::deleted(FormatHelper::plural($total, 'cliente excluído', 'clientes excluídos', 'Nenhum cliente excluído') . '.');
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }

    // Vigia.js: regra remote no campo e-mail (POST field, value).
    public function emailLivre()
    {
        try {
            if (!PermissionHelper::requireToken()) {
                return;
            }

            $email = strtolower(trim(InputHelper::string('value', '', 'post')));
            $id = InputHelper::int('id');   // vem na URL da regra remote (o JavaScript atualiza ao abrir a edição)

            $usado = $email !== '' && OrmTables::table('#__loja_clientes')
                ->where('email', $email)
                ->where('id', '<>', $id)
                ->exists();

            return $usado
                ? ApiResponseHelper::validationError(array('email' => array('Já existe um cliente com este e-mail.')))
                : ApiResponseHelper::success('Disponível.');
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }

    // Vitrine.js: <a data-vitrine-export> leva os filtros e a ordem atuais.
    public function exportar()
    {
        try {
            if (!PermissionHelper::require('core.manage')) {
                return;
            }

            $ordem = InputHelper::sorting(ClienteRegras::ordenaveis(), 'created_at', 'DESC');

            $query = $this->filtrar($this->tabela()->select('id, nome, email, documento, celular, uf, cidade, limite_credito, status, created_at'))
                ->orderBy($ordem['field'], $ordem['direction'])
                ->orderBy('id', 'DESC');

            // Generator: lê 1.000 por vez e escreve no CSV; a memória não cresce com a tabela.
            ExportHelper::download('clientes-' . date('Y-m-d') . '.csv', $this->emLotes($query, 1000), array(
                'id' => 'Código',
                'nome' => 'Nome',
                'email' => 'E-mail',
                'documento' => 'CPF/CNPJ',
                'celular' => 'Celular',
                'cidade' => 'Cidade',
                'uf' => 'UF',
                'limite_credito' => array('label' => 'Limite (R$)', 'callback' => function ($c) {
                    return number_format((float) $c->limite_credito, 2, ',', '.');
                }),
                'status' => 'Status',
                'created_at' => array('label' => 'Cadastro', 'callback' => function ($c) {
                    return DateHelper::toUser($c->created_at);
                }),
            ), array('utf8_bom' => true));

            JFactory::getApplication()->close();
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }

    private function emLotes($query, $tamanho)
    {
        for ($lote = 0; ; $lote++) {
            $consulta = clone $query;
            $linhas = $consulta->limit($tamanho, $lote * $tamanho)->get();

            foreach ($linhas as $linha) {
                yield $linha;
            }

            if (count($linhas) < $tamanho) {
                return;
            }
        }
    }
}

// =====================================================================
// 4. View (views/clientes/tmpl/default.php)
// =====================================================================
?>
<?php
$base = 'index.php?option=com_loja&task=clientes.';
$url = function ($task) use ($base) {
    return JRoute::_($base . $task . '&format=json', false);
};

JHtml::_('script', 'https://cdnjs.cloudflare.com/ajax/libs/axios/1.7.7/axios.min.js');
JHtml::_('script', 'com_loja/vigia.js', array('relative' => true, 'version' => 'auto'));
JHtml::_('script', 'com_loja/vitrine.js', array('relative' => true, 'version' => 'auto'));
JHtml::_('stylesheet', 'com_loja/vigia.css', array('relative' => true, 'version' => 'auto'));
JHtml::_('stylesheet', 'com_loja/vitrine.css', array('relative' => true, 'version' => 'auto'));
JHtml::_('bootstrap.modal');   // Joomla 4/5: window.bootstrap.Modal

// Regras do PHP no navegador (sem unique/closures; a regra remote cobre o e-mail).
$vigia = ValidationHelper::clientConfig(ClienteRegras::regras(), ClienteRegras::mensagens(), ClienteRegras::rotulos());
$ufs = explode(',', ClienteRegras::UFS);
?>

<div id="clientes"
     data-vitrine="<?php echo $url('listar'); ?>"
     data-vitrine-limit="20" data-vitrine-sort="created_at" data-vitrine-direction="DESC"
     data-vitrine-remember="clientes">

    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <h1 class="h3 m-0">Clientes</h1>
        <div class="d-flex gap-2">
            <a class="btn btn-outline-secondary" data-vitrine-export href="<?php echo JRoute::_($base . 'exportar', false); ?>">Exportar CSV</a>
            <button type="button" class="btn btn-primary" id="novo-cliente">Novo cliente</button>
        </div>
    </div>

    <div data-vitrine-message hidden></div>

    <form data-vitrine-filters class="row g-2 align-items-end mb-3">
        <div class="col-md-4">
            <label class="form-label" for="f-busca">Buscar</label>
            <input type="search" id="f-busca" name="busca" class="form-control" placeholder="Nome, e-mail, CPF/CNPJ ou celular">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="f-uf">Estado</label>
            <select id="f-uf" name="uf" class="form-select">
                <option value="">Todos</option>
                <?php foreach ($ufs as $uf) : ?>
                    <option value="<?php echo $uf; ?>"><?php echo $uf; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="f-de">Cadastro de</label>
            <input type="date" id="f-de" name="de" class="form-control">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="f-ate">até</label>
            <input type="date" id="f-ate" name="ate" class="form-control">
        </div>
        <div class="col-md-2">
            <div class="form-check"><input class="form-check-input" type="checkbox" name="status[]" value="ativo" id="f-ativo"><label class="form-check-label" for="f-ativo">Ativos</label></div>
            <div class="form-check"><input class="form-check-input" type="checkbox" name="status[]" value="inativo" id="f-inativo"><label class="form-check-label" for="f-inativo">Inativos</label></div>
        </div>
        <div class="col-12"><button type="button" class="btn btn-link p-0" data-vitrine-reset>Limpar filtros</button></div>
    </form>

    <div data-vitrine-batch hidden class="alert alert-secondary d-flex align-items-center gap-3 py-2">
        <span><strong data-vitrine-selected-count></strong> selecionado(s)</span>
        <button type="button" class="btn btn-sm btn-danger" data-batch-action="excluir"
                data-url="<?php echo $url('excluir'); ?>" data-confirm="Excluir :count clientes? Esta ação não pode ser desfeita.">Excluir selecionados</button>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th style="width:2rem"><input type="checkbox" class="form-check-input" data-select-all aria-label="Selecionar todos"></th>
                    <th data-sort="id">#</th>
                    <th data-sort="nome">Cliente</th>
                    <th>Documento</th>
                    <th data-sort="cidade">Cidade</th>
                    <th data-sort="limite_credito" class="text-end">Limite</th>
                    <th data-sort="status">Status</th>
                    <th data-sort="created_at">Cadastro</th>
                    <th class="text-end">Ações</th>
                </tr>
            </thead>
            <tbody data-vitrine-items></tbody>
        </table>
    </div>

    <template data-vitrine-template>
        <tr data-class-table-secondary="inativo">   <!-- ativo/inativo/proximo_status vêm do transform (JavaScript abaixo) -->
            <td><input type="checkbox" class="form-check-input" data-select></td>
            <td data-field="id"></td>
            <td>
                <strong data-field="nome"></strong><br>
                <small class="text-muted"><a data-href="mailto:{email}" data-field="email"></a> · <span data-field="celular" data-format="phone"></span></small>
            </td>
            <td class="text-nowrap" data-field="documento" data-format="cpf_cnpj"></td>
            <td><span data-field="cidade"></span>/<span data-field="uf"></span></td>
            <td class="text-end" data-field="limite_credito" data-format="money"></td>
            <td>
                <span class="badge bg-success" data-if="ativo">Ativo</span>
                <span class="badge bg-secondary" data-if="inativo">Inativo</span>
            </td>
            <td class="text-nowrap" data-field="created_at" data-format="datetime"></td>
            <td class="text-end text-nowrap">
                <button type="button" class="btn btn-sm btn-outline-primary" data-action="editar">Editar</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-action="status"
                        data-url="<?php echo $url('alterarStatus'); ?>&id={id}&status={proximo_status}"
                        data-attr-aria-label="Mudar status de {nome}">
                    <span data-if="ativo">Desativar</span><span data-if="inativo">Ativar</span>
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" data-action="excluir"
                        data-url="<?php echo $url('excluir'); ?>&id={id}" data-confirm="Excluir {nome}?">Excluir</button>
            </td>
        </tr>
    </template>

    <p data-vitrine-empty hidden class="text-center text-muted py-5">Nenhum cliente encontrado com esses filtros.</p>
    <div data-vitrine-error hidden class="alert alert-danger"><span data-vitrine-error-message></span> <button type="button" class="btn btn-sm btn-link" data-vitrine-retry>Tentar de novo</button></div>

    <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center">
        <small class="text-muted" data-vitrine-summary></small>
        <nav data-vitrine-pagination="jpaginate" data-vitrine-display="7"></nav>
        <label class="d-flex align-items-center gap-2 small">Por página
            <select class="form-select form-select-sm w-auto" data-vitrine-limit-select><option>20</option><option>50</option><option>100</option></select>
        </label>
    </div>

    <?php echo JHtml::_('form.token'); ?>
</div>

<!-- Modal de inclusão/edição: o mesmo formulário para os dois -->
<div class="modal fade" id="modal-cliente" tabindex="-1" aria-labelledby="modal-cliente-titulo" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <form class="modal-content" id="form-cliente" method="post" action="<?php echo $url('salvar'); ?>"
              data-vigia='<?php echo htmlspecialchars(json_encode($vigia), ENT_QUOTES, 'UTF-8'); ?>'>
            <div class="modal-header">
                <h2 class="modal-title h5" id="modal-cliente-titulo">Novo cliente</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div data-vigia-message></div>
                <input type="hidden" name="id" value="">

                <div class="row g-3">
                    <div class="col-md-8">
                        <label class="form-label" for="c-nome">Nome</label>
                        <input id="c-nome" name="nome" class="form-control" autocomplete="name">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="c-tipo">Tipo</label>
                        <select id="c-tipo" name="tipo" class="form-select">
                            <option value="pf">Pessoa física</option>
                            <option value="pj">Pessoa jurídica</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="c-email">E-mail</label>
                        <input id="c-email" name="email" type="email" class="form-control" autocomplete="email"
                               data-remote-url="<?php echo $url('emailLivre'); ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="c-documento">CPF ou CNPJ</label>
                        <input id="c-documento" name="documento" class="form-control" data-mask="cpf_cnpj" data-unmask>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="c-celular">Celular</label>
                        <input id="c-celular" name="celular" class="form-control" data-mask="phone" data-unmask autocomplete="tel">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="c-uf">UF</label>
                        <select id="c-uf" name="uf" class="form-select">
                            <option value="">—</option>
                            <?php foreach ($ufs as $uf) : ?>
                                <option value="<?php echo $uf; ?>"><?php echo $uf; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="c-cidade">Cidade</label>
                        <input id="c-cidade" name="cidade" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="c-limite">Limite de crédito</label>
                        <input id="c-limite" name="limite_credito" class="form-control" data-mask="money" data-unmask>
                    </div>
                </div>
                <?php echo JHtml::_('form.token'); ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" data-loading-text="Salvando…">Salvar</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    var modalEl = document.getElementById('modal-cliente');
    var modal = new bootstrap.Modal(modalEl);
    var form = document.getElementById('form-cliente');
    var titulo = document.getElementById('modal-cliente-titulo');
    var vigia = Vigia.form(form);

    // Coloca um valor no campo e reaplica a máscara (o Vigia formata no evento input).
    function preencher(nome, valor) {
        var campo = form.elements[nome];
        campo.value = valor === null || valor === undefined ? '' : String(valor);
        campo.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function abrir(cliente) {
        form.reset();
        vigia.clearErrors();
        titulo.textContent = cliente ? 'Editar ' + cliente.nome : 'Novo cliente';
        preencher('id', cliente ? cliente.id : '');

        // Regra remote com o id: na edição, o e-mail do próprio cliente não conta como repetido.
        var email = form.elements.email;
        email.setAttribute('data-rules', 'remote:' + email.getAttribute('data-remote-url') + '&id=' + (cliente ? cliente.id : 0));

        if (cliente) {
            ['nome', 'email', 'tipo', 'documento', 'celular', 'uf', 'cidade'].forEach(function (nome) {
                preencher(nome, cliente[nome]);
            });
            // Do banco vem "1500.00": a máscara de dinheiro lê o ponto decimal.
            preencher('limite_credito', Number(cliente.limite_credito).toFixed(2));
        }

        modal.show();
    }

    var clientes = Vitrine.create('#clientes', {
        // Campos calculados antes de desenhar a linha (data-if, data-class e {proximo_status} na URL).
        transform: function (cliente) {
            cliente.ativo = cliente.status === 'ativo';
            cliente.inativo = !cliente.ativo;
            cliente.proximo_status = cliente.ativo ? 'inativo' : 'ativo';

            return cliente;
        },
        actions: {
            editar: function (cliente) {
                abrir(cliente);
            }
        }
    });

    document.getElementById('novo-cliente').addEventListener('click', function () {
        abrir(null);
    });

    // Salvou: fecha o modal e recarrega a lista (o cache de páginas é ignorado).
    form.addEventListener('vigia:success', function () {
        modal.hide();
        clientes.reload();
    });
})();
</script>
