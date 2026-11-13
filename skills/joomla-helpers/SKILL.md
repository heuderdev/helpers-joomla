---
name: joomla-helpers
description: Cria, refatora e revisa código de componentes Joomla 4.4, 5 e 6 (PHP 8.2+, MySQL/PostgreSQL) usando os helpers de github.com/heuderdev/helpers-joomla (OrmTables/OrmBase, DbTransactionHelper, InputHelper, ValidationHelper, DateHelper, Vigia.js, ApiResponseHelper, PermissionHelper, UploadMaster, FileHelper, CsvHelper, ExportHelper, QueueHelper, LogHelper, AuditHelper, HttpHelper, LockHelper, CacheHelper, MailHelper, EmailSendHelper, RateLimitHelper, CryptoHelper, Vitrine.js, FormatHelper, WebhookHelper, SettingsHelper, ArrayHelper, BackupDatabaseHelper). Use SEMPRE que o pedido envolver Joomla, com_*, JControllerLegacy, controller/model/view/task de componente, JFactory/JDatabase, ou frases como "cria o controller", "refatora esse controller", "importa esse CSV", "exporta pra CSV", "upload no Joomla", "coloca na fila", "revisa esse código Joomla", "converte pra usar os helpers".
---

# Joomla com os helpers

Entregue código Joomla **pronto para produção**, curto e seguro, apoiado nos helpers. Gaste tokens em código, não em prosa.

## Princípios de execução (não regredir)

1. **API real, nunca inventada.** Na dúvida, `scripts/api.sh <Helper> [metodo]` (~20 linhas). Nunca leia um helper inteiro (6 a 120 KB).
2. **Carregue só a ficha da área.** Este arquivo + 1 ficha resolvem quase tudo.
3. **PHP 8.2+ e Joomla 4.4/5.** Pode usar tipos (`?int`, união, retorno, `never`), `readonly`, enums, `match`, `fn`, `?->`, `??=`, promoção no construtor e `str_contains`. **Não** use o que é do 8.3+ (constante tipada, `#[\Override]`, `json_validate`, `new X()->m()` sem parênteses, `array_find`, pipe `|>`, `array_first`): `checar.sh` acusa. Parâmetro com `= null` precisa de `?Tipo` (aviso no 8.4). `JFactory`/`JText`/`JSession` funcionam no 4 e no 5 (no 5 com o plugin de compatibilidade ativo, o padrão); o Joomla 3 não roda no PHP 8.2. LogHelper, FileHelper, ExportHelper, CsvHelper, ChunkHelper e UploadMaster rodam também no Joomla 6, no Laravel e em PHP puro via `PlatformHelper.php` (references/arquivos.md); dentro desses helpers não use `JFile`/`JFolder`/`JLog` direto, use `PlatformHelper::*`.
4. **Segurança não é opcional:** token em toda escrita (`PermissionHelper::requireToken()`, que também aceita `X-CSRF-Token`; ou `JSession::checkToken()`); permissão antes da ação; nada de SQL com variável concatenada; `catch (Throwable $e)` → `ApiResponseHelper::exception($e)`; recurso de usuário confere o dono.
5. **Validar antes de entregar:** `scripts/checar.sh <arquivos>` sem ERROS.
6. **Refatorar = mesmo comportamento.** Mantenha tasks, rotas, assinaturas públicas e formato de resposta, salvo pedido explícito.
7. **Resposta enxuta:** código/diff primeiro; depois no máximo 5 linhas (o que mudou, o que testar). Sem repetir a API nem explicar o óbvio. Ambíguo → assuma o caso comum e diga em 1 linha.

## Recursos (carregue só quando o passo pedir)

| Arquivo | Quando |
|---|---|
| `references/banco.md` | ORM, consultas, paginação, escrita, transação, outro banco, backup do banco |
| `references/entrada-saida.md` | InputHelper, regras do ValidationHelper, respostas, permissões, Vigia.js (formulário no navegador), Vitrine.js |
| `references/arquivos.md` | upload, download, arquivos, importar/exportar CSV, arquivos gigantes |
| `references/fila.md` | tarefas demoradas, jobs, worker, cron |
| `references/infra.md` | LogHelper, AuditHelper, IncludeHelper, DateHelper (datas e fuso), LockHelper, CacheHelper, RateLimitHelper, CryptoHelper, FormatHelper, SettingsHelper, ArrayHelper |
| `references/integracao.md` | HttpHelper, MailHelper, EmailSendHelper, WebhookHelper |
| `references/receitas.md` | pedido que combina vários helpers (exclusão auditada, CSV em fila, exportação, download privado, sincronizar bancos) |
| `references/refatoracao.md` | **sempre** ao refatorar ou revisar código existente |

