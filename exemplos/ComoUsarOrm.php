<?php

/*
 * Exemplo de estudo: OrmBase + OrmTables + DbTransactionHelper.
 *
 * Domínio: uma loja simples com produtos, pedidos e itens de pedido.
 * O arquivo é dividido em 4 camadas, de baixo para cima:
 *
 *   1. Models      → uma classe por tabela, com configuração e "scopes"
 *   2. Exceções    → erros de negócio separados de erros técnicos
 *   3. Service     → regras de negócio, transações e consultas
 *   4. Controller  → só lê a entrada, chama o service e responde
 *
 * Tabelas usadas:
 *
 * CREATE TABLE `#__loja_produtos` (
 *   `id` INT AUTO_INCREMENT PRIMARY KEY,
 *   `nome` VARCHAR(150) NOT NULL,
 *   `preco` DECIMAL(10,2) NOT NULL,
 *   `estoque` INT NOT NULL DEFAULT 0,
 *   `ativo` TINYINT(1) NOT NULL DEFAULT 1,
 *   `created_at` DATETIME NULL,
 *   `updated_at` DATETIME NULL
 * ) ENGINE=InnoDB;
 *
 * CREATE TABLE `#__loja_pedidos` (
 *   `id` INT AUTO_INCREMENT PRIMARY KEY,
 *   `cliente_id` INT NOT NULL,
 *   `status` VARCHAR(20) NOT NULL,
 *   `total` DECIMAL(10,2) NOT NULL DEFAULT 0,
 *   `metadados` JSON NULL,
 *   `created_at` DATETIME NULL,
 *   `updated_at` DATETIME NULL,
 *   `deleted_at` DATETIME NULL
 * ) ENGINE=InnoDB;
 *
 * CREATE TABLE `#__loja_pedido_itens` (
 *   `id` INT AUTO_INCREMENT PRIMARY KEY,
 *   `pedido_id` INT NOT NULL,
 *   `produto_id` INT NOT NULL,
 *   `quantidade` INT NOT NULL,
 *   `preco_unitario` DECIMAL(10,2) NOT NULL
 * ) ENGINE=InnoDB;
 *
 * CREATE TABLE `#__loja_eventos` (
 *   `id` INT AUTO_INCREMENT PRIMARY KEY,
 *   `tipo` VARCHAR(50) NOT NULL,
 *   `referencia_id` INT NOT NULL,
 *   `descricao` VARCHAR(255) NOT NULL,
 *   `created_at` DATETIME NULL,
 *   `updated_at` DATETIME NULL
 * ) ENGINE=InnoDB;
 *
 * Importante: transações só funcionam em tabelas InnoDB.
 */

defined('_JEXEC') or die;

$helpers = JPATH_SITE . '/components/com_generico/helpers';

require_once $helpers . '/DbTransactionHelper.php';
require_once $helpers . '/OrmTables.php';
require_once $helpers . '/ApiResponseHelper.php';
require_once $helpers . '/InputHelper.php';


/* =====================================================================
 * 1. MODELS
 *
 * Cada model herda de OrmBase e declara a configuração da tabela como
 * propriedades. Tudo o que não for declarado usa o padrão do OrmBase:
 * primaryKey 'id', timestamps ligados, sem soft delete.
 *
 * Uso: ProdutoModel::query() devolve sempre uma instância NOVA, sem
 * filtros de consultas anteriores.
 *
 * Atenção: em $table use só o nome da tabela, sem alias
 * ('#__x AS x'). O alias funciona no SELECT, mas create(), update() e
 * delete() usam o nome direto e quebrariam.
 * ===================================================================== */

class ProdutoModel extends OrmBase
{
    protected $table = '#__loja_produtos';

    /*
     * casts: converte o que vem do banco (tudo string no MySQL) para o
     * tipo certo do PHP. 'bool' também é convertido para 1/0 ao gravar.
     */
    protected $casts = [
        'preco' => 'float',
        'estoque' => 'int',
        'ativo' => 'bool',
    ];

    /*
     * fillable: create()/update() ignoram qualquer coluna fora desta
     * lista. Protege contra o usuário enviar, por exemplo, 'id' no POST.
     */
    protected $fillable = [
        'nome',
        'preco',
        'estoque',
        'ativo',
    ];

