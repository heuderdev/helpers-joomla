<?php

defined('_JEXEC') or die;

class ApiResponseHelper
{
    const STATUS_SUCCESS = 'sucesso';

    const STATUS_ERROR = 'erro';

    const STATUS_WARNING = 'aviso';

    const STATUS_INFO = 'info';

    const HTTP_OK = 200;

    const HTTP_CREATED = 201;

    const HTTP_NO_CONTENT = 204;

    const HTTP_BAD_REQUEST = 400;

    const HTTP_UNAUTHORIZED = 401;

    const HTTP_FORBIDDEN = 403;

    const HTTP_NOT_FOUND = 404;

    const HTTP_METHOD_NOT_ALLOWED = 405;

    const HTTP_CONFLICT = 409;

    const HTTP_UNPROCESSABLE_ENTITY = 422;

    const HTTP_TOO_MANY_REQUESTS = 429;

    const HTTP_INTERNAL_SERVER_ERROR = 500;

    const HTTP_SERVICE_UNAVAILABLE = 503;

    private static $defaultRedirect = null;

    private static $closeApplication = true;

    private static $logErrors = true;

    private static $logCategory = 'api_response';

    private static function app()
    {
        return JFactory::getApplication();
    }

    private static function isJsonRequest()
    {
        try {
            $app = self::app();

            $format = strtolower(
                trim(
                    (string) $app->input->getCmd(
                        'format',
                        ''
                    )
                )
            );

            if ($format === 'json') {
                return true;
            }

            $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                ? strtolower(
                    trim(
                        (string) $_SERVER['HTTP_X_REQUESTED_WITH']
                    )
                )
                : '';

            if ($requestedWith === 'xmlhttprequest') {
                return true;
            }

            $accept = isset($_SERVER['HTTP_ACCEPT'])
                ? strtolower(
                    (string) $_SERVER['HTTP_ACCEPT']
                )
                : '';

            return strpos($accept, 'application/json') !== false;
        } catch (Throwable $error) {
            return false;
        }
    }

    private static function getRequestId()
    {
        try {
            if (class_exists('LogHelper')) {
                return LogHelper::getRequestId();
            }

            if (function_exists('random_bytes')) {
                return bin2hex(random_bytes(16));
            }

            return sha1(
                uniqid('', true) .
                mt_rand()
            );
        } catch (Throwable $error) {
            return sha1(
                uniqid('', true) .
                mt_rand()
            );
        }
    }

    private static function normalizeErrors($errors)
    {
        if ($errors === null) {
            return array();
        }

        if (is_string($errors)) {
            return array(
                '_general' => array(
                    $errors
                )
            );
        }

        if (!is_array($errors)) {
            return array(
                '_general' => array(
                    'Dados de erro inválidos.'
                )
            );
        }

        $normalized = array();

        foreach ($errors as $field => $messages) {
            if (!is_array($messages)) {
                $messages = array($messages);
            }

            $normalized[$field] = array();

            foreach ($messages as $message) {
                if (
                    $message === null ||
                    $message === ''
                ) {
                    continue;
                }

                $normalized[$field][] = (string) $message;
            }

            if (empty($normalized[$field])) {
                unset($normalized[$field]);
            }
        }

        return $normalized;
    }

    private static function firstError(array $errors, $default = '')
    {
        foreach ($errors as $messages) {
            if (is_array($messages) && !empty($messages)) {
                return (string) reset($messages);
            }
        }

        return (string) $default;
    }

    private static function sanitizeData($data)
    {
        if ($data === null) {
            return null;
        }

        if (class_exists('LogHelper')) {
            return LogHelper::sanitize($data);
        }

        return $data;
    }

    private static function logError($message, array $context = array())
    {
        if (!self::$logErrors) {
            return;
        }

        try {
            if (class_exists('LogHelper')) {
                LogHelper::error(
                    $message,
                    self::$logCategory,
                    $context
                );

                return;
            }

            JLog::add(
                $message,
                JLog::ERROR,
                self::$logCategory
            );
        } catch (Throwable $error) {
        }
    }

    private static function setHttpStatus($statusCode)
    {
        $statusCode = (int) $statusCode;

        if ($statusCode < 100 || $statusCode > 599) {
            return;
        }

        try {
            self::app()->setHeader(
                'Status',
                (string) $statusCode,
                true
            );

            http_response_code($statusCode);
        } catch (Throwable $error) {
        }
    }

    private static function buildPayload($success, $status, $message, $data = null, array $errors = array(), array $meta = array())
    {
        $payload = array(
            'success' => (bool) $success,
            'status' => (string) $status,
            'mensagem' => (string) $message,
            'data' => $data,
            'errors' => self::normalizeErrors($errors),
            'request_id' => self::getRequestId()
        );

        if (!empty($meta)) {
            $payload['meta'] = $meta;
        }

        return $payload;
    }