Caminhos são relativos à pasta **desta skill** (onde está este SKILL.md), não ao projeto. Rode os scripts **a partir da pasta do projeto/worktree**.

| Script | Faz |
|---|---|
| `scripts/onde.sh` | mapa do projeto atual: raiz do Joomla e versão, seus componentes, onde já há helpers e quais diferem da versão oficial. **Rode primeiro** num projeto novo |
| `scripts/api.sh` | lista helpers · `api.sh OrmBase` assinaturas · `api.sh OrmBase paginate` doc + início · `--corpo` inteiro · `api.sh CsvHelper --busca upsert` |
| `scripts/instalar.sh <comp>/helpers Helper...` | copia helpers + dependências para o componente (`--todos`, `--forcar`); não sobrescreve arquivo alterado |
| `scripts/checar.sh <arquivo/pasta>` | `php -l`, recursos acima do PHP 8.2 e obsoletos, SQL concatenado, falta de token e trechos que os helpers substituem |

Nenhum caminho é fixo. Os scripts acham os helpers nesta ordem: `$HELPERS_JOOMLA_DIR` → cópia dentro do projeto atual (para consultar a API) → repositório onde a skill está → clone automático em `~/.cache/helpers-joomla`.
**Sem a pasta `scripts/`** (ferramenta que copiou só o SKILL.md): assinaturas com `grep -n "public .*function" <helpers>/<Helper>.php`; fichas em `https://raw.githubusercontent.com/heuderdev/helpers-joomla/main/skills/joomla-helpers/references/<ficha>.md`; sintaxe com `php -l`.

## Fluxos

**Criar:** `onde.sh` (onde fica o componente e os helpers) → identifique a área → ficha (ou receita) → `instalar.sh` se o componente ainda não tem os helpers → escreva → `checar.sh` → entregue.
**Refatorar:** `onde.sh` → `references/refatoracao.md` → `checar.sh` no original (os avisos são o mapa) → reescreva por task → `checar.sh` até zerar erros → diff + "mudou / igual / testar".
**Revisar:** `checar.sh` + leitura → achados por gravidade (segurança > bug > compatibilidade com PHP 8.2 > troca por helper), cada um com arquivo:linha e a correção em código.

## Mapa pedido → helper

