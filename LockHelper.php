<?php

defined('_JEXEC') or die;

/*
 * Trava de tarefa: impede que a mesma tarefa rode duas vezes ao mesmo
 * tempo (cron que se sobrepõe, clique duplo em "gerar boletos",
 * importação concorrente, dois workers no mesmo pedido).
 *
 *   $r = LockHelper::run('gerar-boletos', function () { ... });
 *   if (!$r['acquired']) { // outra execução está em andamento }
 *
 * Drivers: 'mysql' (GET_LOCK), 'pgsql' (pg_try_advisory_lock) e 'file'
 * (flock). Os três são liberados sozinhos se o processo morrer: não há
 * trava "presa" depois de um erro fatal.
 */

class LockHelperException extends RuntimeException
{
}

class LockHelper
{
    const DRIVER_AUTO = 'auto';

    const DRIVER_MYSQL = 'mysql';

    const DRIVER_PGSQL = 'pgsql';

    const DRIVER_FILE = 'file';

    private static $driver = self::DRIVER_AUTO;

    private static $connection = null;

    private static $directory = null;

    // Travas deste processo: nome => array(driver, chave, contagem, handle do arquivo, conexão)
    private static $held = array();

    private static $shutdownRegistered = false;

    /*
     * 'auto' (padrão): banco do Joomla (MySQL/MariaDB ou PostgreSQL);
     * 'file': arquivos em tmp/ (um servidor só).
     */
    public static function setDriver($driver)
    {
        $driver = strtolower(trim((string) $driver));

        if (!in_array($driver, array(self::DRIVER_AUTO, self::DRIVER_MYSQL, self::DRIVER_PGSQL, self::DRIVER_FILE), true)) {
            throw new InvalidArgumentException('Driver de trava inválido: ' . $driver . '.');
        }

        self::$driver = $driver;
    }

    /*
     * Conexão usada pelas travas de banco: null (padrão do Joomla), nome
     * registrado no DbConnectionHelper ou um driver pronto.
     */
    public static function setConnection($connection)
    {
        self::$connection = $connection;
    }

    /*
     * Pasta das travas em arquivo (padrão: tmp_path do Joomla + /locks).
     */
    public static function setDirectory($directory)
    {
        self::$directory = rtrim((string) $directory, '/\\');
    }

    /*
     * Tenta pegar a trava, esperando até $wait segundos (0 = não espera).
     * Devolve true/false. Dentro do mesmo processo é reentrante: pegar de
     * novo só incrementa a contagem.
     */
    public static function acquire($name, $wait = 0)
    {
        $name = self::normalizeName($name);

        if (isset(self::$held[$name])) {
            self::$held[$name]['count']++;

            return true;
        }

        $wait = max(0, (float) $wait);
        $driver = self::resolveDriver();
        $key = self::key($name);

        switch ($driver) {
            case self::DRIVER_MYSQL:
                $lock = self::acquireMysql($key, $wait);
                break;

            case self::DRIVER_PGSQL:
                $lock = self::acquirePgsql($key, $wait);
                break;

            default:
                $lock = self::acquireFile($key, $wait);
        }

        if ($lock === false) {
            return false;
        }

        self::$held[$name] = array_merge($lock, array('driver' => $driver, 'key' => $key, 'count' => 1));
        self::registerShutdown();

        return true;
    }

