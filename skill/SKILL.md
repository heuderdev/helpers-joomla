---
name: joomla-helpers-perplexity
description: Especialista nos helpers PHP do repositório github.com/heuderdev/helpers-joomla para componentes Joomla 3.4.5+, 4 e 5 (PHP 7.0+, MySQL e PostgreSQL). Use sempre que o pedido envolver controller, model, consulta ao banco, ORM, transação, entrada de formulário, validação, resposta JSON, permissão, upload, arquivos, CSV, exportação, fila de jobs, log, auditoria, datas ou fuso horário num componente Joomla.
---

# helpers-joomla

Você escreve código Joomla usando os helpers do repositório `heuderdev/helpers-joomla`. Responda com **código pronto para colar**, usando a API real abaixo. Nunca invente métodos: se algo não estiver aqui nem nas fichas, diga e proponha o código PHP/Joomla puro.

## Economia de tokens (siga sempre)

1. **Resolva com este arquivo.** Ele cobre a maioria dos pedidos.
2. Precisa de detalhes de um helper → leia **só a ficha da área** (tabela abaixo). Nunca leia duas fichas se uma basta.
3. Só leia o código-fonte quando a ficha não responder (opção rara, comportamento de borda, bug). Busque **um arquivo**, pelo link cru: `https://raw.githubusercontent.com/heuderdev/helpers-joomla/main/<Arquivo>.php`. Não abra `docs/*.html` (pesados) nem o repositório inteiro.
4. Na resposta: código primeiro; explicação em no máximo 3 linhas; só os `require_once` necessários; não repita a API para o usuário; não explique o que o código já diz.
5. Pedido ambíguo → assuma o caso mais comum (Joomla do site, conexão padrão, MySQL) e declare a suposição numa linha, em vez de perguntar.

| Área | Ficha | Helpers |
|---|---|---|
| Banco | `referencias/banco.md` | DbConnectionHelper, DbTransactionHelper, OrmBase, OrmTables |
| Entrada e saída | `referencias/entrada-saida.md` | InputHelper, ValidationHelper, ApiResponseHelper, PermissionHelper, Vigia.js |
| Arquivos | `referencias/arquivos.md` | UploadMaster, FileHelper, CsvHelper, ChunkHelper, ExportHelper |
| Fila | `referencias/fila.md` | QueueHelper, AbstractJob, JobRegistry, QueueWorker, worker CLI |
| Infra | `referencias/infra.md` | LogHelper, AuditHelper, IncludeHelper, DateHelper, LockHelper, CacheHelper, RateLimitHelper, CryptoHelper |
| Integrações | `referencias/integracao.md` | HttpHelper, MailHelper |
| Exemplos completos | `receitas.md` | CRUD, importação CSV em fila, exportação, upload privado |

## Mapa rápido: pedido → helper

| Pedido | Use |
|---|---|
| Ler campo do formulário/URL | `InputHelper::string/int/uint/decimal/bool/email/cpf/date…('campo', $padrao, 'post')` |
| Validar dados | `ValidationHelper::validate($dados, $regras)` → `['valid', 'errors', 'first_error']` |
| Responder JSON/HTML | `return ApiResponseHelper::success/created/error/notFound/fromValidation/paginated/exception(...)` |
| Validar no navegador, máscaras, erros 422 no campo | `js/vigia.js` + `<form data-vigia='<?php echo htmlspecialchars(json_encode(ValidationHelper::clientConfig($regras)), ENT_QUOTES) ?>'>` |
| Consultar/gravar tabela sem criar classe | `OrmTables::table('#__x', $opcoes)->where()->get()` |
| Model reutilizável | `class XModel extends OrmBase { protected $table = '#__x'; }` |
| "Tudo ou nada" | `DbTransactionHelper::run(function () {...})` ou `$orm->transaction(...)` |
| Outro banco ao mesmo tempo | `DbConnectionHelper::register('erp', [...])` + opção `'connection' => 'erp'` |
| Checar permissão | `PermissionHelper::require('core.edit', 'com_x')` / `can(...)` |
| Receber arquivo | `UploadMaster::upload(UploadMaster::getFileFromInput('arquivo'), 'pasta', null, $opcoes)` |
| Download protegido | `UploadMaster::stream($caminho)` ou `FileHelper::stream(...)` |
| Importar CSV | `CsvHelper::analyze()` → `preview()` → `import($caminho, '#__tabela', null, $opcoes)` |
| Exportar CSV | `ExportHelper::download($nome, $linhas, $colunas)` / `downloadFromQuery(...)` |
| Arquivo gigante em pedaços | `ChunkHelper::readLines/resumeLines(...)` (classe no arquivo `ChunkUploadHelper.php`) |
| Tarefa demorada em segundo plano | `QueueHelper::push('tipo', $payload)` + job `extends AbstractJob` + worker CLI |
| Log técnico | `LogHelper::info/error/exception(...)` |
| Trilha de auditoria (quem mudou o quê) | `AuditHelper::created/updated/deleted(...)` |
| Data/hora: gravar, mostrar, filtrar período | `DateHelper::nowSql()` · `toUser($v)` · `fromUser($v)` · `range('month')` · `between($de, $ate)` |
| Guardar token de API / conferir webhook | `CryptoHelper::encrypt($v, "contexto")` / `verifySignature(...)` |
| Limitar tentativas (login, SMS, contato) | `if (!RateLimitHelper::enforce("chave:" . $ip, 5, 3600)) return;` |
| Enviar e-mail (confirmação, senha) | `MailHelper::send(["to"=>..., "template"=>..., "data"=>...])` ou `queue(...)` |
| Guardar resultado caro (dashboard, API) | `CacheHelper::remember("assunto:det", 300, $fn)` + `flushPrefix("assunto")` |
| Uma execução por vez (cron, clique duplo) | `LockHelper::run("nome", $fn)` → `$r["acquired"]` |
| Chamar API externa (ERP, pagamento, frete) | `HttpHelper::get/post($url, ...)` → `$r["ok"]`, `$r["json"]`; `HttpHelper::client([...])` |
| Carregar helpers com dependências | `IncludeHelper::load(['OrmTables', 'ApiResponseHelper'])` |

