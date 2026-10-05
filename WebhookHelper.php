<?php

defined('_JEXEC') or die;

/*
 * Recebe webhooks (gateway de pagamento, ERP, WhatsApp, marketplace):
 * confere a assinatura (ou o token), descarta reenvios do mesmo evento,
 * guarda o evento e entrega para a fila ou para uma função, respondendo
 * rápido com o status HTTP que o remetente entende.
 *
 *   public function webhookPagamento()
 *   {
 *       WebhookHelper::handle(array(
 *           'provider' => 'gateway',
 *           'secret' => $params->get('webhook_secret'),
 *           'signature_header' => 'X-Signature',
 *           'job' => 'processar_pagamento',      // QueueHelper: o job lê WebhookHelper::payload($id)
 *       ));
 *   }
 *
 * A tabela #__helpers_webhooks é criada sozinha (ou rode tables/webhookHelper.sql).
 */

class WebhookHelper
{
    const STATUS_RECEIVED = 'recebido';

    const STATUS_QUEUED = 'na_fila';

    const STATUS_PROCESSED = 'processado';

    const STATUS_FAILED = 'falhou';

    private static $table = '#__helpers_webhooks';

    private static $connection = null;

    private static $tableReady = false;

    /*
     * Fluxo completo. Opções:
     *  provider          nome do remetente (obrigatório; separa ids de remetentes diferentes)
     *  secret            segredo do HMAC + signature_header (ex.: 'X-Signature'),
     *                    prefix ('sha256='), encoding ('hex'|'base64'), algo ('sha256')
     *  token / token_header   alternativa: token fixo num cabeçalho (comparado em tempo constante)
     *  verify            alternativa: function ($body, $headers) => bool
     *  id_path           caminho do id do evento no JSON (padrão 'id'; sem id usa o hash do corpo)
     *  type_path         caminho do tipo do evento (padrão 'event', depois 'type')
     *  job               tipo do job do QueueHelper (recebe array('webhook_id' => ...))
     *  job_options       opções do QueueHelper::push()
     *  handler           function ($payload, $record) executada na hora (no lugar do job)
     *  respond           false: não responde (só devolve o resultado)
     *  body / headers    para testes
     *
     * Devolve array('status' => 'queued'|'processed'|'duplicate'|'unauthorized'|'invalid'|'error',
     * 'http' => código respondido, 'webhook_id' => id gravado, 'event_id', 'type').
     */
    public static function handle(array $options)
    {
        $result = self::process($options);

        if (!isset($options['respond']) || $options['respond']) {
            self::respond($result);
        }

        return $result;
    }

