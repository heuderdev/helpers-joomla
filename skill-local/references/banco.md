# Banco: DbConnectionHelper, DbTransactionHelper, OrmBase, OrmTables

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
