<?php

defined('_JEXEC') or die;

jimport('joomla.log.log');
jimport('joomla.filesystem.folder');

class LogHelper
{
    const LEVEL_DEBUG = 'debug';

    const LEVEL_INFO = 'info';

    const LEVEL_NOTICE = 'notice';

    const LEVEL_WARNING = 'warning';

    const LEVEL_ERROR = 'error';

    const LEVEL_CRITICAL = 'critical';

    const LEVEL_ALERT = 'alert';

    const LEVEL_EMERGENCY = 'emergency';

    private static $defaultCategory = 'application';

    private static $baseDirectory = null;

    private static $configuredLoggers = array();

    private static $requestId = null;

    private static $enabled = true;

    private static $maxContextLength = 10000;

    private static $directoryPermission = 0755;

    private static $sensitiveKeys = array(
        'password',
        'senha',
        'senha_confirmacao',
        'password_confirmation',
        'confirm_password',
        'token',
        'csrf',
        'form_token',
        'authorization',
        'bearer',
        'api_key',
        'apikey',
        'secret',
        'client_secret',
        'access_token',
        'refresh_token',
        'private_key',
        'credit_card',
        'card_number',
        'numero_cartao',
        'cvv',
        'cvc',
        'security_code',
        'cookie',
        'session',
        'sessid'
    );

    private static function normalizePath($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $path = preg_replace('#/+#', '/', $path);

        return rtrim($path, '/');
    }

    private static function siteRoot()
    {
        $siteRoot = realpath(JPATH_SITE);

        if ($siteRoot === false) {
            throw new RuntimeException(
                'Não foi possível localizar a raiz do site.'
            );
        }

        return self::normalizePath($siteRoot);
    }

    private static function normalizeCategory($category)
    {
        $category = trim((string) $category);

        if ($category === '') {
            $category = self::$defaultCategory;
        }

        $category = preg_replace(
            '/[^a-zA-Z0-9_.-]/',
            '_',
            $category
        );

        return substr($category, 0, 100);
    }

    private static function normalizeLevel($level)
    {
        $level = strtolower(trim((string) $level));

        $levels = array(
            self::LEVEL_DEBUG,
            self::LEVEL_INFO,
            self::LEVEL_NOTICE,
            self::LEVEL_WARNING,
            self::LEVEL_ERROR,
            self::LEVEL_CRITICAL,
            self::LEVEL_ALERT,
            self::LEVEL_EMERGENCY
        );

        if (!in_array($level, $levels, true)) {
            return self::LEVEL_INFO;
        }

        return $level;
    }

    private static function priority($level)
    {
        switch (self::normalizeLevel($level)) {
            case self::LEVEL_DEBUG:
                return JLog::DEBUG;

            case self::LEVEL_INFO:
                return JLog::INFO;

            case self::LEVEL_NOTICE:
                return JLog::NOTICE;

            case self::LEVEL_WARNING:
                return JLog::WARNING;

            case self::LEVEL_ERROR:
                return JLog::ERROR;

            case self::LEVEL_CRITICAL:
                return JLog::CRITICAL;

            case self::LEVEL_ALERT:
                return JLog::ALERT;

            case self::LEVEL_EMERGENCY:
                return JLog::EMERGENCY;
        }

        return JLog::INFO;
    }

    private static function truncate($value, $limit = null)
    {
        if ($limit === null) {
            $limit = self::$maxContextLength;
        }

        $value = (string) $value;

        if (strlen($value) <= (int) $limit) {
            return $value;
        }

        return substr($value, 0, (int) $limit) . '...[truncado]';
    }

    private static function normalizeKey($key)
    {
        $key = strtolower((string) $key);

        return str_replace(
            array('-', ' '),
            '_',
            $key
        );
    }

