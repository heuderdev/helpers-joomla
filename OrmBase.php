<?php

defined('_JEXEC') or die;

if (!class_exists('DbTransactionHelper')) {
    require_once __DIR__ . '/DbTransactionHelper.php';
}

class OrmBase
{
    protected $db;

    protected $table;

    protected $primaryKey = 'id';

    protected $timestamps = true;

    protected $createdAtColumn = 'created_at';

    protected $updatedAtColumn = 'updated_at';

    protected $softDeletes = false;

    protected $deletedAtColumn = 'deleted_at';

    protected $casts = [];

    protected $fillable = [];

    protected $guarded = [];

    protected $tableColumns = null;

    protected $wheres = [];

    protected $selects = [];

    protected $joins = [];

    protected $orders = [];

    protected $groups = [];

    protected $havings = [];

    protected $limitValue = null;

    protected $offsetValue = null;

    protected $allowMassOperation = false;

    protected $lockForUpdate = false;

    /*
     * Opções aceitas no construtor. Só as informadas sobrescrevem as
     * propriedades; as demais mantêm o valor definido na classe, o que
     * permite models do tipo:
     *
     * class PedidoModel extends OrmBase
     * {
     *     protected $table = '#__pedidos';
     *     protected $softDeletes = true;
     * }
     */
    protected static $opcoesPermitidas = [
        'primaryKey' => 'string',
        'timestamps' => 'bool',
        'createdAtColumn' => 'string',
        'updatedAtColumn' => 'string',
        'softDeletes' => 'bool',
        'deletedAtColumn' => 'string',
        'casts' => 'array',
        'fillable' => 'array',
        'guarded' => 'array',
    ];

    public function __construct($table = null, array $opcoes = [])
    {
        if ($table !== null) {
            $this->table = $table;
        }

        $this->table = trim((string) $this->table);

        if ($this->table === '') {
            throw new InvalidArgumentException(
                'A tabela deve ser informada ao instanciar ' .
                static::class .
                ' (parâmetro $table ou propriedade protected $table).'
            );
        }

        $this->db = JFactory::getDbo();

        foreach (static::$opcoesPermitidas as $opcao => $tipo) {
            if (!isset($opcoes[$opcao])) {
                continue;
            }

            $valor = $opcoes[$opcao];

            if ($tipo === 'bool') {
                $valor = (bool) $valor;
            } elseif ($tipo === 'array') {
                $valor = (array) $valor;
            }

            $this->$opcao = $valor;
        }
    }

    /*
     * Ponto de entrada dos models: PedidoModel::query()->where(...)->get().
     * Cada chamada devolve uma instância nova, sem filtros de consultas
     * anteriores.
     */
    public static function query(array $opcoes = [])
    {
        return new static(null, $opcoes);
    }

    public static function tabela($table, array $opcoes = [])
    {
        return new static($table, $opcoes);
    }

    public function db()
    {
        return $this->db;
    }

    public function tabelaAtual()
    {
        return $this->table;
    }

    public function newQuery()
    {
        $this->wheres = [];
        $this->selects = [];
        $this->joins = [];
        $this->orders = [];
        $this->groups = [];
        $this->havings = [];
        $this->limitValue = null;
        $this->offsetValue = null;
        $this->allowMassOperation = false;
        $this->lockForUpdate = false;

        return $this;
    }

    /*
     * Libera updateWhere()/deleteWhere() sem nenhum where, ou seja,
     * atingindo a tabela inteira. Vale só para a próxima operação.
     */
    public function allowMassOperation()
    {
        $this->allowMassOperation = true;

        return $this;
    }

    protected function garantirFiltroParaOperacaoEmMassa($operacao)
    {
        if (!empty($this->wheres) || $this->allowMassOperation) {
            return;
        }

        $this->newQuery();

        throw new LogicException(
            'Operação ' . $operacao . ' sem where na tabela ' .
            $this->table . ' atingiria todos os registros. ' .
            'Chame allowMassOperation() se for intencional.'
        );
    }

    protected function getTableColumns()
    {
        if ($this->tableColumns !== null) {
            return $this->tableColumns;
        }

        try {
            $colunas = $this->db->getTableColumns($this->table, false);

            $this->tableColumns = array_keys($colunas);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Não foi possível obter as colunas da tabela ' .
                $this->table . ': ' . $e->getMessage(),
                500,
                $e
            );
        }