    /*
     * "Scopes": métodos do model que adicionam filtros e devolvem $this.
     * Dão nome às regras do domínio e mantêm a cadeia fluente:
     *
     *     ProdutoModel::query()->ativos()->buscarPorNome('caneta')->get();
     */
    public function ativos()
    {
        return $this->where('ativo', 1);
    }

    public function buscarPorNome($termo)
    {
        $termo = trim((string) $termo);

        if ($termo !== '') {
            $this->whereLike('nome', $termo);
        }

        return $this;
    }
}

class PedidoModel extends OrmBase
{
    const STATUS_ABERTO = 'aberto';
    const STATUS_PAGO = 'pago';
    const STATUS_CANCELADO = 'cancelado';
    const STATUS_EXPIRADO = 'expirado';

    protected $table = '#__loja_pedidos';

    /*
     * Com softDeletes, delete() só preenche deleted_at, e todas as
     * consultas (get, find, count, paginate...) ignoram esses registros.
     * restore($id) desfaz; forceDelete($id) apaga de verdade.
     */
    protected $softDeletes = true;

    protected $casts = [
        'cliente_id' => 'int',
        'total' => 'float',
        'metadados' => 'json',
    ];

    protected $fillable = [
        'cliente_id',
        'status',
        'total',
        'metadados',
    ];

    public function doCliente($clienteId)
    {
        return $this->where('cliente_id', (int) $clienteId);
    }

    /*
     * Scope opcional: sem status, não filtra. Isso evita vários "if"
     * no service quando o filtro vem da tela.
     */
    public function comStatus($status)
    {
        $status = trim((string) $status);

        if ($status !== '') {
            $this->where('status', $status);
        }

        return $this;
    }

    public function recentes()
    {
        return $this->latest(); // ORDER BY created_at DESC
    }
}

class PedidoItemModel extends OrmBase
{
    protected $table = '#__loja_pedido_itens';

    // A tabela não tem created_at/updated_at.
    protected $timestamps = false;

    protected $casts = [
        'pedido_id' => 'int',
        'produto_id' => 'int',
        'quantidade' => 'int',
        'preco_unitario' => 'float',
    ];

    protected $fillable = [
        'pedido_id',
        'produto_id',
        'quantidade',
        'preco_unitario',
    ];

    public function doPedido($pedidoId)
    {
        return $this->where('pedido_id', (int) $pedidoId);
    }
}


/* =====================================================================
 * 2. EXCEÇÕES DE NEGÓCIO
 *
 * Separar "o usuário pediu algo inválido" (mostrar a mensagem) de
 * "o banco caiu" (logar e mostrar mensagem genérica) é o que permite
 * ao controller responder certo sem conhecer as regras.
 * ===================================================================== */

class PedidoException extends RuntimeException
{
}

class EstoqueInsuficienteException extends PedidoException
{
}


/* =====================================================================
 * 3. SERVICE
 * ===================================================================== */