    private static function isSensitiveKey($key)
    {
        $key = self::normalizeKey($key);

        foreach (self::$sensitiveKeys as $sensitiveKey) {
            if (
                $key === $sensitiveKey ||
                strpos($key, $sensitiveKey . '_') === 0 ||
                substr($key, -strlen('_' . $sensitiveKey)) === '_' . $sensitiveKey ||
                strpos($key, $sensitiveKey) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    private static function maskValue($value)
    {
        if (is_array($value)) {
            return self::sanitizeContext($value);
        }

        if (is_object($value)) {
            return self::sanitizeContext((array) $value);
        }

        if ($value === null || $value === '') {
            return $value;
        }

        $value = (string) $value;
        $length = strlen($value);

        if ($length <= 4) {
            return '****';
        }

        return str_repeat(
            '*',
            max(4, $length - 4)
        ) . substr($value, -4);
    }

    private static function sanitizeContext($context)
    {
        if ($context === null) {
            return null;
        }

        if (is_object($context)) {
            $context = (array) $context;
        }

        if (!is_array($context)) {
            return self::truncate($context);
        }

        $sanitized = array();

        foreach ($context as $key => $value) {
            if (self::isSensitiveKey($key)) {
                $sanitized[$key] = self::maskValue($value);

                continue;
            }

            if (is_array($value) || is_object($value)) {
                $sanitized[$key] = self::sanitizeContext($value);

                continue;
            }

            $sanitized[$key] = self::truncate($value);
        }

        return $sanitized;
    }

    private static function encodeContext($context)
    {
        if (empty($context)) {
            return '';
        }

        $context = self::sanitizeContext($context);

        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (json_last_error() !== JSON_ERROR_NONE) {
            return '[contexto indisponível]';
        }

        return self::truncate($json);
    }

    private static function generateRequestId()
    {
        if (function_exists('random_bytes')) {
            $bytes = random_bytes(16);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(16);
        } else {
            return sha1(
                uniqid('', true) .
                    mt_rand()
            );
        }

        if ($bytes === false) {
            return sha1(
                uniqid('', true) .
                    mt_rand()
            );
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

    private static function resolveBaseDirectory($directory = null)
    {
        $siteRoot = self::siteRoot();

        if ($directory === null || trim((string) $directory) === '') {
            if (self::$baseDirectory !== null) {
                return self::$baseDirectory;
            }

            $directory = JFactory::getConfig()->get(
                'log_path',
                JPATH_ADMINISTRATOR . '/logs'
            );
        }

        $directory = trim((string) $directory);
        $directory = str_replace('\\', '/', $directory);

        if (
            strpos($directory, "\0") !== false ||
            strpos($directory, '../') !== false ||
            strpos($directory, '..\\') !== false
        ) {
            throw new InvalidArgumentException(
                'Diretório de logs inválido.'
            );
        }

        if (strpos($directory, '/') !== 0) {
            $directory = $siteRoot . '/' . trim($directory, '/');
        }

        $directory = self::normalizePath($directory);

        if (
            $directory !== $siteRoot &&
            strpos($directory, $siteRoot . '/') !== 0
        ) {
            throw new RuntimeException(
                'O diretório de logs precisa estar dentro de JPATH_SITE.'
            );
        }

        if (!JFolder::exists($directory)) {
            if (!JFolder::create(
                $directory,
                self::$directoryPermission
            )) {
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

        $realDirectory = self::normalizePath($realDirectory);

        if (
            $realDirectory !== $siteRoot &&
            strpos($realDirectory, $siteRoot . '/') !== 0
        ) {
            throw new RuntimeException(
                'O diretório de logs validado não é permitido.'
            );
        }

        return $realDirectory;
    }

    private static function loggerKey($category, $level, $directory)
    {
        return self::normalizeCategory($category) .
            ':' .
            self::normalizeLevel($level) .
            ':' .
            md5(self::normalizePath($directory));
    }

    private static function configureLogger($category, $level, $directory = null)
    {
        $category = self::normalizeCategory($category);
        $level = self::normalizeLevel($level);
        $directory = self::resolveBaseDirectory($directory);

        $key = self::loggerKey(
            $category,
            $level,
            $directory
        );

        if (isset(self::$configuredLoggers[$key])) {
            return;
        }

        $fileName = $category .
            '.' .
            $level .
            '.php';

        JLog::addLogger(
            array(
                'text_file' => $fileName,
                'text_file_path' => $directory,
                'text_entry_format' => '{DATETIME} {PRIORITY} {CATEGORY} {MESSAGE}'
            ),
            self::priority($level),
            array($category)
        );

        self::$configuredLoggers[$key] = true;
    }

    private static function requestContext()
    {
        $user = JFactory::getUser();

        return array(
            'request_id' => self::getRequestId(),
            'user_id' => !empty($user) && (int) $user->id > 0
                ? (int) $user->id
                : null,
            'request_method' => isset($_SERVER['REQUEST_METHOD'])
                ? strtoupper($_SERVER['REQUEST_METHOD'])
                : null,
            'request_uri' => isset($_SERVER['REQUEST_URI'])
                ? $_SERVER['REQUEST_URI']
                : null,
            'ip' => isset($_SERVER['REMOTE_ADDR'])
                ? $_SERVER['REMOTE_ADDR']
                : null
        );
    }

    private static function write($level, $message, $category = null, array $context = array(), $directory = null)
    {
        if (!self::$enabled) {
            return false;
        }

        try {
            $level = self::normalizeLevel($level);
            $category = self::normalizeCategory($category);

            if ($directory === null && isset($context['log_directory'])) {
                $directory = $context['log_directory'];

                unset($context['log_directory']);
            }

            self::configureLogger(
                $category,
                $level,
                $directory
            );

            $context = array_merge(
                self::requestContext(),
                $context
            );

            $message = self::truncate($message, 10000);
            $contextText = self::encodeContext($context);

            if ($contextText !== '') {
                $message .= ' | Contexto: ' . $contextText;
            }

            JLog::add(
                $message,
                self::priority($level),
                $category
            );

            return true;
        } catch (Throwable $error) {
            return false;
        }
    }

    public static function setEnabled($enabled)
    {
        self::$enabled = (bool) $enabled;
    }

    public static function setDefaultCategory($category)
    {
        self::$defaultCategory = self::normalizeCategory($category);
    }

    public static function setBaseDirectory($directory)
    {
        self::$baseDirectory = self::resolveBaseDirectory(
            $directory
        );

        return self::$baseDirectory;
    }

    public static function getBaseDirectory()
    {
        return self::resolveBaseDirectory(
            self::$baseDirectory
        );
    }

    public static function setDirectoryPermission($permission)
    {
        $permission = (int) $permission;

        if ($permission <= 0) {
            throw new InvalidArgumentException(
                'Permissão de diretório inválida.'
            );
        }

        self::$directoryPermission = $permission;
    }

    public static function setRequestId($requestId)
    {
        $requestId = trim((string) $requestId);

        if ($requestId === '') {
            throw new InvalidArgumentException(
                'Request ID inválido.'
            );
        }

        self::$requestId = self::truncate(
            $requestId,
            100
        );
    }

    public static function getRequestId()
    {
        if (self::$requestId === null) {
            self::$requestId = self::generateRequestId();
        }

        return self::$requestId;
    }

    public static function addSensitiveKey($key)
    {
        $key = self::normalizeKey($key);

        if (
            $key !== '' &&
            !in_array($key, self::$sensitiveKeys, true)
        ) {
            self::$sensitiveKeys[] = $key;
        }
    }

    public static function sanitize($context)
    {
        return self::sanitizeContext($context);
    }

    public static function debug($message, $category = null, array $context = array(), $directory = null)
    {
        return self::write(
            self::LEVEL_DEBUG,
            $message,
            $category,
            $context,
            $directory
        );
    }

    public static function info($message, $category = null, array $context = array(), $directory = null)
    {
        return self::write(
            self::LEVEL_INFO,
            $message,
            $category,
            $context,
            $directory
        );
    }

    public static function notice($message, $category = null, array $context = array(), $directory = null)
    {
        return self::write(
            self::LEVEL_NOTICE,
            $message,
            $category,
            $context,
            $directory
        );
    }

    public static function warning($message, $category = null, array $context = array(), $directory = null)
    {
        return self::write(
            self::LEVEL_WARNING,
            $message,
            $category,
            $context,
            $directory
        );
    }

    public static function error($message, $category = null, array $context = array(), $directory = null)
    {
        return self::write(
            self::LEVEL_ERROR,
            $message,
            $category,
            $context,
            $directory
        );
    }

    public static function critical($message, $category = null, array $context = array(), $directory = null)
    {
        return self::write(
            self::LEVEL_CRITICAL,
            $message,
            $category,
            $context,
            $directory
        );
    }

    public static function alert($message, $category = null, array $context = array(), $directory = null)
    {
        return self::write(
            self::LEVEL_ALERT,
            $message,
            $category,
            $context,
            $directory
        );
    }

    public static function emergency($message, $category = null, array $context = array(), $directory = null)
    {
        return self::write(
            self::LEVEL_EMERGENCY,
            $message,
            $category,
            $context,
            $directory
        );
    }

    public static function exception(Throwable $error, $category = null, array $context = array(), $directory = null)
    {
        $exceptionContext = array(
            'exception_class' => get_class($error),
            'exception_code' => (int) $error->getCode(),
            'exception_message' => $error->getMessage(),
            'exception_file' => $error->getFile(),
            'exception_line' => $error->getLine(),
            'exception_trace' => self::truncate(
                $error->getTraceAsString(),
                20000
            )
        );

        $context = array_merge(
            $context,
            $exceptionContext
        );

        return self::error(
            'Exceção capturada: ' . $error->getMessage(),
            $category,
            $context,
            $directory
        );
    }

    public static function database(Throwable $error, $query = null, array $context = array(), $directory = null)
    {
        if ($query !== null) {
            $context['query'] = self::truncate(
                $query,
                15000
            );
        }

        return self::exception(
            $error,
            'database',
            $context,
            $directory
        );
    }

    public static function security($message, array $context = array(), $directory = null)
    {
        return self::warning(
            $message,
            'security',
            $context,
            $directory
        );
    }

    public static function api($message, array $context = array(), $level = self::LEVEL_INFO, $directory = null)
    {
        return self::write(
            $level,
            $message,
            'api',
            $context,
            $directory
        );
    }

    public static function upload($message, array $context = array(), $level = self::LEVEL_INFO, $directory = null)
    {
        return self::write(
            $level,
            $message,
            'upload',
            $context,
            $directory
        );
    }

    public static function queue($message, array $context = array(), $level = self::LEVEL_INFO, $directory = null)
    {
        return self::write(
            $level,
            $message,
            'queue',
            $context,
            $directory
        );
    }

    public static function audit($message, array $context = array(), $level = self::LEVEL_INFO, $directory = null)
    {
        return self::write(
            $level,
            $message,
            'audit',
            $context,
            $directory
        );
    }

    public static function payment($message, array $context = array(), $level = self::LEVEL_INFO, $directory = null)
    {
        return self::write(
            $level,
            $message,
            'payment',
            $context,
            $directory
        );
    }
}
