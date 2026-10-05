# Infra: LogHelper, AuditHelper, IncludeHelper, DateHelper, LockHelper, CacheHelper, RateLimitHelper, CryptoHelper, FormatHelper, SettingsHelper

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

## CacheHelper (resultados caros)

```php
$totais = CacheHelper::remember('dashboard:totais', 300, function () { return Servico::totais(); });
$cats = CacheHelper::rememberForever('categorias:select', $fn);
CacheHelper::flushPrefix('categorias');      // no save do cadastro: invalida categorias:*
CacheHelper::get($k, $padrao) / set($k, $v, $seg) / has / forget / pull / flush()
```
- Sobre o cache do Joomla (handler do site), funciona com o cache do site desligado; prazo por item em segundos; guarda `null`/`false`; falha de armazenamento não derruba (calcula e segue).
- Chave `assunto:detalhes`; `flushPrefix('assunto')` invalida tudo do assunto. Resultado que depende do usuário/grupo → id na chave.
- Com LockHelper carregado, um só processo recalcula a chave expirada (opção `'lock' => false` desliga). `increment()` não é atômico (limites → RateLimitHelper). Nunca use cache para decidir saldo/estoque.

## RateLimitHelper (limite de tentativas)

```php
if (!RateLimitHelper::enforce('contato:' . RateLimitHelper::ip(), 5, 3600)) return;   // 429 + Retry-After
$s = RateLimitHelper::hit('busca:' . $ip, 60, 60);   // ['allowed','hits','limit','remaining','retry_after','reset_at']
RateLimitHelper::tooManyAttempts('login:' . $u, 5);   // só consulta
RateLimitHelper::clear('login:' . $u);                // login certo zera
```
- Contador atômico no banco (upsert MySQL/MariaDB ou PostgreSQL 9.5+), tabela `#__helpers_rate_limits` criada sozinha (`tables/rateLimitHelper.sql`). Janela fixa. Banco fora → libera (`setFailOpen(false)` para SMS pago).
- `ip()` só confia em X-Forwarded-For com `setTrustedProxies([...])`. Login: chave por conta **e** por IP; conte só as erradas; mensagem não revela se o usuário existe.

## CryptoHelper (segredos, webhooks, tokens)

```php
$v = CryptoHelper::encrypt($tokenApi, 'config.erp_token');   // grava no banco ('h1:...')
$t = CryptoHelper::decrypt($v, 'config.erp_token');          // null se adulterado/contexto/chave errada
CryptoHelper::verifySignature(file_get_contents('php://input'), $assinatura, $segredo, ['prefix' => 'sha256=']);
$link = CryptoHelper::sign(['pedido' => $id], 86400);  CryptoHelper::verifySigned($t);   // dados legíveis, não alteráveis
CryptoHelper::token(); CryptoHelper::numericCode(6); CryptoHelper::hashToken($token); CryptoHelper::equals($a, $b);
```
- XChaCha20-Poly1305 (sodium) ou AES-256-CBC+HMAC; contexto amarra o valor à coluna. Chave: `setKey(base64 de 32 bytes)` fora da pasta pública (padrão: derivada do `secret` do Joomla; trocar o secret perde os dados). Rotação: `setPreviousKeys()` + `reencrypt()`.
- Senha de usuário **não**: use `JUserHelper::hashPassword()`. Coluna cifrada não é buscável: guarde também `hashToken()` para busca. Token no banco só como `hashToken()`.

## FormatHelper (formatação brasileira)

```php
FormatHelper::money(1234.5);                 // 'R$ 1.234,50' (aceita '1.234,50', '1234.5'); sem símbolo: money($v, false)
FormatHelper::toCents('R$ 1.234,56');        // 123456 (conta em inteiros, sem float) · moneyFromCents(123456)
FormatHelper::cpfCnpj($doc); cep($v); phone($v); whatsapp($v); onlyDigits($v);
FormatHelper::slug('Pão de Açúcar');         // 'pao-de-acucar' · limit($html, 120) · plural($n, 'item', 'itens', 'Nenhum item')
FormatHelper::number($v, 2); percent(12.5); bytes(1536); name('MARIA DA SILVA'); initials($nome);
```
- Não lança; valor inválido volta como veio (texto) ou ''. Formatar não valida (CPF errado sai formatado): valide com ValidationHelper. Na view, escape a saída.

## SettingsHelper (configurações do componente)

```php
$limite = SettingsHelper::int('limite_upload_mb', 10);   // string/int/float/bool/array/oneOf; padrão se ausente ou vazio
$token = SettingsHelper::secret('erp_token');            // decifra (CryptoHelper)
SettingsHelper::requireKeys(['erp_url', 'erp_token']);   // RuntimeException "Configure em Opções do componente: ..."
SettingsHelper::set('erp_token', $novo, true);           // grava em #__extensions (cifrado); exija core.admin
SettingsHelper::setComponent('com_x');                   // CLI/plugin; padrão = option da requisição
```
- `bool` entende '1'/'0', sim/não; `array` aceita campo múltiplo, JSON ou texto por vírgula/linha; `'smtp.host'` lê subform. Repita no código o default do config.xml (só vale após salvar as Opções).
