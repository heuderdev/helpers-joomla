# Infra: LogHelper, AuditHelper, IncludeHelper, DateHelper, LockHelper

## LogHelper (log técnico em arquivo)

```php
LogHelper::setBaseDirectory('components/com_x/logs');   // relativo a JPATH_SITE; padrão: log_path da configuração do Joomla
LogHelper::setDefaultCategory('com_x');
LogHelper::info('Pedido criado.', 'pedidos', ['pedido_id' => 10]);
LogHelper::exception($e, 'pedidos', ['pedido_id' => 10]);   // classe, mensagem, arquivo, linha
```
- Níveis: `debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency` — todos `($mensagem, $categoria = null, array $contexto = [])`.
- Atalhos com categoria fixa: `security`, `api`, `upload`, `queue`, `audit`, `payment` — `($mensagem, $contexto)`; `database(Throwable $e, $sql, $contexto)`.
- Arquivo: `<categoria>.<nível>.php`. Contexto sensível (senha, token, cartão, cvv, cookie, session…) é mascarado; `addSensitiveKey('minha_chave')` acrescenta chaves.
- `setRequestId()/getRequestId()` correlaciona logs de uma requisição; `setEnabled(false)` desliga.
- `write()` é **privado**: nunca gere `LogHelper::write(...)`.

## AuditHelper (trilha de auditoria no banco)

Tabela `#__audit_logs` (SQL em `tables/auditHelper.sql`). Grava usuário, IP, user agent, request_id e o antes/depois, com dados sensíveis mascarados.

```php
AuditHelper::created('pedido', $id, (array) $pedido);
AuditHelper::updated('pedido', $id, (array) $antes, (array) $depois, ['only_changes' => true]);
AuditHelper::deleted('pedido', $id, (array) $antes);
AuditHelper::statusChanged('pedido', $id, 'aberto', 'pago');
AuditHelper::securityDenied('acesso_negado', ['entity_type' => 'pedido', 'entity_id' => $id]);
```
Também: `restored`, `viewed`, `uploaded($tipo, $id, $arquivo)`, `downloaded`, `exported($tipo, $dados)`, `imported`, `payment($evento, $tipo, $id, $dados)`, `login($userId, $sucesso)`, `logout`, `queue($evento, $jobId, $dados)`, `exception($e)`, `custom($evento, $opcoes)`, `log($evento, $opcoes)`.
Opções comuns: `entity_type`, `entity_id`, `parent_entity_type`, `parent_entity_id`, `before`, `after`, `only_changes`, `description`, `metadata`, `level`, `category`, `status`.
Consulta: `find($id)`, `list($filtros)`, `count($filtros)`, `purge($dataAntes, 1000)`. Utilidades: `diff($antes, $depois)`, `sanitize($dados)`, `setConnection()`, `setTable()`, `setEnabled()`.
Grave a auditoria **dentro da mesma transação** da alteração quando ela precisar ser consistente.

## IncludeHelper (carregar helpers com as dependências)

```php
require_once JPATH_COMPONENT . '/helpers/IncludeHelper.php';
IncludeHelper::registerAutoloader();   // recomendado: cada helper é incluído (com as dependências) no primeiro uso
IncludeHelper::load(['OrmTables', 'ApiResponseHelper', 'CsvHelper']);   // ou carga explícita: ordem topológica, cada arquivo uma vez
IncludeHelper::loadAll();
IncludeHelper::getDependencies('CsvHelper');
```
Procura os arquivos na mesma pasta do `IncludeHelper.php`. Conhece: LogHelper, DateHelper, InputHelper, ValidationHelper, DbConnectionHelper, DbTransactionHelper, OrmBase, OrmTables, ApiResponseHelper, PermissionHelper, FileHelper, AuditHelper, CsvHelper, ChunkUploadHelper (classe `ChunkHelper`), ExportHelper, QueueHelper, UploadMaster. O autoloader também resolve `CsvHelperException` e `UploadMasterException`. **Não** conhece os arquivos de `fila/`: carregue-os com `require_once`.

