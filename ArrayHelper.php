<?php

// Bloqueia só o acesso direto pela URL; carrega no Joomla, Laravel ou PHP puro.
if (!defined('_JEXEC') && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    die('Acesso restrito.');
}

/*
 * Manipulação de arrays e objetos com caminho em notação de ponto.
 *
 *   ArrayHelper::get($dados, 'cliente.endereco.cidade', '-');
 *   ArrayHelper::get($pedido, 'itens.*.preco');      // [10, 20, 30]
 *   ArrayHelper::set($dados, 'cliente.nome', 'Ana');
 *   ArrayHelper::pluck($pedidos, 'id');
 *   ArrayHelper::groupBy($pedidos, 'status');
 *
 * Caminho: 'a.b.c', inteiro (3) ou lista de segmentos (['a', 'b.c']) para
 * chaves que contêm ponto. Se a chave literal 'a.b' existir, ela vence.
 * Em get/has, o segmento '*' percorre todos os itens daquele nível.
 *
 * Funciona com arrays, stdClass, objetos com propriedades públicas,
 * objetos com __get/__isset (Joomla CMSObject/Table) e ArrayAccess
 * (ArrayObject, Joomla Registry).
 */

class ArrayHelper
{
    // ------------------------------------------------------------------
    // Conversão
    // ------------------------------------------------------------------

    /*
     * Array (ou objeto) para stdClass. Com $recursivo, sub-arrays
     * associativos viram objetos; listas continuam arrays se
     * $preservarListas for true.
     */
    public static function toObject(
        $dados,
        $recursivo = false,
        $preservarListas = true
    ) {
        if (is_object($dados)) {
            if (!$recursivo) {
                return $dados;
            }

            $dados = self::toArray($dados);
        }

        if (!is_array($dados)) {
            throw new InvalidArgumentException(
                'Os dados devem ser um array ou objeto.'
            );
        }

        if (!$recursivo) {
            return (object) $dados;
        }

        $objeto = new stdClass();

        foreach ($dados as $chave => $valor) {
            $objeto->{$chave} = self::convertValueToObject(
                $valor,
                $preservarListas
            );
        }

        return $objeto;
    }

    /*
     * Lista de registros (arrays) para lista de objetos, mantendo as chaves.
     */
    public static function toObjects(
        array $registros,
        $recursivo = false,
        $preservarListas = true
    ) {
        $resultado = [];

        foreach ($registros as $chave => $registro) {
            if (is_array($registro)) {
                $registro = self::toObject(
                    $registro,
                    $recursivo,
                    $preservarListas
                );
            } elseif (!is_object($registro)) {
                throw new InvalidArgumentException(
                    'Cada registro deve ser um array ou objeto.'
                );
            } elseif ($recursivo) {
                $registro = self::toObject(
                    $registro,
                    true,
                    $preservarListas
                );
            }

            $resultado[$chave] = $registro;
        }

        return $resultado;
    }

    /*
     * Array ou qualquer objeto para array. Aceita stdClass, objetos com
     * toArray() (Registry, models), Traversable, JsonSerializable e objetos
     * comuns (propriedades públicas).
     *
     * Com $recursivo, converte também os níveis internos. Datas, enums e
     * closures são mantidos como estão; referências circulares também,
     * para não entrar em loop.
     */
    public static function toArray($dados, $recursivo = false)
    {
        $pilha = [];

        return self::toArrayInternal($dados, (bool) $recursivo, $pilha);
    }