        return $this->tableColumns;
    }

    protected function filtrarColunasValidas(array $dados)
    {
        $colunasValidas = $this->getTableColumns();

        $dadosFiltrados = [];

        foreach ($dados as $coluna => $valor) {
            if (!in_array($coluna, $colunasValidas, true)) {
                continue;
            }

            if (!empty($this->fillable)
                && !in_array($coluna, $this->fillable, true)) {
                continue;
            }

            if (!empty($this->guarded)
                && in_array($coluna, $this->guarded, true)) {
                continue;
            }

            $dadosFiltrados[$coluna] = $valor;
        }

        return $dadosFiltrados;
    }

    protected function aplicarCast($coluna, $valor)
    {
        if ($valor === null) {
            return null;
        }

        if (!isset($this->casts[$coluna])) {
            return $valor;
        }

        switch ($this->casts[$coluna]) {
            case 'int':
            case 'integer':
                return (int) $valor;

            case 'float':
            case 'double':
                return (float) $valor;

            case 'bool':
            case 'boolean':
                return (bool) $valor;

            case 'string':
                return (string) $valor;

            case 'array':
            case 'json':
                return is_string($valor)
                    ? json_decode($valor, true)
                    : $valor;

            case 'datetime':
            case 'date':
                return (string) $valor;

            default:
                return $valor;
        }
    }

    protected function aplicarCastsEmObjeto($objeto)
    {
        if ($objeto === null) {
            return null;
        }

        foreach ($this->casts as $coluna => $tipo) {
            if (property_exists($objeto, $coluna)) {
                $objeto->$coluna = $this->aplicarCast(
                    $coluna,
                    $objeto->$coluna
                );
            }
        }

        return $objeto;
    }

    protected function aplicarCastsEmLista(array $lista)
    {
        foreach ($lista as $indice => $objeto) {
            $lista[$indice] = $this->aplicarCastsEmObjeto($objeto);
        }

        return $lista;
    }

    protected function prepararValorParaPersistencia($coluna, $valor)
    {
        if (isset($this->casts[$coluna])
            && in_array($this->casts[$coluna], ['array', 'json'], true)) {
            return is_array($valor)
                ? json_encode(
                    $valor,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                )
                : $valor;
        }

        if (isset($this->casts[$coluna])
            && in_array($this->casts[$coluna], ['bool', 'boolean'], true)) {
            return $valor ? 1 : 0;
        }

        return $valor;
    }

    /*
     * Executa uma operação e padroniza o erro: qualquer exceção vira
     * RuntimeException "<mensagem> na tabela <tabela>: <erro original>",
     * código 500, com a exceção original em getPrevious().
     *
     * LogicException (uso incorreto do ORM, ex.: lockForUpdate() fora de
     * transação) sobe sem embrulho, para não parecer erro de banco.
     */
    protected function executar(callable $operacao, $mensagemErro)
    {
        try {
            return $operacao();
        } catch (LogicException $e) {
            throw $e;
        } catch (Exception $e) {
            throw new RuntimeException(
                $mensagemErro . ' na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    /*
     * Igual a executar(), mas limpa a query no final, com sucesso ou
     * erro. Usado pelos métodos que encerram uma consulta montada.
     */
    protected function executarEResetar(callable $operacao, $mensagemErro)
    {
        try {
            return $this->executar($operacao, $mensagemErro);
        } finally {
            $this->newQuery();
        }
    }

    public function select($colunas)
    {
        $colunas = is_array($colunas)
            ? $colunas
            : func_get_args();

        foreach ($colunas as $coluna) {
            $this->selects[] = $coluna;
        }

        return $this;
    }

    /*
     * Aplica $callback só quando $valor é verdadeiro. Evita quebrar a
     * cadeia com if quando o filtro vem da tela:
     *
     * ->when($status, function ($q, $status) {
     *     $q->where('status', $status);
     * })
     *
     * Cuidado: '0', 0 e '' contam como falso.
     */
    public function when($valor, callable $callback, $senao = null)
    {
        if ($valor) {
            $callback($this, $valor);
        } elseif (is_callable($senao)) {
            $senao($this, $valor);
        }

        return $this;
    }

    protected function adicionarWhere(array $condicao, $conector = 'AND')
    {
        $condicao['conector'] = $conector;

        $this->wheres[] = $condicao;

        return $this;
    }

    protected function adicionarWhereBasico(array $argumentos, $conector)
    {
        if (count($argumentos) === 2) {
            $operador = '=';
            $valor = $argumentos[1];
        } else {
            $operador = strtoupper(trim($argumentos[1]));
            $valor = $argumentos[2];
        }

        $operadoresPermitidos = [
            '=',
            '!=',
            '<>',
            '>',
            '<',
            '>=',
            '<=',
        ];

        if (!in_array($operador, $operadoresPermitidos, true)) {
            throw new InvalidArgumentException(
                'Operador de comparação inválido: ' . $operador
            );
        }

        return $this->adicionarWhere([
            'tipo' => 'basico',
            'coluna' => $argumentos[0],
            'operador' => $operador,
            'valor' => $valor,
        ], $conector);
    }

    public function where($coluna, $operadorOuValor, $valor = null)
    {
        return $this->adicionarWhereBasico(func_get_args(), 'AND');
    }

    public function orWhere($coluna, $operadorOuValor, $valor = null)
    {
        return $this->adicionarWhereBasico(func_get_args(), 'OR');
    }

    public function whereIn($coluna, array $valores)
    {
        return $this->adicionarWhere([
            'tipo' => 'in',
            'coluna' => $coluna,
            'valores' => $valores,
            'negado' => false,
        ]);
    }

    public function orWhereIn($coluna, array $valores)
    {
        return $this->adicionarWhere([
            'tipo' => 'in',
            'coluna' => $coluna,
            'valores' => $valores,
            'negado' => false,
        ], 'OR');
    }

    public function whereNotIn($coluna, array $valores)
    {
        return $this->adicionarWhere([
            'tipo' => 'in',
            'coluna' => $coluna,
            'valores' => $valores,
            'negado' => true,
        ]);
    }

    public function whereNull($coluna)
    {
        return $this->adicionarWhere([
            'tipo' => 'nulo',
            'coluna' => $coluna,
            'negado' => false,
        ]);
    }

    public function orWhereNull($coluna)
    {
        return $this->adicionarWhere([
            'tipo' => 'nulo',
            'coluna' => $coluna,
            'negado' => false,
        ], 'OR');
    }

    public function whereNotNull($coluna)
    {
        return $this->adicionarWhere([
            'tipo' => 'nulo',
            'coluna' => $coluna,
            'negado' => true,
        ]);
    }

    public function whereBetween($coluna, $valorInicial, $valorFinal)
    {
        return $this->adicionarWhere([
            'tipo' => 'entre',
            'coluna' => $coluna,
            'valorInicial' => $valorInicial,
            'valorFinal' => $valorFinal,
        ]);
    }

    public function whereLike($coluna, $valor)
    {
        return $this->adicionarWhere([
            'tipo' => 'like',
            'coluna' => $coluna,
            'valor' => $valor,
        ]);
    }

    public function orWhereLike($coluna, $valor)
    {
        return $this->adicionarWhere([
            'tipo' => 'like',
            'coluna' => $coluna,
            'valor' => $valor,
        ], 'OR');
    }

    public function whereRaw($expressaoSql)
    {
        return $this->adicionarWhere([
            'tipo' => 'raw',
            'expressao' => $expressaoSql,
        ]);
    }

    /*
     * Agrupa condições entre parênteses:
     *
     * ->where('ativo', 1)
     * ->whereGroup(function ($q) {
     *     $q->where('estoque', '<=', 5)->orWhere('preco', '<=', 0);
     * })
     *
     * gera: ativo = 1 AND (estoque <= 5 OR preco <= 0)
     *
     * Dentro do callback use só métodos where*.
     */
    public function whereGroup(callable $callback, $conector = 'AND')
    {
        $wheresExternos = $this->wheres;
        $this->wheres = [];

        try {
            $callback($this);

            $wheresDoGrupo = $this->wheres;
        } finally {
            $this->wheres = $wheresExternos;
        }

        if (empty($wheresDoGrupo)) {
            return $this;
        }

        return $this->adicionarWhere([
            'tipo' => 'grupo',
            'wheres' => $wheresDoGrupo,
        ], $conector);
    }

    public function orWhereGroup(callable $callback)
    {
        return $this->whereGroup($callback, 'OR');
    }

    public function join($tabela, $condicao = null, $tipo = 'INNER')
    {
        $tabela = trim((string) $tabela);
        $tipo = strtoupper(trim((string) $tipo));

        if ($tabela === '') {
            throw new InvalidArgumentException(
                'A tabela do JOIN deve ser informada.'
            );
        }

        $tiposNormalizados = [
            'INNER JOIN' => 'INNER',
            'LEFT JOIN' => 'LEFT',
            'LEFT OUTER JOIN' => 'LEFT OUTER',
            'RIGHT JOIN' => 'RIGHT',
            'RIGHT OUTER JOIN' => 'RIGHT OUTER',
            'CROSS JOIN' => 'CROSS',
        ];

        if (isset($tiposNormalizados[$tipo])) {
            $tipo = $tiposNormalizados[$tipo];
        }

        $tiposPermitidos = [
            'INNER',
            'LEFT',
            'LEFT OUTER',
            'RIGHT',
            'RIGHT OUTER',
            'CROSS',
        ];

        if ($tipo === 'FULL'
            || $tipo === 'FULL OUTER'
            || $tipo === 'FULL JOIN'
            || $tipo === 'FULL OUTER JOIN') {
            throw new RuntimeException(
                'FULL OUTER JOIN não é suportado nativamente pelo MySQL/MariaDB. ' .
                'Utilize UNION entre LEFT JOIN e RIGHT JOIN ou uma consulta SQL específica.'
            );
        }

        if (!in_array($tipo, $tiposPermitidos, true)) {
            throw new InvalidArgumentException(
                'Tipo de JOIN inválido: ' . $tipo
            );
        }

        if ($tipo !== 'CROSS' && trim((string) $condicao) === '') {
            throw new InvalidArgumentException(
                'A condição ON é obrigatória para JOIN do tipo ' . $tipo . '.'
            );
        }

        $this->joins[] = [
            'tabela' => $tabela,
            'condicao' => $condicao,
            'tipo' => $tipo,
        ];

        return $this;
    }

    public function innerJoin($tabela, $condicao)
    {
        return $this->join($tabela, $condicao, 'INNER');
    }

    public function leftJoin($tabela, $condicao)
    {
        return $this->join($tabela, $condicao, 'LEFT');
    }

    public function leftOuterJoin($tabela, $condicao)
    {
        return $this->join($tabela, $condicao, 'LEFT OUTER');
    }

    public function rightJoin($tabela, $condicao)
    {
        return $this->join($tabela, $condicao, 'RIGHT');
    }

    public function rightOuterJoin($tabela, $condicao)
    {
        return $this->join($tabela, $condicao, 'RIGHT OUTER');
    }

    public function crossJoin($tabela)
    {
        return $this->join($tabela, null, 'CROSS');
    }

    public function orderBy($coluna, $direcao = 'ASC')
    {
        $direcao = strtoupper($direcao) === 'DESC'
            ? 'DESC'
            : 'ASC';

        $this->orders[] = $this->db->quoteName($coluna) . ' ' . $direcao;

        return $this;
    }

    public function latest($coluna = null)
    {
        return $this->orderBy(
            $coluna !== null ? $coluna : $this->createdAtColumn,
            'DESC'
        );
    }

    public function oldest($coluna = null)
    {
        return $this->orderBy(
            $coluna !== null ? $coluna : $this->createdAtColumn,
            'ASC'
        );
    }

    public function groupBy($coluna)
    {
        $this->groups[] = $this->db->quoteName($coluna);

        return $this;
    }

    public function having($condicaoSql)
    {
        $this->havings[] = $condicaoSql;

        return $this;
    }

    public function limit($quantidade, $offset = 0)
    {
        $this->limitValue = (int) $quantidade;
        $this->offsetValue = (int) $offset;

        return $this;
    }

    /*
     * SELECT ... FOR UPDATE: trava as linhas lidas até o fim da
     * transação. Outra transação que tente travar ou alterar as mesmas
     * linhas espera. Use para "ler, conferir e depois gravar":
     *
     * DbTransactionHelper::run(function () {
     *     $produto = ProdutoModel::query()
     *         ->where('id', $id)
     *         ->lockForUpdate()
     *         ->first();
     *     // ninguém altera este produto até o COMMIT
     * });
     *
     * Fora de uma transação a trava não teria efeito, então lança erro.
     */
    public function lockForUpdate()
    {
        $this->lockForUpdate = true;

        return $this;
    }

    protected function montarWheres($query)
    {
        if (empty($this->wheres)) {
            return $query;
        }

        /*
         * Parênteses isolam os OR do usuário dos filtros adicionados depois
         * (ex.: soft delete), já que o Joomla junta os where com AND.
         */
        $query->where('(' . $this->montarExpressaoWheres($this->wheres) . ')');

        return $query;
    }

    protected function montarExpressaoWheres(array $wheres)
    {
        $expressoes = [];

        foreach (array_values($wheres) as $indice => $condicao) {
            $sql = $this->montarCondicaoUnica($condicao);

            $expressoes[] = $indice === 0
                ? $sql
                : $condicao['conector'] . ' ' . $sql;
        }

        return implode(' ', $expressoes);
    }

    protected function montarCondicaoUnica(array $condicao)
    {
        switch ($condicao['tipo']) {
            case 'basico':
                return $this->db->quoteName($condicao['coluna']) .
                    ' ' . $condicao['operador'] .
                    ' ' . $this->db->quote($condicao['valor']);

            case 'in':
                if (empty($condicao['valores'])) {
                    return $condicao['negado'] ? '1 = 1' : '1 = 0';
                }

                $valoresEscapados = array_map(
                    [$this->db, 'quote'],
                    $condicao['valores']
                );

                return $this->db->quoteName($condicao['coluna']) .
                    ($condicao['negado'] ? ' NOT IN (' : ' IN (') .
                    implode(',', $valoresEscapados) .
                    ')';

            case 'nulo':
                return $this->db->quoteName($condicao['coluna']) .
                    ($condicao['negado'] ? ' IS NOT NULL' : ' IS NULL');

            case 'entre':
                return $this->db->quoteName($condicao['coluna']) .
                    ' BETWEEN ' .
                    $this->db->quote($condicao['valorInicial']) .
                    ' AND ' .
                    $this->db->quote($condicao['valorFinal']);

            case 'like':
                $valorEscapado = $this->db->escape(
                    $condicao['valor'],
                    true
                );

                return $this->db->quoteName($condicao['coluna']) .
                    ' LIKE ' .
                    $this->db->quote('%' . $valorEscapado . '%', false);

            case 'raw':
                return $condicao['expressao'];

            case 'grupo':
                return '(' . $this->montarExpressaoWheres($condicao['wheres']) . ')';

            default:
                throw new RuntimeException(
                    'Tipo de condição where desconhecido: ' .
                    $condicao['tipo']
                );
        }
    }

    /*
     * Aceita:
     * - #__pedidos
     * - #__pedidos AS p
     * - #__pedidos p
     *
     * Retorna:
     * - `#__pedidos`
     * - `#__pedidos` AS `p`
     */
    protected function montarTabelaComAlias($tabela)
    {
        $tabela = trim((string) $tabela);

        if (preg_match(
            '/^(.+?)\s+AS\s+([a-zA-Z_][a-zA-Z0-9_]*)$/i',
            $tabela,
            $matches
        )) {
            return $this->db->quoteName(
                trim($matches[1]),
                trim($matches[2])
            );
        }

        if (preg_match(
            '/^([^\s]+)\s+([a-zA-Z_][a-zA-Z0-9_]*)$/',
            $tabela,
            $matches
        )) {
            return $this->db->quoteName(
                trim($matches[1]),
                trim($matches[2])
            );
        }

        return $this->db->quoteName($tabela);
    }

    protected function montarJoins($query)
    {
        foreach ($this->joins as $join) {
            $tipo = $join['tipo'];
            $tabela = $join['tabela'];
            $condicao = $join['condicao'];

            if ($tipo === 'CROSS') {
                $query->join('CROSS', $tabela);
                continue;
            }

            $query->join(
                $tipo,
                $tabela . ' ON ' . $condicao
            );
        }

        return $query;
    }

    protected function montarQueryBase()
    {
        $query = $this->db->getQuery(true);

        $colunasSelecionadas = !empty($this->selects)
            ? $this->selects
            : ['*'];

        $query->select($colunasSelecionadas);
        $query->from($this->montarTabelaComAlias($this->table));

        $this->montarJoins($query);
        $this->montarWheres($query);

        if ($this->softDeletes) {
            $query->where(
                $this->db->quoteName($this->deletedAtColumn) .
                ' IS NULL'
            );
        }

        foreach ($this->groups as $grupo) {
            $query->group($grupo);
        }

        foreach ($this->havings as $having) {
            $query->having($having);
        }

        foreach ($this->orders as $order) {
            $query->order($order);
        }

        return $query;
    }

    /*
     * Prepara o SELECT no driver. Com lockForUpdate(), o LIMIT é montado
     * aqui, porque o MySQL exige "... LIMIT n FOR UPDATE" e o driver
     * acrescentaria o LIMIT depois do FOR UPDATE.
     */
    protected function prepararSelect($query, $offset = 0, $limite = 0)
    {
        $offset = (int) $offset;
        $limite = (int) $limite;

        if (!$this->lockForUpdate) {
            $this->db->setQuery($query, $offset, $limite);

            return;
        }

        if (!DbTransactionHelper::isActive()) {
            throw new LogicException(
                'lockForUpdate() só tem efeito dentro de uma transação. ' .
                'Use DbTransactionHelper::run() ou $orm->transaction().'
            );
        }

        $sql = (string) $query;

        if ($limite > 0) {
            $sql .= ' LIMIT ' . $offset . ', ' . $limite;
        }

        $this->db->setQuery($sql . ' FOR UPDATE');
    }

    public function get()
    {
        return $this->executarEResetar(function () {
            $this->prepararSelect(
                $this->montarQueryBase(),
                $this->offsetValue,
                $this->limitValue
            );

            return $this->aplicarCastsEmLista(
                $this->db->loadObjectList()
            );
        }, 'Erro ao executar consulta');
    }

    public function first()
    {
        $this->limitValue = 1;
        $this->offsetValue = 0;

        $resultado = $this->get();

        return !empty($resultado)
            ? $resultado[0]
            : null;
    }

    public function all()
    {
        return $this->newQuery()->get();
    }

    public function find($id)
    {
        $this->newQuery();

        $this->where($this->primaryKey, (int) $id);

        return $this->first();
    }

    public function findOrFail($id)
    {
        $registro = $this->find($id);

        if ($registro === null) {
            throw new RuntimeException(
                'Registro não encontrado na tabela ' .
                $this->table .
                ' para o ID ' . (int) $id . '.',
                404
            );
        }

        return $registro;
    }

    public function findBy($coluna, $valor)
    {
        $this->newQuery();

        $this->where($coluna, $valor);

        return $this->first();
    }

    public function value($coluna)
    {
        $this->select([$coluna]);

        return $this->executarEResetar(function () {
            $this->prepararSelect($this->montarQueryBase(), 0, 1);

            return $this->db->loadResult();
        }, 'Erro ao obter valor da coluna ' . $coluna);
    }

    public function pluck($coluna, $colunaChave = null)
    {
        $colunas = $colunaChave !== null
            ? [$coluna, $colunaChave]
            : [$coluna];

        $this->select($colunas);

        return $this->executarEResetar(function () use ($coluna, $colunaChave) {
            $this->prepararSelect(
                $this->montarQueryBase(),
                $this->offsetValue,
                $this->limitValue
            );

            return $colunaChave !== null
                ? $this->db->loadAssocList($colunaChave, $coluna)
                : $this->db->loadColumn();
        }, 'Erro ao executar pluck');
    }

    public function exists()
    {
        $this->select(['1']);

        return $this->executarEResetar(function () {
            $this->prepararSelect($this->montarQueryBase(), 0, 1);

            return $this->db->loadResult() !== null;
        }, 'Erro ao verificar existência');
    }

    public function count($coluna = '*')
    {
        $this->selects = [
            'COUNT(' .
            ($coluna === '*'
                ? '*'
                : $this->db->quoteName($coluna)) .
            ') AS total',
        ];

        return $this->executarEResetar(function () {
            $this->prepararSelect($this->montarQueryBase());

            return (int) $this->db->loadResult();
        }, 'Erro ao contar registros');
    }

    public function sum($coluna)
    {
        return $this->agregar('SUM', $coluna);
    }

    public function avg($coluna)
    {
        return $this->agregar('AVG', $coluna);
    }

    public function min($coluna)
    {
        return $this->agregar('MIN', $coluna);
    }

    public function max($coluna)
    {
        return $this->agregar('MAX', $coluna);
    }

    protected function agregar($funcao, $coluna)
    {
        $this->selects = [
            $funcao .
            '(' .
            $this->db->quoteName($coluna) .
            ') AS agregado',
        ];

        return $this->executarEResetar(function () {
            $this->prepararSelect($this->montarQueryBase());

            $resultado = $this->db->loadResult();

            return $resultado !== null
                ? (float) $resultado
                : null;
        }, 'Erro ao calcular ' . $funcao);
    }

    public function paginate($porPagina = 15, $paginaAtual = 1)
    {
        $porPagina = max(1, (int) $porPagina);
        $paginaAtual = max(1, (int) $paginaAtual);

        $clone = clone $this;

        try {
            $totalGeral = $clone->count();
        } catch (Throwable $e) {
            $this->newQuery();

            throw $e;
        }

        $offset = ($paginaAtual - 1) * $porPagina;

        $this->limit($porPagina, $offset);

        $itens = $this->get();

        $paginacao = new JPagination(
            $totalGeral,
            $offset,
            $porPagina
        );

        return [
            'itens' => $itens,
            'total' => $totalGeral,
            'por_pagina' => $porPagina,
            'pagina_atual' => $paginaAtual,
            'total_paginas' => (int) ceil(
                $totalGeral / max(1, $porPagina)
            ),
            'paginacao' => $paginacao,
        ];
    }

    /*
     * Processa a consulta em lotes, sem carregar tudo na memória:
     *
     * PedidoModel::query()->where('status', 'pago')->chunk(500, function ($lote, $numeroDoLote) {
     *     foreach ($lote as $pedido) { ... }
     * });
     *
     * Retornar false no callback interrompe. Sem orderBy, ordena pela
     * chave primária para os lotes serem estáveis.
     *
     * Atenção: a paginação é por OFFSET. Se o callback alterar uma coluna
     * usada no where (ex.: muda o status filtrado), registros serão
     * pulados; nesse caso, filtre por id > último id processado.
     */
    public function chunk($tamanho, callable $callback)
    {
        $tamanho = max(1, (int) $tamanho);

        $base = clone $this;
        $this->newQuery();

        if (empty($base->orders)) {
            $base->orderBy($this->primaryKey);
        }

        $numeroDoLote = 0;

        do {
            $consulta = clone $base;

            $lote = $consulta
                ->limit($tamanho, $numeroDoLote * $tamanho)
                ->get();

            if (empty($lote)) {
                break;
            }

            $numeroDoLote++;

            if ($callback($lote, $numeroDoLote) === false) {
                break;
            }
        } while (count($lote) === $tamanho);

        return $numeroDoLote;
    }

    /*
     * Filtra colunas, preenche timestamps e converte os valores (json,
     * bool) para gravar. Devolve [coluna => valor pronto para o banco].
     */
    protected function prepararDadosParaGravar(array $dados, $criando)
    {
        $dados = $this->filtrarColunasValidas($dados);

        if ($this->timestamps) {
            $agora = date('Y-m-d H:i:s');

            if ($criando) {
                $dados[$this->createdAtColumn] = $agora;
            }

            $dados[$this->updatedAtColumn] = $agora;
        }

        foreach ($dados as $coluna => $valor) {
            $dados[$coluna] = $this->prepararValorParaPersistencia(
                $coluna,
                $valor
            );
        }

        return $dados;
    }

    protected function aplicarSet($query, array $dados)
    {
        foreach ($dados as $coluna => $valor) {
            $query->set(
                $this->db->quoteName($coluna) .
                ' = ' .
                ($valor === null ? 'NULL' : $this->db->quote($valor))
            );
        }

        return $query;
    }

    protected function whereChavePrimaria($query, $id)
    {
        return $query->where(
            $this->db->quoteName($this->primaryKey) .
            ' = ' .
            (int) $id
        );
    }

    protected function novaQueryUpdate()
    {
        return $this->db
            ->getQuery(true)
            ->update($this->db->quoteName($this->table));
    }

    protected function novaQueryDelete()
    {
        return $this->db
            ->getQuery(true)
            ->delete($this->db->quoteName($this->table));
    }

    protected function executarEscrita($query)
    {
        $this->db->setQuery($query);
        $this->db->execute();

        return (int) $this->db->getAffectedRows();
    }

    /*
     * UPDATE/DELETE usando os where montados. Bloqueia a operação sem
     * where (ver allowMassOperation()) e devolve as linhas afetadas.
     */
    protected function executarEscritaEmMassa($operacao, callable $montarQuery, $mensagemErro)
    {
        $this->garantirFiltroParaOperacaoEmMassa($operacao);

        return $this->executarEResetar(function () use ($montarQuery) {
            $query = $montarQuery();

            if ($query === null) {
                return 0;
            }

            $this->montarWheres($query);

            return $this->executarEscrita($query);
        }, $mensagemErro);
    }

    public function create(array $dados)
    {
        return $this->executar(function () use ($dados) {
            $objeto = (object) $this->prepararDadosParaGravar($dados, true);

            $this->db->insertObject(
                $this->table,
                $objeto,
                $this->primaryKey
            );

            return $this->find($objeto->{$this->primaryKey});
        }, 'Erro ao criar registro');
    }

    public function createMany(array $listaDeDados)
    {
        return $this->executar(function () use ($listaDeDados) {
            return DbTransactionHelper::run(
                function () use ($listaDeDados) {
                    $criados = [];

                    foreach ($listaDeDados as $dados) {
                        $criados[] = $this->create($dados);
                    }

                    return $criados;
                },
                ['retries' => 0]
            );
        }, 'Erro ao criar múltiplos registros');
    }

    public function firstOrCreate(
        array $condicoes,
        array $dadosAdicionais = []
    ) {
        $this->newQuery();

        foreach ($condicoes as $coluna => $valor) {
            $this->where($coluna, $valor);
        }

        $registroExistente = $this->first();

        if ($registroExistente !== null) {
            return $registroExistente;
        }

        return $this->create(
            array_merge($condicoes, $dadosAdicionais)
        );
    }

    public function updateOrCreate(array $condicoes, array $dados)
    {
        $this->newQuery();

        foreach ($condicoes as $coluna => $valor) {
            $this->where($coluna, $valor);
        }

        $registroExistente = $this->first();

        if ($registroExistente === null) {
            return $this->create(array_merge($condicoes, $dados));
        }

        $this->newQuery();

        return $this->update(
            (int) $registroExistente->{$this->primaryKey},
            $dados
        );
    }

    public function update($id, array $dados)
    {
        return $this->executar(function () use ($id, $dados) {
            $dados = $this->prepararDadosParaGravar($dados, false);

            if (!empty($dados)) {
                $query = $this->aplicarSet($this->novaQueryUpdate(), $dados);

                $this->executarEscrita($this->whereChavePrimaria($query, $id));
            }

            return $this->find($id);
        }, 'Erro ao atualizar registro');
    }

    public function updateWhere(array $dados)
    {
        return $this->executarEscritaEmMassa('updateWhere', function () use ($dados) {
            $dados = $this->prepararDadosParaGravar($dados, false);

            return empty($dados)
                ? null
                : $this->aplicarSet($this->novaQueryUpdate(), $dados);
        }, 'Erro ao atualizar registros');
    }

    protected function somarNaColuna($id, $coluna, $quantidade, $verbo)
    {
        return $this->executar(function () use ($id, $coluna, $quantidade) {
            $query = $this->novaQueryUpdate()->set(
                $this->db->quoteName($coluna) .
                ' = ' .
                $this->db->quoteName($coluna) .
                ($quantidade < 0 ? ' - ' : ' + ') .
                abs($quantidade)
            );

            $this->executarEscrita($this->whereChavePrimaria($query, $id));

            return $this->find($id);
        }, 'Erro ao ' . $verbo . ' coluna ' . $coluna);
    }

    public function increment($id, $coluna, $quantidade = 1)
    {
        return $this->somarNaColuna($id, $coluna, (int) $quantidade, 'incrementar');
    }

    public function decrement($id, $coluna, $quantidade = 1)
    {
        return $this->somarNaColuna($id, $coluna, -(int) $quantidade, 'decrementar');
    }

    public function touch($id)
    {
        if (!$this->timestamps) {
            return $this->find($id);
        }

        return $this->update($id, []);
    }

    /*
     * Com softDeletes, "excluir" é um UPDATE que preenche deleted_at.
     */
    protected function novaQueryExclusao()
    {
        if (!$this->softDeletes) {
            return $this->novaQueryDelete();
        }

        return $this->aplicarSet(
            $this->novaQueryUpdate(),
            [$this->deletedAtColumn => date('Y-m-d H:i:s')]
        );
    }

    public function delete($id)
    {
        return $this->executar(function () use ($id) {
            $this->executarEscrita(
                $this->whereChavePrimaria($this->novaQueryExclusao(), $id)
            );

            return true;
        }, 'Erro ao excluir registro');
    }

    public function deleteWhere()
    {
        return $this->executarEscritaEmMassa('deleteWhere', function () {
            return $this->novaQueryExclusao();
        }, 'Erro ao excluir registros');
    }

    public function restore($id)
    {
        if (!$this->softDeletes) {
            return $this->find($id);
        }

        return $this->executar(function () use ($id) {
            $query = $this->aplicarSet(
                $this->novaQueryUpdate(),
                [$this->deletedAtColumn => null]
            );

            $this->executarEscrita($this->whereChavePrimaria($query, $id));

            return $this->find($id);
        }, 'Erro ao restaurar registro');
    }

    public function forceDelete($id)
    {
        return $this->executar(function () use ($id) {
            $this->executarEscrita(
                $this->whereChavePrimaria($this->novaQueryDelete(), $id)
            );

            return true;
        }, 'Erro ao excluir definitivamente o registro');
    }

    /*
     * $opcoes é repassado ao DbTransactionHelper::run(). Por padrão não há
     * retry; passe ['retries' => N] só se o callback puder ser reexecutado
     * com segurança (sem e-mails, chamadas HTTP etc.).
     */
    public function transaction(callable $callback, array $opcoes = [])
    {
        $opcoes += ['retries' => 0];

        try {
            return DbTransactionHelper::run(
                function () use ($callback) {
                    return $callback($this);
                },
                $opcoes
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                'Erro durante transação na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

     /**
     * Remove todos os registros da tabela e reinicia o auto incremento.
     *
     * ATENÇÃO:
     * - TRUNCATE TABLE é destrutivo.
     * - Em MySQL, normalmente executa commit implícito.
     * - Não deve ser tratado como operação com rollback.
     *
     * @param bool $confirmar Deve ser obrigatoriamente true.
     *
     * @return array
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
        public function truncate($confirmar = false)
        {
            if ($confirmar !== true) {
                throw new InvalidArgumentException(
                    'Para truncar a tabela, informe true como confirmação explícita.'
                );
            }

            $tabela = trim((string) $this->table);

            if ($tabela === '') {
                throw new InvalidArgumentException(
                    'A tabela para truncamento não foi informada.'
                );
            }

            if (
                !preg_match(
                    '/^[a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)?$/',
                    $tabela
                )
            ) {
                throw new InvalidArgumentException(
                    'Nome de tabela inválido para truncamento.'
                );
            }

            try {
                $tabelaExiste = $this->db->getTableColumns(
                    $tabela,
                    false
                );

                if (empty($tabelaExiste)) {
                    throw new RuntimeException(
                        'A tabela informada não existe ou não possui colunas acessíveis: ' .
                        $tabela
                    );
                }

                $quantidadeAntes = 0;

                try {
                    $queryCount = $this->db
                        ->getQuery(true)
                        ->select('COUNT(*)')
                        ->from($this->db->quoteName($tabela));

                    $this->db->setQuery($queryCount);

                    $quantidadeAntes = (int) $this->db->loadResult();
                } catch (Throwable $countError) {

                }


                $sql = 'TRUNCATE TABLE ' .
                    $this->db->quoteName($tabela);

                $this->db->setQuery($sql);
                $this->db->execute();

                $this->newQuery();

                $resultado = array(
                    'success' => true,
                    'tabela' => $tabela,
                    'registros_removidos_estimados' => $quantidadeAntes
                );


                return $resultado;
            } catch (Throwable $e) {

                throw new RuntimeException(
                    'Não foi possível truncar a tabela ' .
                    $tabela . ': ' .
                    $e->getMessage(),
                    500,
                    $e
                );
            }
        }
}
