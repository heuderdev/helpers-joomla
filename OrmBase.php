<?php

defined('_JEXEC') or die;

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

    public function __construct($table, array $opcoes = [])
    {
        $table = trim((string) $table);

        if ($table === '') {
            throw new InvalidArgumentException(
                'A tabela deve ser informada ao instanciar OrmBase.'
            );
        }

        $this->db = JFactory::getDbo();
        $this->table = $table;

        $this->primaryKey = isset($opcoes['primaryKey'])
            ? $opcoes['primaryKey']
            : 'id';

        $this->timestamps = isset($opcoes['timestamps'])
            ? (bool) $opcoes['timestamps']
            : true;

        $this->createdAtColumn = isset($opcoes['createdAtColumn'])
            ? $opcoes['createdAtColumn']
            : 'created_at';

        $this->updatedAtColumn = isset($opcoes['updatedAtColumn'])
            ? $opcoes['updatedAtColumn']
            : 'updated_at';

        $this->softDeletes = isset($opcoes['softDeletes'])
            ? (bool) $opcoes['softDeletes']
            : false;

        $this->deletedAtColumn = isset($opcoes['deletedAtColumn'])
            ? $opcoes['deletedAtColumn']
            : 'deleted_at';

        $this->casts = isset($opcoes['casts'])
            ? (array) $opcoes['casts']
            : [];

        $this->fillable = isset($opcoes['fillable'])
            ? (array) $opcoes['fillable']
            : [];

        $this->guarded = isset($opcoes['guarded'])
            ? (array) $opcoes['guarded']
            : [];
    }

    public static function tabela($table, array $opcoes = [])
    {
        return new self($table, $opcoes);
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

        return $this;
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

    public function where($coluna, $operadorOuValor, $valor = null)
    {
        if (func_num_args() === 2) {
            $operador = '=';
            $valor = $operadorOuValor;
        } else {
            $operador = strtoupper(trim($operadorOuValor));
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

        $this->wheres[] = [
            'tipo' => 'basico',
            'coluna' => $coluna,
            'operador' => $operador,
            'valor' => $valor,
            'conector' => 'AND',
        ];

        return $this;
    }

    public function orWhere($coluna, $operadorOuValor, $valor = null)
    {
        if (func_num_args() === 2) {
            $operador = '=';
            $valor = $operadorOuValor;
        } else {
            $operador = strtoupper(trim($operadorOuValor));
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

        $this->wheres[] = [
            'tipo' => 'basico',
            'coluna' => $coluna,
            'operador' => $operador,
            'valor' => $valor,
            'conector' => 'OR',
        ];

        return $this;
    }

    public function whereIn($coluna, array $valores)
    {
        $this->wheres[] = [
            'tipo' => 'in',
            'coluna' => $coluna,
            'valores' => $valores,
            'negado' => false,
            'conector' => 'AND',
        ];

        return $this;
    }

    public function whereNotIn($coluna, array $valores)
    {
        $this->wheres[] = [
            'tipo' => 'in',
            'coluna' => $coluna,
            'valores' => $valores,
            'negado' => true,
            'conector' => 'AND',
        ];

        return $this;
    }

    public function whereNull($coluna)
    {
        $this->wheres[] = [
            'tipo' => 'nulo',
            'coluna' => $coluna,
            'negado' => false,
            'conector' => 'AND',
        ];

        return $this;
    }

    public function whereNotNull($coluna)
    {
        $this->wheres[] = [
            'tipo' => 'nulo',
            'coluna' => $coluna,
            'negado' => true,
            'conector' => 'AND',
        ];

        return $this;
    }

    public function whereBetween($coluna, $valorInicial, $valorFinal)
    {
        $this->wheres[] = [
            'tipo' => 'entre',
            'coluna' => $coluna,
            'valorInicial' => $valorInicial,
            'valorFinal' => $valorFinal,
            'conector' => 'AND',
        ];

        return $this;
    }

    public function whereLike($coluna, $valor)
    {
        $this->wheres[] = [
            'tipo' => 'like',
            'coluna' => $coluna,
            'valor' => $valor,
            'conector' => 'AND',
        ];

        return $this;
    }

    public function whereRaw($expressaoSql)
    {
        $this->wheres[] = [
            'tipo' => 'raw',
            'expressao' => $expressaoSql,
            'conector' => 'AND',
        ];

        return $this;
    }

    /**
     * Mantém compatibilidade com:
     *
     * ->join('#__clientes AS c', 'c.id = p.cliente_id')
     * ->join('#__clientes AS c', 'c.id = p.cliente_id', 'LEFT')
     *
     * Tipos aceitos:
     * INNER, LEFT, LEFT OUTER, RIGHT, RIGHT OUTER e CROSS.
     */
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

        if ($tipo === 'FULL' || $tipo === 'FULL OUTER'
            || $tipo === 'FULL JOIN' || $tipo === 'FULL OUTER JOIN') {
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

    protected function montarWheres($query)
    {
        if (empty($this->wheres)) {
            return $query;
        }

        $expressoes = [];

        foreach ($this->wheres as $indice => $condicao) {
            $sql = $this->montarCondicaoUnica($condicao);

            if ($indice === 0) {
                $expressoes[] = $sql;
                continue;
            }

            $expressoes[] = $condicao['conector'] . ' ' . $sql;
        }

        $query->where(implode(' ', $expressoes));

        return $query;
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

            default:
                throw new RuntimeException(
                    'Tipo de condição where desconhecido: ' .
                    $condicao['tipo']
                );
        }
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
        $query->from($this->db->quoteName($this->table));

        $this->montarJoins($query);
        $this->montarWheres($query);

        if ($this->softDeletes) {
            $query->where(
                $this->db->quoteName($this->deletedAtColumn) .
                ' IS NULL'
            );
        }

        if (!empty($this->groups)) {
            foreach ($this->groups as $grupo) {
                $query->group($grupo);
            }
        }

        if (!empty($this->havings)) {
            foreach ($this->havings as $having) {
                $query->having($having);
            }
        }

        if (!empty($this->orders)) {
            foreach ($this->orders as $order) {
                $query->order($order);
            }
        }

        return $query;
    }

    public function get()
    {
        try {
            $query = $this->montarQueryBase();

            $this->db->setQuery(
                $query,
                (int) $this->offsetValue,
                (int) $this->limitValue
            );

            $resultado = $this->db->loadObjectList();

            $this->newQuery();

            return $this->aplicarCastsEmLista($resultado);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao executar consulta na tabela ' .
                $this->table . ': ' . $e->getMessage(),
                500,
                $e
            );
        }
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
        $this->limitValue = 1;
        $this->offsetValue = 0;

        try {
            $query = $this->montarQueryBase();

            $this->db->setQuery($query, 0, 1);

            $resultado = $this->db->loadResult();

            $this->newQuery();

            return $resultado;
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao obter valor da coluna ' .
                $coluna .
                ' na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function pluck($coluna, $colunaChave = null)
    {
        $colunas = $colunaChave !== null
            ? [$coluna, $colunaChave]
            : [$coluna];

        $this->select($colunas);

        try {
            $query = $this->montarQueryBase();

            $this->db->setQuery(
                $query,
                (int) $this->offsetValue,
                (int) $this->limitValue
            );

            if ($colunaChave !== null) {
                $resultado = $this->db->loadAssocList(
                    $colunaChave,
                    $coluna
                );
            } else {
                $resultado = $this->db->loadColumn();
            }

            $this->newQuery();

            return $resultado;
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao executar pluck na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function exists()
    {
        $this->select(['1']);
        $this->limitValue = 1;
        $this->offsetValue = 0;

        try {
            $query = $this->montarQueryBase();

            $this->db->setQuery($query, 0, 1);

            $resultado = $this->db->loadResult();

            $this->newQuery();

            return $resultado !== null;
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao verificar existência na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
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

        try {
            $query = $this->montarQueryBase();

            $this->db->setQuery($query);

            $resultado = (int) $this->db->loadResult();

            $this->newQuery();

            return $resultado;
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao contar registros na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
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

        try {
            $query = $this->montarQueryBase();

            $this->db->setQuery($query);

            $resultado = $this->db->loadResult();

            $this->newQuery();

            return $resultado !== null
                ? (float) $resultado
                : null;
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao calcular ' .
                $funcao .
                ' na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function paginate($porPagina = 15, $paginaAtual = 1)
    {
        $porPagina = max(1, (int) $porPagina);
        $paginaAtual = max(1, (int) $paginaAtual);

        $clone = clone $this;

        $totalGeral = $clone->count();

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

    public function create(array $dados)
    {
        try {
            $dadosFiltrados = $this->filtrarColunasValidas($dados);

            if ($this->timestamps) {
                $agora = date('Y-m-d H:i:s');

                $dadosFiltrados[$this->createdAtColumn] = $agora;
                $dadosFiltrados[$this->updatedAtColumn] = $agora;
            }

            $objeto = new stdClass();

            foreach ($dadosFiltrados as $coluna => $valor) {
                $objeto->$coluna = $this->prepararValorParaPersistencia(
                    $coluna,
                    $valor
                );
            }

            $this->db->insertObject(
                $this->table,
                $objeto,
                $this->primaryKey
            );

            return $this->find($objeto->{$this->primaryKey});
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao criar registro na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function createMany(array $listaDeDados)
    {
        $criados = [];

        try {
            $this->db->transactionStart();

            foreach ($listaDeDados as $dados) {
                $criados[] = $this->create($dados);
            }

            $this->db->transactionCommit();

            return $criados;
        } catch (Exception $e) {
            try {
                $this->db->transactionRollback();
            } catch (Exception $rollbackException) {
            }

            throw new RuntimeException(
                'Erro ao criar múltiplos registros na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
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
        try {
            $dadosFiltrados = $this->filtrarColunasValidas($dados);

            if ($this->timestamps) {
                $dadosFiltrados[$this->updatedAtColumn] = date(
                    'Y-m-d H:i:s'
                );
            }

            if (empty($dadosFiltrados)) {
                return $this->find($id);
            }

            $query = $this->db->getQuery(true);

            $query->update($this->db->quoteName($this->table));

            foreach ($dadosFiltrados as $coluna => $valor) {
                $valorPreparado = $this->prepararValorParaPersistencia(
                    $coluna,
                    $valor
                );

                $query->set(
                    $this->db->quoteName($coluna) .
                    ' = ' .
                    $this->db->quote($valorPreparado)
                );
            }

            $query->where(
                $this->db->quoteName($this->primaryKey) .
                ' = ' .
                (int) $id
            );

            $this->db->setQuery($query);
            $this->db->execute();

            return $this->find($id);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao atualizar registro na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function updateWhere(array $dados)
    {
        try {
            $dadosFiltrados = $this->filtrarColunasValidas($dados);

            if ($this->timestamps) {
                $dadosFiltrados[$this->updatedAtColumn] = date(
                    'Y-m-d H:i:s'
                );
            }

            if (empty($dadosFiltrados)) {
                $this->newQuery();

                return 0;
            }

            $query = $this->db->getQuery(true);

            $query->update($this->db->quoteName($this->table));

            foreach ($dadosFiltrados as $coluna => $valor) {
                $valorPreparado = $this->prepararValorParaPersistencia(
                    $coluna,
                    $valor
                );

                $query->set(
                    $this->db->quoteName($coluna) .
                    ' = ' .
                    $this->db->quote($valorPreparado)
                );
            }

            $this->montarWheres($query);

            $this->db->setQuery($query);
            $this->db->execute();

            $linhasAfetadas = (int) $this->db->getAffectedRows();

            $this->newQuery();

            return $linhasAfetadas;
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao atualizar registros na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function increment($id, $coluna, $quantidade = 1)
    {
        try {
            $query = $this->db->getQuery(true);

            $query->update($this->db->quoteName($this->table));

            $query->set(
                $this->db->quoteName($coluna) .
                ' = ' .
                $this->db->quoteName($coluna) .
                ' + ' .
                (int) $quantidade
            );

            $query->where(
                $this->db->quoteName($this->primaryKey) .
                ' = ' .
                (int) $id
            );

            $this->db->setQuery($query);
            $this->db->execute();

            return $this->find($id);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao incrementar coluna ' .
                $coluna .
                ' na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function decrement($id, $coluna, $quantidade = 1)
    {
        try {
            $query = $this->db->getQuery(true);

            $query->update($this->db->quoteName($this->table));

            $query->set(
                $this->db->quoteName($coluna) .
                ' = ' .
                $this->db->quoteName($coluna) .
                ' - ' .
                (int) $quantidade
            );

            $query->where(
                $this->db->quoteName($this->primaryKey) .
                ' = ' .
                (int) $id
            );

            $this->db->setQuery($query);
            $this->db->execute();

            return $this->find($id);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao decrementar coluna ' .
                $coluna .
                ' na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function touch($id)
    {
        if (!$this->timestamps) {
            return $this->find($id);
        }

        return $this->update($id, []);
    }

    public function delete($id)
    {
        try {
            if ($this->softDeletes) {
                $query = $this->db->getQuery(true);

                $query->update($this->db->quoteName($this->table));

                $query->set(
                    $this->db->quoteName($this->deletedAtColumn) .
                    ' = ' .
                    $this->db->quote(date('Y-m-d H:i:s'))
                );

                $query->where(
                    $this->db->quoteName($this->primaryKey) .
                    ' = ' .
                    (int) $id
                );

                $this->db->setQuery($query);
                $this->db->execute();

                return true;
            }

            $query = $this->db->getQuery(true);

            $query->delete($this->db->quoteName($this->table));

            $query->where(
                $this->db->quoteName($this->primaryKey) .
                ' = ' .
                (int) $id
            );

            $this->db->setQuery($query);
            $this->db->execute();

            return true;
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao excluir registro na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function deleteWhere()
    {
        try {
            if ($this->softDeletes) {
                $query = $this->db->getQuery(true);

                $query->update($this->db->quoteName($this->table));

                $query->set(
                    $this->db->quoteName($this->deletedAtColumn) .
                    ' = ' .
                    $this->db->quote(date('Y-m-d H:i:s'))
                );

                $this->montarWheres($query);

                $this->db->setQuery($query);
                $this->db->execute();

                $linhasAfetadas = (int) $this->db->getAffectedRows();

                $this->newQuery();

                return $linhasAfetadas;
            }

            $query = $this->db->getQuery(true);

            $query->delete($this->db->quoteName($this->table));

            $this->montarWheres($query);

            $this->db->setQuery($query);
            $this->db->execute();

            $linhasAfetadas = (int) $this->db->getAffectedRows();

            $this->newQuery();

            return $linhasAfetadas;
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao excluir registros na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function restore($id)
    {
        if (!$this->softDeletes) {
            return $this->find($id);
        }

        try {
            $query = $this->db->getQuery(true);

            $query->update($this->db->quoteName($this->table));

            $query->set(
                $this->db->quoteName($this->deletedAtColumn) .
                ' = NULL'
            );

            $query->where(
                $this->db->quoteName($this->primaryKey) .
                ' = ' .
                (int) $id
            );

            $this->db->setQuery($query);
            $this->db->execute();

            return $this->find($id);
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao restaurar registro na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function forceDelete($id)
    {
        try {
            $query = $this->db->getQuery(true);

            $query->delete($this->db->quoteName($this->table));

            $query->where(
                $this->db->quoteName($this->primaryKey) .
                ' = ' .
                (int) $id
            );

            $this->db->setQuery($query);
            $this->db->execute();

            return true;
        } catch (Exception $e) {
            throw new RuntimeException(
                'Erro ao excluir definitivamente o registro na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }

    public function transaction(callable $callback)
    {
        try {
            $this->db->transactionStart();

            $resultado = $callback($this);

            $this->db->transactionCommit();

            return $resultado;
        } catch (Exception $e) {
            try {
                $this->db->transactionRollback();
            } catch (Exception $rollbackException) {
            }

            throw new RuntimeException(
                'Erro durante transação na tabela ' .
                $this->table .
                ': ' . $e->getMessage(),
                500,
                $e
            );
        }
    }
}