    /*
     * O mesmo fluxo de handle(), sem responder.
     */
    public static function process(array $options)
    {
        $provider = isset($options['provider']) ? trim((string) $options['provider']) : '';
        $body = array_key_exists('body', $options) ? (string) $options['body'] : (string) file_get_contents('php://input');
        $headers = isset($options['headers']) ? self::normalizeHeaders($options['headers']) : self::requestHeaders();

        if ($provider === '') {
            return self::result('error', 500, 'Webhook sem "provider" configurado.');
        }

        if (!self::verify($body, $headers, $options)) {
            self::log('warning', 'Webhook com assinatura inválida.', array('provider' => $provider, 'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null));

            return self::result('unauthorized', 401, 'Assinatura inválida.');
        }

        $payload = json_decode($body, true);

        if (!is_array($payload)) {
            // Alguns remetentes mandam formulário (application/x-www-form-urlencoded).
            parse_str($body, $payload);

            if (!is_array($payload) || !$payload) {
                return self::result('invalid', 400, 'Corpo do webhook inválido.');
            }
        }

        $eventId = self::path($payload, isset($options['id_path']) ? $options['id_path'] : 'id');
        $eventId = $eventId === null || $eventId === '' ? 'sha256:' . hash('sha256', $body) : (string) $eventId;
        $type = isset($options['type_path']) ? self::path($payload, $options['type_path']) : self::path($payload, 'event');
        $type = $type === null ? self::path($payload, 'type') : $type;
        $type = is_scalar($type) ? (string) $type : '';

        try {
            $record = self::store($provider, $eventId, $type, $body);
        } catch (Throwable $error) {
            self::log('error', 'Falha ao gravar webhook: ' . $error->getMessage(), array('provider' => $provider, 'event_id' => $eventId));

            // 500: o remetente tenta de novo mais tarde.
            return self::result('error', 500, 'Falha ao registrar o evento.', null, $eventId, $type);
        }

        if ($record['duplicate'] && $record['status'] !== self::STATUS_FAILED && $record['status'] !== self::STATUS_RECEIVED) {
            return self::result('duplicate', 200, 'Evento já recebido.', $record['id'], $eventId, $type);
        }

        $id = $record['id'];

        try {
            if (!empty($options['job'])) {
                if (!class_exists('QueueHelper')) {
                    throw new RuntimeException('Carregue o QueueHelper para usar a opção "job".');
                }

                $push = QueueHelper::push((string) $options['job'], array('webhook_id' => $id), isset($options['job_options']) ? (array) $options['job_options'] : array());

                if (is_array($push) && isset($push['success']) && !$push['success']) {
                    throw new RuntimeException(isset($push['mensagem']) ? $push['mensagem'] : 'Falha ao enfileirar.');
                }

                self::setStatus($id, self::STATUS_QUEUED);

                return self::result('queued', 200, 'Evento recebido.', $id, $eventId, $type);
            }

            if (isset($options['handler']) && is_callable($options['handler'])) {
                call_user_func($options['handler'], $payload, self::find($id));
                self::markProcessed($id);

                return self::result('processed', 200, 'Evento processado.', $id, $eventId, $type);
            }

            return self::result('processed', 200, 'Evento recebido.', $id, $eventId, $type);
        } catch (Throwable $error) {
            self::markFailed($id, $error->getMessage());
            self::log('error', 'Falha ao processar webhook: ' . $error->getMessage(), array('provider' => $provider, 'webhook_id' => $id, 'event_id' => $eventId));

            return self::result('error', 500, 'Falha ao processar o evento.', $id, $eventId, $type);
        }
    }

    /*
     * Confere a autenticidade (secret+signature_header, token+token_header
     * ou verify). Sem nenhuma das três, recusa: webhook aberto é convite
     * a pedidos falsos marcados como pagos.
     */
    public static function verify($body, array $headers, array $options)
    {
        $headers = self::normalizeHeaders($headers);

        if (isset($options['verify']) && is_callable($options['verify'])) {
            return (bool) call_user_func($options['verify'], $body, $headers);
        }

        if (!empty($options['secret']) && !empty($options['signature_header'])) {
            $signature = self::header($headers, $options['signature_header']);

            if (!class_exists('CryptoHelper')) {
                $algo = isset($options['algo']) ? $options['algo'] : 'sha256';
                $raw = hash_hmac($algo, $body, (string) $options['secret'], true);
                $prefix = isset($options['prefix']) ? (string) $options['prefix'] : '';
                $expected = $prefix . ((isset($options['encoding']) && $options['encoding'] === 'base64') ? base64_encode($raw) : bin2hex($raw));

                return $signature !== '' && hash_equals($expected, $signature);
            }

            return CryptoHelper::verifySignature($body, $signature, (string) $options['secret'], array(
                'algo' => isset($options['algo']) ? $options['algo'] : 'sha256',
                'encoding' => isset($options['encoding']) ? $options['encoding'] : 'hex',
                'prefix' => isset($options['prefix']) ? $options['prefix'] : ''
            ));
        }

        if (!empty($options['token']) && !empty($options['token_header'])) {
            $given = self::header($headers, $options['token_header']);

            return $given !== '' && hash_equals((string) $options['token'], $given);
        }

        return false;
    }

    /*
     * Payload (array) de um webhook gravado: use dentro do job.
     */
    public static function payload($webhookId)
    {
        $record = self::find($webhookId);

        if (!$record) {
            return null;
        }

        $payload = json_decode($record['payload'], true);

        if (!is_array($payload)) {
            parse_str($record['payload'], $payload);
        }

        return is_array($payload) ? $payload : null;
    }

    public static function find($webhookId)
    {
        $db = self::db();
        self::ensureTable($db);

        $db->setQuery('SELECT * FROM ' . $db->quoteName(self::$table) . ' WHERE id = ' . (int) $webhookId);
        $row = $db->loadAssoc();

        return is_array($row) ? $row : null;
    }

    public static function markProcessed($webhookId)
    {
        return self::setStatus($webhookId, self::STATUS_PROCESSED, null, true);
    }

    public static function markFailed($webhookId, $message)
    {
        return self::setStatus($webhookId, self::STATUS_FAILED, substr((string) $message, 0, 1000));
    }

    /*
     * Apaga eventos processados mais antigos que $days dias (rode num cron).
     */
    public static function prune($days = 30)
    {
        $db = self::db();
        self::ensureTable($db);

        $limit = gmdate('Y-m-d H:i:s', time() - max(1, (int) $days) * 86400);
        $db->setQuery(
            'DELETE FROM ' . $db->quoteName(self::$table)
            . ' WHERE status = ' . $db->quote(self::STATUS_PROCESSED) . ' AND received_at < ' . $db->quote($limit)
        );
        $db->execute();

        return true;
    }

    public static function setTable($table)
    {
        if (!preg_match('/^[#a-zA-Z0-9_]+$/', (string) $table)) {
            throw new InvalidArgumentException('Nome de tabela inválido.');
        }

        self::$table = (string) $table;
        self::$tableReady = false;
    }

    public static function setConnection($connection)
    {
        self::$connection = $connection;
        self::$tableReady = false;
    }

    public static function createTableSql($postgres = false)
    {
        if ($postgres) {
            return 'CREATE TABLE IF NOT EXISTS ' . self::$table . ' ('
                . ' id BIGSERIAL PRIMARY KEY,'
                . ' provider VARCHAR(50) NOT NULL,'
                . ' event_id VARCHAR(191) NOT NULL,'
                . ' event_type VARCHAR(100) NOT NULL DEFAULT \'\','
                . ' payload TEXT NOT NULL,'
                . ' status VARCHAR(20) NOT NULL,'
                . ' error TEXT NULL,'
                . ' attempts INTEGER NOT NULL DEFAULT 1,'
                . ' received_at TIMESTAMP NOT NULL,'
                . ' processed_at TIMESTAMP NULL,'
                . ' UNIQUE (provider, event_id))';
        }

        return 'CREATE TABLE IF NOT EXISTS `' . self::$table . '` ('
            . ' `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . ' `provider` VARCHAR(50) NOT NULL,'
            . ' `event_id` VARCHAR(191) NOT NULL,'
            . ' `event_type` VARCHAR(100) NOT NULL DEFAULT \'\','
            . ' `payload` MEDIUMTEXT NOT NULL,'
            . ' `status` VARCHAR(20) NOT NULL,'
            . ' `error` TEXT NULL,'
            . ' `attempts` INT UNSIGNED NOT NULL DEFAULT 1,'
            . ' `received_at` DATETIME NOT NULL,'
            . ' `processed_at` DATETIME NULL,'
            . ' PRIMARY KEY (`id`),'
            . ' UNIQUE KEY `uk_provider_event` (`provider`, `event_id`),'
            . ' KEY `idx_status_received` (`status`, `received_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    // ------------------------------------------------------------------

    /*
     * Grava o evento; se (provider, event_id) já existe, é reenvio:
     * devolve o registro existente com duplicate = true.
     */
    private static function store($provider, $eventId, $type, $body)
    {
        $db = self::db();
        self::ensureTable($db);

        $table = $db->quoteName(self::$table);
        $existing = self::findByEvent($db, $provider, $eventId);

        if ($existing) {
            $db->setQuery('UPDATE ' . $table . ' SET attempts = attempts + 1 WHERE id = ' . (int) $existing['id']);
            $db->execute();

            return array('id' => (int) $existing['id'], 'duplicate' => true, 'status' => $existing['status']);
        }

        try {
            $db->setQuery(
                'INSERT INTO ' . $table . ' (provider, event_id, event_type, payload, status, received_at) VALUES ('
                . $db->quote(substr($provider, 0, 50)) . ', '
                . $db->quote(substr($eventId, 0, 191)) . ', '
                . $db->quote(substr($type, 0, 100)) . ', '
                . $db->quote($body) . ', '
                . $db->quote(self::STATUS_RECEIVED) . ', '
                . $db->quote(gmdate('Y-m-d H:i:s')) . ')'
            );
            $db->execute();
        } catch (Throwable $error) {
            // Dois reenvios simultâneos: o UNIQUE barrou o segundo.
            $existing = self::findByEvent($db, $provider, $eventId);

            if ($existing) {
                return array('id' => (int) $existing['id'], 'duplicate' => true, 'status' => $existing['status']);
            }

            throw $error;
        }

        $created = self::findByEvent($db, $provider, $eventId);

        return array('id' => (int) $created['id'], 'duplicate' => false, 'status' => self::STATUS_RECEIVED);
    }

    private static function findByEvent($db, $provider, $eventId)
    {
        $db->setQuery(
            'SELECT id, status FROM ' . $db->quoteName(self::$table)
            . ' WHERE provider = ' . $db->quote(substr($provider, 0, 50)) . ' AND event_id = ' . $db->quote(substr($eventId, 0, 191))
        );
        $row = $db->loadAssoc();

        return is_array($row) ? $row : null;
    }

    private static function setStatus($webhookId, $status, $error = null, $processed = false)
    {
        try {
            $db = self::db();
            self::ensureTable($db);

            $db->setQuery(
                'UPDATE ' . $db->quoteName(self::$table) . ' SET status = ' . $db->quote($status)
                . ', error = ' . ($error === null ? 'NULL' : $db->quote($error))
                . ($processed ? ', processed_at = ' . $db->quote(gmdate('Y-m-d H:i:s')) : '')
                . ' WHERE id = ' . (int) $webhookId
            );
            $db->execute();

            return true;
        } catch (Throwable $error) {
            self::log('error', 'Falha ao atualizar o status do webhook.', array('webhook_id' => $webhookId, 'erro' => $error->getMessage()));

            return false;
        }
    }

    private static function respond(array $result)
    {
        if (!class_exists('ApiResponseHelper')) {
            http_response_code($result['http']);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(array('success' => $result['http'] < 300, 'mensagem' => $result['message']));

            if (class_exists('JFactory')) {
                JFactory::getApplication()->close();
            }

            return;
        }

        if ($result['http'] < 300) {
            ApiResponseHelper::success($result['message'], null, array('log' => false));
        } elseif ($result['http'] === 401) {
            ApiResponseHelper::unauthorized($result['message']);
        } elseif ($result['http'] === 400) {
            ApiResponseHelper::badRequest($result['message']);
        } else {
            ApiResponseHelper::error($result['message'], null, array(), array('http_status' => 500));
        }
    }

    private static function result($status, $http, $message, $webhookId = null, $eventId = null, $type = null)
    {
        return array(
            'status' => $status,
            'http' => (int) $http,
            'message' => $message,
            'webhook_id' => $webhookId === null ? null : (int) $webhookId,
            'event_id' => $eventId,
            'type' => $type
        );
    }

    private static function path($data, $path)
    {
        foreach (explode('.', (string) $path) as $part) {
            if (!is_array($data) || !array_key_exists($part, $data)) {
                return null;
            }

            $data = $data[$part];
        }

        return $data;
    }

    private static function header(array $headers, $name)
    {
        $name = strtolower((string) $name);

        return isset($headers[$name]) ? trim((string) $headers[$name]) : '';
    }

    private static function normalizeHeaders(array $headers)
    {
        $normalized = array();

        foreach ($headers as $name => $value) {
            // Aceita também o formato do $_SERVER (HTTP_X_SIGNATURE).
            $key = preg_replace('/^http-/', '', strtolower(str_replace('_', '-', (string) $name)));
            $normalized[$key] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return $normalized;
    }

    private static function requestHeaders()
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                return self::normalizeHeaders($headers);
            }
        }

        $headers = array();

        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $headers[substr($key, 5)] = $value;
            }
        }

        return self::normalizeHeaders($headers);
    }

    private static function db()
    {
        if (is_object(self::$connection)) {
            return self::$connection;
        }

        if (self::$connection !== null && class_exists('DbConnectionHelper')) {
            return DbConnectionHelper::get(self::$connection);
        }

        return JFactory::getDbo();
    }

    private static function isPostgres($db)
    {
        $type = strtolower(method_exists($db, 'getServerType') ? (string) $db->getServerType() : (string) $db->getName());

        return strpos($type, 'postgres') !== false || strpos($type, 'pgsql') !== false;
    }

    private static function ensureTable($db)
    {
        if (self::$tableReady) {
            return;
        }

        $postgres = self::isPostgres($db);
        $sql = self::createTableSql($postgres);

        $db->setQuery($postgres ? str_replace('EXISTS ' . self::$table, 'EXISTS ' . $db->quoteName(self::$table), $sql) : $sql);
        $db->execute();

        self::$tableReady = true;
    }

    private static function log($level, $message, array $context)
    {
        if (class_exists('LogHelper') && method_exists('LogHelper', $level)) {
            LogHelper::$level($message, 'webhook', $context);
        }
    }
}