| Pedido | Use |
|---|---|
| Ler campo | `InputHelper::string/int/uint/decimal/bool/email/cpf/date/dateBr('c', $padrao, 'post')`; vários: `InputHelper::post(['c' => 'string'])` |
| Validar | `ValidationHelper::validate($d, ['email' => 'required\|email'])` → `valid`, `errors`, `first_error` |
| Responder | `ApiResponseHelper::success/created/updated/deleted/error/notFound/forbidden/fromValidation/paginated/exception` (JSON ou mensagem + redirect, automático) |
| Formulário no navegador (validar, máscara, erros 422 no campo, axios) | `js/vigia.js`: `<form data-vigia='<?php echo htmlspecialchars(json_encode(ValidationHelper::clientConfig($regras)), ENT_QUOTES) ?>'>` + `data-mask="cpf"` |
| Tabela sem classe | `OrmTables::table('#__x', ['casts' => [...], 'fillable' => [...]])` |
| Model | `class XModel extends OrmBase { protected $table = '#__x'; }` |
| Tudo ou nada | `DbTransactionHelper::run(function () {...})` |
| Outro banco | `DbConnectionHelper::register('erp', [...])` + `'connection' => 'erp'` |
| Permissão | `PermissionHelper::can/require('core.edit', 'com_x')` |
| Upload / download | `UploadMaster::upload(...)` / `UploadMaster::stream(...)` |
| CSV entra / sai | `CsvHelper::import(...)` / `ExportHelper::download(...)` |
| Demorado | `QueueHelper::push()` + `extends AbstractJob` + `fila/cli/queue-worker.php` |
| Log / auditoria | `LogHelper::error(...)` / `AuditHelper::updated(...)` |
| Data / hora / fuso | gravar `DateHelper::nowSql()`; mostrar `toUser($v)`; formulário `fromUser($v)`; período `range('month')` / `between($de, $ate)` |
| Ler opção do componente | `SettingsHelper::int("chave", $padrao)` · `secret("token")` |
| Receber webhook (pagamento, ERP) | `WebhookHelper::handle(["provider"=>..., "secret"=>..., "signature_header"=>..., "job"=>...])` |
| Formatar dinheiro, CPF, telefone, slug | `FormatHelper::money($v)` · `toCents($v)` · `cpfCnpj($v)` · `slug($t)` |
| Filtrar/agrupar/somar dados já na memória (JSON de API, payload, linhas) | `ArrayHelper::get($d, "a.b", $padrao)` · `pluck` · `groupBy` · `where` · `sortBy` · `sum` · `toTree` |
| Backup do banco (.sql / .sql.gz, estrutura ou completo) | `BackupDatabaseHelper::full(["gzip" => true])` · `schema()` · `downloadFull()` + `PlatformHelper::close()` |
| Tela de listagem (paginação, filtros, lote) | `js/vitrine.js`: `<div data-vitrine="url">` + `ApiResponseHelper::paginated()` |
| Guardar token de API / conferir webhook | `CryptoHelper::encrypt($v, "contexto")` / `verifySignature(...)` |
| Limitar tentativas (login, SMS, contato) | `if (!RateLimitHelper::enforce("chave:" . $ip, 5, 3600)) return;` |
| Enviar e-mail (confirmação, senha) | `MailHelper::send(["to"=>..., "template"=>..., "data"=>...])` ou `queue(...)` |
| Enviar por outra conta (Gmail, Outlook, ElasticEmail, SendGrid), várias contas ou fora do Joomla | `EmailSendHelper::register("gmail", [...])` · `make(["elasticemail", "gmail"])->to()->subject()->view()->send()` |
| Guardar resultado caro (dashboard, API) | `CacheHelper::remember("assunto:det", 300, $fn)` + `flushPrefix("assunto")` |
| Uma execução por vez (cron, clique duplo) | `LockHelper::run("nome", $fn)` → `$r["acquired"]` |
| Chamar API externa (ERP, pagamento, frete) | `HttpHelper::get/post($url, ...)` → `$r["ok"]`, `$r["json"]`; `HttpHelper::client([...])` |

## Contratos que mais causam bug

- UploadMaster, FileHelper, CsvHelper, ExportHelper, ChunkHelper e `QueueHelper::push` **não lançam**: teste `$r['success']`, motivo em `$r['mensagem']`. OrmBase/OrmTables **lançam**; `findOrFail` usa código 404.
- `ApiResponseHelper::exception()` responde **sempre 500**: trate o 404 antes.
- `PermissionHelper::require*` em HTML sem `$redirect` devolve `false` e **não para**: `if (!...) return;` (ou `setUseApiResponse(true)`).
- `updateWhere/deleteWhere` sem `where` lançam, salvo `allowMassOperation()`.
- `whereLike` já faz "contém"; `when($v, fn)` ignora `'0'`, `0` e `''`.
- Datas no banco em UTC: grave com `DateHelper::nowSql()`/`fromUser()`, nunca `date()`; mostre com `toUser()`; filtre período com `range()`/`between()` (senão "hoje" perde a noite). `ChunkUploadHelper.php` define `ChunkHelper`. `LogHelper::write` é privado.

## Esqueleto de controller

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
        return OrmTables::table('#__loja_produtos', ['fillable' => ['nome', 'preco'], 'casts' => ['preco' => 'float']]);
    }

    public function listar()
    {
        try {
            $r = $this->produtos()
                ->when(InputHelper::string('busca', '', 'get'), function ($q, $b) { $q->whereLike('nome', $b); })
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
            $dados = InputHelper::post(['nome' => 'string', 'preco' => 'float']);
            $v = ValidationHelper::validate($dados, ['nome' => 'required|max_length:150', 'preco' => 'required|numeric|min:0']);

            if (!$v['valid']) {
                return ApiResponseHelper::fromValidation($v);
            }

            $id = InputHelper::uint('id', 0, 'post');

            return $id > 0
                ? ApiResponseHelper::updated('Salvo.', $this->produtos()->update($id, $dados))
                : ApiResponseHelper::created('Criado.', $this->produtos()->create($dados));
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }
}
```
