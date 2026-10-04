# Infra: LogHelper, AuditHelper, IncludeHelper

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
IncludeHelper::load(['OrmTables', 'ApiResponseHelper', 'CsvHelper']);   // ordem topológica, cada arquivo uma vez
IncludeHelper::loadAll();
IncludeHelper::getDependencies('CsvHelper');
```
Procura os arquivos na mesma pasta do `IncludeHelper.php`. Conhece: LogHelper, InputHelper, ValidationHelper, DbConnectionHelper, DbTransactionHelper, OrmBase, OrmTables, ApiResponseHelper, PermissionHelper, FileHelper, AuditHelper, CsvHelper, ChunkUploadHelper, ExportHelper, QueueHelper. **Não** conhece UploadMaster nem os arquivos de `fila/`: carregue-os com `require_once`.
