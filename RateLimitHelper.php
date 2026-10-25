<?php

defined('_JEXEC') or die;

/*
 * Limite de tentativas por chave e janela de tempo (login, formulário de
 * contato, envio de SMS/código, "esqueci minha senha", endpoints
 * públicos). Contadores no banco, com incremento atômico (MySQL/MariaDB
 * e PostgreSQL 9.5+): vale entre vários servidores e processos.
 *
 *   if (!RateLimitHelper::enforce('contato:' . RateLimitHelper::ip(), 5, 3600)) {
 *       return;   // já respondeu 429 com Retry-After
 *   }
 *
 * A tabela é criada sozinha no primeiro uso (ou rode tables/rateLimitHelper.sql).
 */

class RateLimitHelper
{
    private static $table = '#__helpers_rate_limits';

    private static $connection = null;

    private static $failOpen = true;

    private static $tableReady = false;

    private static $trustedProxies = array();

    /*
     * Conta uma tentativa e informa se ela está dentro do limite.
     * Devolve array('allowed', 'hits', 'limit', 'remaining', 'retry_after', 'reset_at').
     */
    public static function hit($key, $max, $seconds)
    {
        $max = max(1, (int) $max);
        $seconds = max(1, (int) $seconds);
        $now = time();

        try {
            $db = self::db();
            self::ensureTable($db);

            $id = $db->quote(self::id($key));
            $table = $db->quoteName(self::$table);
            $expires = $now + $seconds;

            if (self::isPostgres($db)) {
                $db->setQuery(
                    'INSERT INTO ' . $table . ' AS t (chave, hits, expires_at) VALUES (' . $id . ', 1, ' . $expires . ')'
                    . ' ON CONFLICT (chave) DO UPDATE SET'
                    . ' hits = CASE WHEN t.expires_at <= ' . $now . ' THEN 1 ELSE t.hits + 1 END,'
                    . ' expires_at = CASE WHEN t.expires_at <= ' . $now . ' THEN ' . $expires . ' ELSE t.expires_at END'
                    . ' RETURNING hits, expires_at'
                );
                $row = $db->loadAssoc();
            } else {
                // No MySQL, "hits" é calculado antes de "expires_at" mudar (as duas leem o valor antigo de expires_at).
                $db->setQuery(
                    'INSERT INTO ' . $table . ' (chave, hits, expires_at) VALUES (' . $id . ', 1, ' . $expires . ')'
                    . ' ON DUPLICATE KEY UPDATE'
                    . ' hits = IF(expires_at <= ' . $now . ', 1, hits + 1),'
                    . ' expires_at = IF(expires_at <= ' . $now . ', ' . $expires . ', expires_at)'
                );
                $db->execute();

                $db->setQuery('SELECT hits, expires_at FROM ' . $table . ' WHERE chave = ' . $id);
                $row = $db->loadAssoc();
            }

            // Limpeza ocasional das chaves vencidas (1 a cada 100 tentativas).
            if (mt_rand(1, 100) === 1) {
                self::prune();
            }

            $hits = (int) $row['hits'];
            $resetAt = (int) $row['expires_at'];
        } catch (Throwable $error) {
            self::log('Falha ao registrar tentativa; liberando por segurança operacional.', $error, $key);

            if (!self::$failOpen) {
                return self::state(false, $max + 1, $max, $now + $seconds, $now);
            }

            return self::state(true, 0, $max, $now + $seconds, $now);
        }

        return self::state($hits <= $max, $hits, $max, $resetAt, $now);
    }

    /*
     * Como hit(), mas responde 429 (ApiResponseHelper::tooManyRequests,
     * com cabeçalho Retry-After) e devolve false quando passa do limite.
     * Uso: if (!RateLimitHelper::enforce(...)) { return; }
     */
    public static function enforce($key, $max, $seconds, $message = null)
    {
        $state = self::hit($key, $max, $seconds);

        if ($state['allowed']) {
            return true;
        }

        if ($message === null) {
            $message = 'Muitas tentativas. Tente novamente em ' . self::humanWait($state['retry_after']) . '.';
        }

        if (class_exists('LogHelper')) {
            LogHelper::security('Limite de tentativas atingido.', array('chave' => (string) $key, 'tentativas' => $state['hits'], 'limite' => $state['limit']));
        }

        if (class_exists('ApiResponseHelper')) {
            ApiResponseHelper::tooManyRequests($message, array('retry_after' => $state['retry_after']));
        }

        return false;
    }

    /*
     * Tentativas já feitas na janela atual (sem contar uma nova).
     */
    public static function attempts($key)
    {
        try {
            $db = self::db();
            self::ensureTable($db);

            $db->setQuery(
                'SELECT hits FROM ' . $db->quoteName(self::$table)
                . ' WHERE chave = ' . $db->quote(self::id($key)) . ' AND expires_at > ' . time()
            );

            return (int) $db->loadResult();
        } catch (Throwable $error) {
            self::log('Falha ao consultar tentativas.', $error, $key);

            return 0;
        }
    }

    /*
     * true se a próxima tentativa já passaria do limite (não conta nada).
     */
    public static function tooManyAttempts($key, $max)
    {
        return self::attempts($key) >= (int) $max;
    }