class PedidoService
{
    /*
     * Cria um pedido com itens e baixa o estoque, tudo ou nada.
     *
     * $itens = [
     *     ['produto_id' => 10, 'quantidade' => 2],
     *     ['produto_id' => 12, 'quantidade' => 1],
     * ];
     */
    public static function criar($clienteId, array $itens)
    {
        if (empty($itens)) {
            throw new PedidoException(
                'O pedido precisa de pelo menos um item.'
            );
        }

        /*
         * DbTransactionHelper::run():
         * - abre a transação e executa o callback;
         * - se o callback termina normalmente → COMMIT e devolve o retorno;
         * - se lança qualquer exceção → ROLLBACK e relança a MESMA
         *   exceção (o tipo é preservado, então o controller ainda
         *   consegue diferenciar EstoqueInsuficienteException);
         * - em deadlock/lock timeout, repete o callback inteiro até
         *   'retries' vezes. Só é seguro porque o callback mexe apenas
         *   no banco: nada de enviar e-mail ou chamar API aqui dentro.
         */
        return DbTransactionHelper::run(
            function () use ($clienteId, $itens) {
                $pedido = PedidoModel::query()->create([
                    'cliente_id' => $clienteId,
                    'status' => PedidoModel::STATUS_ABERTO,
                    'total' => 0,
                    'metadados' => ['origem' => 'site'], // json: vira texto ao gravar
                ]);

                $total = 0.0;

                foreach ($itens as $item) {
                    $produtoId = (int) $item['produto_id'];
                    $quantidade = (int) $item['quantidade'];

                    if ($quantidade <= 0) {
                        throw new PedidoException(
                            'Quantidade inválida para o produto ' . $produtoId . '.'
                        );
                    }

                    /*
                     * Ler, conferir, gravar. lockForUpdate() gera
                     * SELECT ... FOR UPDATE: a linha do produto fica travada
                     * até o COMMIT. Uma compra simultânea do mesmo produto
                     * espera aqui, e quando passar já lê o estoque baixado.
                     * Sem a trava, as duas poderiam ler "estoque = 1" e
                     * vender a mesma unidade.
                     */
                    $produto = ProdutoModel::query()
                        ->where('id', $produtoId)
                        ->lockForUpdate()
                        ->first();

                    if ($produto === null || !$produto->ativo) {
                        throw new PedidoException(
                            'Produto ' . $produtoId . ' indisponível.'
                        );
                    }

                    if ($produto->estoque < $quantidade) {
                        throw new EstoqueInsuficienteException(
                            'Estoque insuficiente para "' . $produto->nome . '".'
                        );
                    }

                    ProdutoModel::query()->decrement(
                        $produto->id,
                        'estoque',
                        $quantidade
                    );

                    PedidoItemModel::query()->create([
                        'pedido_id' => $pedido->id,
                        'produto_id' => $produto->id,
                        'quantidade' => $quantidade,
                        // Grava o preço do momento; o produto pode mudar depois.
                        'preco_unitario' => $produto->preco,
                    ]);

                    $total += $produto->preco * $quantidade;
                }

                self::registrarEvento(
                    'pedido.criado',
                    $pedido->id,
                    count($itens) . ' item(ns)'
                );

                // update() devolve o registro já atualizado e com casts.
                return PedidoModel::query()->update(
                    $pedido->id,
                    ['total' => round($total, 2)]
                );
            },
            [
                'retries' => 3,
                'context' => ['cliente_id' => $clienteId], // vai para o log
            ]
        );
    }

    /*
     * Cancela um pedido: devolve o estoque e faz soft delete.
     */
    public static function cancelar($pedidoId)
    {
        return DbTransactionHelper::run(function () use ($pedidoId) {
            /*
             * Trava o pedido: se o cliente clicar duas vezes em "cancelar",
             * a segunda requisição espera a primeira terminar e então já
             * vê status 'cancelado', sem devolver o estoque duas vezes.
             * (Sem essa preocupação, findOrFail($id) bastaria.)
             */
            $pedido = PedidoModel::query()
                ->where('id', (int) $pedidoId)
                ->lockForUpdate()
                ->first();

            if ($pedido === null) {
                // Mesmo código que findOrFail usa; o controller trata 404.
                throw new RuntimeException('Pedido não encontrado.', 404);
            }

            if ($pedido->status !== PedidoModel::STATUS_ABERTO) {
                throw new PedidoException(
                    'Só pedidos em aberto podem ser cancelados.'
                );
            }

            $itens = PedidoItemModel::query()
                ->doPedido($pedido->id)
                ->get();

            foreach ($itens as $item) {
                ProdutoModel::query()->increment(
                    $item->produto_id,
                    'estoque',
                    $item->quantidade
                );
            }

            PedidoModel::query()->update($pedido->id, [
                'status' => PedidoModel::STATUS_CANCELADO,
            ]);

            // Soft delete: só preenche deleted_at.
            PedidoModel::query()->delete($pedido->id);

            self::registrarEvento('pedido.cancelado', $pedido->id, 'Cancelado pelo cliente');

            return true;
        });
    }

    /*
     * Transação aninhada: cada cancelar() abre a sua própria transação,
     * e aqui elas ficam dentro de uma maior. O DbTransactionHelper
     * transforma as internas em SAVEPOINTs. Se o 3º pedido falhar,
     * os dois primeiros também são desfeitos: tudo ou nada.
     */
    public static function cancelarVarios(array $pedidoIds)
    {
        return DbTransactionHelper::run(function () use ($pedidoIds) {
            foreach ($pedidoIds as $pedidoId) {
                self::cancelar($pedidoId);
            }

            return count($pedidoIds);
        });
    }