    /*
     * true para [0 => .., 1 => .., 2 => ..] (chaves sequenciais desde 0).
     */
    public static function isList(array $dados)
    {
        if (function_exists('array_is_list')) {
            return array_is_list($dados);
        }

        $indice = 0;

        foreach ($dados as $chave => $valor) {
            if ($chave !== $indice) {
                return false;
            }

            $indice++;
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Acesso por caminho
    // ------------------------------------------------------------------

    /*
     * Valor no caminho ou $padrao. $padrao pode ser uma Closure, chamada
     * só quando necessário.
     */
    public static function get($dados, $caminho, $padrao = null)
    {
        if ($caminho === null) {
            return $dados;
        }

        $valor = null;

        if (self::readLiteral($dados, $caminho, $valor)) {
            return $valor;
        }

        return self::getBySegments($dados, self::segments($caminho), $padrao);
    }

    /*
     * true se o caminho existir, mesmo que o valor seja null.
     * Com '*', todos os itens daquele nível precisam ter o restante.
     */
    public static function has($dados, $caminho)
    {
        if ($caminho === null) {
            return false;
        }

        $valor = null;

        if (self::readLiteral($dados, $caminho, $valor)) {
            return true;
        }

        return self::hasBySegments($dados, self::segments($caminho));
    }

    /*
     * Grava $valor no caminho, criando os níveis que faltarem (array
     * dentro de array, stdClass dentro de objeto). Altera $dados e
     * também o devolve.
     */
    public static function set(&$dados, $caminho, $valor)
    {
        if ($caminho === null) {
            $dados = $valor;

            return $dados;
        }

        if ($dados === null) {
            $dados = [];
        }

        self::assertContainer($dados);

        if (is_string($caminho) && strpos($caminho, '.') !== false) {
            $encontrado = false;
            self::readKey($dados, $caminho, $encontrado);

            if ($encontrado) {
                self::writeKey($dados, $caminho, $valor);

                return $dados;
            }
        }

        self::setBySegments($dados, self::segments($caminho), $valor);

        return $dados;
    }

    /*
     * Remove o caminho, se existir. Altera $dados e também o devolve.
     */
    public static function forget(&$dados, $caminho)
    {
        if ($caminho === null || (!is_array($dados) && !is_object($dados))) {
            return $dados;
        }

        if (is_string($caminho) && strpos($caminho, '.') !== false) {
            $encontrado = false;
            self::readKey($dados, $caminho, $encontrado);

            if ($encontrado) {
                self::unsetKey($dados, $caminho);

                return $dados;
            }
        }

        self::forgetBySegments($dados, self::segments($caminho));

        return $dados;
    }

    // ------------------------------------------------------------------
    // Seleção de chaves
    // ------------------------------------------------------------------

    /*
     * Só as chaves pedidas (aceita caminhos com ponto). Devolve array.
     */
    public static function only($dados, $chaves)
    {
        $resultado = [];

        foreach ((array) $chaves as $chave) {
            $valor = null;

            if (self::readLiteral($dados, $chave, $valor)) {
                $resultado[$chave] = $valor;
                continue;
            }

            if (self::has($dados, $chave)) {
                self::set($resultado, $chave, self::get($dados, $chave));
            }
        }

        return $resultado;
    }

    /*
     * Tudo menos as chaves informadas (aceita caminhos com ponto).
     * Objetos são clonados, inclusive os que estão no caminho removido,
     * para não alterar o original.
     */
    public static function except($dados, $chaves)
    {
        if (is_object($dados)) {
            $dados = clone $dados;
        }

        foreach ((array) $chaves as $chave) {
            $valor = null;

            if ($chave !== null && !self::readLiteral($dados, $chave, $valor)) {
                self::detachPath($dados, self::segments($chave));
            }

            self::forget($dados, $chave);
        }

        return $dados;
    }

    // ------------------------------------------------------------------
    // Coleções de registros
    // ------------------------------------------------------------------

    /*
     * Valores de um campo em cada registro. $caminho e $chavePor aceitam
     * caminho ou Closure($registro, $chave).
     */
    public static function pluck(
        array $registros,
        $caminho,
        $chavePor = null
    ) {
        $resultado = [];

        foreach ($registros as $indice => $registro) {
            $valor = self::resolve($registro, $indice, $caminho);

            if ($chavePor === null) {
                $resultado[] = $valor;
                continue;
            }

            if (!($chavePor instanceof Closure) && !self::has($registro, $chavePor)) {
                continue;
            }

            $chave = self::normalizeIndex(
                self::resolve($registro, $indice, $chavePor)
            );

            $resultado[$chave] = $valor;
        }

        return $resultado;
    }

    /*
     * Indexa os registros pelo valor do campo (o último vence).
     */
    public static function keyBy(array $registros, $caminho)
    {
        $resultado = [];

        foreach ($registros as $indice => $registro) {
            if (!($caminho instanceof Closure) && !self::has($registro, $caminho)) {
                continue;
            }

            $chave = self::normalizeIndex(
                self::resolve($registro, $indice, $caminho)
            );

            $resultado[$chave] = $registro;
        }

        return $resultado;
    }

    /*
     * Agrupa os registros pelo valor do campo. Null vira '', bool vira 0/1,
     * enums e datas viram texto.
     */
    public static function groupBy(
        array $registros,
        $caminho,
        $preservarChaves = false
    ) {
        $resultado = [];

        foreach ($registros as $indice => $registro) {
            if (!($caminho instanceof Closure) && !self::has($registro, $caminho)) {
                continue;
            }

            $chave = self::normalizeIndex(
                self::resolve($registro, $indice, $caminho)
            );

            if (!array_key_exists($chave, $resultado)) {
                $resultado[$chave] = [];
            }

            if ($preservarChaves) {
                $resultado[$chave][$indice] = $registro;
            } else {
                $resultado[$chave][] = $registro;
            }
        }

        return $resultado;
    }

    /*
     * Primeiro item (ou o primeiro que passar no filtro).
     */
    public static function first(
        array $dados,
        ?callable $filtro = null,
        $padrao = null
    ) {
        foreach ($dados as $chave => $valor) {
            if ($filtro === null || $filtro($valor, $chave)) {
                return $valor;
            }
        }

        return self::value($padrao);
    }

    /*
     * Registros cujo campo é igual a $valor. Por padrão a comparação é
     * estrita (===); passe $estrito = false para comparar '5' com 5, como
     * acontece com valores vindos do banco.
     */
    public static function where(
        array $registros,
        $caminho,
        $valor,
        $preservarChaves = false,
        $estrito = true
    ) {
        return self::filterRecords(
            $registros,
            $caminho,
            function ($atual) use ($valor, $estrito) {
                return $estrito ? $atual === $valor : $atual == $valor;
            },
            $preservarChaves
        );
    }

    /*
     * Registros cujo campo está na lista. Não estrito por padrão, porque
     * valores do banco costumam vir como texto.
     */
    public static function whereIn(
        array $registros,
        $caminho,
        array $valores,
        $preservarChaves = false,
        $estrito = false
    ) {
        return self::filterRecords(
            $registros,
            $caminho,
            function ($atual) use ($valores, $estrito) {
                return in_array($atual, $valores, (bool) $estrito);
            },
            $preservarChaves
        );
    }

    public static function whereNotIn(
        array $registros,
        $caminho,
        array $valores,
        $preservarChaves = false,
        $estrito = false
    ) {
        return self::filterRecords(
            $registros,
            $caminho,
            function ($atual, $existe) use ($valores, $estrito) {
                return !$existe || !in_array($atual, $valores, (bool) $estrito);
            },
            $preservarChaves,
            true
        );
    }

    /*
     * Registros cujo campo é null ou não existe.
     */
    public static function whereNull(array $registros, $caminho, $preservarChaves = false)
    {
        return self::filterRecords(
            $registros,
            $caminho,
            function ($atual) {
                return $atual === null;
            },
            $preservarChaves,
            true
        );
    }

    public static function whereNotNull(array $registros, $caminho, $preservarChaves = false)
    {
        return self::filterRecords(
            $registros,
            $caminho,
            function ($atual) {
                return $atual !== null;
            },
            $preservarChaves
        );
    }

    /*
     * Filtro com operador:
     *   =, ==, ===, !=, <>, !==, <, <=, >, >=,
     *   in, not in, between, not between (valor = [min, max]),
     *   like, not like (% e _ como no SQL, sem diferenciar maiúsculas),
     *   contains, starts with, ends with.
     *
     *   ArrayHelper::whereCompare($pedidos, 'total', '>=', 100);
     *   ArrayHelper::whereCompare($clientes, 'nome', 'like', 'ana%');
     */
    public static function whereCompare(
        array $registros,
        $caminho,
        $operador,
        $valor,
        $preservarChaves = false
    ) {
        $operador = strtolower(trim((string) $operador));

        return self::filterRecords(
            $registros,
            $caminho,
            function ($atual) use ($operador, $valor) {
                return self::compare($atual, $operador, $valor);
            },
            $preservarChaves
        );
    }

    /*
     * Último item (ou o último que passar no filtro).
     */
    public static function last(
        array $dados,
        ?callable $filtro = null,
        $padrao = null
    ) {
        if ($filtro === null) {
            return $dados === [] ? self::value($padrao) : end($dados);
        }

        return self::first(array_reverse($dados, true), $filtro, $padrao);
    }

    /*
     * Ordena registros por um campo ou Closure. Estável: empates mantêm a
     * ordem original. Null fica antes de qualquer valor.
     */
    public static function sortBy(
        array $registros,
        $caminho,
        $decrescente = false,
        $preservarChaves = false
    ) {
        return self::sortByMany(
            $registros,
            [[$caminho, $decrescente ? 'desc' : 'asc']],
            $preservarChaves
        );
    }

    /*
     * Ordena por vários campos:
     *   ArrayHelper::sortByMany($r, ['status' => 'asc', 'total' => 'desc']);
     * Para usar Closure ou caminho em array: [[fn ($r) => ..., 'desc']].
     */
    public static function sortByMany(
        array $registros,
        array $criterios,
        $preservarChaves = false
    ) {
        $regras = [];

        foreach ($criterios as $chave => $criterio) {
            if (is_array($criterio)) {
                $caminho = $criterio[0];
                $direcao = isset($criterio[1]) ? $criterio[1] : 'asc';
            } else {
                $caminho = $chave;
                $direcao = $criterio;
            }

            $regras[] = [
                $caminho,
                strtolower((string) $direcao) === 'desc' ? -1 : 1,
            ];
        }

        $linhas = [];

        foreach ($registros as $indice => $registro) {
            $valores = [];

            foreach ($regras as $regra) {
                $valores[] = self::resolve($registro, $indice, $regra[0]);
            }

            $linhas[] = [$indice, $registro, $valores];
        }

        usort($linhas, function ($a, $b) use ($regras) {
            foreach ($regras as $posicao => $regra) {
                $resultado = self::compareValues($a[2][$posicao], $b[2][$posicao]);

                if ($resultado !== 0) {
                    return $resultado * $regra[1];
                }
            }

            return 0;
        });

        $resultado = [];

        foreach ($linhas as $linha) {
            if ($preservarChaves) {
                $resultado[$linha[0]] = $linha[1];
            } else {
                $resultado[] = $linha[1];
            }
        }

        return $resultado;
    }

    /*
     * Remove duplicados (pelo valor ou por um campo/Closure). Mantém o
     * primeiro e as chaves originais.
     */
    public static function unique(array $dados, $caminho = null, $estrito = false)
    {
        $vistos = [];
        $resultado = [];

        foreach ($dados as $indice => $item) {
            $valor = $caminho === null ? $item : self::resolve($item, $indice, $caminho);

            if (in_array($valor, $vistos, (bool) $estrito)) {
                continue;
            }

            $vistos[] = $valor;
            $resultado[$indice] = $item;
        }

        return $resultado;
    }

    /*
     * Soma de um campo (ou dos próprios valores, se $caminho for null).
     * Ignora valores não numéricos.
     */
    public static function sum(array $registros, $caminho = null)
    {
        $total = 0;

        foreach (self::numericValues($registros, $caminho) as $valor) {
            $total += $valor;
        }

        return $total;
    }

    /*
     * Média de um campo; null se não houver valores numéricos.
     */
    public static function avg(array $registros, $caminho = null)
    {
        $valores = self::numericValues($registros, $caminho);

        return $valores === [] ? null : array_sum($valores) / count($valores);
    }

    public static function min(array $registros, $caminho = null)
    {
        $valores = self::numericValues($registros, $caminho);

        return $valores === [] ? null : min($valores);
    }

    public static function max(array $registros, $caminho = null)
    {
        $valores = self::numericValues($registros, $caminho);

        return $valores === [] ? null : max($valores);
    }

    /*
     * Quantidade de registros por valor do campo: ['ativo' => 3, 'inativo' => 1].
     */
    public static function countBy(array $registros, $caminho = null)
    {
        $resultado = [];

        foreach ($registros as $indice => $registro) {
            $chave = self::normalizeIndex(
                $caminho === null ? $registro : self::resolve($registro, $indice, $caminho)
            );

            $resultado[$chave] = isset($resultado[$chave]) ? $resultado[$chave] + 1 : 1;
        }

        return $resultado;
    }

    /*
     * Divide em [os que passam, os que não passam], mantendo as chaves.
     */
    public static function partition(array $dados, callable $filtro)
    {
        $passam = [];
        $naoPassam = [];

        foreach ($dados as $chave => $valor) {
            if ($filtro($valor, $chave)) {
                $passam[$chave] = $valor;
            } else {
                $naoPassam[$chave] = $valor;
            }
        }

        return [$passam, $naoPassam];
    }

    /*
     * array_map que mantém as chaves e passa ($valor, $chave).
     */
    public static function map(array $dados, callable $callback)
    {
        $resultado = [];

        foreach ($dados as $chave => $valor) {
            $resultado[$chave] = $callback($valor, $chave);
        }

        return $resultado;
    }

    /*
     * O callback devolve [chave => valor]; os pares são juntados.
     *   ArrayHelper::mapWithKeys($r, fn ($x) => [$x['id'] => $x['nome']]);
     */
    public static function mapWithKeys(array $dados, callable $callback)
    {
        $resultado = [];

        foreach ($dados as $chave => $valor) {
            foreach ((array) $callback($valor, $chave) as $novaChave => $novoValor) {
                $resultado[$novaChave] = $novoValor;
            }
        }

        return $resultado;
    }

    /*
     * Só alguns campos de cada registro (útil antes de exportar ou
     * devolver em API). Mantém as chaves dos registros.
     */
    public static function select(array $registros, $campos)
    {
        $resultado = [];

        foreach ($registros as $indice => $registro) {
            $resultado[$indice] = self::only($registro, $campos);
        }

        return $resultado;
    }

    /*
     * Monta uma árvore a partir de registros com id/parent_id
     * (categorias, menus, comentários). Registros cujo pai não existe
     * ficam na raiz. Arrays recebem a chave $filhos; objetos, a propriedade.
     */
    public static function toTree(
        array $registros,
        $campoId = 'id',
        $campoPai = 'parent_id',
        $filhos = 'children',
        $raiz = null
    ) {
        $nos = [];

        foreach ($registros as $registro) {
            $id = self::normalizeIndex(self::get($registro, $campoId));

            if (is_object($registro)) {
                $registro = clone $registro;
                $registro->{$filhos} = [];
            } else {
                $registro[$filhos] = [];
            }

            $nos[$id] = $registro;
        }

        $arvore = [];

        // Referências, para que os filhos já montados apareçam no pai.
        foreach (array_keys($nos) as $id) {
            $pai = self::get($nos[$id], $campoPai);
            $chavePai = $pai === null ? null : self::normalizeIndex($pai);

            if (
                $pai === $raiz
                || $chavePai === null
                // Comparado como texto: o banco devolve '5' e a chave do array vira 5.
                || (string) $chavePai === (string) $id
                || !array_key_exists($chavePai, $nos)
            ) {
                $arvore[] = &$nos[$id];
            } elseif (is_object($nos[$chavePai])) {
                $nos[$chavePai]->{$filhos}[] = &$nos[$id];
            } else {
                $nos[$chavePai][$filhos][] = &$nos[$id];
            }
        }

        // Quebra as referências antes de devolver.
        return self::copyTree($arvore, $filhos);
    }

    // ------------------------------------------------------------------
    // Estrutura
    // ------------------------------------------------------------------

    /*
     * Achata em notação de ponto: ['a' => ['b' => 1]] -> ['a.b' => 1].
     * Arrays vazios ficam como [] na chave correspondente.
     */
    public static function dot($dados, $prefixo = '')
    {
        $resultado = [];

        foreach (self::toArray($dados) as $chave => $valor) {
            if (is_object($valor) && self::isConvertibleObject($valor)) {
                $valor = self::toArray($valor);
            }

            if (is_array($valor) && $valor !== []) {
                foreach (self::dot($valor, $prefixo . $chave . '.') as $subchave => $subvalor) {
                    $resultado[$subchave] = $subvalor;
                }
            } else {
                $resultado[$prefixo . $chave] = $valor;
            }
        }

        return $resultado;
    }

    /*
     * Inverso de dot(): ['a.b' => 1] -> ['a' => ['b' => 1]].
     */
    public static function undot(array $dados)
    {
        $resultado = [];

        foreach ($dados as $chave => $valor) {
            self::setBySegments($resultado, self::segments($chave), $valor);
        }

        return $resultado;
    }

    /*
     * Achata em uma lista simples de valores, até a profundidade informada.
     */
    public static function flatten(array $dados, $profundidade = INF)
    {
        $resultado = [];

        foreach ($dados as $item) {
            if (is_object($item) && !($item instanceof Traversable)) {
                $resultado[] = $item;
                continue;
            }

            if (!is_iterable($item)) {
                $resultado[] = $item;
                continue;
            }

            $item = is_array($item) ? $item : iterator_to_array($item, false);
            $valores = $profundidade <= 1
                ? array_values($item)
                : self::flatten($item, $profundidade - 1);

            foreach ($valores as $valor) {
                $resultado[] = $valor;
            }
        }

        return $resultado;
    }

    /*
     * Junta uma lista de arrays em um só: [[1, 2], [3]] -> [1, 2, 3].
     */
    public static function collapse(array $dados)
    {
        return self::flatten($dados, 1);
    }

    /*
     * Mescla recursiva sem o efeito do array_merge_recursive (que
     * transforma valores repetidos em listas). Chaves de texto são
     * substituídas/mescladas; chaves numéricas são acrescentadas.
     */
    public static function merge(array $base, array ...$outros)
    {
        foreach ($outros as $outro) {
            foreach ($outro as $chave => $valor) {
                if (is_int($chave)) {
                    $base[] = $valor;
                } elseif (
                    is_array($valor)
                    && isset($base[$chave])
                    && is_array($base[$chave])
                ) {
                    $base[$chave] = self::merge($base[$chave], $valor);
                } else {
                    $base[$chave] = $valor;
                }
            }
        }

        return $base;
    }

    /*
     * Remove null e '' (e arrays que ficarem vazios, se pedido).
     * Listas são reindexadas.
     */
    public static function clean(array $dados, $removerArraysVazios = false, $recursivo = true)
    {
        $lista = self::isList($dados);

        foreach ($dados as $chave => $valor) {
            if ($recursivo && is_array($valor)) {
                $valor = self::clean($valor, $removerArraysVazios, true);
                $dados[$chave] = $valor;
            }

            if ($valor === null || $valor === '' || ($removerArraysVazios && $valor === [])) {
                unset($dados[$chave]);
            }
        }

        return $lista ? array_values($dados) : $dados;
    }

    /*
     * Renomeia chaves: ['NUM_COOP' => 'num_coop']. Com $manterOutras =
     * false, só as chaves mapeadas ficam.
     */
    public static function renameKeys(array $dados, array $mapa, $manterOutras = true)
    {
        $resultado = [];

        foreach ($dados as $chave => $valor) {
            if (array_key_exists($chave, $mapa)) {
                $resultado[$mapa[$chave]] = $valor;
            } elseif ($manterOutras) {
                $resultado[$chave] = $valor;
            }
        }

        return $resultado;
    }

    /*
     * Chaves em minúsculas (CASE_LOWER) ou maiúsculas (CASE_UPPER),
     * opcionalmente em todos os níveis. Suporta acentos.
     */
    public static function changeKeyCase(array $dados, $caixa = CASE_LOWER, $recursivo = false)
    {
        $resultado = [];

        foreach ($dados as $chave => $valor) {
            if (is_string($chave)) {
                $chave = function_exists('mb_strtolower')
                    ? ($caixa === CASE_UPPER ? mb_strtoupper($chave, 'UTF-8') : mb_strtolower($chave, 'UTF-8'))
                    : ($caixa === CASE_UPPER ? strtoupper($chave) : strtolower($chave));
            }

            if ($recursivo && is_array($valor)) {
                $valor = self::changeKeyCase($valor, $caixa, true);
            }

            $resultado[$chave] = $valor;
        }

        return $resultado;
    }

    // ------------------------------------------------------------------
    // Atalhos
    // ------------------------------------------------------------------

    /*
     * Lê e remove o caminho.
     */
    public static function pull(&$dados, $caminho, $padrao = null)
    {
        if (!self::has($dados, $caminho)) {
            return self::value($padrao);
        }

        $valor = self::get($dados, $caminho);
        self::forget($dados, $caminho);

        return $valor;
    }

    /*
     * Grava só se o caminho ainda não existir.
     */
    public static function add(&$dados, $caminho, $valor)
    {
        if (!self::has($dados, $caminho)) {
            self::set($dados, $caminho, $valor);
        }

        return $dados;
    }

    /*
     * true se pelo menos um dos caminhos existir.
     */
    public static function hasAny($dados, array $caminhos)
    {
        foreach ($caminhos as $caminho) {
            if (self::has($dados, $caminho)) {
                return true;
            }
        }

        return false;
    }

    /*
     * true se todos os caminhos existirem.
     */
    public static function hasAll($dados, array $caminhos)
    {
        if ($caminhos === []) {
            return false;
        }

        foreach ($caminhos as $caminho) {
            if (!self::has($dados, $caminho)) {
                return false;
            }
        }

        return true;
    }

    /*
     * Garante um array: null -> [], escalar/objeto -> [valor].
     */
    public static function wrap($valor)
    {
        if ($valor === null) {
            return [];
        }

        return is_array($valor) ? $valor : [$valor];
    }

    /*
     * true se o array tiver chaves de texto ou fora de sequência.
     */
    public static function isAssoc(array $dados)
    {
        return !self::isList($dados);
    }

    /*
     * true se o valor puder ser lido com get() (array ou objeto).
     */
    public static function accessible($valor)
    {
        return is_array($valor) || is_object($valor);
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private static function filterRecords(
        array $registros,
        $caminho,
        callable $teste,
        $preservarChaves,
        $incluirAusentes = false
    ) {
        $resultado = [];

        foreach ($registros as $chave => $registro) {
            if ($caminho instanceof Closure) {
                $existe = true;
                $atual = $caminho($registro, $chave);
            } else {
                $existe = self::has($registro, $caminho);
                $atual = $existe ? self::get($registro, $caminho) : null;
            }

            if (!$existe && !$incluirAusentes) {
                continue;
            }

            if (!$teste($atual, $existe)) {
                continue;
            }

            if ($preservarChaves) {
                $resultado[$chave] = $registro;
            } else {
                $resultado[] = $registro;
            }
        }

        return $resultado;
    }

    private static function compare($atual, $operador, $valor)
    {
        switch ($operador) {
            case '=':
            case '==':
                return $atual == $valor;
            case '===':
                return $atual === $valor;
            case '!=':
            case '<>':
                return $atual != $valor;
            case '!==':
                return $atual !== $valor;
            case '<':
                return $atual !== null && self::compareValues($atual, $valor) < 0;
            case '<=':
                return $atual !== null && self::compareValues($atual, $valor) <= 0;
            case '>':
                return $atual !== null && self::compareValues($atual, $valor) > 0;
            case '>=':
                return $atual !== null && self::compareValues($atual, $valor) >= 0;
            case 'in':
                return in_array($atual, self::wrap($valor), false);
            case 'not in':
                return !in_array($atual, self::wrap($valor), false);
            case 'between':
            case 'not between':
                $limites = array_values(self::wrap($valor));

                if (count($limites) !== 2) {
                    throw new InvalidArgumentException(
                        'O operador ' . $operador . ' exige [mínimo, máximo].'
                    );
                }

                $dentro = $atual !== null
                    && self::compareValues($atual, $limites[0]) >= 0
                    && self::compareValues($atual, $limites[1]) <= 0;

                return $operador === 'between' ? $dentro : !$dentro;
            case 'like':
            case 'not like':
                $padrao = '/^' . strtr(
                    preg_quote((string) $valor, '/'),
                    ['%' => '.*', '_' => '.']
                ) . '$/isu';
                $casa = is_scalar($atual) && preg_match($padrao, (string) $atual) === 1;

                return $operador === 'like' ? $casa : !$casa;
            case 'contains':
                return is_scalar($atual) && self::lowerText($valor) !== ''
                    && strpos(self::lowerText($atual), self::lowerText($valor)) !== false;
            case 'starts with':
                // Texto vazio não casa (como em contains).
                return is_scalar($atual) && self::lowerText($valor) !== ''
                    && strpos(self::lowerText($atual), self::lowerText($valor)) === 0;
            case 'ends with':
                $fim = self::lowerText($valor);

                return is_scalar($atual) && $fim !== ''
                    && substr(self::lowerText($atual), -strlen($fim)) === $fim;
        }

        throw new InvalidArgumentException('Operador não suportado: ' . $operador . '.');
    }

    private static function compareValues($a, $b)
    {
        if ($a === $b) {
            return 0;
        }

        if ($a === null) {
            return -1;
        }

        if ($b === null) {
            return 1;
        }

        if ($a instanceof DateTimeInterface && $b instanceof DateTimeInterface) {
            return $a <=> $b;
        }

        if ($a instanceof BackedEnum) {
            $a = $a->value;
        }

        if ($b instanceof BackedEnum) {
            $b = $b->value;
        }

        if (is_string($a) && is_string($b) && !is_numeric($a) && !is_numeric($b)) {
            return strnatcasecmp($a, $b);
        }

        return $a <=> $b;
    }

    private static function lowerText($valor)
    {
        $valor = (string) $valor;

        return function_exists('mb_strtolower') ? mb_strtolower($valor, 'UTF-8') : strtolower($valor);
    }

    private static function numericValues(array $registros, $caminho)
    {
        $valores = [];

        foreach ($registros as $indice => $registro) {
            $valor = $caminho === null ? $registro : self::resolve($registro, $indice, $caminho);

            if (is_int($valor) || is_float($valor)) {
                $valores[] = $valor;
            } elseif (is_string($valor) && is_numeric(trim($valor))) {
                $valores[] = trim($valor) + 0;
            }
        }

        return $valores;
    }

    private static function copyTree(array $nos, $filhos)
    {
        $resultado = [];

        foreach ($nos as $no) {
            if (is_object($no)) {
                $no->{$filhos} = self::copyTree($no->{$filhos}, $filhos);
            } else {
                $no[$filhos] = self::copyTree($no[$filhos], $filhos);
            }

            $resultado[] = $no;
        }

        return $resultado;
    }

    private static function convertValueToObject(
        $valor,
        $preservarListas
    ) {
        if (!is_array($valor)) {
            return $valor;
        }

        if ($preservarListas && self::isList($valor)) {
            $resultado = [];

            foreach ($valor as $chave => $item) {
                $resultado[$chave] = self::convertValueToObject(
                    $item,
                    $preservarListas
                );
            }

            return $resultado;
        }

        return self::toObject($valor, true, $preservarListas);
    }

    private static function toArrayInternal($dados, $recursivo, array &$pilha)
    {
        $id = is_object($dados) ? spl_object_id($dados) : null;

        if ($id !== null) {
            $pilha[$id] = true;
        }

        $dados = self::objectToArray($dados);

        if ($recursivo) {
            foreach ($dados as $chave => $valor) {
                if (is_array($valor)) {
                    $dados[$chave] = self::toArrayInternal($valor, true, $pilha);
                } elseif (
                    is_object($valor)
                    && self::isConvertibleObject($valor)
                    && !isset($pilha[spl_object_id($valor)])
                ) {
                    $dados[$chave] = self::toArrayInternal($valor, true, $pilha);
                }
            }
        }

        if ($id !== null) {
            unset($pilha[$id]);
        }

        return $dados;
    }

    private static function objectToArray($dados)
    {
        if (is_array($dados)) {
            return $dados;
        }

        if (!is_object($dados)) {
            throw new InvalidArgumentException(
                'Os dados devem ser um array ou objeto.'
            );
        }

        if ($dados instanceof stdClass) {
            return get_object_vars($dados);
        }

        if (is_callable([$dados, 'toArray'])) {
            $convertido = $dados->toArray();

            if (is_array($convertido)) {
                return $convertido;
            }
        }

        if ($dados instanceof Traversable) {
            return iterator_to_array($dados, true);
        }

        if ($dados instanceof JsonSerializable) {
            $convertido = $dados->jsonSerialize();

            if (is_array($convertido)) {
                return $convertido;
            }

            if (is_object($convertido)) {
                return get_object_vars($convertido);
            }
        }

        return get_object_vars($dados);
    }

    /*
     * Objetos que representam um valor (e não uma estrutura) ficam como estão.
     */
    private static function isConvertibleObject($objeto)
    {
        return !($objeto instanceof DateTimeInterface)
            && !($objeto instanceof UnitEnum)
            && !($objeto instanceof Closure);
    }

    private static function segments($caminho)
    {
        if (is_array($caminho)) {
            $segmentos = array_values($caminho);
        } elseif (is_string($caminho) || is_int($caminho)) {
            $segmentos = explode('.', (string) $caminho);
        } else {
            throw new InvalidArgumentException(
                'O caminho deve ser uma string, inteiro ou array.'
            );
        }

        if ($segmentos === []) {
            throw new InvalidArgumentException(
                'O caminho deve conter pelo menos uma chave.'
            );
        }

        foreach ($segmentos as $segmento) {
            self::validateIndex($segmento);
        }

        return $segmentos;
    }

    private static function readLiteral($dados, $caminho, &$valor)
    {
        if (!is_string($caminho) || strpos($caminho, '.') === false) {
            return false;
        }

        $encontrado = false;
        $valor = self::readKey($dados, $caminho, $encontrado);

        return $encontrado;
    }

    private static function getBySegments($dados, array $segmentos, $padrao)
    {
        while ($segmentos !== []) {
            $segmento = array_shift($segmentos);

            if ($segmento === '*') {
                $itens = self::iterable($dados);

                if ($itens === null) {
                    return self::value($padrao);
                }

                $resultado = [];
                $aninhado = in_array('*', $segmentos, true);

                foreach ($itens as $item) {
                    $valor = $segmentos === []
                        ? $item
                        : self::getBySegments($item, $segmentos, $padrao);

                    if ($aninhado && is_array($valor)) {
                        foreach ($valor as $subitem) {
                            $resultado[] = $subitem;
                        }
                    } else {
                        $resultado[] = $valor;
                    }
                }

                return $resultado;
            }

            $encontrado = false;
            $dados = self::readKey($dados, $segmento, $encontrado);

            if (!$encontrado) {
                return self::value($padrao);
            }
        }

        return $dados;
    }

    private static function hasBySegments($dados, array $segmentos)
    {
        while ($segmentos !== []) {
            $segmento = array_shift($segmentos);

            if ($segmento === '*') {
                $itens = self::iterable($dados);

                if ($itens === null) {
                    return false;
                }

                $algum = false;

                foreach ($itens as $item) {
                    $algum = true;

                    if ($segmentos !== [] && !self::hasBySegments($item, $segmentos)) {
                        return false;
                    }
                }

                return $algum;
            }

            $encontrado = false;
            $dados = self::readKey($dados, $segmento, $encontrado);

            if (!$encontrado) {
                return false;
            }
        }

        return true;
    }

    private static function setBySegments(&$alvo, array $segmentos, $valor)
    {
        $segmento = array_shift($segmentos);

        if ($segmentos === []) {
            self::writeKey($alvo, $segmento, $valor);

            return;
        }

        // Arrays: por referência, sem copiar o nível inteiro.
        if (is_array($alvo)) {
            if (
                !array_key_exists($segmento, $alvo)
                || (!is_array($alvo[$segmento]) && !is_object($alvo[$segmento]))
            ) {
                $alvo[$segmento] = [];
            }

            self::setBySegments($alvo[$segmento], $segmentos, $valor);

            return;
        }

        $encontrado = false;
        $filho = self::readKey($alvo, $segmento, $encontrado);

        if (is_object($filho)) {
            // Objeto é passado por handle: basta alterar.
            self::setBySegments($filho, $segmentos, $valor);

            return;
        }

        if (!$encontrado || !is_array($filho)) {
            $filho = $alvo instanceof ArrayAccess ? [] : new stdClass();
        }

        self::setBySegments($filho, $segmentos, $valor);
        self::writeKey($alvo, $segmento, $filho);
    }

    private static function forgetBySegments(&$alvo, array $segmentos)
    {
        $segmento = array_shift($segmentos);

        if ($segmentos === []) {
            self::unsetKey($alvo, $segmento);

            return;
        }

        if (is_array($alvo)) {
            if (
                array_key_exists($segmento, $alvo)
                && (is_array($alvo[$segmento]) || is_object($alvo[$segmento]))
            ) {
                self::forgetBySegments($alvo[$segmento], $segmentos);
            }

            return;
        }

        $encontrado = false;
        $filho = self::readKey($alvo, $segmento, $encontrado);

        if (!$encontrado) {
            return;
        }

        if (is_object($filho)) {
            self::forgetBySegments($filho, $segmentos);
        } elseif (is_array($filho)) {
            self::forgetBySegments($filho, $segmentos);
            self::writeKey($alvo, $segmento, $filho);
        }
    }

    /*
     * Troca por cópias os objetos nos níveis intermediários do caminho,
     * para que forget() não chegue ao objeto original (usado por except()).
     */
    private static function detachPath(&$alvo, array $segmentos)
    {
        if (count($segmentos) < 2) {
            return;
        }

        $segmento = array_shift($segmentos);
        $encontrado = false;
        $filho = self::readKey($alvo, $segmento, $encontrado);

        if (!$encontrado || (!is_array($filho) && !is_object($filho))) {
            return;
        }

        if (is_object($filho)) {
            $filho = clone $filho;
        }

        self::detachPath($filho, $segmentos);
        self::writeKey($alvo, $segmento, $filho);
    }

    private static function readKey($dados, $chave, &$encontrado)
    {
        $encontrado = false;

        if (is_array($dados)) {
            if (array_key_exists($chave, $dados)) {
                $encontrado = true;

                return $dados[$chave];
            }

            return null;
        }

        if (!is_object($dados)) {
            return null;
        }

        if ($dados instanceof ArrayAccess && $dados->offsetExists($chave)) {
            $encontrado = true;

            return $dados[$chave];
        }

        $propriedades = get_object_vars($dados);

        if (array_key_exists($chave, $propriedades)) {
            $encontrado = true;

            return $propriedades[$chave];
        }

        // Propriedades mágicas (__isset/__get).
        if (isset($dados->{$chave})) {
            $encontrado = true;

            return $dados->{$chave};
        }

        return null;
    }

    private static function writeKey(&$alvo, $chave, $valor)
    {
        if (is_array($alvo)) {
            $alvo[$chave] = $valor;
        } elseif ($alvo instanceof ArrayAccess) {
            $alvo[$chave] = $valor;
        } elseif (is_object($alvo)) {
            $alvo->{$chave} = $valor;
        } else {
            throw new InvalidArgumentException(
                'Não é possível gravar a chave "' . $chave . '" em um valor escalar.'
            );
        }
    }

    private static function unsetKey(&$alvo, $chave)
    {
        if (is_array($alvo)) {
            unset($alvo[$chave]);
        } elseif ($alvo instanceof ArrayAccess) {
            if ($alvo->offsetExists($chave)) {
                unset($alvo[$chave]);
            } else {
                unset($alvo->{$chave});
            }
        } elseif (is_object($alvo)) {
            unset($alvo->{$chave});
        }
    }

    private static function iterable($dados)
    {
        if (is_array($dados) || $dados instanceof Traversable) {
            return $dados;
        }

        if (is_object($dados)) {
            return get_object_vars($dados);
        }

        return null;
    }

    private static function assertContainer($dados)
    {
        if (!is_array($dados) && !is_object($dados)) {
            throw new InvalidArgumentException(
                'Os dados devem ser um array ou objeto.'
            );
        }
    }

    private static function resolve($registro, $indice, $caminho)
    {
        if ($caminho instanceof Closure) {
            return $caminho($registro, $indice);
        }

        return self::get($registro, $caminho);
    }

    private static function value($valor)
    {
        return $valor instanceof Closure ? $valor() : $valor;
    }

    /*
     * Converte o valor de um campo em chave de array válida.
     */
    private static function normalizeIndex($chave)
    {
        if (is_int($chave) || is_string($chave)) {
            return $chave;
        }

        if ($chave === null) {
            return '';
        }

        if (is_bool($chave)) {
            return (int) $chave;
        }

        if (is_float($chave)) {
            return (string) $chave;
        }

        if ($chave instanceof BackedEnum) {
            return $chave->value;
        }

        if ($chave instanceof UnitEnum) {
            return $chave->name;
        }

        if ($chave instanceof DateTimeInterface) {
            return $chave->format('Y-m-d H:i:s');
        }

        if (is_object($chave) && method_exists($chave, '__toString')) {
            return (string) $chave;
        }

        throw new InvalidArgumentException(
            'O valor não pode ser usado como chave (use string, número, bool, null, enum ou data).'
        );
    }

    private static function validateIndex($chave)
    {
        if (!is_string($chave) && !is_int($chave)) {
            throw new InvalidArgumentException(
                'A chave deve ser uma string ou inteiro.'
            );
        }
    }
}
