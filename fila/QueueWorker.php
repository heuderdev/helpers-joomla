<?php

defined('_JEXEC') or die;

/*
 * Laço do worker: reserva jobs, executa e registra o resultado.
 *
 * Normalmente roda pela linha de comando (fila/cli/queue-worker.php), mas
 * também pode ser chamado de um controller, de uma tarefa agendada do
 * Joomla ou de qualquer script:
 *
 *     $resumo = QueueWorker::run([
 *         'queue' => 'imports',
 *         'max_time' => 50,
 *         'stop_when_empty' => true,
 *     ]);
 *
 * Opções:
 *   queue            fila a consumir; 'all' (padrão) consome todas
 *   types            tipos aceitos; padrão: os registrados no JobRegistry para a fila
 *   once             processa no máximo um job e sai
 *   stop_when_empty  sai quando não houver job disponível
 *   max_jobs         sai depois de N jobs (0 = sem limite)
 *   max_time         sai depois de N segundos (0 = sem limite); o job em andamento termina antes
 *   memory_mb        sai quando o uso de memória passar de N MB (padrão 256; 0 = sem limite)
 *   sleep            segundos de espera quando a fila está vazia (padrão 3)
 *   release_expired  recupera jobs de workers mortos ao iniciar e a cada minuto (padrão true)
 *   output           callable que recebe cada mensagem (ex.: function ($m) { echo $m, PHP_EOL; })
 *   started_by       identificação do worker gravada no job (padrão host:pid)
 *
 * Proteções:
 *   - handle() que lança exceção vira fail() com a mensagem;
 *   - handle() que termina sem complete/release/fail é concluído automaticamente;
 *   - erro fatal, exit() ou estouro de tempo (timeout_seconds) durante o job
 *     vira fail() pelo shutdown handler, em vez de deixar o job travado;
 *   - SIGTERM/SIGINT (com a extensão pcntl) terminam o job atual e então
 *     encerram; um segundo sinal encerra na hora.
 */
class QueueWorker
{
    private static $pararSolicitado = false;

    private static $jobAtual = null;

    private static $shutdownRegistrado = false;

    private static $sinaisRegistrados = false;

    private static $output = null;

    public static function run(array $options = [])
    {
        $options = array_merge([
            'queue' => 'all',
            'types' => null,
            'once' => false,
            'stop_when_empty' => false,
            'max_jobs' => 0,
            'max_time' => 0,
            'memory_mb' => 256,
            'sleep' => 3,
            'release_expired' => true,
            'output' => null,
            'started_by' => null,
        ], $options);

        self::$pararSolicitado = false;
        self::$output = is_callable($options['output']) ? $options['output'] : null;
        self::registrarShutdown();
        self::registrarSinais();

        $queue = trim((string) $options['queue']) === '' ? 'all' : trim((string) $options['queue']);
        $types = is_array($options['types']) ? $options['types'] : JobRegistry::typesByQueue($queue);
        $maxJobs = max(0, (int) $options['max_jobs']);
        $maxTime = max(0, (int) $options['max_time']);
        $memoryLimit = max(0, (int) $options['memory_mb']) * 1048576;
        $sleep = max(1, (int) $options['sleep']);
        $nextOptions = $options['started_by'] ? ['started_by' => (string) $options['started_by']] : [];

        $resumo = [
            'processados' => 0,
            'concluidos' => 0,
            'liberados' => 0,
            'falhas' => 0,
            'cancelados' => 0,
            'recuperados' => 0,
            'motivo' => '',
            'segundos' => 0,
        ];

        $inicio = microtime(true);

        if (empty($types)) {
            $resumo['motivo'] = 'nenhum job registrado para a fila "' . $queue . '"';
            self::out('Nenhum job registrado para a fila "' . $queue . '". Registre os jobs no JobRegistry (opção --bootstrap no CLI).');

            return $resumo;
        }

        self::out('Worker iniciado. Fila: ' . $queue . '. Tipos: ' . implode(', ', $types) . '.');

        $ultimaRecuperacao = 0;

        while (true) {
            if ($options['release_expired'] && time() - $ultimaRecuperacao >= 60) {
                $recuperados = QueueHelper::releaseExpiredLocks();
                $ultimaRecuperacao = time();

                if ($recuperados > 0) {
                    $resumo['recuperados'] += $recuperados;
                    self::out($recuperados . ' job(s) de workers que pararam de responder foram devolvidos à fila.');
                }
            }

            $motivo = self::motivoParaParar($resumo, $inicio, $maxJobs, $maxTime, $memoryLimit);

            if ($motivo !== null) {
                $resumo['motivo'] = $motivo;
                break;
            }

            $job = QueueHelper::next($types, $queue === 'all' ? null : $queue, $nextOptions);

            if (!$job) {
                if (QueueHelper::lastError() !== null) {
                    self::out('Erro ao buscar job: ' . QueueHelper::lastError());
                }

                if ($options['once'] || $options['stop_when_empty']) {
                    $resumo['motivo'] = 'fila vazia';
                    break;
                }

                self::dormir($sleep, $inicio, $maxTime);
                continue;
            }

            $resumo['processados']++;
            $situacao = self::processar($job);
            $resumo[$situacao]++;

            if ($options['once']) {
                $resumo['motivo'] = 'once';
                break;
            }
        }

        $resumo['segundos'] = round(microtime(true) - $inicio, 2);
        self::out('Worker encerrado (' . $resumo['motivo'] . '). Processados: ' . $resumo['processados']
            . ', concluídos: ' . $resumo['concluidos'] . ', liberados: ' . $resumo['liberados']
            . ', falhas: ' . $resumo['falhas'] . ', cancelados: ' . $resumo['cancelados'] . '.');

        return $resumo;
    }