    /*
     * Operação em massa: updateWhere() altera todos os registros que
     * casam com os where. Sem nenhum where, ela lança LogicException
     * para você não alterar a tabela inteira por engano. Se for mesmo a
     * intenção, chame ->allowMassOperation() antes.
     */
    public static function expirarAbertosAntigos($dias = 7)
    {
        $limite = JFactory::getDate('-' . (int) $dias . ' days')->toSql();

        return PedidoModel::query()
            ->comStatus(PedidoModel::STATUS_ABERTO)
            ->where('created_at', '<', $limite)
            ->updateWhere(['status' => PedidoModel::STATUS_EXPIRADO]); // devolve linhas afetadas
    }

    /*
     * Listagem com filtros vindos da tela + paginação.
     *
     * when($valor, $callback) só aplica o filtro se $valor for
     * verdadeiro, e passa o valor para o callback. A consulta fica numa
     * cadeia só, sem if no meio. Para filtros que se repetem em várias
     * telas, prefira um scope no model (como comStatus).
     */
    public static function listar(array $filtros, $pagina = 1)
    {
        $filtros += [
            'status' => '',
            'cliente_id' => 0,
            'desde' => '',
            'busca_id' => '',
        ];

        /*
         * paginate() devolve:
         * itens, total, por_pagina, pagina_atual, total_paginas, paginacao
         * ('paginacao' é um JPagination, útil em views HTML).
         */
        return PedidoModel::query()
            ->comStatus($filtros['status'])
            ->when($filtros['cliente_id'], function ($q, $clienteId) {
                $q->doCliente($clienteId);
            })
            ->when($filtros['desde'], function ($q, $desde) {
                $q->where('created_at', '>=', $desde);
            })
            ->when($filtros['busca_id'], function ($q, $ids) {
                // "12, 15, 20" → WHERE id IN (12, 15, 20)
                $q->whereIn('id', array_map('intval', explode(',', $ids)));
            })
            ->recentes()
            ->paginate(20, $pagina);
    }

    /*
     * whereGroup() coloca condições entre parênteses. Aqui:
     *
     *   ativo = 1 AND (estoque <= 5 OR preco <= 0)
     *
     * Sem o grupo, o SQL seria "ativo = 1 AND estoque <= 5 OR preco <= 0",
     * e como AND tem precedência, produtos INATIVOS com preço zero
     * também apareceriam.
     */
    public static function produtosParaRevisar($estoqueMinimo = 5)
    {
        return ProdutoModel::query()
            ->ativos()
            ->whereGroup(function ($q) use ($estoqueMinimo) {
                $q->where('estoque', '<=', (int) $estoqueMinimo)
                    ->orWhere('preco', '<=', 0);
            })
            ->orderBy('estoque')
            ->get();
    }

    /*
     * chunk() lê a consulta em lotes (aqui, de 500 em 500), então dá
     * para exportar 1 milhão de pedidos sem estourar a memória do PHP.
     * O callback recebe o lote e o número do lote; devolver false para.
     */
    public static function exportarPagosCsv($caminhoArquivo)
    {
        $arquivo = fopen($caminhoArquivo, 'w');

        if ($arquivo === false) {
            throw new RuntimeException('Não foi possível criar ' . $caminhoArquivo);
        }

        try {
            fputcsv($arquivo, ['id', 'cliente_id', 'total', 'data'], ',', '"', '\\');

            PedidoModel::query()
                ->comStatus(PedidoModel::STATUS_PAGO)
                ->select(['id', 'cliente_id', 'total', 'created_at'])
                ->chunk(500, function ($lote) use ($arquivo) {
                    foreach ($lote as $pedido) {
                        fputcsv($arquivo, [
                            $pedido->id,
                            $pedido->cliente_id,
                            number_format($pedido->total, 2, ',', ''),
                            $pedido->created_at,
                        ], ',', '"', '\\');
                    }
                });
        } finally {
            fclose($arquivo);
        }
    }

