# Banco: DbConnectionHelper, DbTransactionHelper, OrmBase, OrmTables, BackupDatabaseHelper

Carregue `OrmTables.php` (ele puxa `OrmBase`, `DbConnectionHelper` e `DbTransactionHelper`). Funciona em MySQL e PostgreSQL.

## OrmTables (sem criar classe)

```text
OrmTables::table('#__x', $opcoes)          // instância nova a cada chamada (alias: make)
OrmTables::register('produtos', '#__x', $opcoes); OrmTables::get('produtos')   // cópia nova, filtros não vazam
OrmTables::has/forget/flush/all
```
Alias de tabela (`'#__x AS x'`) só em consultas; para gravar use o nome sem alias.

## OrmBase (model por herança)

```php
class PedidoModel extends OrmBase
{
    protected $table = '#__loja_pedidos';
    protected $fillable = ['cliente_id', 'total', 'status'];
    protected $casts = ['total' => 'float', 'itens' => 'json', 'pago' => 'bool'];
    protected $softDeletes = true;
    // protected $connection = 'erp';
}
$m = new PedidoModel();            // ou PedidoModel::query()
```

**Opções** (no array de `table()`/construtor ou como propriedade): `primaryKey` ('id'), `timestamps` (true → `created_at`/`updated_at`), `createdAtColumn`, `updatedAtColumn`, `softDeletes` (false → `deleted_at`), `deletedAtColumn`, `casts`, `fillable`, `guarded`, `connection`.
**casts:** int, integer, float, double, bool, boolean, string, json, array, date, datetime.

### Consulta (encadeável; os filtros são limpos depois de executar)
`select($cols)` · `where($col, $valor)` / `where($col, '>=', $valor)` · `orWhere` · `whereIn/orWhereIn/whereNotIn($col, array)` · `whereNull/orWhereNull/whereNotNull` · `whereBetween($col, $a, $b)` · `whereLike/orWhereLike($col, $texto)` ("contém": já envolve com %, escapa % e _) · `whereGroup(function ($q) {...}, 'AND')` / `orWhereGroup` · `whereRaw($sqlJaSeguro)` · `when($valor, function ($q, $valor) {...}, $senao)` (aplica só se o valor for verdadeiro: `'0'`, `0` e `''` não aplicam) · `join/innerJoin/leftJoin/rightJoin($tabela, 'a.id = b.x_id')` · `crossJoin` · `orderBy($col, 'DESC')` · `latest()/oldest()` · `groupBy` · `having($sql)` · `limit($n, $offset)` · `lockForUpdate()` (só dentro de transação).

### Leitura (executa)
| Método | Retorno |
|---|---|
| `get()` | `stdClass[]` com casts |
| `first()` | objeto ou null |
| `find($id)` / `findBy($col, $valor)` | objeto ou null (ignoram filtros anteriores) |
| `findOrFail($id)` | objeto; `RuntimeException` código 404 |
| `all()` | todos (cuidado com tabelas grandes) |
| `value($col)` / `pluck($col, $chave = null)` | valor / lista ou mapa `[chave => valor]` (não combine com `select`) |
| `exists()` / `count($col = '*')` / `sum/avg/min/max($col)` | bool / int / float\|null |
| `paginate($porPagina, $pagina)` | `['itens','total','por_pagina','pagina_atual','total_paginas','paginacao' => JPagination]` |
| `chunk($tamanho, function ($lote, $n) {...})` | nº de lotes; `return false` para parar. Avança por OFFSET: se alterar a coluna filtrada, filtre por `id > $ultimoId` |

### Escrita
| Método | Faz |
|---|---|
| `create(array)` | INSERT respeitando fillable/guarded e timestamps; devolve o registro |
| `createMany(array $lista)` | vários |
| `update($id, array)` | devolve o registro atualizado |
| `updateOrCreate(array $condicoes, array $dados)` | upsert simples |
| `updateWhere(array)` / `deleteWhere()` | em massa pelos filtros; **sem where lança exceção**, salvo `->allowMassOperation()` |
| `increment/decrement($id, $col, $n = 1)` · `touch($id)` | |
| `delete($id)` | com softDeletes vira UPDATE de `deleted_at` |
| `restore($id)` / `forceDelete($id)` | |
| `truncate(true)` | esvazia (exige `true`) |
| `transaction(function ($orm) {...}, ['retries' => 3])` | transação na conexão da instância |

### SQL raw (o que o builder não cobre: CTE, UNION, subquery, funções do banco)
Valores **sempre** em `$bindings`, nunca concatenados. `?` posicional ou `:nome` nomeado (não misture); array vira lista para `IN (?)` (vazio lança exceção); null → NULL, bool → 1/0, int/float sem aspas (ok em LIMIT), DateTime → `'Y-m-d H:i:s'`. Ignora `?`/`:x` dentro de strings e comentários e o `::` do PostgreSQL. `#__` vira o prefixo. Não usa os filtros montados nem aplica casts. Erros de banco → `RuntimeException`; binding faltando/sobrando → `InvalidArgumentException`.