    /*
     * Executa um job já reservado. Devolve a chave do resumo:
     * concluidos, liberados, falhas ou cancelados.
     */
    public static function processar($job)
    {
        $id = (int) $job->id;
        $token = (string) $job->lock_token;
        $limiteAnterior = ini_get('max_execution_time');

        self::$jobAtual = ['id' => $id, 'token' => $token, 'tipo' => $job->tipo];
        self::out('Job #' . $id . ' [' . $job->tipo . '] tentativa ' . $job->tentativas . '/' . $job->max_tentativas . '.');

        // Folga de 15s para o próprio job reagir com shouldStop().
        self::limiteDeTempo((int) $job->timeout_seconds + 15);

        try {
            $handler = JobRegistry::resolve($job);
            $retorno = $handler->handle();

            if (!$handler->isFinished() && !$handler->lostLock()) {
                $resultado = is_array($retorno) ? $retorno : ($retorno === null ? [] : ['retorno' => $retorno]);

                if (!QueueHelper::complete($id, $token, $resultado)) {
                    self::out('Job #' . $id . ': não foi possível concluir (' . QueueHelper::lastError() . ').');
                }
            }
        } catch (Throwable $erro) {
            $mensagem = get_class($erro) . ': ' . $erro->getMessage() . ' em ' . basename($erro->getFile()) . ':' . $erro->getLine();

            if (!QueueHelper::fail($id, $token, $mensagem)) {
                self::out('Job #' . $id . ': não foi possível registrar a falha (' . QueueHelper::lastError() . ').');
            }
        } finally {
            self::$jobAtual = null;
            self::limiteDeTempo((int) $limiteAnterior);
        }

        $final = QueueHelper::find($id);
        $status = $final ? $final->status : '';
        $situacoes = [
            QueueHelper::STATUS_COMPLETED => 'concluidos',
            QueueHelper::STATUS_PENDING => 'liberados',
            QueueHelper::STATUS_RETRY => 'falhas',
            QueueHelper::STATUS_FAILED => 'falhas',
            QueueHelper::STATUS_CANCELLED => 'cancelados',
        ];

        $situacao = isset($situacoes[$status]) ? $situacoes[$status] : 'falhas';
        $mensagem = 'Job #' . $id . ': ' . $status;

        if ($final && $final->erro && $situacao === 'falhas') {
            $mensagem .= ' - ' . $final->erro;
        }

        self::out($mensagem . '.');

        return $situacao;
    }

    /*
     * Pede para o worker parar depois do job atual. Também é o que
     * SIGTERM/SIGINT fazem.
     */
    public static function requestStop()
    {
        self::$pararSolicitado = true;
    }

    public static function stopRequested()
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }

        return self::$pararSolicitado;
    }

    /*
     * Chamado pelo PHP ao terminar o processo. Se havia job em execução,
     * o processo morreu no meio dele (erro fatal, timeout, exit): registra
     * a falha para o job não ficar preso até o lock expirar.
     */
    public static function aoTerminar()
    {
        if (self::$jobAtual === null) {
            return;
        }

        $job = self::$jobAtual;
        self::$jobAtual = null;

        $erro = error_get_last();
        $fatais = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR];

        $mensagem = $erro !== null && in_array($erro['type'], $fatais, true)
            ? 'Erro fatal: ' . $erro['message'] . ' em ' . basename($erro['file']) . ':' . $erro['line']
            : 'O processo terminou durante a execução do job (exit/die ou sinal).';

        // Uma transação aberta pelo job seria desfeita junto com a falha.
        try {
            QueueHelper::getDatabase()->transactionRollback();
        } catch (Throwable $e) {
        }

        QueueHelper::fail($job['id'], $job['token'], $mensagem);
        self::out('Job #' . $job['id'] . ': ' . $mensagem);
    }

    private static function motivoParaParar(array $resumo, $inicio, $maxJobs, $maxTime, $memoryLimit)
    {
        if (self::stopRequested()) {
            return 'sinal de parada';
        }

        if ($maxJobs > 0 && $resumo['processados'] >= $maxJobs) {
            return 'limite de jobs';
        }

        if ($maxTime > 0 && microtime(true) - $inicio >= $maxTime) {
            return 'limite de tempo';
        }

        if ($memoryLimit > 0 && memory_get_usage(true) >= $memoryLimit) {
            return 'limite de memória';
        }

        return null;
    }

    private static function dormir($segundos, $inicio, $maxTime)
    {
        if ($maxTime > 0) {
            $segundos = min($segundos, max(0, (int) ceil($inicio + $maxTime - microtime(true))));
        }

        for ($i = 0; $i < $segundos && !self::stopRequested(); $i++) {
            sleep(1);
        }
    }

    private static function limiteDeTempo($segundos)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(max(0, (int) $segundos));
        }
    }

    private static function registrarShutdown()
    {
        if (!self::$shutdownRegistrado) {
            register_shutdown_function([__CLASS__, 'aoTerminar']);
            self::$shutdownRegistrado = true;
        }
    }

    private static function registrarSinais()
    {
        if (self::$sinaisRegistrados || PHP_SAPI !== 'cli' || !function_exists('pcntl_signal')) {
            return;
        }

        $handler = function () {
            if (self::$pararSolicitado) {
                self::out('Segundo sinal recebido: encerrando agora.');
                exit(1);
            }

            self::out('Sinal recebido: o worker vai parar depois do job atual.');
            self::$pararSolicitado = true;
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        self::$sinaisRegistrados = true;
    }

    private static function out($mensagem)
    {
        if (self::$output !== null) {
            call_user_func(self::$output, $mensagem);
        }
    }
}
