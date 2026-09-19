<?php

defined('_JEXEC') or die;

class DbTransactionHelper
{
    private static $transactionDepth = 0;

    private static $transactionActive = false;

    private static $logCategory = 'db_transaction';

    private static $logDirectory = null;

    private static $auditEnabled = false;

    private static $maxRetries = 3;

    private static $retryDelayMicroseconds = 250000;

    private static $retryableErrorCodes = array(
        1205,
        1213
    );

    private static function db()
    {
        return JFactory::getDbo();
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

    private static function getExceptionCode(Throwable $error)
    {
        $code = (int) $error->getCode();

        if ($code > 0) {
            return $code;
        }

        $message = $error->getMessage();

        if (
            preg_match(
                '/\b(1205|1213)\b/',
                $message,
                $matches
            )
        ) {
            return (int) $matches[1];
        }

        return 0;
    }

    private static function isRetryable(Throwable $error)
    {
        $code = self::getExceptionCode($error);

        if (in_array($code, self::$retryableErrorCodes, true)) {
            return true;
        }

        $message = strtolower(
            (string) $error->getMessage()
        );

        $retryableMessages = array(
            'deadlock found',
            'lock wait timeout',
            'try restarting transaction',
            'serialization failure'
        );

        foreach ($retryableMessages as $retryableMessage) {
            if (strpos($message, $retryableMessage) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function startInternal()
    {
        $db = self::db();

        if (self::$transactionDepth === 0) {
            $db->transactionStart(false);
            self::$transactionActive = true;
            self::$transactionDepth = 1;

            self::log(
                'debug',
                'Transação iniciada.',
                array(
                    'depth' => self::$transactionDepth
                )
            );

            return;
        }

        $db->transactionStart(true);
        self::$transactionDepth++;

        self::log(
            'debug',
            'Savepoint de transação iniciado.',
            array(
                'depth' => self::$transactionDepth
            )
        );
    }

    private static function commitInternal()
    {
        if (self::$transactionDepth <= 0) {
            throw new RuntimeException(
                'Não existe transação ativa para confirmar.'
            );
        }

        $db = self::db();

        if (self::$transactionDepth === 1) {
            $db->transactionCommit(false);

            self::$transactionDepth = 0;
            self::$transactionActive = false;

            self::log(
                'debug',
                'Transação confirmada.',
                array(
                    'depth' => 0
                )
            );

            return;
        }

        $db->transactionCommit(true);
        self::$transactionDepth--;

        self::log(
            'debug',
            'Savepoint de transação confirmado.',
            array(
                'depth' => self::$transactionDepth
            )
        );
    }

    private static function rollbackInternal()
    {
        if (self::$transactionDepth <= 0) {
            return;
        }

        $db = self::db();

        if (self::$transactionDepth === 1) {
            $db->transactionRollback(false);

            self::$transactionDepth = 0;
            self::$transactionActive = false;

            self::log(
                'warning',
                'Transação revertida.',
                array(
                    'depth' => 0
                )
            );

            return;
        }

        $db->transactionRollback(true);
        self::$transactionDepth--;

        self::log(
            'warning',
            'Savepoint de transação revertido.',
            array(
                'depth' => self::$transactionDepth
            )
        );
    }

    private static function resetState()
    {
        self::$transactionDepth = 0;
        self::$transactionActive = false;
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
            'intval',
            $codes
        );

        self::$retryableErrorCodes = array_values(
            array_unique($codes)
        );
    }

    public static function isActive()
    {
        return self::$transactionActive;
    }

    public static function depth()
    {
        return self::$transactionDepth;
    }

    public static function begin()
    {
        try {
            self::startInternal();

            return true;
        } catch (Throwable $error) {
            self::resetState();

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

    public static function commit()
    {
        try {
            self::commitInternal();

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

    public static function rollback()
    {
        try {
            self::rollbackInternal();

            return true;
        } catch (Throwable $error) {
            self::resetState();

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

        $attempt = 0;

        while (true) {
            $attempt++;
            $started = false;

            try {
                self::startInternal();
                $started = true;

                $result = call_user_func(
                    $callback,
                    self::db(),
                    $attempt
                );

                self::commitInternal();

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
                        self::rollbackInternal();
                    } catch (Throwable $rollbackError) {
                        self::resetState();

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
                $canRetry = $retryable && $attempt <= $retries;

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