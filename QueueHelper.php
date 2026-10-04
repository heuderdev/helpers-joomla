<?php

defined('_JEXEC') or die;

/*
 * Fila de jobs em banco de dados (MySQL ou PostgreSQL).
 *
 * Quem enfileira (controller, model, plugin):
 *
 *     $r = QueueHelper::push('importar_csv', ['importacao_id' => 10], ['usuario_id' => $userId]);
 *     $r['data']['uuid'];                       // guarde para consultar o andamento
 *
 * Quem processa (worker, normalmente fila/cli/queue-worker.php):
 *
 *     $job = QueueHelper::next(['importar_csv']);
 *     ...trabalho...
 *     QueueHelper::complete($job->id, $job->lock_token, ['linhas' => 5000]);
 *
 * Ciclo de vida de um job:
 *
 *     pending ──next()──> processing ──complete()──> completed
 *        ^                    │
 *        │                    ├──release()──> pending  (pausa: volta à fila sem gastar tentativa)
 *        │                    ├──fail()─────> retry    (volta à fila depois de uma espera)
 *        │                    │          └──> failed   (sem tentativas restantes)
 *        │                    └──lock expirado (worker morreu)──> retry ou failed
 *        └──retry()── failed / cancelled          cancel() ──> cancelled
 *
 * A reserva (next) é atômica sem precisar de transação: o UPDATE só vale
 * se o job ainda estiver livre. Dois workers nunca recebem o mesmo job.
 *
 * Todas as datas são gravadas em UTC, como o restante do Joomla.
 *
 * Funciona no Joomla 3.4.5 ou superior, 4 e 5 (com ou sem o plugin de
 * compatibilidade), em PHP 7.0 ou superior.
 *
 * Métodos de ação devolvem true/false (ou null) e nunca lançam exceção:
 * o motivo da última falha fica em QueueHelper::lastError().
 */
class QueueHelper
{
    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_RETRY = 'retry';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    private static $table = '#__queue_jobs';

    private static $connection = null;

    private static $logCategory = 'queue_helper';

    private static $logDirectory = null;

    private static $loggersConfigurados = [];

    private static $defaultQueue = 'default';

    private static $defaultMaxAttempts = 3;

    private static $defaultTimeoutSeconds = 240;

    private static $defaultRetryAfterSeconds = 300;

    private static $defaultRetryDelaySeconds = 30;

    private static $maxRetryDelaySeconds = 3600;

    private static $maxPayloadLength = 10485760;

    private static $maxResultLength = 10485760;

    private static $maxErrorLength = 50000;

    private static $lastError = null;

    /*
     * Quantos jobs candidatos next() tenta reservar antes de desistir.
     * Com vários workers, o primeiro candidato pode ser levado por outro.
     */
    private static $claimCandidates = 5;

    /* ------------------------------------------------------------------
     * Configuração
     * ------------------------------------------------------------------ */

    public static function setTable($table)
    {
        $table = trim((string) $table);

        if ($table === '' || !preg_match('/^[#_a-zA-Z0-9]+$/', $table)) {
            throw new InvalidArgumentException('Nome de tabela inválido.');
        }

        self::$table = $table;
    }

    public static function getTable()
    {
        return self::$table;
    }

    /*
     * Usa outro banco para a fila. Aceita um driver já criado ou o nome de
     * uma conexão registrada no DbConnectionHelper. null volta para o
     * banco do Joomla.
     */
    public static function setConnection($driverOuNome)
    {
        if ($driverOuNome !== null && !is_object($driverOuNome)) {
            $driverOuNome = trim((string) $driverOuNome);

            if ($driverOuNome === '' || $driverOuNome === 'default') {
                $driverOuNome = null;
            } elseif (!class_exists('DbConnectionHelper')) {
                throw new RuntimeException('Para usar uma conexão pelo nome, carregue o DbConnectionHelper.');
            }
        }

        self::$connection = $driverOuNome;
    }

    /*
     * O driver de banco usado pela fila.
     */
    public static function getDatabase()
    {
        return self::db();
    }

    public static function setDefaultQueue($queue)
    {
        $queue = trim((string) $queue);

        self::$defaultQueue = $queue === ''
            ? 'default'
            : self::normalizeName($queue, 'queue', 100);
    }

    public static function getDefaultQueue()
    {
        return self::$defaultQueue;
    }