## DateHelper (datas e fuso horário)

Banco em **UTC**, tela no **fuso do usuário** (perfil → configuração global → UTC). Nunca grave com `date('Y-m-d H:i:s')` (fuso do servidor).

```php
$dados['pago_em'] = DateHelper::nowSql();                 // gravar agora (UTC)
echo DateHelper::toUser($pedido->created_at);             // '04/10/2026 23:30' (fuso do usuário); vazio → ''
$entrega = DateHelper::fromUser('25/12/2026 14:30');      // digitado (fuso dele) → UTC; vazio → null; inválido lança
$quando = DateHelper::toSql('tomorrow 08:00', 'user');    // relativo no fuso do usuário → UTC (ex.: disponivel_em da fila)
$mes = DateHelper::range('month');                        // ['start', 'end'] UTC inclusivos → whereBetween('created_at', $mes['start'], $mes['end'])
$periodo = DateHelper::between(InputHelper::date('de'), InputHelper::date('ate'));   // filtro "de/até"
```
- Entradas: `Y-m-d H:i:s`, `Y-m-d`, `d/m/Y [H:i]`, `Y-m-d\TH:i` (datetime-local), ISO 8601 com fuso, timestamp, `DateTime`, relativos (`'+30 minutes'`, `'today'`). Data impossível (31/02) lança `InvalidArgumentException`; `null`/`''`/`0000-00-00` contam como vazio.
- Outros: `now()` (DateTimeImmutable UTC), `today()`, `format($v, $fmt, $tz)`, `longDate($v, $hora, $diaSemana)` ("4 de outubro de 2026"), `parse()`, `timestamp()`, `isValid()`, `isEmpty()`, `isPast/isFuture`, `diffInSeconds`, `diffInDays` (dias de calendário), `relative()` ("há 5 minutos"), `lastDays($n)`.
- `range($periodo, $ref)`: `day|week|month|year`; `$ref` só data = dia do calendário, com hora = instante UTC do banco, relativo = no fuso.
- Constantes: `SQL`, `SQL_DATE`, `BR`, `BR_DATETIME`, `BR_FULL`, `ISO`. Fusos: `'UTC'`, `'user'`, nome IANA ou `DateTimeZone`.
- Worker CLI (sem usuário): `DateHelper::setUserTimezone(DateHelper::timezoneForUser($uid))` e depois `setUserTimezone(null)`. Testes: `setTestNow('2026-10-05 02:30:00')` / `setTestNow(null)`.
- `OrmBase` (timestamps, soft delete) e `QueueHelper` já gravam com o DateHelper.

## LockHelper (uma execução por vez)

```php
$r = LockHelper::run('gerar-boletos', function () { return Servico::gerar(); });   // ['acquired' => bool, 'result' => ...]
if (!$r['acquired']) return ApiResponseHelper::conflict('Já está em andamento.');
LockHelper::run('pedido:' . $id, $fn, 10);                 // espera até 10 s pela trava
LockHelper::run('x', $fn, 0, ['throw' => true]);           // LockHelperException se ocupada
if (LockHelper::acquire('import:' . $id)) { try { ... } finally { LockHelper::release('import:' . $id); } }
```
- Drivers: `auto` (GET_LOCK no MySQL/MariaDB, `pg_try_advisory_lock` no PostgreSQL), `file` (flock, um servidor). Soltam sozinhas se o processo morrer; exceção no callback solta e relança.
- Evita execução **simultânea**, não repetida: releia o estado dentro da trava. `isLocked()` só para exibir. Nome por recurso (`'pedido:' . $id`) para paralelizar o resto. Em job: sem trava → `$this->release(60)`.
- PgBouncer em modo transaction não suporta trava de sessão (use `setDriver('file')`).