    /*
     * Zera a chave (ex.: login certo apaga as tentativas erradas).
     */
    public static function clear($key)
    {
        try {
            $db = self::db();
            self::ensureTable($db);

            $db->setQuery('DELETE FROM ' . $db->quoteName(self::$table) . ' WHERE chave = ' . $db->quote(self::id($key)));
            $db->execute();

            return true;
        } catch (Throwable $error) {
            self::log('Falha ao zerar tentativas.', $error, $key);

            return false;
        }
    }

    /*
     * Apaga as chaves vencidas (também roda sozinho de vez em quando).
     */
    public static function prune()
    {
        try {
            $db = self::db();
            self::ensureTable($db);

            $db->setQuery('DELETE FROM ' . $db->quoteName(self::$table) . ' WHERE expires_at <= ' . time());
            $db->execute();

            return true;
        } catch (Throwable $error) {
            self::log('Falha ao limpar tentativas vencidas.', $error, '*');

            return false;
        }
    }

    /*
     * IP de quem fez a requisição. Só confia em X-Forwarded-For quando o
     * REMOTE_ADDR é um proxy seu (setTrustedProxies): sem isso, qualquer
     * um troca o IP mandando o cabeçalho e escapa do limite.
     */
    public static function ip()
    {
        $remote = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';

        if (!in_array($remote, self::$trustedProxies, true) || empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $remote;
        }

        // O primeiro IP da direita para a esquerda que não é proxy nosso.
        $chain = array_reverse(array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])));

        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) && !in_array($ip, self::$trustedProxies, true)) {
                return $ip;
            }
        }

        return $remote;
    }

    public static function setTrustedProxies(array $ips)
    {
        self::$trustedProxies = array_values(array_map('strval', $ips));
    }

    public static function setTable($table)
    {
        if (!preg_match('/^[#a-zA-Z0-9_]+$/', (string) $table)) {
            throw new InvalidArgumentException('Nome de tabela inválido.');
        }

        self::$table = (string) $table;
        self::$tableReady = false;
    }

    /*
     * null (padrão do Joomla), nome registrado no DbConnectionHelper ou driver.
     */
    public static function setConnection($connection)
    {
        self::$connection = $connection;
        self::$tableReady = false;
    }

    /*
     * true (padrão): se o banco falhar, libera (não bloqueia todo mundo).
     * false: se o banco falhar, bloqueia (para limites críticos de segurança).
     */
    public static function setFailOpen($failOpen)
    {
        self::$failOpen = (bool) $failOpen;
    }

    /*
     * SQL de criação da tabela (o mesmo de tables/rateLimitHelper.sql).
     */
    public static function createTableSql($postgres = false)
    {
        if ($postgres) {
            return 'CREATE TABLE IF NOT EXISTS ' . self::$table . ' ('
                . ' chave CHAR(40) NOT NULL PRIMARY KEY,'
                . ' hits INTEGER NOT NULL DEFAULT 0,'
                . ' expires_at BIGINT NOT NULL)';
        }

        return 'CREATE TABLE IF NOT EXISTS `' . self::$table . '` ('
            . ' `chave` CHAR(40) NOT NULL,'
            . ' `hits` INT UNSIGNED NOT NULL DEFAULT 0,'
            . ' `expires_at` BIGINT NOT NULL,'
            . ' PRIMARY KEY (`chave`),'
            . ' KEY `idx_expires_at` (`expires_at`)'
            . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    }

    // ------------------------------------------------------------------

    private static function state($allowed, $hits, $max, $resetAt, $now)
    {
        return array(
            'allowed' => (bool) $allowed,
            'hits' => (int) $hits,
            'limit' => (int) $max,
            'remaining' => max(0, (int) $max - (int) $hits),
            'retry_after' => $allowed ? 0 : max(1, (int) $resetAt - (int) $now),
            'reset_at' => (int) $resetAt
        );
    }

    private static function humanWait($seconds)
    {
        if ($seconds < 60) {
            return $seconds . ' segundo' . ($seconds === 1 ? '' : 's');
        }

        $minutes = (int) ceil($seconds / 60);

        if ($minutes < 60) {
            return $minutes . ' minuto' . ($minutes === 1 ? '' : 's');
        }

        $hours = (int) ceil($minutes / 60);

        return $hours . ' hora' . ($hours === 1 ? '' : 's');
    }

    // A chave vira um hash: pode conter e-mail, IP ou qualquer texto sem ir ao banco em claro.
    private static function id($key)
    {
        $key = trim((string) $key);

        if ($key === '') {
            throw new InvalidArgumentException('Informe a chave do limite.');
        }

        return sha1($key);
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

        $sql = self::createTableSql(self::isPostgres($db));

        $db->setQuery(self::isPostgres($db) ? str_replace(self::$table, $db->quoteName(self::$table), $sql) : $sql);
        $db->execute();

        if (self::isPostgres($db)) {
            $db->setQuery('CREATE INDEX IF NOT EXISTS idx_' . preg_replace('/[^a-z0-9_]/', '', str_replace('#__', '', self::$table)) . '_expires ON ' . $db->quoteName(self::$table) . ' (expires_at)');
            $db->execute();
        }

        self::$tableReady = true;
    }

    private static function log($message, Throwable $error, $key)
    {
        if (class_exists('LogHelper')) {
            LogHelper::error($message, 'rate_limit', array('chave' => (string) $key, 'erro' => $error->getMessage()));
        }
    }
}