    public static function setLogCategory($category)
    {
        $category = trim((string) $category);

        if ($category === '') {
            throw new InvalidArgumentException('Categoria de log inválida.');
        }

        self::$logCategory = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $category);
    }

    /*
     * Grava os logs da fila em <diretório>/<categoria>.php. Caminho relativo
     * é resolvido a partir de JPATH_SITE. Sem esta chamada, as mensagens
     * vão para os loggers que o Joomla já tiver configurados.
     */
    public static function setLogDirectory($directory)
    {
        self::$logDirectory = self::resolveLogDirectory($directory);

        return self::$logDirectory;
    }

    public static function setDefaultMaxAttempts($attempts)
    {
        self::$defaultMaxAttempts = self::validarMaxTentativas($attempts);
    }

    public static function setDefaultTimeoutSeconds($seconds)
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('Timeout padrão inválido.');
        }

        self::$defaultTimeoutSeconds = $seconds;
    }

    public static function setDefaultRetryAfterSeconds($seconds)
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException('Retry after padrão inválido.');
        }

        self::$defaultRetryAfterSeconds = $seconds;
    }

    public static function setDefaultRetryDelaySeconds($seconds)
    {
        $seconds = (int) $seconds;

        if ($seconds < 0) {
            throw new InvalidArgumentException('Delay de retry inválido.');
        }

        self::$defaultRetryDelaySeconds = $seconds;
    }

    public static function setMaxRetryDelaySeconds($seconds)
    {
        $seconds = (int) $seconds;

        if ($seconds < 0) {
            throw new InvalidArgumentException('Delay máximo de retry inválido.');
        }

        self::$maxRetryDelaySeconds = $seconds;
    }

    /*
     * Motivo da última falha de um método de ação (push, next, complete…),
     * ou null se a última chamada deu certo.
     */
    public static function lastError()
    {
        return self::$lastError;
    }

    /* ------------------------------------------------------------------
     * Produtor
     * ------------------------------------------------------------------ */

    /*
     * Opções:
     *   queue                fila; sem ela, usa a fila do JobRegistry para o tipo ou a fila padrão
     *   prioridade           maior sai primeiro (padrão 0)
     *   max_tentativas       execuções com erro antes de falhar de vez (1 a 100)
     *   timeout_seconds      tempo máximo de uma execução (o worker aplica)
     *   retry_after_seconds  sem heartbeat por este tempo, o job é considerado abandonado
     *   delay_seconds        só fica disponível daqui a N segundos
     *   disponivel_em        ou numa data (UTC): 'Y-m-d H:i:s', DateTime ou timestamp
     *   usuario_id           dono do job (para status/cancel/retry com checagem)
     *   total, progresso     valores iniciais da barra de progresso
     *   unico                true: não enfileira se já houver job ativo do mesmo tipo e fila
     *                        com o mesmo payload; ['chave', ...]: compara só essas chaves
     */
    public static function push($tipo, array $payload = [], array $options = [])
    {
        self::$lastError = null;
        $tipoOriginal = $tipo;

        try {
            $tipo = self::normalizeType($tipo);
            $queue = self::queueParaTipo($tipo, isset($options['queue']) ? $options['queue'] : null);

            $maxAttempts = self::validarMaxTentativas(
                isset($options['max_tentativas']) ? $options['max_tentativas'] : self::$defaultMaxAttempts
            );

            $timeoutSeconds = isset($options['timeout_seconds'])
                ? (int) $options['timeout_seconds']
                : self::$defaultTimeoutSeconds;

            $retryAfterSeconds = isset($options['retry_after_seconds'])
                ? (int) $options['retry_after_seconds']
                : self::$defaultRetryAfterSeconds;

            if ($timeoutSeconds <= 0) {
                throw new InvalidArgumentException('Timeout do job inválido.');
            }

            if ($retryAfterSeconds <= 0) {
                throw new InvalidArgumentException('Retry after do job inválido.');
            }

            $prioridade = isset($options['prioridade']) ? (int) $options['prioridade'] : 0;
            $progresso = isset($options['progresso']) ? max(0, (float) $options['progresso']) : 0;
            $total = isset($options['total']) ? max(0, (float) $options['total']) : 0;
            $usuarioId = isset($options['usuario_id']) ? (int) $options['usuario_id'] : 0;

            if (isset($options['delay_seconds']) && (int) $options['delay_seconds'] > 0) {
                $disponivelEm = self::dateFromNow((int) $options['delay_seconds']);
            } else {
                $disponivelEm = self::normalizeDate(
                    isset($options['disponivel_em']) ? $options['disponivel_em'] : null
                );
            }

            $payloadJson = self::jsonEncode($payload, 'payload', self::$maxPayloadLength);

            if (!empty($options['unico'])) {
                $existente = self::findActiveDuplicate($tipo, $queue, $payload, $options['unico']);

                if ($existente !== null) {
                    self::log('info', 'Job idêntico já está na fila; nada foi adicionado.', [
                        'job_id' => (int) $existente->id,
                        'tipo' => $tipo,
                        'queue' => $queue,
                    ]);

                    return self::pushResult(
                        'Já existe um job igual na fila.',
                        (int) $existente->id,
                        $existente->uuid,
                        $queue,
                        $tipo,
                        $existente->status,
                        true
                    );
                }
            }

            $uuid = self::generateUuid();
            $now = self::now();

            $job = new stdClass();
            $job->uuid = $uuid;
            $job->queue = $queue;
            $job->tipo = $tipo;
            $job->payload = $payloadJson;
            $job->status = self::STATUS_PENDING;
            $job->prioridade = $prioridade;
            $job->progresso = $progresso;
            $job->total = $total;
            $job->percentual = self::calculatePercentual($progresso, $total);
            $job->tentativas = 0;
            $job->max_tentativas = $maxAttempts;
            $job->timeout_seconds = $timeoutSeconds;
            $job->retry_after_seconds = $retryAfterSeconds;
            $job->disponivel_em = $disponivelEm;
            $job->usuario_id = $usuarioId > 0 ? $usuarioId : null;
            $job->criado_em = $now;
            $job->atualizado_em = $now;

            self::db()->insertObject(self::$table, $job, 'id');

            if (empty($job->id)) {
                $found = self::findByUuidInternal($uuid);
                $job->id = $found ? $found->id : 0;
            }

            self::log('info', 'Job adicionado à fila.', [
                'job_id' => (int) $job->id,
                'uuid' => $uuid,
                'queue' => $queue,
                'tipo' => $tipo,
                'prioridade' => $prioridade,
                'usuario_id' => $job->usuario_id,
            ]);

            return self::pushResult('Job adicionado à fila com sucesso.', (int) $job->id, $uuid, $queue, $tipo, self::STATUS_PENDING, false);
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao adicionar job à fila.', [
                'erro' => $error->getMessage(),
                'tipo' => is_scalar($tipoOriginal) ? (string) $tipoOriginal : null,
            ]);

            return [
                'success' => false,
                'status' => 'erro',
                'mensagem' => 'Não foi possível adicionar o job à fila.',
                'erro' => $error->getMessage(),
                'data' => [],
            ];
        }
    }

    /* ------------------------------------------------------------------
     * Consumidor (worker)
     * ------------------------------------------------------------------ */

    /*
     * Reserva o próximo job disponível e o devolve já em processamento,
     * com lock_token preenchido. Devolve null se não houver job.
     *
     * $allowedTypes vazio aceita qualquer tipo. $queue null (ou 'all')
     * aceita qualquer fila. Opção: started_by (identificação do worker).
     *
     * Ordem: maior prioridade primeiro; dentro da mesma prioridade, o mais
     * antigo.
     */
    public static function next(array $allowedTypes = [], $queue = null, array $options = [])
    {
        self::$lastError = null;

        try {
            $queue = $queue !== null && trim((string) $queue) !== '' && $queue !== 'all'
                ? self::normalizeQueue($queue)
                : null;

            $types = [];

            foreach ($allowedTypes as $type) {
                $types[] = self::normalizeType($type);
            }

            $startedBy = isset($options['started_by']) && trim((string) $options['started_by']) !== ''
                ? self::limitText(trim((string) $options['started_by']), 150)
                : self::workerIdentifier();

            $db = self::db();
            $now = self::now();

            $select = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(self::$table))
                ->where($db->quoteName('status') . ' IN (' . $db->quote(self::STATUS_PENDING) . ', ' . $db->quote(self::STATUS_RETRY) . ')')
                ->where($db->quoteName('disponivel_em') . ' <= ' . $db->quote($now))
                ->order($db->quoteName('prioridade') . ' DESC')
                ->order($db->quoteName('id') . ' ASC');

            if ($queue !== null) {
                $select->where($db->quoteName('queue') . ' = ' . $db->quote($queue));
            }

            if (!empty($types)) {
                $select->where($db->quoteName('tipo') . ' IN (' . implode(', ', array_map([$db, 'quote'], $types)) . ')');
            }

            // Se outros workers levarem todos os candidatos, busca de novo:
            // null só quando a fila estiver realmente vazia.
            for ($rodada = 0; $rodada < 10; $rodada++) {
                $db->setQuery($select, 0, self::$claimCandidates);
                $candidates = (array) $db->loadColumn();

                if (empty($candidates)) {
                    return null;
                }

                $job = self::claimFirst($candidates, $startedBy);

                if ($job !== null) {
                    return $job;
                }
            }

            return null;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao reservar job da fila.', [
                'erro' => $error->getMessage(),
                'queue' => $queue,
                'types' => $allowedTypes,
            ]);

            return null;
        }
    }

    /*
     * Tenta reservar os candidatos em ordem; devolve o primeiro conseguido.
     */
    private static function claimFirst(array $candidates, $startedBy)
    {
        $db = self::db();
        $now = self::now();

        foreach ($candidates as $candidateId) {
            $lockToken = self::generateLockToken();

            // Só vale se ninguém reservou o job desde o SELECT acima.
            $update = $db->getQuery(true)
                ->update($db->quoteName(self::$table))
                ->set([
                    $db->quoteName('status') . ' = ' . $db->quote(self::STATUS_PROCESSING),
                    $db->quoteName('tentativas') . ' = ' . $db->quoteName('tentativas') . ' + 1',
                    $db->quoteName('iniciado_em') . ' = COALESCE(' . $db->quoteName('iniciado_em') . ', ' . $db->quote($now) . ')',
                    $db->quoteName('lock_token') . ' = ' . $db->quote($lockToken),
                    $db->quoteName('lock_em') . ' = ' . $db->quote($now),
                    $db->quoteName('last_heartbeat_at') . ' = ' . $db->quote($now),
                    $db->quoteName('started_by') . ' = ' . $db->quote($startedBy),
                    $db->quoteName('atualizado_em') . ' = ' . $db->quote($now),
                ])
                ->where($db->quoteName('id') . ' = ' . (int) $candidateId)
                ->where($db->quoteName('status') . ' IN (' . $db->quote(self::STATUS_PENDING) . ', ' . $db->quote(self::STATUS_RETRY) . ')');

            $db->setQuery($update);
            $db->execute();

            if ((int) $db->getAffectedRows() !== 1) {
                continue;
            }

            $job = self::findByIdInternal((int) $candidateId);

            if (!$job || $job->lock_token !== $lockToken) {
                continue;
            }

            self::log('info', 'Job reservado pelo worker.', [
                'job_id' => $job->id,
                'uuid' => $job->uuid,
                'queue' => $job->queue,
                'tipo' => $job->tipo,
                'tentativas' => $job->tentativas,
                'started_by' => $startedBy,
            ]);

            return $job;
        }

        return null;
    }

    /*
     * Avisa que o worker continua vivo e renova o lock. Devolve false se o
     * job não pertence mais a este worker (foi cancelado, ou o lock expirou
     * e outro worker o assumiu): nesse caso, pare o processamento.
     */
    public static function heartbeat($id, $lockToken)
    {
        self::$lastError = null;

        try {
            $now = self::now();

            return self::updateOwned($id, $lockToken, [
                'last_heartbeat_at' => $now,
                'lock_em' => $now,
                'atualizado_em' => $now,
            ]);
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('warning', 'Falha ao enviar heartbeat do job.', [
                'job_id' => is_scalar($id) ? $id : null,
                'erro' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /*
     * Atualiza o progresso (e renova o lock, como o heartbeat). Sem $total,
     * mantém o total atual. Devolve false nos mesmos casos do heartbeat.
     */
    public static function progress($id, $lockToken, $progresso, $total = null)
    {
        self::$lastError = null;

        try {
            $progresso = max(0, (float) $progresso);

            if ($total === null) {
                $job = self::findByIdInternal(self::normalizeId($id));
                $total = $job ? $job->total : 0;
            }

            $total = max(0, (float) $total);

            if ($total > 0 && $progresso > $total) {
                $progresso = $total;
            }

            $now = self::now();

            return self::updateOwned($id, $lockToken, [
                'progresso' => $progresso,
                'total' => $total,
                'percentual' => self::calculatePercentual($progresso, $total),
                'lock_em' => $now,
                'last_heartbeat_at' => $now,
                'atualizado_em' => $now,
            ]);
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('warning', 'Falha ao atualizar progresso do job.', [
                'job_id' => is_scalar($id) ? $id : null,
                'erro' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /*
     * Conclui o job com sucesso. $result fica disponível em status() e
     * find() (coluna resultado).
     */
    public static function complete($id, $lockToken, array $result = [])
    {
        self::$lastError = null;

        try {
            $job = self::lockedJob($id, $lockToken);
            $now = self::now();
            $total = (float) $job->total;

            $affected = self::updateById($job->id, [
                'status' => self::STATUS_COMPLETED,
                'progresso' => $total > 0 ? $total : (float) $job->progresso,
                'percentual' => $total > 0 ? 100 : (float) $job->percentual,
                'resultado' => self::jsonEncode($result, 'resultado', self::$maxResultLength),
                'erro' => null,
                'finalizado_em' => $now,
                'lock_token' => null,
                'lock_em' => null,
                'last_heartbeat_at' => $now,
                'atualizado_em' => $now,
            ], [
                'status' => self::STATUS_PROCESSING,
                'lock_token' => $job->lock_token,
            ]);

            if ($affected !== 1) {
                throw new RuntimeException('O job não pôde ser finalizado: o lock foi perdido.');
            }

            self::log('info', 'Job concluído.', [
                'job_id' => $job->id,
                'tipo' => $job->tipo,
                'tentativas' => $job->tentativas,
            ]);

            return true;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao concluir job.', [
                'job_id' => is_scalar($id) ? $id : null,
                'erro' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /*
     * Devolve o job à fila para continuar depois (processamento em fatias).
     * Por padrão a pausa NÃO conta como tentativa: um job pode ser liberado
     * quantas vezes precisar. O progresso é mantido.
     *
     * Opções:
     *   delay_seconds  só volta a ficar disponível daqui a N segundos (padrão 0)
     *   status         'pending' (padrão) ou 'retry'
     *   count_attempt  true: a execução conta como tentativa (ex.: serviço externo fora do ar)
     */
    public static function release($id, $lockToken, array $options = [])
    {
        self::$lastError = null;

        try {
            $job = self::lockedJob($id, $lockToken);

            $delaySeconds = isset($options['delay_seconds']) ? max(0, (int) $options['delay_seconds']) : 0;
            $status = isset($options['status']) ? trim((string) $options['status']) : self::STATUS_PENDING;
            $countAttempt = !empty($options['count_attempt']);

            if ($status !== self::STATUS_PENDING && $status !== self::STATUS_RETRY) {
                throw new InvalidArgumentException('Status inválido para liberar job.');
            }

            $db = self::db();
            $now = self::now();
            $availableAt = $delaySeconds > 0 ? self::dateFromNow($delaySeconds) : $now;

            $data = [
                'status' => $status,
                'disponivel_em' => $availableAt,
                'lock_token' => null,
                'lock_em' => null,
                'last_heartbeat_at' => null,
                'started_by' => null,
                'atualizado_em' => $now,
            ];

            if (!$countAttempt) {
                $column = $db->quoteName('tentativas');
                $data['tentativas'] = self::raw('CASE WHEN ' . $column . ' > 0 THEN ' . $column . ' - 1 ELSE 0 END');
            }

            $affected = self::updateById($job->id, $data, [
                'status' => self::STATUS_PROCESSING,
                'lock_token' => $job->lock_token,
            ]);

            if ($affected !== 1) {
                throw new RuntimeException('O job não pôde ser liberado: o lock foi perdido.');
            }

            self::log('info', 'Job liberado para próxima execução.', [
                'job_id' => $job->id,
                'status' => $status,
                'disponivel_em' => $availableAt,
                'conta_tentativa' => $countAttempt,
            ]);

            return true;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao liberar job.', [
                'job_id' => is_scalar($id) ? $id : null,
                'erro' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /*
     * Registra um erro. Se ainda houver tentativas, o job vai para 'retry'
     * e volta depois de uma espera crescente (30s, 60s, 120s… até 1h);
     * senão, vai para 'failed'.
     *
     * Opções:
     *   delay_seconds  espera fixa até a próxima tentativa
     *   permanent      true: falha de vez, sem novas tentativas (ex.: dado inválido)
     */
    public static function fail($id, $lockToken, $errorMessage, array $options = [])
    {
        self::$lastError = null;

        try {
            $errorMessage = self::limitText(trim((string) $errorMessage), self::$maxErrorLength);

            if ($errorMessage === '') {
                $errorMessage = 'Falha não especificada no processamento do job.';
            }

            $job = self::lockedJob($id, $lockToken);
            $permanent = !empty($options['permanent']);

            $status = $permanent || $job->tentativas >= $job->max_tentativas
                ? self::STATUS_FAILED
                : self::STATUS_RETRY;

            $now = self::now();
            $delaySeconds = isset($options['delay_seconds'])
                ? max(0, (int) $options['delay_seconds'])
                : self::calculateRetryDelay($job->tentativas);

            $availableAt = $status === self::STATUS_RETRY ? self::dateFromNow($delaySeconds) : $now;

            $affected = self::updateById($job->id, [
                'status' => $status,
                'erro' => $errorMessage,
                'disponivel_em' => $availableAt,
                'finalizado_em' => $status === self::STATUS_FAILED ? $now : null,
                'lock_token' => null,
                'lock_em' => null,
                'last_heartbeat_at' => null,
                'started_by' => null,
                'atualizado_em' => $now,
            ], [
                'status' => self::STATUS_PROCESSING,
                'lock_token' => $job->lock_token,
            ]);

            if ($affected !== 1) {
                throw new RuntimeException('O job não pôde ser marcado como falho: o lock foi perdido.');
            }

            self::log($status === self::STATUS_FAILED ? 'error' : 'warning', 'Job falhou durante processamento.', [
                'job_id' => $job->id,
                'tipo' => $job->tipo,
                'status' => $status,
                'tentativas' => $job->tentativas,
                'max_tentativas' => $job->max_tentativas,
                'disponivel_em' => $availableAt,
                'erro' => $errorMessage,
            ]);

            return true;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('critical', 'Falha ao registrar erro do job.', [
                'job_id' => is_scalar($id) ? $id : null,
                'erro' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /* ------------------------------------------------------------------
     * Gestão (telas, APIs, manutenção)
     * ------------------------------------------------------------------ */

    /*
     * Cancela um job pendente ou aguardando retry. Um job em processamento
     * só é cancelado com $force = true: o worker percebe no próximo
     * heartbeat/progress (que passam a devolver false) e não consegue mais
     * concluí-lo.
     *
     * Com $userId, só o dono do job pode cancelar.
     */
    public static function cancel($idOrUuid, $userId = null, $force = false)
    {
        self::$lastError = null;

        try {
            $job = self::findOrFail($idOrUuid);
            self::assertOwner($job, $userId, 'cancelar');

            $allowed = [self::STATUS_PENDING, self::STATUS_RETRY];

            if ($force) {
                $allowed[] = self::STATUS_PROCESSING;
            }

            if (!in_array($job->status, $allowed, true)) {
                throw new RuntimeException($job->status === self::STATUS_PROCESSING
                    ? 'Não é possível cancelar um job em processamento sem força.'
                    : 'Este job não pode mais ser cancelado.');
            }

            $now = self::now();

            $affected = self::updateById($job->id, [
                'status' => self::STATUS_CANCELLED,
                'finalizado_em' => $now,
                'lock_token' => null,
                'lock_em' => null,
                'last_heartbeat_at' => null,
                'started_by' => null,
                'atualizado_em' => $now,
            ], [
                'status' => $allowed,
            ]);

            if ($affected !== 1) {
                throw new RuntimeException('Não foi possível cancelar o job: o status mudou durante a operação.');
            }

            self::log('notice', 'Job cancelado.', [
                'job_id' => $job->id,
                'status_anterior' => $job->status,
                'usuario_id' => $userId,
            ]);

            return true;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('warning', 'Falha ao cancelar job.', [
                'identificador' => is_scalar($idOrUuid) ? $idOrUuid : null,
                'erro' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /*
     * Recoloca na fila um job falho ou cancelado, zerando as tentativas.
     * O progresso é mantido, para jobs que retomam de onde pararam.
     * Opção: reset_progress = true zera também o progresso.
     */
    public static function retry($idOrUuid, $userId = null, array $options = [])
    {
        self::$lastError = null;

        try {
            $job = self::findOrFail($idOrUuid);
            self::assertOwner($job, $userId, 'reenfileirar');

            $allowed = [self::STATUS_FAILED, self::STATUS_CANCELLED];

            if (!in_array($job->status, $allowed, true)) {
                throw new RuntimeException('Somente jobs falhos ou cancelados podem ser reenfileirados.');
            }

            $now = self::now();

            $data = [
                'status' => self::STATUS_PENDING,
                'tentativas' => 0,
                'erro' => null,
                'resultado' => null,
                'disponivel_em' => $now,
                'iniciado_em' => null,
                'finalizado_em' => null,
                'lock_token' => null,
                'lock_em' => null,
                'last_heartbeat_at' => null,
                'started_by' => null,
                'atualizado_em' => $now,
            ];

            if (!empty($options['reset_progress'])) {
                $data['progresso'] = 0;
                $data['percentual'] = 0;
            }

            $affected = self::updateById($job->id, $data, ['status' => $allowed]);

            if ($affected !== 1) {
                throw new RuntimeException('Não foi possível reenfileirar o job: o status mudou durante a operação.');
            }

            self::log('notice', 'Job reenfileirado manualmente.', [
                'job_id' => $job->id,
                'usuario_id' => $userId,
            ]);

            return true;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('warning', 'Falha ao reenfileirar job.', [
                'identificador' => is_scalar($idOrUuid) ? $idOrUuid : null,
                'erro' => $error->getMessage(),
            ]);

            return false;
        }
    }

    /*
     * Recupera jobs de workers que morreram (sem heartbeat há mais de
     * retry_after_seconds do job). Eles voltam para 'retry' ou, sem
     * tentativas restantes, vão para 'failed'. O worker chama este método
     * ao iniciar; chame também num cron se usar outro tipo de consumidor.
     *
     * $defaultRetryAfterSeconds só é usado para jobs gravados sem
     * retry_after_seconds. Devolve quantos jobs foram recuperados.
     */
    public static function releaseExpiredLocks($defaultRetryAfterSeconds = null)
    {
        self::$lastError = null;

        try {
            $defaultRetryAfterSeconds = $defaultRetryAfterSeconds !== null
                ? max(1, (int) $defaultRetryAfterSeconds)
                : self::$defaultRetryAfterSeconds;

            $db = self::db();
            $now = self::now();
            $nowUnix = self::toUnix($now);

            $query = $db->getQuery(true)
                ->select($db->quoteName(['id', 'tentativas', 'max_tentativas', 'retry_after_seconds', 'lock_token', 'lock_em', 'last_heartbeat_at', 'iniciado_em']))
                ->from($db->quoteName(self::$table))
                ->where($db->quoteName('status') . ' = ' . $db->quote(self::STATUS_PROCESSING));

            $db->setQuery($query);
            $jobs = (array) $db->loadObjectList();
            $released = 0;

            foreach ($jobs as $job) {
                $retryAfterSeconds = (int) $job->retry_after_seconds > 0
                    ? (int) $job->retry_after_seconds
                    : $defaultRetryAfterSeconds;

                $reference = !empty($job->last_heartbeat_at)
                    ? $job->last_heartbeat_at
                    : (!empty($job->lock_em) ? $job->lock_em : $job->iniciado_em);

                if (!empty($reference) && $nowUnix < self::toUnix($reference) + $retryAfterSeconds) {
                    continue;
                }

                $failed = (int) $job->tentativas >= (int) $job->max_tentativas;
                $status = $failed ? self::STATUS_FAILED : self::STATUS_RETRY;

                $affected = self::updateById((int) $job->id, [
                    'status' => $status,
                    'erro' => $failed
                        ? 'O worker parou de responder e o job não tem mais tentativas.'
                        : 'O worker parou de responder. Job liberado para nova tentativa.',
                    'disponivel_em' => $now,
                    'finalizado_em' => $failed ? $now : null,
                    'lock_token' => null,
                    'lock_em' => null,
                    'last_heartbeat_at' => null,
                    'started_by' => null,
                    'atualizado_em' => $now,
                ], [
                    'status' => self::STATUS_PROCESSING,
                    'lock_token' => $job->lock_token,
                ]);

                if ($affected === 1) {
                    $released++;

                    self::log($failed ? 'error' : 'warning', 'Job recuperado após lock expirado.', [
                        'job_id' => (int) $job->id,
                        'status' => $status,
                        'tentativas' => (int) $job->tentativas,
                        'max_tentativas' => (int) $job->max_tentativas,
                    ]);
                }
            }

            return $released;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao recuperar locks expirados.', ['erro' => $error->getMessage()]);

            return 0;
        }
    }

    /*
     * Busca um job pelo id ou uuid. Devolve o objeto completo (payload e
     * resultado já como array) ou null.
     */
    public static function find($idOrUuid)
    {
        try {
            if (is_int($idOrUuid) || (is_string($idOrUuid) && ctype_digit(trim($idOrUuid)))) {
                return self::findByIdInternal((int) $idOrUuid);
            }

            return self::findByUuidInternal($idOrUuid);
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao buscar job.', [
                'identificador' => is_scalar($idOrUuid) ? $idOrUuid : null,
                'erro' => $error->getMessage(),
            ]);

            return null;
        }
    }

    /*
     * Situação do job para mostrar ao usuário (sem payload e sem lock).
     * Com $userId, só o dono pode consultar.
     */
    public static function status($idOrUuid, $userId = null)
    {
        try {
            $job = self::find($idOrUuid);

            if (!$job) {
                return self::statusError('Job não encontrado.');
            }

            if (!self::isOwner($job, $userId)) {
                return self::statusError('Usuário sem permissão para consultar este job.');
            }

            return [
                'success' => true,
                'status' => 'sucesso',
                'mensagem' => 'Status do job carregado com sucesso.',
                'data' => [
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'queue' => $job->queue,
                    'tipo' => $job->tipo,
                    'status' => $job->status,
                    'finalizado' => in_array($job->status, [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED], true),
                    'prioridade' => $job->prioridade,
                    'progresso' => $job->progresso,
                    'total' => $job->total,
                    'percentual' => $job->percentual,
                    'tentativas' => $job->tentativas,
                    'max_tentativas' => $job->max_tentativas,
                    'timeout_seconds' => $job->timeout_seconds,
                    'retry_after_seconds' => $job->retry_after_seconds,
                    'disponivel_em' => $job->disponivel_em,
                    'iniciado_em' => $job->iniciado_em,
                    'finalizado_em' => $job->finalizado_em,
                    'started_by' => $job->started_by,
                    'usuario_id' => $job->usuario_id,
                    'resultado' => $job->resultado,
                    'erro' => $job->erro,
                    'criado_em' => $job->criado_em,
                    'atualizado_em' => $job->atualizado_em,
                ],
            ];
        } catch (Throwable $error) {
            self::registrarErro($error);

            return self::statusError('Não foi possível consultar o status do job.');
        }
    }

    /*
     * Lista jobs, do mais prioritário e mais novo para o mais antigo.
     * Filtros: queue, tipo, status (texto ou array), usuario_id, uuid,
     * date_start, date_end (criado_em, UTC).
     */
    public static function list(array $filters = [], $limit = 50, $offset = 0)
    {
        try {
            $limit = min(500, max(1, (int) $limit));
            $offset = max(0, (int) $offset);
            $db = self::db();

            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::$table))
                ->order($db->quoteName('prioridade') . ' DESC')
                ->order($db->quoteName('id') . ' DESC');

            self::applyFilters($query, $filters);

            $db->setQuery($query, $offset, $limit);
            $jobs = (array) $db->loadObjectList();

            foreach ($jobs as $key => $job) {
                $jobs[$key] = self::normalizeJob($job);
            }

            return $jobs;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao listar jobs.', ['erro' => $error->getMessage()]);

            return [];
        }
    }

    /*
     * Quantos jobs atendem aos filtros (os mesmos de list()).
     */
    public static function count(array $filters = [])
    {
        try {
            $db = self::db();

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(self::$table));

            self::applyFilters($query, $filters);

            $db->setQuery($query);

            return (int) $db->loadResult();
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao contar jobs.', ['erro' => $error->getMessage()]);

            return 0;
        }
    }

    /*
     * Quantidade de jobs por status, para painéis e monitoramento:
     * ['pending' => 3, 'processing' => 1, ..., 'total' => 40,
     *  'mais_antigo_pendente' => '2026-01-01 10:00:00' ou null].
     * Os filtros são os mesmos de list().
     */
    public static function stats(array $filters = [])
    {
        $stats = [
            self::STATUS_PENDING => 0,
            self::STATUS_PROCESSING => 0,
            self::STATUS_RETRY => 0,
            self::STATUS_COMPLETED => 0,
            self::STATUS_FAILED => 0,
            self::STATUS_CANCELLED => 0,
            'total' => 0,
            'mais_antigo_pendente' => null,
        ];

        try {
            $db = self::db();
            unset($filters['status']);

            $query = $db->getQuery(true)
                ->select($db->quoteName('status') . ', COUNT(*) AS ' . $db->quoteName('quantidade'))
                ->from($db->quoteName(self::$table))
                ->group($db->quoteName('status'));

            self::applyFilters($query, $filters);
            $db->setQuery($query);

            foreach ((array) $db->loadObjectList() as $row) {
                $stats[$row->status] = (int) $row->quantidade;
                $stats['total'] += (int) $row->quantidade;
            }

            $oldest = $db->getQuery(true)
                ->select('MIN(' . $db->quoteName('disponivel_em') . ')')
                ->from($db->quoteName(self::$table))
                ->where($db->quoteName('status') . ' IN (' . $db->quote(self::STATUS_PENDING) . ', ' . $db->quote(self::STATUS_RETRY) . ')');

            self::applyFilters($oldest, $filters);
            $db->setQuery($oldest);

            $value = $db->loadResult();
            $stats['mais_antigo_pendente'] = $value ? (string) $value : null;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao calcular estatísticas da fila.', ['erro' => $error->getMessage()]);
        }

        return $stats;
    }

    /*
     * Apaga jobs finalizados antes de uma data (UTC). Aceita
     * 'Y-m-d H:i:s', 'Y-m-d', DateTime, timestamp ou um texto relativo
     * como '-30 days'. Apaga no máximo $limit por chamada.
     */
    public static function purge($dateBefore, array $statuses = [], $limit = 1000)
    {
        self::$lastError = null;

        try {
            $dateBefore = self::normalizeDate($dateBefore, null, true);
            $limit = min(10000, max(1, (int) $limit));
            $allowedStatuses = [self::STATUS_COMPLETED, self::STATUS_FAILED, self::STATUS_CANCELLED];

            $statuses = empty($statuses)
                ? $allowedStatuses
                : array_values(array_intersect($statuses, $allowedStatuses));

            if (empty($statuses)) {
                throw new InvalidArgumentException('Nenhum status válido informado para limpeza.');
            }

            $db = self::db();

            $select = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(self::$table))
                ->where($db->quoteName('status') . ' IN (' . implode(', ', array_map([$db, 'quote'], $statuses)) . ')')
                ->where($db->quoteName('finalizado_em') . ' IS NOT NULL')
                ->where($db->quoteName('finalizado_em') . ' < ' . $db->quote($dateBefore))
                ->order($db->quoteName('id') . ' ASC');

            $db->setQuery($select, 0, $limit);
            $ids = array_map('intval', (array) $db->loadColumn());

            if (empty($ids)) {
                return 0;
            }

            $delete = $db->getQuery(true)
                ->delete($db->quoteName(self::$table))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');

            $db->setQuery($delete);
            $db->execute();

            $deleted = (int) $db->getAffectedRows();

            self::log('notice', 'Jobs antigos removidos.', [
                'quantidade' => $deleted,
                'data_limite' => $dateBefore,
                'statuses' => $statuses,
            ]);

            return $deleted;
        } catch (Throwable $error) {
            self::registrarErro($error);
            self::log('error', 'Falha ao remover jobs antigos.', ['erro' => $error->getMessage()]);

            return 0;
        }
    }

    /*
     * Cria a tabela da fila se ela ainda não existir (MySQL/MariaDB ou
     * PostgreSQL). Lança exceção em caso de erro: use na instalação.
     */
    public static function createTable()
    {
        $db = self::db();
        $prefixed = str_replace('#__', $db->getPrefix(), self::$table);

        if (in_array($prefixed, (array) $db->getTableList(), true)) {
            return false;
        }

        foreach (self::schemaStatements(self::isPostgres($db)) as $sql) {
            $db->setQuery($sql);
            $db->execute();
        }

        self::log('notice', 'Tabela da fila criada.', ['tabela' => $prefixed]);

        return true;
    }

    /* ------------------------------------------------------------------
     * Internos
     * ------------------------------------------------------------------ */

    private static function db()
    {
        if (is_object(self::$connection)) {
            return self::$connection;
        }

        if (is_string(self::$connection)) {
            return DbConnectionHelper::get(self::$connection);
        }

        // Joomla 4/5: pelo container (Factory::getDbo() está obsoleto).
        if (class_exists('Joomla\\CMS\\Factory') && method_exists('Joomla\\CMS\\Factory', 'getContainer')) {
            $container = call_user_func(['Joomla\\CMS\\Factory', 'getContainer']);

            if ($container->has('Joomla\\Database\\DatabaseInterface')) {
                return $container->get('Joomla\\Database\\DatabaseInterface');
            }
        }

        return call_user_func([self::factoryClass(), 'getDbo']);
    }

    private static function factoryClass()
    {
        return class_exists('Joomla\\CMS\\Factory') ? 'Joomla\\CMS\\Factory' : 'JFactory';
    }

    private static function logClass()
    {
        if (class_exists('Joomla\\CMS\\Log\\Log')) {
            return 'Joomla\\CMS\\Log\\Log';
        }

        return class_exists('JLog') ? 'JLog' : null;
    }

    private static function now()
    {
        return call_user_func([self::factoryClass(), 'getDate'])->toSql();
    }

    private static function dateFromNow($seconds)
    {
        return call_user_func([self::factoryClass(), 'getDate'], 'now +' . (int) $seconds . ' seconds')->toSql();
    }

    private static function toUnix($sqlDate)
    {
        return (int) call_user_func([self::factoryClass(), 'getDate'], (string) $sqlDate)->toUnix();
    }

    /*
     * Valor que entra cru no SET do UPDATE (expressão SQL).
     */
    private static function raw($sql)
    {
        $raw = new stdClass();
        $raw->sql = (string) $sql;

        return $raw;
    }

    private static function registrarErro($error)
    {
        self::$lastError = $error->getMessage();
    }

    private static function log($level, $message, array $context = [])
    {
        try {
            $log = self::logClass();

            if ($log === null) {
                return;
            }

            $levels = [
                'debug' => 'DEBUG',
                'info' => 'INFO',
                'notice' => 'NOTICE',
                'warning' => 'WARNING',
                'error' => 'ERROR',
                'critical' => 'CRITICAL',
            ];

            $level = strtolower((string) $level);
            $priority = constant($log . '::' . (isset($levels[$level]) ? $levels[$level] : 'INFO'));

            if (self::$logDirectory !== null) {
                self::configureLogger($log);
            }

            $contextText = '';

            if (!empty($context)) {
                $json = json_encode(self::sanitize($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($json !== false) {
                    $contextText = ' | Contexto: ' . self::limitText($json, 10000);
                }
            }

            call_user_func([$log, 'add'], self::limitText((string) $message . $contextText, 20000), $priority, self::$logCategory);
        } catch (Throwable $error) {
            // Log nunca pode derrubar a fila.
        }
    }

    /*
     * Registra o arquivo de log uma única vez por diretório e categoria.
     */
    private static function configureLogger($log)
    {
        $key = self::$logDirectory . '|' . self::$logCategory;

        if (isset(self::$loggersConfigurados[$key])) {
            return;
        }

        call_user_func(
            [$log, 'addLogger'],
            [
                'text_file' => self::$logCategory . '.php',
                'text_file_path' => self::$logDirectory,
                'text_entry_format' => '{DATETIME} {PRIORITY} {CATEGORY} {MESSAGE}',
            ],
            constant($log . '::ALL'),
            [self::$logCategory]
        );

        self::$loggersConfigurados[$key] = true;
    }

    private static function resolveLogDirectory($directory)
    {
        $directory = trim((string) $directory);

        if ($directory === '') {
            throw new InvalidArgumentException('Diretório de logs inválido.');
        }

        $siteRoot = realpath(JPATH_SITE);

        if ($siteRoot === false) {
            throw new RuntimeException('Não foi possível identificar JPATH_SITE.');
        }

        $siteRoot = self::normalizePath($siteRoot);
        $directory = str_replace('\\', '/', $directory);

        if (strpos($directory, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $directory)) {
            throw new RuntimeException('Diretório de logs inválido.');
        }

        if (strpos($directory, '/') !== 0 && !preg_match('#^[a-zA-Z]:/#', $directory)) {
            $directory = $siteRoot . '/' . trim($directory, '/');
        }

        $directory = self::normalizePath($directory);

        if ($directory !== $siteRoot && strpos($directory, $siteRoot . '/') !== 0) {
            throw new RuntimeException('Diretório de logs fora de JPATH_SITE.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar o diretório de logs.');
        }

        $realDirectory = realpath($directory);

        if ($realDirectory === false) {
            throw new RuntimeException('Não foi possível validar o diretório de logs.');
        }

        return self::normalizePath($realDirectory);
    }

    private static function normalizePath($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $path = preg_replace('#/+#', '/', $path);

        return rtrim($path, '/');
    }

    private static function limitText($value, $limit)
    {
        $value = (string) $value;
        $limit = (int) $limit;

        if (strlen($value) <= $limit) {
            return $value;
        }

        // Não corta um caractere UTF-8 ao meio.
        $cut = substr($value, 0, $limit);

        if (function_exists('mb_strcut')) {
            $cut = mb_strcut($value, 0, $limit, 'UTF-8');
        }

        return $cut . '...[truncado]';
    }

    private static function normalizeName($value, $field, $maxLength)
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new InvalidArgumentException('O campo ' . $field . ' é obrigatório.');
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $value)) {
            throw new InvalidArgumentException('O campo ' . $field . ' possui caracteres inválidos: use letras, números, "_", "." e "-".');
        }

        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException('O campo ' . $field . ' aceita no máximo ' . $maxLength . ' caracteres.');
        }

        return $value;
    }

    private static function normalizeQueue($queue = null)
    {
        if ($queue === null || trim((string) $queue) === '') {
            return self::$defaultQueue;
        }

        return self::normalizeName($queue, 'queue', 100);
    }

    private static function normalizeType($type)
    {
        return self::normalizeName($type, 'tipo', 150);
    }

    /*
     * Fila explícita > fila registrada no JobRegistry para o tipo > padrão.
     */
    private static function queueParaTipo($tipo, $queue)
    {
        if ($queue !== null && trim((string) $queue) !== '') {
            return self::normalizeQueue($queue);
        }

        if (class_exists('JobRegistry', false) && JobRegistry::has($tipo)) {
            return self::normalizeQueue(JobRegistry::getQueue($tipo));
        }

        return self::$defaultQueue;
    }

    private static function validarMaxTentativas($attempts)
    {
        $attempts = (int) $attempts;

        if ($attempts <= 0 || $attempts > 100) {
            throw new InvalidArgumentException('Máximo de tentativas inválido: use de 1 a 100.');
        }

        return $attempts;
    }

    private static function normalizeUuid($uuid)
    {
        $uuid = trim((string) $uuid);

        if (!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $uuid)) {
            throw new InvalidArgumentException('UUID de job inválido.');
        }

        return strtolower($uuid);
    }

    private static function normalizeId($id)
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            throw new InvalidArgumentException('ID do job inválido.');
        }

        return (int) $id;
    }

    private static function normalizeLockToken($lockToken)
    {
        $lockToken = trim((string) $lockToken);

        if (!preg_match('/^[a-f0-9]{32,128}$/i', $lockToken)) {
            throw new InvalidArgumentException('Token de lock inválido.');
        }

        return $lockToken;
    }

    /*
     * Datas sempre em UTC. Aceita 'Y-m-d H:i:s', 'Y-m-d', DateTime,
     * timestamp e, com $relativo, textos como '-30 days'.
     */
    private static function normalizeDate($date, $default = null, $relativo = false)
    {
        if ($date === null || (is_string($date) && trim($date) === '')) {
            return $default !== null ? $default : self::now();
        }

        if ($date instanceof DateTime || $date instanceof DateTimeInterface) {
            $copy = new DateTime('@' . $date->getTimestamp());

            return $copy->format('Y-m-d H:i:s');
        }

        if (is_int($date) || (is_string($date) && ctype_digit($date) && strlen($date) >= 9)) {
            return gmdate('Y-m-d H:i:s', (int) $date);
        }

        $date = trim((string) $date);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date .= ' 00:00:00';
        }

        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $date, new DateTimeZone('UTC'));

        if ($dateTime && $dateTime->format('Y-m-d H:i:s') === $date) {
            return $date;
        }

        if ($relativo && preg_match('/^[+-]?\s*\d+\s+[a-z]+(\s+ago)?$/i', $date)) {
            return call_user_func([self::factoryClass(), 'getDate'], $date)->toSql();
        }

        throw new InvalidArgumentException('Data inválida. Use o formato Y-m-d H:i:s (UTC).');
    }

    private static function generateUuid()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private static function generateLockToken()
    {
        return bin2hex(random_bytes(32));
    }

    /*
     * O payload e o resultado são gravados exatamente como recebidos: quem
     * mascara dados sensíveis é só o log.
     */
    private static function jsonEncode($data, $field, $maxLength)
    {
        if ($data === null) {
            return null;
        }

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);

        if ($json === false) {
            throw new RuntimeException('Não foi possível converter ' . $field . ' para JSON: ' . json_last_error_msg() . '.');
        }

        if (strlen($json) > $maxLength) {
            throw new RuntimeException('O conteúdo de ' . $field . ' excede o tamanho máximo permitido (' . $maxLength . ' bytes). Grave os dados num arquivo ou tabela e passe só a referência.');
        }

        return $json;
    }

    private static function jsonDecode($json)
    {
        if ($json === null || trim((string) $json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /*
     * Mascara chaves sensíveis. Usado apenas nos logs.
     */
    private static function sanitize($data)
    {
        $sensitiveKeys = [
            'password', 'senha', 'token', 'csrf', 'authorization', 'bearer',
            'api_key', 'apikey', 'secret', 'private_key', 'card_number',
            'numero_cartao', 'cvv', 'cvc', 'cookie', 'session',
        ];

        if (is_object($data)) {
            $data = (array) $data;
        }

        if (!is_array($data)) {
            return $data;
        }

        $sanitized = [];

        foreach ($data as $key => $value) {
            $keyNormalized = strtolower(str_replace(['-', ' '], '_', (string) $key));

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strpos($keyNormalized, $sensitiveKey) !== false) {
                    $sanitized[$key] = '****';

                    continue 2;
                }
            }

            $sanitized[$key] = is_array($value) || is_object($value) ? self::sanitize($value) : $value;
        }

        return $sanitized;
    }

    private static function normalizeJob($job)
    {
        if (!$job) {
            return null;
        }

        $job->id = (int) $job->id;
        $job->prioridade = (int) $job->prioridade;
        $job->progresso = (float) $job->progresso;
        $job->total = (float) $job->total;
        $job->percentual = (float) $job->percentual;
        $job->tentativas = (int) $job->tentativas;
        $job->max_tentativas = (int) $job->max_tentativas;
        $job->timeout_seconds = (int) $job->timeout_seconds;
        $job->retry_after_seconds = (int) $job->retry_after_seconds;
        $job->usuario_id = $job->usuario_id !== null ? (int) $job->usuario_id : null;
        $job->payload = self::jsonDecode($job->payload);
        $job->resultado = self::jsonDecode($job->resultado);

        return $job;
    }

    /*
     * UPDATE ... WHERE id = $id AND <condições>. Devolve as linhas
     * afetadas. Só use o retorno quando o UPDATE muda o status: no MySQL,
     * uma linha cujos valores não mudaram conta como 0.
     */
    private static function updateById($id, array $data, array $conditions = [])
    {
        $id = self::normalizeId($id);

        if (empty($data)) {
            return 0;
        }

        $db = self::db();
        $fields = [];

        foreach ($data as $column => $value) {
            if ($value === null) {
                $fields[] = $db->quoteName($column) . ' = NULL';
            } elseif (is_object($value) && isset($value->sql)) {
                $fields[] = $db->quoteName($column) . ' = ' . $value->sql;
            } elseif (is_int($value) || is_float($value)) {
                $fields[] = $db->quoteName($column) . ' = ' . self::sqlNumber($value);
            } else {
                $fields[] = $db->quoteName($column) . ' = ' . $db->quote((string) $value);
            }
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName(self::$table))
            ->set($fields)
            ->where($db->quoteName('id') . ' = ' . $id);

        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $query->where($db->quoteName($column) . ' IS NULL');
            } elseif (is_array($value)) {
                $query->where($db->quoteName($column) . ' IN (' . implode(', ', array_map([$db, 'quote'], $value)) . ')');
            } else {
                $query->where($db->quoteName($column) . ' = ' . $db->quote((string) $value));
            }
        }

        $db->setQuery($query);
        $db->execute();

        return (int) $db->getAffectedRows();
    }

    /*
     * Número sem notação científica e com ponto decimal, em qualquer locale.
     */
    private static function sqlNumber($value)
    {
        if (is_int($value)) {
            return (string) $value;
        }

        $text = rtrim(rtrim(number_format((float) $value, 4, '.', ''), '0'), '.');

        return $text === '' || $text === '-0' ? '0' : $text;
    }

    /*
     * Atualiza um job que está em processamento com este lock e confirma
     * relendo a linha (no MySQL as linhas afetadas não servem para isso:
     * dois heartbeats no mesmo segundo dariam 0).
     */
    private static function updateOwned($id, $lockToken, array $data)
    {
        $id = self::normalizeId($id);
        $lockToken = self::normalizeLockToken($lockToken);

        self::updateById($id, $data, [
            'status' => self::STATUS_PROCESSING,
            'lock_token' => $lockToken,
        ]);

        $db = self::db();
        $query = $db->getQuery(true)
            ->select('COUNT(*)')
            ->from($db->quoteName(self::$table))
            ->where($db->quoteName('id') . ' = ' . $id)
            ->where($db->quoteName('status') . ' = ' . $db->quote(self::STATUS_PROCESSING))
            ->where($db->quoteName('lock_token') . ' = ' . $db->quote($lockToken));

        $db->setQuery($query);

        if ((int) $db->loadResult() !== 1) {
            throw new RuntimeException('O job não está mais com este worker (cancelado, concluído ou lock expirado).');
        }

        return true;
    }

    /*
     * Carrega o job e confere se está em processamento com este lock.
     */
    private static function lockedJob($id, $lockToken)
    {
        $id = self::normalizeId($id);
        $lockToken = self::normalizeLockToken($lockToken);
        $job = self::findByIdInternal($id);

        if (!$job) {
            throw new RuntimeException('Job não encontrado.');
        }

        if ($job->status !== self::STATUS_PROCESSING) {
            throw new RuntimeException('O job não está em processamento (status atual: ' . $job->status . ').');
        }

        if (empty($job->lock_token) || !hash_equals((string) $job->lock_token, $lockToken)) {
            throw new RuntimeException('Token de lock inválido: o job pertence a outro worker.');
        }

        return $job;
    }

    private static function findOrFail($idOrUuid)
    {
        $job = is_int($idOrUuid) || (is_string($idOrUuid) && ctype_digit(trim($idOrUuid)))
            ? self::findByIdInternal((int) $idOrUuid)
            : self::findByUuidInternal($idOrUuid);

        if (!$job) {
            throw new RuntimeException('Job não encontrado.');
        }

        return $job;
    }

    private static function isOwner($job, $userId)
    {
        return $userId === null || (int) $userId <= 0 || (int) $job->usuario_id === (int) $userId;
    }

    private static function assertOwner($job, $userId, $acao)
    {
        if (!self::isOwner($job, $userId)) {
            throw new RuntimeException('Usuário sem permissão para ' . $acao . ' este job.');
        }
    }

    private static function findByIdInternal($id)
    {
        $id = self::normalizeId($id);
        $db = self::db();

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName(self::$table))
            ->where($db->quoteName('id') . ' = ' . $id);

        $db->setQuery($query);

        return self::normalizeJob($db->loadObject());
    }

    private static function findByUuidInternal($uuid)
    {
        $uuid = self::normalizeUuid($uuid);
        $db = self::db();

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName(self::$table))
            ->where($db->quoteName('uuid') . ' = ' . $db->quote($uuid));

        $db->setQuery($query);

        return self::normalizeJob($db->loadObject());
    }

    /*
     * Procura um job ativo (pendente, em retry ou em processamento) do
     * mesmo tipo e fila com o mesmo payload, ou com os mesmos valores nas
     * chaves indicadas. A comparação é feita no PHP para ser exata em
     * qualquer banco e collation.
     */
    private static function findActiveDuplicate($tipo, $queue, array $payload, $unico)
    {
        $keys = is_array($unico) ? array_values($unico) : null;
        $target = $keys === null ? $payload : self::onlyKeys($payload, $keys);
        $db = self::db();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'uuid', 'status', 'payload']))
            ->from($db->quoteName(self::$table))
            ->where($db->quoteName('tipo') . ' = ' . $db->quote($tipo))
            ->where($db->quoteName('queue') . ' = ' . $db->quote($queue))
            ->where($db->quoteName('status') . ' IN (' . implode(', ', array_map([$db, 'quote'], [self::STATUS_PENDING, self::STATUS_RETRY, self::STATUS_PROCESSING])) . ')')
            ->order($db->quoteName('id') . ' ASC');

        $db->setQuery($query, 0, 5000);

        foreach ((array) $db->loadObjectList() as $row) {
            $existing = self::jsonDecode($row->payload);

            if ($keys !== null) {
                $existing = self::onlyKeys($existing, $keys);
            }

            if ($existing == $target) {
                return $row;
            }
        }

        return null;
    }

    private static function onlyKeys(array $data, array $keys)
    {
        $result = [];

        foreach ($keys as $key) {
            $result[$key] = array_key_exists($key, $data) ? $data[$key] : null;
        }

        return $result;
    }

    private static function applyFilters($query, array $filters)
    {
        $db = self::db();

        if (isset($filters['queue']) && trim((string) $filters['queue']) !== '' && $filters['queue'] !== 'all') {
            $query->where($db->quoteName('queue') . ' = ' . $db->quote(self::normalizeQueue($filters['queue'])));
        }

        if (isset($filters['tipo']) && !is_array($filters['tipo']) && trim((string) $filters['tipo']) !== '') {
            $query->where($db->quoteName('tipo') . ' = ' . $db->quote(self::normalizeType($filters['tipo'])));
        }

        if (!empty($filters['status'])) {
            $statuses = array_filter(array_map('trim', array_map('strval', (array) $filters['status'])), 'strlen');

            if (!empty($statuses)) {
                $query->where($db->quoteName('status') . ' IN (' . implode(', ', array_map([$db, 'quote'], $statuses)) . ')');
            }
        }

        if (isset($filters['usuario_id']) && (int) $filters['usuario_id'] > 0) {
            $query->where($db->quoteName('usuario_id') . ' = ' . (int) $filters['usuario_id']);
        }

        if (isset($filters['uuid']) && trim((string) $filters['uuid']) !== '') {
            $query->where($db->quoteName('uuid') . ' = ' . $db->quote(self::normalizeUuid($filters['uuid'])));
        }

        if (!empty($filters['date_start'])) {
            $query->where($db->quoteName('criado_em') . ' >= ' . $db->quote(self::normalizeDate($filters['date_start'], null, true)));
        }

        if (!empty($filters['date_end'])) {
            $query->where($db->quoteName('criado_em') . ' <= ' . $db->quote(self::normalizeDate($filters['date_end'], null, true)));
        }
    }

    private static function pushResult($mensagem, $id, $uuid, $queue, $tipo, $status, $duplicado)
    {
        return [
            'success' => true,
            'status' => 'sucesso',
            'mensagem' => $mensagem,
            'data' => [
                'id' => $id,
                'uuid' => $uuid,
                'queue' => $queue,
                'tipo' => $tipo,
                'status' => $status,
                'duplicado' => $duplicado,
            ],
        ];
    }

    private static function statusError($mensagem)
    {
        return [
            'success' => false,
            'status' => 'erro',
            'mensagem' => $mensagem,
            'data' => [],
        ];
    }

    private static function calculatePercentual($progresso, $total)
    {
        $progresso = max(0, (float) $progresso);
        $total = max(0, (float) $total);

        if ($total <= 0) {
            return 0;
        }

        return min(100, round(($progresso / $total) * 100, 4));
    }

    /*
     * Espera antes da próxima tentativa: base × 2^(tentativa-1), com teto.
     */
    private static function calculateRetryDelay($attempt)
    {
        $attempt = max(1, (int) $attempt);
        $delay = self::$defaultRetryDelaySeconds * pow(2, min($attempt - 1, 20));

        return (int) min($delay, self::$maxRetryDelaySeconds);
    }

    private static function workerIdentifier()
    {
        $hostname = function_exists('gethostname') ? gethostname() : php_uname('n');

        return self::limitText(($hostname ?: 'unknown-host') . ':' . getmypid(), 150);
    }

    private static function isPostgres($db)
    {
        $name = strtolower((string) (method_exists($db, 'getServerType') ? $db->getServerType() : $db->getName()));

        return strpos($name, 'postgres') !== false || strpos(strtolower((string) $db->getName()), 'pgsql') !== false;
    }

    private static function schemaStatements($postgres)
    {
        $t = self::$table;
        $n = str_replace('#__', '', $t);

        if ($postgres) {
            return [
                'CREATE TABLE IF NOT EXISTS "' . $t . '" (
                    "id" BIGSERIAL NOT NULL,
                    "uuid" CHAR(36) NOT NULL,
                    "queue" VARCHAR(100) NOT NULL DEFAULT \'default\',
                    "tipo" VARCHAR(150) NOT NULL,
                    "payload" TEXT NULL,
                    "status" VARCHAR(30) NOT NULL DEFAULT \'pending\',
                    "prioridade" INTEGER NOT NULL DEFAULT 0,
                    "progresso" BIGINT NOT NULL DEFAULT 0,
                    "total" BIGINT NOT NULL DEFAULT 0,
                    "percentual" NUMERIC(7,4) NOT NULL DEFAULT 0,
                    "tentativas" INTEGER NOT NULL DEFAULT 0,
                    "max_tentativas" INTEGER NOT NULL DEFAULT 3,
                    "timeout_seconds" INTEGER NOT NULL DEFAULT 240,
                    "retry_after_seconds" INTEGER NOT NULL DEFAULT 300,
                    "disponivel_em" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
                    "iniciado_em" TIMESTAMP WITHOUT TIME ZONE NULL,
                    "finalizado_em" TIMESTAMP WITHOUT TIME ZONE NULL,
                    "lock_token" VARCHAR(100) NULL,
                    "lock_em" TIMESTAMP WITHOUT TIME ZONE NULL,
                    "last_heartbeat_at" TIMESTAMP WITHOUT TIME ZONE NULL,
                    "started_by" VARCHAR(150) NULL,
                    "usuario_id" INTEGER NULL,
                    "resultado" TEXT NULL,
                    "erro" TEXT NULL,
                    "criado_em" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
                    "atualizado_em" TIMESTAMP WITHOUT TIME ZONE NOT NULL,
                    PRIMARY KEY ("id")
                )',
                'CREATE UNIQUE INDEX IF NOT EXISTS "' . $t . '_idx_uuid" ON "' . $t . '" ("uuid")',
                'CREATE INDEX IF NOT EXISTS "' . $t . '_idx_dispatch" ON "' . $t . '" ("queue", "status", "disponivel_em", "prioridade", "id")',
                'CREATE INDEX IF NOT EXISTS "' . $t . '_idx_status" ON "' . $t . '" ("status", "disponivel_em", "prioridade", "id")',
                'CREATE INDEX IF NOT EXISTS "' . $t . '_idx_tipo" ON "' . $t . '" ("tipo", "status", "disponivel_em")',
                'CREATE INDEX IF NOT EXISTS "' . $t . '_idx_usuario" ON "' . $t . '" ("usuario_id", "criado_em")',
                'CREATE INDEX IF NOT EXISTS "' . $t . '_idx_finalizado" ON "' . $t . '" ("finalizado_em")',
            ];
        }

        return [
            'CREATE TABLE IF NOT EXISTS `' . $t . '` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `uuid` CHAR(36) NOT NULL,
                `queue` VARCHAR(100) NOT NULL DEFAULT \'default\',
                `tipo` VARCHAR(150) NOT NULL,
                `payload` LONGTEXT NULL,
                `status` VARCHAR(30) NOT NULL DEFAULT \'pending\',
                `prioridade` INT NOT NULL DEFAULT 0,
                `progresso` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `total` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `percentual` DECIMAL(7,4) NOT NULL DEFAULT 0.0000,
                `tentativas` INT UNSIGNED NOT NULL DEFAULT 0,
                `max_tentativas` INT UNSIGNED NOT NULL DEFAULT 3,
                `timeout_seconds` INT UNSIGNED NOT NULL DEFAULT 240,
                `retry_after_seconds` INT UNSIGNED NOT NULL DEFAULT 300,
                `disponivel_em` DATETIME NOT NULL,
                `iniciado_em` DATETIME NULL DEFAULT NULL,
                `finalizado_em` DATETIME NULL DEFAULT NULL,
                `lock_token` VARCHAR(100) NULL DEFAULT NULL,
                `lock_em` DATETIME NULL DEFAULT NULL,
                `last_heartbeat_at` DATETIME NULL DEFAULT NULL,
                `started_by` VARCHAR(150) NULL DEFAULT NULL,
                `usuario_id` INT UNSIGNED NULL DEFAULT NULL,
                `resultado` LONGTEXT NULL,
                `erro` LONGTEXT NULL,
                `criado_em` DATETIME NOT NULL,
                `atualizado_em` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `idx_' . $n . '_uuid` (`uuid`),
                KEY `idx_' . $n . '_dispatch` (`queue`, `status`, `disponivel_em`, `prioridade`, `id`),
                KEY `idx_' . $n . '_status` (`status`, `disponivel_em`, `prioridade`, `id`),
                KEY `idx_' . $n . '_tipo` (`tipo`, `status`, `disponivel_em`),
                KEY `idx_' . $n . '_usuario` (`usuario_id`, `criado_em`),
                KEY `idx_' . $n . '_finalizado` (`finalizado_em`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        ];
    }
}
