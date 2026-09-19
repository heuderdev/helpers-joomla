<?php

defined('_JEXEC') or die;

class QueueHelper
{
    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_RETRY = 'retry';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    const STATUS_CANCELLED = 'cancelled';

    private static $table = '#__queue_jobs';

    private static $logCategory = 'queue_helper';

    private static $logDirectory = null;

    private static $defaultQueue = 'default';

    private static $defaultMaxAttempts = 3;

    private static $defaultTimeoutSeconds = 240;

    private static $defaultRetryAfterSeconds = 300;

    private static $defaultRetryDelaySeconds = 30;

    private static $maxPayloadLength = 10485760;

    private static $maxResultLength = 10485760;

    private static $maxErrorLength = 50000;

    private static function db()
    {
        return JFactory::getDbo();
    }

    private static function now()
    {
        return JFactory::getDate()->toSql();
    }

    private static function log($level, $message, array $context = array())
    {
        try {
            $priority = JLog::INFO;

            switch (strtolower((string) $level)) {
                case 'debug':
                    $priority = JLog::DEBUG;
                    break;

                case 'notice':
                    $priority = JLog::NOTICE;
                    break;

                case 'warning':
                    $priority = JLog::WARNING;
                    break;

                case 'error':
                    $priority = JLog::ERROR;
                    break;

                case 'critical':
                    $priority = JLog::CRITICAL;
                    break;
            }

            $contextText = '';

            if (!empty($context)) {
                $json = json_encode(
                    self::sanitize($context),
                    JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                );

                if ($json !== false) {
                    $contextText = ' | Contexto: ' .
                        self::limitText(
                            $json,
                            10000
                        );
                }
            }

            if (
                self::$logDirectory !== null &&
                self::$logDirectory !== ''
            ) {
                self::configureLogger(
                    self::$logDirectory,
                    $priority
                );
            }

            JLog::add(
                self::limitText(
                    (string) $message . $contextText,
                    20000
                ),
                $priority,
                self::$logCategory
            );
        } catch (Throwable $error) {
        }
    }

    private static function configureLogger($directory, $priority)
    {
        $directory = self::resolveLogDirectory($directory);

        JLog::addLogger(
            array(
                'text_file' => self::$logCategory . '.php',
                'text_file_path' => $directory,
                'text_entry_format' => '{DATETIME} {PRIORITY} {CATEGORY} {MESSAGE}'
            ),
            $priority,
            array(self::$logCategory)
        );
    }

    private static function resolveLogDirectory($directory)
    {
        $directory = trim((string) $directory);

        if ($directory === '') {
            throw new InvalidArgumentException(
                'Diretório de logs inválido.'
            );
        }

        $siteRoot = realpath(JPATH_SITE);

        if ($siteRoot === false) {
            throw new RuntimeException(
                'Não foi possível identificar JPATH_SITE.'
            );
        }

        $siteRoot = self::normalizePath($siteRoot);
        $directory = str_replace('\\', '/', $directory);

        if (
            strpos($directory, "\0") !== false ||
            strpos($directory, '../') !== false ||
            strpos($directory, '..\\') !== false
        ) {
            throw new RuntimeException(
                'Diretório de logs inválido.'
            );
        }

        if (strpos($directory, '/') !== 0) {
            $directory = $siteRoot .
                '/' .
                trim($directory, '/');
        }

        $directory = self::normalizePath($directory);

        if (
            $directory !== $siteRoot &&
            strpos($directory, $siteRoot . '/') !== 0
        ) {
            throw new RuntimeException(
                'Diretório de logs fora de JPATH_SITE.'
            );
        }

        if (!is_dir($directory)) {
            if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException(
                    'Não foi possível criar o diretório de logs.'
                );
            }
        }

        $realDirectory = realpath($directory);

        if ($realDirectory === false) {
            throw new RuntimeException(
                'Não foi possível validar o diretório de logs.'
            );
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

        return substr($value, 0, $limit) . '...[truncado]';
    }

    private static function normalizeName($value, $field, $maxLength = 150)
    {
        $value = trim((string) $value);

        if ($value === '') {
            throw new InvalidArgumentException(
                'O campo ' . $field . ' é obrigatório.'
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $value)) {
            throw new InvalidArgumentException(
                'O campo ' . $field . ' possui caracteres inválidos.'
            );
        }

        return self::limitText(
            $value,
            $maxLength
        );
    }

    private static function normalizeQueue($queue = null)
    {
        if ($queue === null || trim((string) $queue) === '') {
            return self::$defaultQueue;
        }

        return self::normalizeName(
            $queue,
            'queue',
            100
        );
    }

    private static function normalizeType($type)
    {
        return self::normalizeName(
            $type,
            'tipo',
            150
        );
    }

    private static function normalizeUuid($uuid)
    {
        $uuid = trim((string) $uuid);

        if (
            !preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
                $uuid
            )
        ) {
            throw new InvalidArgumentException(
                'UUID de job inválido.'
            );
        }