## Regras de ouro (o código gerado deve respeitar)

- **Compatibilidade:** Joomla 3.4.5+, 4 e 5; PHP **7.0**. Proibido: tipos anuláveis `?int`, `void`, `fn()`, `match`, `??=`, `?->`, propriedades tipadas, desestruturação `[$a, $b] =`, `catch (A | B)`, `str_contains`. Use `JFactory`, `JText`, `JSession` (funcionam nas três versões; no 5 com o plugin de compatibilidade ativo, que é o padrão).
- **Carregamento:** `require_once JPATH_COMPONENT . '/helpers/<Helper>.php';` (os arquivos ficam juntos em `helpers/`). Ou `IncludeHelper::load([...])`, que resolve as dependências.
- **Tabelas:** sempre `#__nome` (prefixo do Joomla). Nunca concatene entrada do usuário em SQL: use `where()`/`whereIn()`; `whereRaw()` só com valores já passados por `$db->quote()`.
- **Toda escrita via POST** confere o token: `JSession::checkToken() or jexit(JText::_('JINVALID_TOKEN'));` (em API JSON: `if (!JSession::checkToken()) return ApiResponseHelper::forbidden('Token inválido.');`).
- **Controller:** `try { ... } catch (Throwable $e) { return ApiResponseHelper::exception($e); }` — a mensagem técnica vai só para o log.
- **Contrato de retorno:** UploadMaster, FileHelper, CsvHelper, ExportHelper, ChunkHelper e `QueueHelper::push()` devolvem `['success' => bool, 'status', 'mensagem', 'data', ...]` e **não lançam exceção**: sempre teste `$r['success']`. OrmBase/OrmTables **lançam** exceção. Métodos de ação do QueueHelper devolvem `bool` e o motivo fica em `QueueHelper::lastError()`.
- **Datas** no banco são UTC: grave com `DateHelper::nowSql()` ou `DateHelper::fromUser($digitado)` (nunca `date()`), mostre com `DateHelper::toUser($v)` e filtre períodos com `DateHelper::range()`/`between()`.
- **IDs públicos:** exponha `uuid` (fila) em vez de ids sequenciais quando o recurso for do usuário; sempre passe `$userId` aos métodos que conferem o dono.

## Esqueleto padrão de controller (adapte; não repita explicações)

```php
<?php
defined('_JEXEC') or die;

require_once JPATH_COMPONENT . '/helpers/OrmTables.php';
require_once JPATH_COMPONENT . '/helpers/InputHelper.php';
require_once JPATH_COMPONENT . '/helpers/ValidationHelper.php';
require_once JPATH_COMPONENT . '/helpers/ApiResponseHelper.php';

class LojaControllerProdutos extends JControllerLegacy
{
    private function produtos()
    {
        return OrmTables::table('#__loja_produtos', [
            'fillable' => ['nome', 'preco', 'ativo'],
            'casts' => ['preco' => 'float', 'ativo' => 'bool'],
        ]);
    }

    public function listar()
    {
        try {
            $r = $this->produtos()
                ->when(InputHelper::string('busca', '', 'get'), function ($q, $busca) { $q->whereLike('nome', $busca); })
                ->orderBy('nome')
                ->paginate(20, InputHelper::uint('pagina', 1, 'get'));

            return ApiResponseHelper::paginated($r, 'Produtos.');
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }

    public function salvar()
    {
        if (!JSession::checkToken()) {
            return ApiResponseHelper::forbidden('Token inválido.');
        }

        try {
            $dados = InputHelper::post(['nome' => 'string', 'preco' => 'float', 'ativo' => 'bool']);   // filtros do Joomla
            $v = ValidationHelper::validate($dados, ['nome' => 'required|max_length:150', 'preco' => 'required|numeric|min:0']);

            if (!$v['valid']) {
                return ApiResponseHelper::fromValidation($v);
            }

            $id = InputHelper::uint('id', 0, 'post');
            $registro = $id > 0 ? $this->produtos()->update($id, $dados) : $this->produtos()->create($dados);

            return $id > 0 ? ApiResponseHelper::updated('Salvo.', $registro) : ApiResponseHelper::created('Criado.', $registro);
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }
}
```

## Armadilhas conhecidas do repositório (avise quando relevante)

- `ChunkUploadHelper.php` define a classe **`ChunkHelper`** e, na versão atual, tem um erro de sintaxe perto da linha 2003 (`'\'` deveria ser `'\\'`). Se o usuário for usá-lo, mande corrigir isso primeiro.
- `PermissionHelper` chama `LogHelper::write()`, que é privado: as negações não são registradas em log (o resto funciona).
- `LogHelper`, `PermissionHelper`, `ExportHelper` e `FileHelper` ainda não foram revisados; use a API como está nas fichas.
- `updateWhere()`/`deleteWhere()` **sem nenhum `where`** lançam exceção, a menos que você chame `->allowMassOperation()` (proteção contra alterar a tabela inteira).
- No PostgreSQL, use os helpers (eles tratam aspas, LIKE e savepoints); não escreva SQL específico de MySQL.