    /*
     * Solta a trava (quando a contagem chega a zero). Devolve false se
     * este processo não a tinha.
     */
    public static function release($name)
    {
        $name = self::normalizeName($name);

        if (!isset(self::$held[$name])) {
            return false;
        }

        self::$held[$name]['count']--;

        if (self::$held[$name]['count'] > 0) {
            return true;
        }

        $lock = self::$held[$name];
        unset(self::$held[$name]);

        try {
            switch ($lock['driver']) {
                case self::DRIVER_MYSQL:
                    $db = $lock['db'];
                    $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock['key']) . ')');
                    $db->loadResult();
                    break;

                case self::DRIVER_PGSQL:
                    $db = $lock['db'];
                    $db->setQuery('SELECT pg_advisory_unlock(' . self::pgKey($lock['key']) . ')');
                    $db->loadResult();
                    break;

                default:
                    flock($lock['handle'], LOCK_UN);
                    fclose($lock['handle']);
            }
        } catch (Throwable $error) {
            self::log('Falha ao liberar a trava "' . $name . '".', $error);

            return false;
        }

        return true;
    }

    /*
     * Executa $callback com a trava e a solta no fim (mesmo com exceção,
     * que é relançada). Devolve array('acquired' => bool, 'result' => o
     * retorno do callback ou null). Opção 'throw' => true lança
     * LockHelperException quando não consegue a trava.
     */
    public static function run($name, $callback, $wait = 0, array $options = array())
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('LockHelper::run() precisa de um callable.');
        }

        if (!self::acquire($name, $wait)) {
            if (!empty($options['throw'])) {
                throw new LockHelperException(isset($options['message']) ? $options['message'] : 'Esta tarefa já está em execução. Aguarde ela terminar.');
            }

            return array('acquired' => false, 'result' => null);
        }

        try {
            $result = call_user_func($callback);
        } finally {
            self::release($name);
        }

        return array('acquired' => true, 'result' => $result);
    }

    /*
     * true se a trava está com alguém (este ou outro processo). Só para
     * informar a tela: para decidir, use acquire()/run(), que são atômicos.
     */
    public static function isLocked($name)
    {
        $name = self::normalizeName($name);

        if (isset(self::$held[$name])) {
            return true;
        }

        $driver = self::resolveDriver();
        $key = self::key($name);

        try {
            if ($driver === self::DRIVER_MYSQL) {
                $db = self::db();
                $db->setQuery('SELECT IS_FREE_LOCK(' . $db->quote($key) . ')');

                return (int) $db->loadResult() !== 1;
            }

            if ($driver === self::DRIVER_PGSQL) {
                $db = self::db();
                $number = (int) self::pgKey($key);

                // Trava de um bigint: classid = 32 bits altos, objid = 32 bits baixos, objsubid = 1.
                $db->setQuery(
                    'SELECT COUNT(*) FROM pg_locks WHERE locktype = ' . $db->quote('advisory')
                    . ' AND objsubid = 1 AND classid = ' . (($number >> 32) & 0xFFFFFFFF)
                    . ' AND objid = ' . ($number & 0xFFFFFFFF)
                );

                return (int) $db->loadResult() > 0;
            }

            $lock = self::acquireFile($key, 0);

            if ($lock === false) {
                return true;
            }

            flock($lock['handle'], LOCK_UN);
            fclose($lock['handle']);

            return false;
        } catch (Throwable $error) {
            self::log('Falha ao consultar a trava "' . $name . '".', $error);

            return false;
        }
    }

    /*
     * Nomes das travas que este processo segura.
     */
    public static function held()
    {
        return array_keys(self::$held);
    }

    public static function releaseAll()
    {
        foreach (array_keys(self::$held) as $name) {
            self::$held[$name]['count'] = 1;
            self::release($name);
        }
    }

    // ------------------------------------------------------------------

    private static function normalizeName($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Informe o nome da trava.');
        }

        return $name;
    }

    /*
     * Chave única por site: o GET_LOCK vale para o servidor MySQL inteiro,
     * então dois sites no mesmo servidor não podem dividir "importacao".
     */
    private static function key($name)
    {
        $scope = '';

        try {
            if (class_exists('JFactory')) {
                $config = JFactory::getConfig();
                $scope = $config->get('db') . '|' . $config->get('dbprefix');
            }
        } catch (Throwable $error) {
            $scope = '';
        }

        // GET_LOCK aceita até 64 caracteres.
        return 'hj_' . sha1($scope . '|' . $name);
    }

    private static function resolveDriver()
    {
        if (self::$driver !== self::DRIVER_AUTO) {
            return self::$driver;
        }

        try {
            $db = self::db();
            $type = strtolower(method_exists($db, 'getServerType') ? (string) $db->getServerType() : (string) $db->getName());

            if (strpos($type, 'postgres') !== false || strpos($type, 'pgsql') !== false) {
                return self::DRIVER_PGSQL;
            }

            if (strpos($type, 'mysql') !== false || strpos($type, 'mariadb') !== false) {
                return self::DRIVER_MYSQL;
            }
        } catch (Throwable $error) {
            // Sem banco: arquivo.
        }

        return self::DRIVER_FILE;
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

    private static function acquireMysql($key, $wait)
    {
        $db = self::db();

        // GET_LOCK espera em segundos inteiros; 0 = tenta uma vez.
        $db->setQuery('SELECT GET_LOCK(' . $db->quote($key) . ', ' . (int) ceil($wait) . ')');

        return (int) $db->loadResult() === 1 ? array('db' => $db) : false;
    }

    private static function acquirePgsql($key, $wait)
    {
        $db = self::db();
        $deadline = microtime(true) + $wait;

        do {
            $db->setQuery('SELECT pg_try_advisory_lock(' . self::pgKey($key) . ')');
            $result = $db->loadResult();

            if ($result === true || $result === 't' || $result === '1' || $result === 1) {
                return array('db' => $db);
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep(200000);
        } while (true);
    }

    // Chave bigint a partir do hash: 60 bits, cabe num bigint positivo (PHP 64 bits).
    private static function pgKey($key)
    {
        return (string) hexdec(substr(sha1($key), 0, 15));
    }

    private static function acquireFile($key, $wait)
    {
        $directory = self::$directory;

        if ($directory === null) {
            $tmp = class_exists('JFactory') ? JFactory::getConfig()->get('tmp_path') : sys_get_temp_dir();
            $directory = rtrim($tmp ?: sys_get_temp_dir(), '/\\') . '/locks';
        }

        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta das travas: ' . $directory);
        }

        $handle = @fopen($directory . '/' . $key . '.lock', 'c');

        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo da trava.');
        }

        $deadline = microtime(true) + $wait;

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                ftruncate($handle, 0);
                fwrite($handle, getmypid() . ' ' . gmdate('Y-m-d H:i:s'));
                fflush($handle);

                return array('handle' => $handle);
            }

            if (microtime(true) >= $deadline) {
                fclose($handle);

                return false;
            }

            usleep(100000);
        } while (true);
    }

    private static function registerShutdown()
    {
        if (!self::$shutdownRegistered) {
            self::$shutdownRegistered = true;
            register_shutdown_function(array(__CLASS__, 'releaseAll'));
        }
    }

    private static function log($message, Throwable $error)
    {
        if (class_exists('LogHelper')) {
            LogHelper::error($message, 'lock', array('erro' => $error->getMessage()));
        }
    }
}