    /*
     * Consultas de leitura curtas. Cada uma começa de query() para não
     * herdar filtros da anterior.
     */
    public static function resumoDoCliente($clienteId)
    {
        return [
            'quantidade_pedidos' => PedidoModel::query()
                ->doCliente($clienteId)
                ->count(),

            'total_gasto' => (float) PedidoModel::query()
                ->doCliente($clienteId)
                ->comStatus(PedidoModel::STATUS_PAGO)
                ->sum('total'),

            'tem_pedido_aberto' => PedidoModel::query()
                ->doCliente($clienteId)
                ->comStatus(PedidoModel::STATUS_ABERTO)
                ->exists(),

            // pluck: só uma coluna, como array simples.
            'ultimos_ids' => PedidoModel::query()
                ->doCliente($clienteId)
                ->recentes()
                ->limit(5)
                ->pluck('id'),

            // pluck com chave: [id => nome]; ótimo para <select>.
            'produtos_para_select' => ProdutoModel::query()
                ->ativos()
                ->orderBy('nome')
                ->pluck('nome', 'id'),
        ];
    }

    /*
     * Consulta com JOIN. Os where com alias ('i.pedido_id') funcionam
     * porque o quoteName do Joomla entende "alias.coluna".
     */
    public static function itensComProduto($pedidoId)
    {
        return OrmTables::table('#__loja_pedido_itens AS i')
            ->select(['i.quantidade', 'i.preco_unitario', 'p.nome'])
            ->innerJoin('#__loja_produtos AS p', 'p.id = i.produto_id')
            ->where('i.pedido_id', (int) $pedidoId)
            ->get();
    }

    /*
     * OrmTables::table() é o atalho para tabelas que não merecem um
     * model próprio, como esta tabela de eventos. Também funciona
     * dentro da transação: se o pedido falhar, o evento também some.
     */
    protected static function registrarEvento($tipo, $referenciaId, $descricao)
    {
        OrmTables::table('#__loja_eventos')->create([
            'tipo' => $tipo,
            'referencia_id' => (int) $referenciaId,
            'descricao' => $descricao,
        ]);
    }
}


/* =====================================================================
 * 4. CONTROLLER
 *
 * Fino: lê a entrada, chama o service e traduz exceções em respostas.
 * Nenhuma regra de negócio e nenhum SQL aqui.
 * ===================================================================== */

class LojaControllerPedidos extends JControllerLegacy
{
    public function criar()
    {
        if (!JSession::checkToken()) {
            return ApiResponseHelper::forbidden('Token inválido.');
        }

        $clienteId = (int) JFactory::getUser()->id;

        if ($clienteId <= 0) {
            return ApiResponseHelper::unauthorized();
        }

        try {
            $pedido = PedidoService::criar(
                $clienteId,
                InputHelper::array('itens', [], 'post')
            );

            return ApiResponseHelper::created('Pedido criado com sucesso.', $pedido);
        } catch (EstoqueInsuficienteException $e) {
            // Mais específica primeiro: herda de PedidoException.
            return ApiResponseHelper::conflict($e->getMessage());
        } catch (PedidoException $e) {
            return ApiResponseHelper::badRequest($e->getMessage());
        } catch (Throwable $e) {
            // Erro técnico: loga os detalhes, mostra mensagem genérica.
            return ApiResponseHelper::exception($e);
        }
    }

    public function cancelar()
    {
        if (!JSession::checkToken()) {
            return ApiResponseHelper::forbidden('Token inválido.');
        }

        try {
            PedidoService::cancelar(InputHelper::uint('id', 0, 'post'));

            return ApiResponseHelper::success('Pedido cancelado.');
        } catch (PedidoException $e) {
            return ApiResponseHelper::conflict($e->getMessage());
        } catch (Throwable $e) {
            if ($e->getCode() === 404) {
                return ApiResponseHelper::notFound('Pedido não encontrado.');
            }

            return ApiResponseHelper::exception($e);
        }
    }

    public function listar()
    {
        try {
            $resultado = PedidoService::listar(
                [
                    'status' => InputHelper::cmd('status', '', 'get'),
                    'cliente_id' => (int) JFactory::getUser()->id,
                ],
                InputHelper::uint('pagina', 1, 'get')
            );

            // O JPagination não é útil em JSON; devolve só os dados.
            unset($resultado['paginacao']);

            return ApiResponseHelper::success('Pedidos carregados.', $resultado);
        } catch (Throwable $e) {
            return ApiResponseHelper::exception($e);
        }
    }
}