        return strtolower($uuid);
    }

    private static function normalizeId($id)
    {
        if (!is_numeric($id) || (int) $id <= 0) {
            throw new InvalidArgumentException(
                'ID do job inválido.'
            );
        }

        return (int) $id;
    }

    private static function normalizeLockToken($lockToken)
    {
        $lockToken = trim((string) $lockToken);

        if (
            $lockToken === '' ||
            !preg_match('/^[a-f0-9]{32,128}$/i', $lockToken)
        ) {
            throw new InvalidArgumentException(
                'Token de lock inválido.'
            );
        }

        return $lockToken;
    }

    private static function generateUuid()
    {
        if (function_exists('random_bytes')) {
            $bytes = random_bytes(16);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(16);
        } else {
            $bytes = false;
        }

        if ($bytes === false) {
            $hash = md5(
                uniqid('', true) .
                    mt_rand()
            );

            return substr($hash, 0, 8) .
                '-' .
                substr($hash, 8, 4) .
                '-4' .
                substr($hash, 13, 3) .
                '-8' .
                substr($hash, 17, 3) .
                '-' .
                substr($hash, 20, 12);
        }

        $bytes[6] = chr(
            (ord($bytes[6]) & 0x0f) | 0x40
        );

        $bytes[8] = chr(
            (ord($bytes[8]) & 0x3f) | 0x80
        );

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }

    private static function generateLockToken()
    {
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes(32));
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(32);

            if ($bytes !== false) {
                return bin2hex($bytes);
            }
        }

        return hash(
            'sha256',
            uniqid('', true) .
                mt_rand() .
                microtime(true)
        );
    }

    private static function jsonEncode($data, $field, $maxLength)
    {
        if ($data === null) {
            return null;
        }

        if (is_string($data)) {
            $decoded = json_decode($data, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                $data = $decoded;
            } else {
                $data = array(
                    'value' => $data
                );
            }
        }

        $json = json_encode(
            self::sanitize($data),
            JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(
                'Não foi possível converter ' .
                    $field .
                    ' para JSON.'
            );
        }

        if (strlen($json) > $maxLength) {
            throw new RuntimeException(
                'O conteúdo de ' .
                    $field .
                    ' excede o tamanho máximo permitido.'
            );
        }

        return $json;
    }

    private static function jsonDecode($json)
    {
        if ($json === null || trim((string) $json) === '') {
            return array();
        }

        $decoded = json_decode(
            $json,
            true
        );

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($decoded)
        ) {
            return array();
        }

        return $decoded;
    }

    private static function sanitize($data)
    {
        $sensitiveKeys = array(
            'password',
            'senha',
            'token',
            'csrf',
            'authorization',
            'bearer',
            'api_key',
            'apikey',
            'secret',
            'client_secret',
            'access_token',
            'refresh_token',
            'private_key',
            'card_number',
            'numero_cartao',
            'cvv',
            'cvc',
            'cookie',
            'session'
        );

        if (is_object($data)) {
            $data = (array) $data;
        }

        if (!is_array($data)) {
            return $data;
        }

        $sanitized = array();

        foreach ($data as $key => $value) {
            $keyNormalized = strtolower(
                str_replace(
                    array('-', ' '),
                    '_',
                    (string) $key
                )
            );

            $sensitive = false;

            foreach ($sensitiveKeys as $sensitiveKey) {
                if (strpos($keyNormalized, $sensitiveKey) !== false) {
                    $sensitive = true;

                    break;
                }
            }

            if ($sensitive) {
                $sanitized[$key] = '****';

                continue;
            }

            if (is_array($value) || is_object($value)) {
                $sanitized[$key] = self::sanitize($value);

                continue;
            }

            $sanitized[$key] = $value;
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
        $job->usuario_id = $job->usuario_id !== null
            ? (int) $job->usuario_id
            : null;
        $job->payload = self::jsonDecode($job->payload);
        $job->resultado = self::jsonDecode($job->resultado);

        return $job;
    }

    private static function updateById($id, array $data, array $conditions = array())
    {
        $id = self::normalizeId($id);

        if (empty($data)) {
            return 0;
        }

        $db = self::db();
        $fields = array();

        foreach ($data as $column => $value) {
            if ($value === null) {
                $fields[] = $db->quoteName($column) . ' = NULL';

                continue;
            }

            if (is_int($value) || is_float($value)) {
                $fields[] = $db->quoteName($column) .
                    ' = ' .
                    $db->quote($value);

                continue;
            }

            $fields[] = $db->quoteName($column) .
                ' = ' .
                $db->quote((string) $value);
        }

        $query = $db->getQuery(true)
            ->update($db->quoteName(self::$table))
            ->set($fields)
            ->where(
                $db->quoteName('id') .
                    ' = ' .
                    $id
            );

        foreach ($conditions as $column => $value) {
            if ($value === null) {
                $query->where(
                    $db->quoteName($column) .
                        ' IS NULL'
                );

                continue;
            }

            if (is_array($value)) {
                $items = array();

                foreach ($value as $item) {
                    $items[] = $db->quote($item);
                }

                if (!empty($items)) {
                    $query->where(
                        $db->quoteName($column) .
                            ' IN (' .
                            implode(',', $items) .
                            ')'
                    );
                }

                continue;
            }

            $query->where(
                $db->quoteName($column) .
                    ' = ' .
                    $db->quote((string) $value)
            );
        }

        $db->setQuery($query);
        $db->execute();

        return (int) $db->getAffectedRows();
    }

    private static function findByIdInternal($id)
    {
        $id = self::normalizeId($id);
        $db = self::db();

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName(self::$table))
            ->where(
                $db->quoteName('id') .
                    ' = ' .
                    $id
            );

        $db->setQuery($query);

        return self::normalizeJob(
            $db->loadObject()
        );
    }

    private static function findByUuidInternal($uuid)
    {
        $uuid = self::normalizeUuid($uuid);
        $db = self::db();

        $query = $db->getQuery(true)
            ->select('*')
            ->from($db->quoteName(self::$table))
            ->where(
                $db->quoteName('uuid') .
                    ' = ' .
                    $db->quote($uuid)
            );

        $db->setQuery($query);

        return self::normalizeJob(
            $db->loadObject()
        );
    }

    private static function calculatePercentual($progresso, $total)
    {
        $progresso = max(0, (float) $progresso);
        $total = max(0, (float) $total);

        if ($total <= 0) {
            return 0;
        }

        return min(
            100,
            round(
                ($progresso / $total) * 100,
                4
            )
        );
    }

    private static function calculateRetryDelay($attempt)
    {
        $attempt = max(1, (int) $attempt);

        $delay = self::$defaultRetryDelaySeconds *
            pow(2, $attempt - 1);

        return min(
            (int) $delay,
            3600
        );
    }

    private static function getRetryStatus($job)
    {
        if (
            (int) $job->tentativas >=
            (int) $job->max_tentativas
        ) {
            return self::STATUS_FAILED;
        }

        return self::STATUS_RETRY;
    }

    private static function assertLock($job, $lockToken)
    {
        if (!$job) {
            throw new RuntimeException(
                'Job não encontrado.'
            );
        }

        if ($job->status !== self::STATUS_PROCESSING) {
            throw new RuntimeException(
                'O job não está em processamento.'
            );
        }

        $lockToken = self::normalizeLockToken($lockToken);

        if (
            empty($job->lock_token) ||
            !hash_equals(
                (string) $job->lock_token,
                $lockToken
            )
        ) {
            throw new RuntimeException(
                'Token de lock inválido.'
            );
        }

        return true;
    }

    private static function normalizeDate($date, $default = null)
    {
        if ($date === null || trim((string) $date) === '') {
            return $default !== null
                ? $default
                : self::now();
        }

        $date = trim((string) $date);

        $dateTime = DateTime::createFromFormat(
            'Y-m-d H:i:s',
            $date
        );

        if (
            !$dateTime ||
            $dateTime->format('Y-m-d H:i:s') !== $date
        ) {
            throw new InvalidArgumentException(
                'Data inválida. Use o formato Y-m-d H:i:s.'
            );
        }

        return $date;
    }

    private static function workerIdentifier()
    {
        $hostname = function_exists('gethostname')
            ? gethostname()
            : php_uname('n');

        $hostname = $hostname
            ? $hostname
            : 'unknown-host';

        return self::limitText(
            $hostname .
                ':' .
                getmypid(),
            150
        );
    }

    public static function setTable($table)
    {
        $table = trim((string) $table);

        if (
            $table === '' ||
            !preg_match('/^[#_a-zA-Z0-9]+$/', $table)
        ) {
            throw new InvalidArgumentException(
                'Nome de tabela inválido.'
            );
        }

        self::$table = $table;
    }

    public static function setDefaultQueue($queue)
    {
        self::$defaultQueue = self::normalizeQueue(
            $queue
        );
    }

    public static function setLogCategory($category)
    {
        $category = trim((string) $category);

        if ($category === '') {
            throw new InvalidArgumentException(
                'Categoria de log inválida.'
            );
        }

        self::$logCategory = preg_replace(
            '/[^a-zA-Z0-9_.-]/',
            '_',
            $category
        );
    }

    public static function setLogDirectory($directory)
    {
        self::$logDirectory = self::resolveLogDirectory(
            $directory
        );

        return self::$logDirectory;
    }

    public static function setDefaultMaxAttempts($attempts)
    {
        $attempts = (int) $attempts;

        if ($attempts <= 0 || $attempts > 100) {
            throw new InvalidArgumentException(
                'Quantidade máxima de tentativas inválida.'
            );
        }

        self::$defaultMaxAttempts = $attempts;
    }

    public static function setDefaultTimeoutSeconds($seconds)
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException(
                'Timeout padrão inválido.'
            );
        }

        self::$defaultTimeoutSeconds = $seconds;
    }

    public static function setDefaultRetryAfterSeconds($seconds)
    {
        $seconds = (int) $seconds;

        if ($seconds <= 0) {
            throw new InvalidArgumentException(
                'Retry after padrão inválido.'
            );
        }

        self::$defaultRetryAfterSeconds = $seconds;
    }

    public static function setDefaultRetryDelaySeconds($seconds)
    {
        $seconds = (int) $seconds;

        if ($seconds < 0) {
            throw new InvalidArgumentException(
                'Delay de retry inválido.'
            );
        }

        self::$defaultRetryDelaySeconds = $seconds;
    }

    public static function push($tipo, array $payload = array(), array $options = array())
    {
        try {
            $tipo = self::normalizeType($tipo);

            $queue = self::normalizeQueue(
                isset($options['queue'])
                    ? $options['queue']
                    : null
            );

            $maxAttempts = isset($options['max_tentativas'])
                ? (int) $options['max_tentativas']
                : self::$defaultMaxAttempts;

            $timeoutSeconds = isset($options['timeout_seconds'])
                ? (int) $options['timeout_seconds']
                : self::$defaultTimeoutSeconds;

            $retryAfterSeconds = isset($options['retry_after_seconds'])
                ? (int) $options['retry_after_seconds']
                : self::$defaultRetryAfterSeconds;

            $prioridade = isset($options['prioridade'])
                ? (int) $options['prioridade']
                : 0;

            $progresso = isset($options['progresso'])
                ? max(0, (float) $options['progresso'])
                : 0;

            $total = isset($options['total'])
                ? max(0, (float) $options['total'])
                : 0;

            $usuarioId = isset($options['usuario_id'])
                ? (int) $options['usuario_id']
                : 0;

            $disponivelEm = self::normalizeDate(
                isset($options['disponivel_em'])
                    ? $options['disponivel_em']
                    : null
            );

            if ($maxAttempts <= 0 || $maxAttempts > 100) {
                throw new InvalidArgumentException(
                    'Máximo de tentativas inválido.'
                );
            }

            if ($timeoutSeconds <= 0) {
                throw new InvalidArgumentException(
                    'Timeout do job inválido.'
                );
            }

            if ($retryAfterSeconds <= 0) {
                throw new InvalidArgumentException(
                    'Retry after do job inválido.'
                );
            }

            $uuid = self::generateUuid();
            $now = self::now();
            $db = self::db();

            $job = new stdClass();

            $job->uuid = $uuid;
            $job->queue = $queue;
            $job->tipo = $tipo;
            $job->payload = self::jsonEncode(
                $payload,
                'payload',
                self::$maxPayloadLength
            );
            $job->status = self::STATUS_PENDING;
            $job->prioridade = $prioridade;
            $job->progresso = $progresso;
            $job->total = $total;
            $job->percentual = self::calculatePercentual(
                $progresso,
                $total
            );
            $job->tentativas = 0;
            $job->max_tentativas = $maxAttempts;
            $job->timeout_seconds = $timeoutSeconds;
            $job->retry_after_seconds = $retryAfterSeconds;
            $job->disponivel_em = $disponivelEm;
            $job->iniciado_em = null;
            $job->finalizado_em = null;
            $job->lock_token = null;
            $job->lock_em = null;
            $job->last_heartbeat_at = null;
            $job->started_by = null;
            $job->usuario_id = $usuarioId > 0
                ? $usuarioId
                : null;
            $job->resultado = null;
            $job->erro = null;
            $job->criado_em = $now;
            $job->atualizado_em = $now;

            $db->insertObject(
                self::$table,
                $job,
                'id'
            );

            self::log(
                'info',
                'Job adicionado à fila.',
                array(
                    'job_id' => (int) $job->id,
                    'uuid' => $uuid,
                    'queue' => $queue,
                    'tipo' => $tipo,
                    'prioridade' => $prioridade,
                    'usuario_id' => $usuarioId > 0
                        ? $usuarioId
                        : null
                )
            );

            return array(
                'success' => true,
                'status' => 'sucesso',
                'mensagem' => 'Job adicionado à fila com sucesso.',
                'data' => array(
                    'id' => (int) $job->id,
                    'uuid' => $uuid,
                    'queue' => $queue,
                    'tipo' => $tipo,
                    'status' => self::STATUS_PENDING
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao adicionar job à fila.',
                array(
                    'erro' => $error->getMessage(),
                    'tipo' => $tipo
                )
            );

            return array(
                'success' => false,
                'status' => 'erro',
                'mensagem' => 'Não foi possível adicionar o job à fila.',
                'data' => array()
            );
        }
    }

    public static function find($idOrUuid)
    {
        try {
            if (is_numeric($idOrUuid)) {
                return self::findByIdInternal(
                    (int) $idOrUuid
                );
            }

            return self::findByUuidInternal(
                $idOrUuid
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao buscar job.',
                array(
                    'identificador' => $idOrUuid,
                    'erro' => $error->getMessage()
                )
            );

            return null;
        }
    }

    public static function next(array $allowedTypes = array(), $queue = null, array $options = array())
    {
        $db = null;
        $transactionStarted = false;

        try {
            $queue = $queue !== null &&
                trim((string) $queue) !== '' &&
                $queue !== 'all'
                ? self::normalizeQueue($queue)
                : null;

            $now = self::now();
            $lockToken = self::generateLockToken();
            $startedBy = isset($options['started_by'])
                ? self::limitText(
                    $options['started_by'],
                    150
                )
                : self::workerIdentifier();

            $db = self::db();

            $db->transactionStart();
            $transactionStarted = true;

            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::$table))
                ->where(
                    '(' .
                        $db->quoteName('status') .
                        ' = ' .
                        $db->quote(self::STATUS_PENDING) .
                        ' OR ' .
                        $db->quoteName('status') .
                        ' = ' .
                        $db->quote(self::STATUS_RETRY) .
                        ')'
                )
                ->where(
                    $db->quoteName('disponivel_em') .
                        ' <= ' .
                        $db->quote($now)
                )
                ->order(
                    $db->quoteName('prioridade') .
                        ' DESC'
                )
                ->order(
                    $db->quoteName('id') .
                        ' ASC'
                );

            if ($queue !== null) {
                $query->where(
                    $db->quoteName('queue') .
                        ' = ' .
                        $db->quote($queue)
                );
            }

            if (!empty($allowedTypes)) {
                $types = array();

                foreach ($allowedTypes as $type) {
                    $types[] = $db->quote(
                        self::normalizeType($type)
                    );
                }

                if (!empty($types)) {
                    $query->where(
                        $db->quoteName('tipo') .
                            ' IN (' .
                            implode(',', $types) .
                            ')'
                    );
                }
            }

            $db->setQuery($query, 0, 1);

            $job = $db->loadObject();

            if (!$job) {
                $db->transactionCommit();
                $transactionStarted = false;

                return null;
            }

            $attempts = (int) $job->tentativas + 1;

            $update = $db->getQuery(true)
                ->update($db->quoteName(self::$table))
                ->set(
                    $db->quoteName('status') .
                        ' = ' .
                        $db->quote(self::STATUS_PROCESSING)
                )
                ->set(
                    $db->quoteName('tentativas') .
                        ' = ' .
                        $attempts
                )
                ->set(
                    $db->quoteName('iniciado_em') .
                        ' = ' .
                        $db->quote($now)
                )
                ->set(
                    $db->quoteName('lock_token') .
                        ' = ' .
                        $db->quote($lockToken)
                )
                ->set(
                    $db->quoteName('lock_em') .
                        ' = ' .
                        $db->quote($now)
                )
                ->set(
                    $db->quoteName('last_heartbeat_at') .
                        ' = ' .
                        $db->quote($now)
                )
                ->set(
                    $db->quoteName('started_by') .
                        ' = ' .
                        $db->quote($startedBy)
                )
                ->set(
                    $db->quoteName('erro') .
                        ' = NULL'
                )
                ->set(
                    $db->quoteName('atualizado_em') .
                        ' = ' .
                        $db->quote($now)
                )
                ->where(
                    $db->quoteName('id') .
                        ' = ' .
                        (int) $job->id
                )
                ->where(
                    '(' .
                        $db->quoteName('status') .
                        ' = ' .
                        $db->quote(self::STATUS_PENDING) .
                        ' OR ' .
                        $db->quoteName('status') .
                        ' = ' .
                        $db->quote(self::STATUS_RETRY) .
                        ')'
                );

            $db->setQuery($update);
            $db->execute();

            if ((int) $db->getAffectedRows() !== 1) {
                $db->transactionRollback();
                $transactionStarted = false;

                return null;
            }

            $db->transactionCommit();
            $transactionStarted = false;

            $reservedJob = self::findByIdInternal(
                (int) $job->id
            );

            if (!$reservedJob) {
                throw new RuntimeException(
                    'Job reservado não encontrado.'
                );
            }

            $reservedJob->lock_token = $lockToken;

            self::log(
                'info',
                'Job reservado pelo worker.',
                array(
                    'job_id' => $reservedJob->id,
                    'uuid' => $reservedJob->uuid,
                    'queue' => $reservedJob->queue,
                    'tipo' => $reservedJob->tipo,
                    'tentativas' => $reservedJob->tentativas,
                    'started_by' => $startedBy
                )
            );

            return $reservedJob;
        } catch (Throwable $error) {
            if ($transactionStarted && $db) {
                try {
                    $db->transactionRollback();
                } catch (Throwable $rollbackError) {
                }
            }

            self::log(
                'error',
                'Falha ao reservar job da fila.',
                array(
                    'erro' => $error->getMessage(),
                    'queue' => $queue,
                    'types' => $allowedTypes
                )
            );

            return null;
        }
    }

    public static function heartbeat($id, $lockToken)
    {
        try {
            $id = self::normalizeId($id);
            $lockToken = self::normalizeLockToken($lockToken);
            $job = self::findByIdInternal($id);

            self::assertLock($job, $lockToken);

            $now = self::now();

            $affected = self::updateById(
                $id,
                array(
                    'last_heartbeat_at' => $now,
                    'lock_em' => $now,
                    'atualizado_em' => $now
                ),
                array(
                    'status' => self::STATUS_PROCESSING,
                    'lock_token' => $lockToken
                )
            );

            return $affected === 1;
        } catch (Throwable $error) {
            self::log(
                'warning',
                'Falha ao enviar heartbeat do job.',
                array(
                    'job_id' => $id,
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function progress($id, $lockToken, $progresso, $total = null)
    {
        try {
            $id = self::normalizeId($id);
            $lockToken = self::normalizeLockToken($lockToken);
            $progresso = max(0, (float) $progresso);

            $job = self::findByIdInternal($id);

            self::assertLock($job, $lockToken);

            if ($total === null) {
                $total = $job->total;
            }

            $total = max(0, (float) $total);

            if ($total > 0 && $progresso > $total) {
                $progresso = $total;
            }

            $percentual = self::calculatePercentual(
                $progresso,
                $total
            );

            $now = self::now();

            $affected = self::updateById(
                $id,
                array(
                    'progresso' => $progresso,
                    'total' => $total,
                    'percentual' => $percentual,
                    'lock_em' => $now,
                    'last_heartbeat_at' => $now,
                    'atualizado_em' => $now
                ),
                array(
                    'status' => self::STATUS_PROCESSING,
                    'lock_token' => $lockToken
                )
            );

            return $affected === 1;
        } catch (Throwable $error) {
            self::log(
                'warning',
                'Falha ao atualizar progresso do job.',
                array(
                    'job_id' => $id,
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function complete($id, $lockToken, array $result = array())
    {
        try {
            $id = self::normalizeId($id);
            $lockToken = self::normalizeLockToken($lockToken);

            $job = self::findByIdInternal($id);

            self::assertLock($job, $lockToken);

            $now = self::now();
            $progresso = (float) $job->progresso;
            $total = (float) $job->total;

            if ($total > 0) {
                $progresso = $total;
            }

            $affected = self::updateById(
                $id,
                array(
                    'status' => self::STATUS_COMPLETED,
                    'progresso' => $progresso,
                    'percentual' => $total > 0 ? 100 : $job->percentual,
                    'resultado' => self::jsonEncode(
                        $result,
                        'resultado',
                        self::$maxResultLength
                    ),
                    'erro' => null,
                    'finalizado_em' => $now,
                    'lock_token' => null,
                    'lock_em' => null,
                    'last_heartbeat_at' => $now,
                    'atualizado_em' => $now
                ),
                array(
                    'status' => self::STATUS_PROCESSING,
                    'lock_token' => $lockToken
                )
            );

            if ($affected !== 1) {
                throw new RuntimeException(
                    'O job não pôde ser finalizado.'
                );
            }

            self::log(
                'info',
                'Job concluído.',
                array(
                    'job_id' => $id,
                    'resultado' => $result
                )
            );

            return true;
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao concluir job.',
                array(
                    'job_id' => $id,
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function release($id, $lockToken, array $options = array())
    {
        try {
            $id = self::normalizeId($id);
            $lockToken = self::normalizeLockToken($lockToken);

            $job = self::findByIdInternal($id);

            self::assertLock($job, $lockToken);

            $delaySeconds = isset($options['delay_seconds'])
                ? max(0, (int) $options['delay_seconds'])
                : 0;

            $status = isset($options['status'])
                ? trim((string) $options['status'])
                : self::STATUS_PENDING;

            if (
                $status !== self::STATUS_PENDING &&
                $status !== self::STATUS_RETRY
            ) {
                throw new InvalidArgumentException(
                    'Status inválido para liberar job.'
                );
            }

            $availableAt = $delaySeconds > 0
                ? JFactory::getDate(
                    'now +' .
                        $delaySeconds .
                        ' seconds'
                )->toSql()
                : self::now();

            $now = self::now();

            $affected = self::updateById(
                $id,
                array(
                    'status' => $status,
                    'disponivel_em' => $availableAt,
                    'lock_token' => null,
                    'lock_em' => null,
                    'last_heartbeat_at' => null,
                    'started_by' => null,
                    'atualizado_em' => $now
                ),
                array(
                    'status' => self::STATUS_PROCESSING,
                    'lock_token' => $lockToken
                )
            );

            if ($affected !== 1) {
                throw new RuntimeException(
                    'O job não pôde ser liberado.'
                );
            }

            self::log(
                'info',
                'Job liberado para próxima execução.',
                array(
                    'job_id' => $id,
                    'status' => $status,
                    'disponivel_em' => $availableAt,
                    'delay_seconds' => $delaySeconds
                )
            );

            return true;
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao liberar job.',
                array(
                    'job_id' => $id,
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function fail($id, $lockToken, $errorMessage, array $options = array())
    {
        try {
            $id = self::normalizeId($id);
            $lockToken = self::normalizeLockToken($lockToken);
            $errorMessage = self::limitText(
                trim((string) $errorMessage),
                self::$maxErrorLength
            );

            if ($errorMessage === '') {
                $errorMessage = 'Falha não especificada no processamento do job.';
            }

            $job = self::findByIdInternal($id);

            self::assertLock($job, $lockToken);

            $status = self::getRetryStatus($job);
            $now = self::now();

            $delaySeconds = isset($options['delay_seconds'])
                ? max(0, (int) $options['delay_seconds'])
                : self::calculateRetryDelay(
                    $job->tentativas
                );

            $availableAt = $status === self::STATUS_RETRY
                ? JFactory::getDate(
                    'now +' .
                        $delaySeconds .
                        ' seconds'
                )->toSql()
                : $now;

            $affected = self::updateById(
                $id,
                array(
                    'status' => $status,
                    'erro' => $errorMessage,
                    'disponivel_em' => $availableAt,
                    'finalizado_em' => $status === self::STATUS_FAILED
                        ? $now
                        : null,
                    'lock_token' => null,
                    'lock_em' => null,
                    'last_heartbeat_at' => null,
                    'started_by' => null,
                    'atualizado_em' => $now
                ),
                array(
                    'status' => self::STATUS_PROCESSING,
                    'lock_token' => $lockToken
                )
            );

            if ($affected !== 1) {
                throw new RuntimeException(
                    'O job não pôde ser marcado como falho.'
                );
            }

            self::log(
                $status === self::STATUS_FAILED
                    ? 'error'
                    : 'warning',
                'Job falhou durante processamento.',
                array(
                    'job_id' => $id,
                    'status' => $status,
                    'tentativas' => $job->tentativas,
                    'max_tentativas' => $job->max_tentativas,
                    'disponivel_em' => $availableAt,
                    'erro' => $errorMessage
                )
            );

            return true;
        } catch (Throwable $error) {
            self::log(
                'critical',
                'Falha ao registrar erro do job.',
                array(
                    'job_id' => $id,
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function cancel($idOrUuid, $userId = null, $force = false)
    {
        try {
            $job = self::find($idOrUuid);

            if (!$job) {
                throw new RuntimeException(
                    'Job não encontrado.'
                );
            }

            if (
                $job->status === self::STATUS_COMPLETED ||
                $job->status === self::STATUS_FAILED ||
                $job->status === self::STATUS_CANCELLED
            ) {
                throw new RuntimeException(
                    'Este job não pode mais ser cancelado.'
                );
            }

            if (
                $job->status === self::STATUS_PROCESSING &&
                !$force
            ) {
                throw new RuntimeException(
                    'Não é possível cancelar um job em processamento sem força.'
                );
            }

            if (
                $userId !== null &&
                (int) $userId > 0 &&
                (int) $job->usuario_id !== (int) $userId
            ) {
                throw new RuntimeException(
                    'Usuário sem permissão para cancelar este job.'
                );
            }

            $now = self::now();

            $affected = self::updateById(
                $job->id,
                array(
                    'status' => self::STATUS_CANCELLED,
                    'finalizado_em' => $now,
                    'lock_token' => null,
                    'lock_em' => null,
                    'last_heartbeat_at' => null,
                    'started_by' => null,
                    'atualizado_em' => $now
                )
            );

            if ($affected !== 1) {
                throw new RuntimeException(
                    'Não foi possível cancelar o job.'
                );
            }

            self::log(
                'notice',
                'Job cancelado.',
                array(
                    'job_id' => $job->id,
                    'usuario_id' => $userId
                )
            );

            return true;
        } catch (Throwable $error) {
            self::log(
                'warning',
                'Falha ao cancelar job.',
                array(
                    'identificador' => $idOrUuid,
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function retry($idOrUuid, $userId = null)
    {
        try {
            $job = self::find($idOrUuid);

            if (!$job) {
                throw new RuntimeException(
                    'Job não encontrado.'
                );
            }

            if (
                $job->status !== self::STATUS_FAILED &&
                $job->status !== self::STATUS_CANCELLED
            ) {
                throw new RuntimeException(
                    'Somente jobs falhos ou cancelados podem ser reenfileirados.'
                );
            }

            if (
                $userId !== null &&
                (int) $userId > 0 &&
                (int) $job->usuario_id !== (int) $userId
            ) {
                throw new RuntimeException(
                    'Usuário sem permissão para reenfileirar este job.'
                );
            }

            $now = self::now();

            $affected = self::updateById(
                $job->id,
                array(
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
                    'atualizado_em' => $now
                )
            );

            if ($affected !== 1) {
                throw new RuntimeException(
                    'Não foi possível reenfileirar o job.'
                );
            }

            self::log(
                'notice',
                'Job reenfileirado manualmente.',
                array(
                    'job_id' => $job->id,
                    'usuario_id' => $userId
                )
            );

            return true;
        } catch (Throwable $error) {
            self::log(
                'warning',
                'Falha ao reenfileirar job.',
                array(
                    'identificador' => $idOrUuid,
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function releaseExpiredLocks($defaultRetryAfterSeconds = null)
    {
        try {
            $defaultRetryAfterSeconds = $defaultRetryAfterSeconds !== null
                ? max(1, (int) $defaultRetryAfterSeconds)
                : self::$defaultRetryAfterSeconds;

            $db = self::db();
            $now = self::now();

            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::$table))
                ->where(
                    $db->quoteName('status') .
                        ' = ' .
                        $db->quote(self::STATUS_PROCESSING)
                )
                ->where(
                    $db->quoteName('lock_em') .
                        ' IS NOT NULL'
                );

            $db->setQuery($query);

            $jobs = $db->loadObjectList();

            if (empty($jobs)) {
                return 0;
            }

            $released = 0;

            foreach ($jobs as $job) {
                $retryAfterSeconds = (int) $job->retry_after_seconds > 0
                    ? (int) $job->retry_after_seconds
                    : $defaultRetryAfterSeconds;

                $referenceDate = !empty($job->last_heartbeat_at)
                    ? $job->last_heartbeat_at
                    : $job->lock_em;

                $expiredAt = JFactory::getDate(
                    $referenceDate
                )->toUnix() + $retryAfterSeconds;

                if (time() < $expiredAt) {
                    continue;
                }

                $attempts = (int) $job->tentativas;
                $maxAttempts = (int) $job->max_tentativas;

                $status = $attempts >= $maxAttempts
                    ? self::STATUS_FAILED
                    : self::STATUS_RETRY;

                $message = $status === self::STATUS_FAILED
                    ? 'Job excedeu o limite de tentativas após expiração de lock.'
                    : 'Lock do worker expirou. Job liberado para nova tentativa.';

                $affected = self::updateById(
                    (int) $job->id,
                    array(
                        'status' => $status,
                        'erro' => $message,
                        'disponivel_em' => $now,
                        'finalizado_em' => $status === self::STATUS_FAILED
                            ? $now
                            : null,
                        'lock_token' => null,
                        'lock_em' => null,
                        'last_heartbeat_at' => null,
                        'started_by' => null,
                        'atualizado_em' => $now
                    ),
                    array(
                        'status' => self::STATUS_PROCESSING,
                        'lock_token' => $job->lock_token
                    )
                );

                if ($affected === 1) {
                    $released++;

                    self::log(
                        $status === self::STATUS_FAILED
                            ? 'error'
                            : 'warning',
                        'Job recuperado após lock expirado.',
                        array(
                            'job_id' => (int) $job->id,
                            'status' => $status,
                            'tentativas' => $attempts,
                            'max_tentativas' => $maxAttempts
                        )
                    );
                }
            }

            return $released;
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao recuperar locks expirados.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return 0;
        }
    }

    public static function status($idOrUuid, $userId = null)
    {
        try {
            $job = self::find($idOrUuid);

            if (!$job) {
                return array(
                    'success' => false,
                    'status' => 'erro',
                    'mensagem' => 'Job não encontrado.',
                    'data' => array()
                );
            }

            if (
                $userId !== null &&
                (int) $userId > 0 &&
                (int) $job->usuario_id !== (int) $userId
            ) {
                return array(
                    'success' => false,
                    'status' => 'erro',
                    'mensagem' => 'Usuário sem permissão para consultar este job.',
                    'data' => array()
                );
            }

            return array(
                'success' => true,
                'status' => 'sucesso',
                'mensagem' => 'Status do job carregado com sucesso.',
                'data' => array(
                    'id' => $job->id,
                    'uuid' => $job->uuid,
                    'queue' => $job->queue,
                    'tipo' => $job->tipo,
                    'status' => $job->status,
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
                    'atualizado_em' => $job->atualizado_em
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao consultar status de job.',
                array(
                    'identificador' => $idOrUuid,
                    'erro' => $error->getMessage()
                )
            );

            return array(
                'success' => false,
                'status' => 'erro',
                'mensagem' => 'Não foi possível consultar o status do job.',
                'data' => array()
            );
        }
    }

    public static function list(array $filters = array(), $limit = 50, $offset = 0)
    {
        try {
            $limit = min(500, max(1, (int) $limit));
            $offset = max(0, (int) $offset);
            $db = self::db();

            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::$table))
                ->order(
                    $db->quoteName('prioridade') .
                        ' DESC'
                )
                ->order(
                    $db->quoteName('id') .
                        ' DESC'
                );

            if (
                isset($filters['queue']) &&
                trim((string) $filters['queue']) !== ''
            ) {
                $query->where(
                    $db->quoteName('queue') .
                        ' = ' .
                        $db->quote(
                            self::normalizeQueue(
                                $filters['queue']
                            )
                        )
                );
            }

            if (
                isset($filters['tipo']) &&
                trim((string) $filters['tipo']) !== ''
            ) {
                $query->where(
                    $db->quoteName('tipo') .
                        ' = ' .
                        $db->quote(
                            self::normalizeType(
                                $filters['tipo']
                            )
                        )
                );
            }

            if (
                isset($filters['status']) &&
                trim((string) $filters['status']) !== ''
            ) {
                $query->where(
                    $db->quoteName('status') .
                        ' = ' .
                        $db->quote(
                            trim((string) $filters['status'])
                        )
                );
            }

            if (
                isset($filters['usuario_id']) &&
                (int) $filters['usuario_id'] > 0
            ) {
                $query->where(
                    $db->quoteName('usuario_id') .
                        ' = ' .
                        (int) $filters['usuario_id']
                );
            }

            if (
                isset($filters['uuid']) &&
                trim((string) $filters['uuid']) !== ''
            ) {
                $query->where(
                    $db->quoteName('uuid') .
                        ' = ' .
                        $db->quote(
                            self::normalizeUuid(
                                $filters['uuid']
                            )
                        )
                );
            }

            if (
                isset($filters['date_start']) &&
                trim((string) $filters['date_start']) !== ''
            ) {
                $query->where(
                    $db->quoteName('criado_em') .
                        ' >= ' .
                        $db->quote(
                            self::normalizeDate(
                                $filters['date_start']
                            )
                        )
                );
            }

            if (
                isset($filters['date_end']) &&
                trim((string) $filters['date_end']) !== ''
            ) {
                $query->where(
                    $db->quoteName('criado_em') .
                        ' <= ' .
                        $db->quote(
                            self::normalizeDate(
                                $filters['date_end']
                            )
                        )
                );
            }

            $db->setQuery(
                $query,
                $offset,
                $limit
            );

            $jobs = $db->loadObjectList();

            if (!is_array($jobs)) {
                return array();
            }

            foreach ($jobs as $key => $job) {
                $jobs[$key] = self::normalizeJob($job);
            }

            return $jobs;
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao listar jobs.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return array();
        }
    }

    public static function count(array $filters = array())
    {
        try {
            $db = self::db();

            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName(self::$table));

            if (
                isset($filters['queue']) &&
                trim((string) $filters['queue']) !== ''
            ) {
                $query->where(
                    $db->quoteName('queue') .
                        ' = ' .
                        $db->quote(
                            self::normalizeQueue(
                                $filters['queue']
                            )
                        )
                );
            }

            if (
                isset($filters['tipo']) &&
                trim((string) $filters['tipo']) !== ''
            ) {
                $query->where(
                    $db->quoteName('tipo') .
                        ' = ' .
                        $db->quote(
                            self::normalizeType(
                                $filters['tipo']
                            )
                        )
                );
            }

            if (
                isset($filters['status']) &&
                trim((string) $filters['status']) !== ''
            ) {
                $query->where(
                    $db->quoteName('status') .
                        ' = ' .
                        $db->quote(
                            trim((string) $filters['status'])
                        )
                );
            }

            if (
                isset($filters['usuario_id']) &&
                (int) $filters['usuario_id'] > 0
            ) {
                $query->where(
                    $db->quoteName('usuario_id') .
                        ' = ' .
                        (int) $filters['usuario_id']
                );
            }

            $db->setQuery($query);

            return (int) $db->loadResult();
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao contar jobs.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return 0;
        }
    }

    public static function purge($dateBefore, array $statuses = array(), $limit = 1000)
    {
        try {
            $dateBefore = self::normalizeDate($dateBefore);
            $limit = min(10000, max(1, (int) $limit));

            if (empty($statuses)) {
                $statuses = array(
                    self::STATUS_COMPLETED,
                    self::STATUS_FAILED,
                    self::STATUS_CANCELLED
                );
            }

            $allowedStatuses = array(
                self::STATUS_COMPLETED,
                self::STATUS_FAILED,
                self::STATUS_CANCELLED
            );

            $statuses = array_values(
                array_intersect(
                    $statuses,
                    $allowedStatuses
                )
            );

            if (empty($statuses)) {
                throw new InvalidArgumentException(
                    'Nenhum status válido informado para limpeza.'
                );
            }

            $db = self::db();
            $statusValues = array();

            foreach ($statuses as $status) {
                $statusValues[] = $db->quote($status);
            }

            $select = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(self::$table))
                ->where(
                    $db->quoteName('status') .
                        ' IN (' .
                        implode(',', $statusValues) .
                        ')'
                )
                ->where(
                    $db->quoteName('finalizado_em') .
                        ' IS NOT NULL'
                )
                ->where(
                    $db->quoteName('finalizado_em') .
                        ' < ' .
                        $db->quote($dateBefore)
                )
                ->order(
                    $db->quoteName('id') .
                        ' ASC'
                );

            $db->setQuery(
                $select,
                0,
                $limit
            );

            $ids = $db->loadColumn();

            if (empty($ids)) {
                return 0;
            }

            $ids = array_map(
                'intval',
                $ids
            );

            $delete = $db->getQuery(true)
                ->delete($db->quoteName(self::$table))
                ->where(
                    $db->quoteName('id') .
                        ' IN (' .
                        implode(',', $ids) .
                        ')'
                );

            $db->setQuery($delete);
            $db->execute();

            $deleted = (int) $db->getAffectedRows();

            self::log(
                'notice',
                'Jobs antigos removidos.',
                array(
                    'quantidade' => $deleted,
                    'data_limite' => $dateBefore,
                    'statuses' => $statuses
                )
            );

            return $deleted;
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao remover jobs antigos.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return 0;
        }
    }
}
