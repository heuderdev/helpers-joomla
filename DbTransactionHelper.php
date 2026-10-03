<?php

defined('_JEXEC') or die;

if (!class_exists('DbConnectionHelper')) {
    require_once __DIR__ . '/DbConnectionHelper.php';
}

/*
 * Transações com savepoints e retry de deadlock, em MySQL e PostgreSQL.
 *
 * Cada conexão tem a sua própria profundidade de transação: uma
 * transação aberta na conexão do Joomla não interfere em outra aberta
 * numa conexão registrada no DbConnectionHelper, e vice-versa.
 *
 * Atenção: uma transação nunca abrange dois bancos. Ver o exemplo em
 * exemplos/ComoUsarOrm.php (seção de múltiplas conexões).
 */
class DbTransactionHelper
{
    /*
     * Estado por conexão: [hash do driver => profundidade]. Só existe
     * entrada enquanto há transação aberta naquela conexão.
     */
    private static $depths = array();

    private static $logCategory = 'db_transaction';

    private static $logDirectory = null;

    private static $auditEnabled = false;

    private static $maxRetries = 3;

    private static $retryDelayMicroseconds = 250000;

    /*
     * MySQL: 1205 (lock wait timeout), 1213 (deadlock).
     * PostgreSQL (SQLSTATE): 40001 (serialization failure), 40P01 (deadlock).
     */
    private static $retryableErrorCodes = array(
        '1205',
        '1213',
        '40001',
        '40P01'
    );

    private static function db($connection = null)
    {
        return DbConnectionHelper::resolve($connection);
    }

    private static function getDepth($db)
    {
        $key = spl_object_hash($db);

        return isset(self::$depths[$key]) ? self::$depths[$key] : 0;
    }

    private static function setDepth($db, $depth)
    {
        $key = spl_object_hash($db);

        if ($depth <= 0) {
            unset(self::$depths[$key]);

            return;
        }

        self::$depths[$key] = $depth;
    }

    private static function log($level, $message, array $context = array())
    {
        try {
            if (class_exists('LogHelper')) {
                LogHelper::write(
                    $level,
                    $message,
                    self::$logCategory,
                    $context,
                    self::$logDirectory
                );

                return;
            }

            $priority = JLog::INFO;

            if ($level === 'error') {
                $priority = JLog::ERROR;
            }

            if ($level === 'warning') {
                $priority = JLog::WARNING;
            }

            if ($level === 'debug') {
                $priority = JLog::DEBUG;
            }

            JLog::add(
                $message,
                $priority,
                self::$logCategory
            );
        } catch (Throwable $error) {
        }
    }

    private static function audit($event, array $data = array())
    {
        if (!self::$auditEnabled || !class_exists('AuditHelper')) {
            return;
        }

        try {
            AuditHelper::custom(
                $event,
                array(
                    'category' => 'database_transaction',
                    'metadata' => $data
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao registrar auditoria de transação.',
                array(
                    'erro' => $error->getMessage()
                )
            );
        }
    }

    /*
     * Coleta os códigos do erro e das exceções encadeadas (getPrevious):
     * - getCode() (PDO devolve o SQLSTATE como string, ex.: '40P01');
     * - SQLSTATE na mensagem: "SQLSTATE[40P01]" ou "40P01, 7, ERROR: ..."
     *   (formato do driver PDO do Joomla 4, cujo getCode() vira 40);
     * - 1205/1213 na mensagem, só quando não há código (legado MySQL).
     */
    private static function getErrorCodes(Throwable $error)
    {
        $codes = array();

        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $code = strtoupper(trim((string) $current->getCode()));
            $message = (string) $current->getMessage();

            if ($code !== '' && $code !== '0') {
                $codes[] = $code;
            }

            if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/i', $message, $matches)
                || preg_match('/^\s*([0-9A-Z]{5}),/i', $message, $matches)) {
                $codes[] = strtoupper($matches[1]);
            }

            if (($code === '' || $code === '0')
                && preg_match('/\b(1205|1213)\b/', $message, $matches)) {
                $codes[] = $matches[1];
            }
        }