| Método | Retorno |
|---|---|
| `raw($sql, $bindings = [])` | `stdClass[]` |
| `rawFirst($sql, $bindings)` | objeto ou null (não adiciona LIMIT) |
| `rawValue($sql, $bindings)` | primeira coluna da primeira linha |
| `rawExecute($sql, $bindings)` | linhas afetadas (INSERT/UPDATE/DELETE/DDL) |
| `rawSql($sql, $bindings)` | SQL final, sem executar (depuração) |

Sem model: `OrmTables::raw($sql, $bindings, ['connection' => 'erp'])` (idem `rawFirst/rawValue/rawExecute/rawSql`).
```php
$linhas = OrmTables::raw('SELECT status, COUNT(*) total FROM #__pedidos WHERE criado_em >= :ini AND id IN (:ids) GROUP BY status', ['ini' => '2026-01-01', 'ids' => [1, 2, 3]]);
$afetadas = PedidoModel::query()->rawExecute('UPDATE #__pedidos SET status = ? WHERE id = ?', ['pago', 10]);
```

`db()` devolve o driver; `serverType()` 'mysql'/'postgresql'; `newQuery()` cópia limpa.

## DbTransactionHelper

```php
$pedido = DbTransactionHelper::run(function ($db, $tentativa) {
    $pedido = OrmTables::table('#__loja_pedidos')->create(['cliente_id' => 7, 'total' => 90.5]);
    OrmTables::table('#__loja_estoque')->where('produto_id', 7)->lockForUpdate()->first();
    return $pedido;                      // valor devolvido por run()
}, ['connection' => null, 'retries' => 3]);   // erro → rollback e relança; deadlock → repete
```
- `attempt($cb, $op)`: igual, mas não lança: `['success','mensagem','data','error']`. `transaction()` = alias de `run()`.
- Aninhamento vira SAVEPOINT automaticamente. Manual: `begin/commit/rollback($conexao)`, `isActive()`, `depth()`.
- Uma transação nunca abrange dois bancos.

## DbConnectionHelper (vários bancos)

```php
DbConnectionHelper::register('erp', [
    'driver' => 'pgsql',      // mysql, mysqli, pdomysql, pgsql, postgresql
    'host' => '10.0.0.5', 'port' => 5432, 'user' => 'app', 'password' => '...',
    'database' => 'erp', 'prefix' => 'erp_',
]);                                           // ou register('erp', $driverPronto)
OrmTables::table('#__clientes', ['connection' => 'erp'])->get();
DbConnectionHelper::get('erp');               // driver; sem nome = banco do Joomla
// has, forget, all, resolve($nomeOuDriverOuNull), serverType, isPostgres, isMysql
```
A conexão abre no primeiro uso. Credenciais: leia de `JFactory::getConfig()`/parâmetros do componente, nunca fixas no código.

## BackupDatabaseHelper (backup do banco em .sql)

```php
require_once JPATH_COMPONENT . '/helpers/BackupDatabaseHelper.php';   // puxa PlatformHelper e DbConnectionHelper
BackupDatabaseHelper::setBaseDirectory('/home/site/backups-banco');   // padrão: {raiz}/backups/database (fora da pasta pública é melhor)
$r = BackupDatabaseHelper::full(['gzip' => true, 'exclude_data' => ['#__session']]);   // estrutura + dados
$r = BackupDatabaseHelper::schema(['connection' => 'erp']);           // só estrutura (tabelas vazias, views, triggers)
// $r['success'], $r['mensagem'], $r['data'] = modo, banco, tabelas, views, triggers, linhas, snapshot, segundos, arquivo, caminho, bytes
// opções: connection, tables, exclude, exclude_data, prefix_only, schemas (pg), views, triggers, gzip, consistent, directory, file_name, save_copy
BackupDatabaseHelper::downloadFull(['gzip' => true, 'save_copy' => true]); PlatformHelper::close();   // download; checar PermissionHelper::require + requireToken antes
BackupDatabaseHelper::tables();   // [['nome', 'tipo' => 'tabela'|'view', 'engine', 'linhas_aprox', 'tamanho_bytes'], ...]
```
- MySQL/MariaDB e PostgreSQL 10+, em PHP puro (sem mysqldump/pg_dump); lê em blocos, não guarda na memória. Não lança: erro volta em `success = false`.
- Nunca apaga: na origem só SELECT/SHOW (PostgreSQL em transação READ ONLY); o arquivo não tem DROP/DELETE/TRUNCATE/REPLACE/UPDATE (MySQL: `CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`; PostgreSQL: `IF NOT EXISTS`, blocos DO, `ON CONFLICT DO NOTHING`). Restaurar sobre banco existente não sobrescreve registros; tabela sem chave duplica linhas se restaurada duas vezes.
- Chame **fora** de transação: no PostgreSQL devolve erro; no MySQL roda sem snapshot (`snapshot => false`). Backup grande: cron/CLI ou fila, com `LockHelper::run`.
- Restaurar: `mysql banco < arquivo.sql` (usa DELIMITER; 2ª restauração no MySQL para na primeira view já existente → `mysql --force`) · `psql -d banco -f arquivo.sql`.