    private static function enqueueMessage($message, $type)
    {
        if (trim((string) $message) === '') {
            return;
        }

        try {
            self::app()->enqueueMessage(
                $message,
                $type
            );
        } catch (Throwable $error) {
            self::logError(
                'Não foi possível adicionar mensagem à fila do Joomla.',
                array(
                    'erro' => $error->getMessage()
                )
            );
        }
    }

    private static function outputJson(array $payload, $statusCode = self::HTTP_OK, $close = null)
    {
        if ($close === null) {
            $close = self::$closeApplication;
        }

        self::setHttpStatus($statusCode);

        try {
            self::app()->setHeader(
                'Content-Type',
                'application/json; charset=utf-8',
                true
            );
        } catch (Throwable $error) {
        }

        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE |
            JSON_UNESCAPED_SLASHES
        );

        if ($close) {
            self::app()->close();
        }

        return $payload;
    }

    private static function redirect($url = null)
    {
        $url = $url !== null
            ? trim((string) $url)
            : self::$defaultRedirect;

        if ($url === null || $url === '') {
            return false;
        }

        self::app()->redirect($url);

        return true;
    }

    private static function respond($success, $status, $message, $data = null, array $errors = array(), $httpStatus = self::HTTP_OK, array $options = array())
    {
        $errors = self::normalizeErrors($errors);

        if (
            !$success &&
            trim((string) $message) === ''
        ) {
            $message = self::firstError(
                $errors,
                'Não foi possível concluir a operação.'
            );
        }

        $payload = self::buildPayload(
            $success,
            $status,
            $message,
            self::sanitizeData($data),
            $errors,
            isset($options['meta']) && is_array($options['meta'])
                ? $options['meta']
                : array()
        );

        if (!$success && !empty($options['log'])) {
            self::logError(
                $message,
                array(
                    'http_status' => $httpStatus,
                    'errors' => $errors,
                    'data' => self::sanitizeData($data)
                )
            );
        }

        $expectsJson = isset($options['json'])
            ? (bool) $options['json']
            : self::isJsonRequest();

        if ($expectsJson) {
            return self::outputJson(
                $payload,
                $httpStatus,
                isset($options['close'])
                    ? (bool) $options['close']
                    : null
            );
        }

        $messageType = $success
            ? 'message'
            : 'error';

        if ($status === self::STATUS_WARNING) {
            $messageType = 'warning';
        }

        if ($status === self::STATUS_INFO) {
            $messageType = 'notice';
        }

        self::enqueueMessage(
            $message,
            $messageType
        );

        if (!empty($options['redirect'])) {
            self::redirect($options['redirect']);
        }

        return $payload;
    }

    public static function setDefaultRedirect($url)
    {
        self::$defaultRedirect = trim((string) $url);
    }

    public static function setCloseApplication($close)
    {
        self::$closeApplication = (bool) $close;
    }

    public static function setLogErrors($logErrors)
    {
        self::$logErrors = (bool) $logErrors;
    }

    public static function setLogCategory($category)
    {
        $category = trim((string) $category);

        if ($category !== '') {
            self::$logCategory = $category;
        }
    }

    public static function isJson()
    {
        return self::isJsonRequest();
    }

    public static function success($message = 'Operação realizada com sucesso.', $data = null, array $options = array())
    {
        return self::respond(
            true,
            self::STATUS_SUCCESS,
            $message,
            $data,
            array(),
            isset($options['http_status'])
                ? (int) $options['http_status']
                : self::HTTP_OK,
            $options
        );
    }

    public static function created($message = 'Registro criado com sucesso.', $data = null, array $options = array())
    {
        $options['http_status'] = self::HTTP_CREATED;

        return self::success(
            $message,
            $data,
            $options
        );
    }

    public static function updated($message = 'Registro atualizado com sucesso.', $data = null, array $options = array())
    {
        return self::success(
            $message,
            $data,
            $options
        );
    }

    public static function deleted($message = 'Registro excluído com sucesso.', $data = null, array $options = array())
    {
        return self::success(
            $message,
            $data,
            $options
        );
    }

    public static function info($message, $data = null, array $options = array())
    {
        return self::respond(
            true,
            self::STATUS_INFO,
            $message,
            $data,
            array(),
            isset($options['http_status'])
                ? (int) $options['http_status']
                : self::HTTP_OK,
            $options
        );
    }

    public static function warning($message, $data = null, array $options = array())
    {
        return self::respond(
            true,
            self::STATUS_WARNING,
            $message,
            $data,
            array(),
            isset($options['http_status'])
                ? (int) $options['http_status']
                : self::HTTP_OK,
            $options
        );
    }

    public static function error($message = 'Não foi possível concluir a operação.', $data = null, array $errors = array(), array $options = array())
    {
        $options['log'] = isset($options['log'])
            ? (bool) $options['log']
            : true;

        return self::respond(
            false,
            self::STATUS_ERROR,
            $message,
            $data,
            $errors,
            isset($options['http_status'])
                ? (int) $options['http_status']
                : self::HTTP_BAD_REQUEST,
            $options
        );
    }

    public static function validationError(array $errors, $message = 'Corrija os campos informados.', array $options = array())
    {
        $options['http_status'] = self::HTTP_UNPROCESSABLE_ENTITY;
        $options['log'] = isset($options['log'])
            ? (bool) $options['log']
            : false;

        return self::respond(
            false,
            self::STATUS_ERROR,
            $message,
            null,
            $errors,
            self::HTTP_UNPROCESSABLE_ENTITY,
            $options
        );
    }

    public static function badRequest($message = 'Requisição inválida.', $data = null, array $options = array())
    {
        $options['http_status'] = self::HTTP_BAD_REQUEST;

        return self::error(
            $message,
            $data,
            array(),
            $options
        );
    }

    public static function unauthorized($message = 'Usuário não autenticado.', array $options = array())
    {
        $options['http_status'] = self::HTTP_UNAUTHORIZED;

        return self::error(
            $message,
            null,
            array(),
            $options
        );
    }

    public static function forbidden($message = 'Você não possui permissão para esta operação.', array $options = array())
    {
        $options['http_status'] = self::HTTP_FORBIDDEN;

        return self::error(
            $message,
            null,
            array(),
            $options
        );
    }

    public static function notFound($message = 'Registro não encontrado.', array $options = array())
    {
        $options['http_status'] = self::HTTP_NOT_FOUND;

        return self::error(
            $message,
            null,
            array(),
            $options
        );
    }

    public static function methodNotAllowed($message = 'Método de requisição não permitido.', array $options = array())
    {
        $options['http_status'] = self::HTTP_METHOD_NOT_ALLOWED;

        return self::error(
            $message,
            null,
            array(),
            $options
        );
    }

    public static function conflict($message = 'A operação não pode ser concluída devido a um conflito de dados.', $data = null, array $options = array())
    {
        $options['http_status'] = self::HTTP_CONFLICT;

        return self::error(
            $message,
            $data,
            array(),
            $options
        );
    }

    public static function tooManyRequests($message = 'Muitas tentativas. Aguarde alguns instantes e tente novamente.', array $options = array())
    {
        $options['http_status'] = self::HTTP_TOO_MANY_REQUESTS;

        return self::error(
            $message,
            null,
            array(),
            $options
        );
    }

    public static function serviceUnavailable($message = 'Serviço temporariamente indisponível.', array $options = array())
    {
        $options['http_status'] = self::HTTP_SERVICE_UNAVAILABLE;

        return self::error(
            $message,
            null,
            array(),
            $options
        );
    }

    public static function exception(Throwable $error, $publicMessage = 'Ocorreu um erro interno ao processar a solicitação.', array $options = array())
    {
        try {
            if (class_exists('LogHelper')) {
                LogHelper::exception(
                    $error,
                    isset($options['log_category'])
                        ? $options['log_category']
                        : self::$logCategory,
                    isset($options['context'])
                        ? (array) $options['context']
                        : array()
                );
            } else {
                JLog::add(
                    $error->getMessage() .
                    ' | Arquivo: ' .
                    $error->getFile() .
                    ' | Linha: ' .
                    $error->getLine(),
                    JLog::ERROR,
                    self::$logCategory
                );
            }
        } catch (Throwable $logError) {
        }

        $options['http_status'] = self::HTTP_INTERNAL_SERVER_ERROR;
        $options['log'] = false;

        return self::error(
            $publicMessage,
            null,
            array(),
            $options
        );
    }

    public static function fromValidation(array $validation, $message = 'Corrija os campos informados.', array $options = array())
    {
        if (
            isset($validation['valid']) &&
            $validation['valid'] === true
        ) {
            return self::success(
                isset($options['success_message'])
                    ? $options['success_message']
                    : 'Dados válidos.',
                isset($options['data'])
                    ? $options['data']
                    : null,
                $options
            );
        }

        $errors = isset($validation['errors'])
            ? (array) $validation['errors']
            : array();

        if (
            isset($validation['first_error']) &&
            trim((string) $validation['first_error']) !== ''
        ) {
            $message = $validation['first_error'];
        }

        return self::validationError(
            $errors,
            $message,
            $options
        );
    }
}