        return array_values(array_unique($codes));
    }

    private static function getExceptionCode(Throwable $error)
    {
        $codes = self::getErrorCodes($error);

        return empty($codes) ? 0 : implode(',', $codes);
    }

    private static function isRetryable(Throwable $error)
    {
        if (array_intersect(self::getErrorCodes($error), self::$retryableErrorCodes)) {
            return true;
        }

        $retryableMessages = array(
            // MySQL / MariaDB
            'deadlock found',
            'lock wait timeout',
            'try restarting transaction',
            // PostgreSQL
            'deadlock detected',
            'could not serialize access',
            'serialization failure'
        );

        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower((string) $current->getMessage());

            foreach ($retryableMessages as $retryableMessage) {
                if (strpos($message, $retryableMessage) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /*
     * Todas as chamadas ao driver usam o modo savepoint (true). Assim o
     * próprio driver do Joomla decide entre iniciar a transação e criar um
     * SAVEPOINT conforme a profundidade interna dele. Com false, um
     * START TRANSACTION dentro de outra transação faz commit implícito da
     * externa no MySQL.
     */
    private static function startInternal($db)
    {
        $db->transactionStart(true);

        $depth = self::getDepth($db) + 1;
        self::setDepth($db, $depth);

        self::log(
            'debug',
            $depth === 1
                ? 'Transação iniciada.'
                : 'Savepoint de transação iniciado.',
            array(
                'depth' => $depth
            )
        );
    }

    private static function commitInternal($db)
    {
        $depth = self::getDepth($db);

        if ($depth <= 0) {
            throw new RuntimeException(
                'Não existe transação ativa para confirmar.'
            );
        }

        $db->transactionCommit(true);

        self::setDepth($db, $depth - 1);

        self::log(
            'debug',
            $depth === 1
                ? 'Transação confirmada.'
                : 'Savepoint de transação confirmado.',
            array(
                'depth' => $depth - 1
            )
        );
    }

    private static function rollbackInternal($db)
    {
        $depth = self::getDepth($db);

        if ($depth <= 0) {
            return;
        }

        try {
            $db->transactionRollback(true);
        } catch (Throwable $error) {
            /*
             * Deadlock e lock wait timeout fazem o MySQL desfazer a transação
             * inteira, e o ROLLBACK TO SAVEPOINT falha. Faz rollback completo
             * para zerar também a profundidade interna do driver.
             */
            self::rollbackAll($db);

            throw $error;
        }

        self::setDepth($db, $depth - 1);

        self::log(
            'warning',
            $depth === 1
                ? 'Transação revertida.'
                : 'Savepoint de transação revertido.',
            array(
                'depth' => $depth - 1
            )
        );
    }

    private static function rollbackAll($db)
    {
        try {
            $db->transactionRollback(false);
        } catch (Throwable $error) {
        }

        self::resetState($db);

        self::log(
            'warning',
            'Transação revertida por completo.',
            array(
                'depth' => 0
            )
        );
    }

    private static function resetState($db)
    {
        self::setDepth($db, 0);
    }

    private static function delayForRetry($attempt)
    {
        $attempt = max(1, (int) $attempt);

        $delay = self::$retryDelayMicroseconds *
            pow(2, $attempt - 1);

        $delay = min(
            $delay,
            5000000
        );

        usleep((int) $delay);
    }

    public static function setLogCategory($category)
    {
        $category = trim((string) $category);

        if ($category === '') {
            throw new InvalidArgumentException(
                'Categoria de log inválida.'
            );
        }

        self::$logCategory = $category;
    }

    public static function setLogDirectory($directory)
    {
        self::$logDirectory = trim((string) $directory);
    }

    public static function setAuditEnabled($enabled)
    {
        self::$auditEnabled = (bool) $enabled;
    }

    public static function setMaxRetries($maxRetries)
    {
        $maxRetries = (int) $maxRetries;

        if ($maxRetries < 0 || $maxRetries > 10) {
            throw new InvalidArgumentException(
                'Quantidade de tentativas inválida.'
            );
        }

        self::$maxRetries = $maxRetries;
    }

    public static function setRetryDelayMicroseconds($microseconds)
    {
        $microseconds = (int) $microseconds;

        if ($microseconds < 0) {
            throw new InvalidArgumentException(
                'Tempo de espera para nova tentativa inválido.'
            );
        }

        self::$retryDelayMicroseconds = $microseconds;
    }

    public static function setRetryableErrorCodes(array $codes)
    {
        $codes = array_map(
            function ($code) {
                return strtoupper(trim((string) $code));
            },
            $codes
        );

        self::$retryableErrorCodes = array_values(
            array_unique($codes)
        );
    }

    /*
     * $connection: null (conexão do Joomla), nome registrado no
     * DbConnectionHelper ou um driver.
     */
    public static function isActive($connection = null)
    {
        return self::getDepth(self::db($connection)) > 0;
    }

    public static function depth($connection = null)
    {
        return self::getDepth(self::db($connection));
    }

    public static function begin($connection = null)
    {
        $db = self::db($connection);

        try {
            self::startInternal($db);

            return true;
        } catch (Throwable $error) {
            self::resetState($db);

            self::log(
                'error',
                'Falha ao iniciar transação.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function commit($connection = null)
    {
        $db = self::db($connection);

        try {
            self::commitInternal($db);

            return true;
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao confirmar transação.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function rollback($connection = null)
    {
        $db = self::db($connection);

        try {
            self::rollbackInternal($db);

            return true;
        } catch (Throwable $error) {
            self::resetState($db);

            self::log(
                'error',
                'Falha ao reverter transação.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function run($callback, array $options = array())
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException(
                'A transação exige um callback válido.'
            );
        }

        $retries = isset($options['retries'])
            ? max(0, min(10, (int) $options['retries']))
            : self::$maxRetries;

        $auditEvent = isset($options['audit_event'])
            ? trim((string) $options['audit_event'])
            : '';

        $context = isset($options['context'])
            ? (array) $options['context']
            : array();

        /*
         * 'connection': nome registrado no DbConnectionHelper ou driver.
         * Sem ela, usa a conexão do Joomla. O callback recebe o driver
         * dessa conexão como primeiro parâmetro.
         */
        $connection = isset($options['connection'])
            ? $options['connection']
            : null;

        $db = self::db($connection);

        if (is_string($connection)) {
            $context['conexao'] = $connection;
        }

        $attempt = 0;

        /*
         * Só a transação mais externa pode repetir: um deadlock desfaz a
         * transação inteira, então repetir apenas o trecho aninhado perderia
         * o trabalho feito antes dele. Nos níveis internos o erro sobe.
         */
        $isOutermost = self::getDepth($db) === 0;

        while (true) {
            $attempt++;
            $started = false;

            try {
                self::startInternal($db);
                $started = true;

                $result = call_user_func(
                    $callback,
                    $db,
                    $attempt
                );

                self::commitInternal($db);

                if ($auditEvent !== '') {
                    self::audit(
                        $auditEvent,
                        array_merge(
                            $context,
                            array(
                                'status' => 'committed',
                                'attempt' => $attempt
                            )
                        )
                    );
                }

                self::log(
                    'debug',
                    'Transação executada com sucesso.',
                    array_merge(
                        $context,
                        array(
                            'attempt' => $attempt
                        )
                    )
                );

                return $result;
            } catch (Throwable $error) {
                if ($started) {
                    try {
                        self::rollbackInternal($db);
                    } catch (Throwable $rollbackError) {
                        self::resetState($db);

                        self::log(
                            'critical',
                            'Falha ao reverter transação após erro.',
                            array_merge(
                                $context,
                                array(
                                    'erro_original' => $error->getMessage(),
                                    'erro_rollback' => $rollbackError->getMessage(),
                                    'attempt' => $attempt
                                )
                            )
                        );
                    }
                }

                $retryable = self::isRetryable($error);
                $canRetry = $retryable
                    && $isOutermost
                    && $attempt <= $retries;

                self::log(
                    $canRetry ? 'warning' : 'error',
                    'Falha ao executar transação.',
                    array_merge(
                        $context,
                        array(
                            'erro' => $error->getMessage(),
                            'codigo' => self::getExceptionCode($error),
                            'attempt' => $attempt,
                            'retryable' => $retryable,
                            'will_retry' => $canRetry
                        )
                    )
                );

                if ($auditEvent !== '') {
                    self::audit(
                        $auditEvent . '.failed',
                        array_merge(
                            $context,
                            array(
                                'status' => 'failed',
                                'erro' => $error->getMessage(),
                                'attempt' => $attempt
                            )
                        )
                    );
                }

                if (!$canRetry) {
                    throw $error;
                }

                self::delayForRetry($attempt);
            }
        }
    }

    public static function attempt($callback, array $options = array())
    {
        try {
            $result = self::run(
                $callback,
                $options
            );

            return array(
                'success' => true,
                'status' => 'sucesso',
                'mensagem' => 'Transação concluída com sucesso.',
                'data' => $result,
                'error' => null
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Transação finalizada com erro.',
                array(
                    'erro' => $error->getMessage(),
                    'arquivo' => $error->getFile(),
                    'linha' => $error->getLine()
                )
            );

            return array(
                'success' => false,
                'status' => 'erro',
                'mensagem' => 'Não foi possível concluir a operação no banco de dados.',
                'data' => null,
                'error' => null
            );
        }
    }

    public static function transaction($callback, array $options = array())
    {
        return self::run(
            $callback,
            $options
        );
    }
}