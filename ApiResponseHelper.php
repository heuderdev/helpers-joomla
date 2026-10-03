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

    /*
     * Joomla 4+: getInput(). O acesso direto a $app->input está obsoleto
     * e deve deixar de existir; no Joomla 3 é a única forma.
     */
    private static function input()
    {
        $app = self::app();

        return method_exists($app, 'getInput')
            ? $app->getInput()
            : $app->input;
    }

    private static function isJsonRequest()
    {
        try {
            $format = strtolower(
                trim(
                    (string) self::input()->getCmd(
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

            if (strpos($accept, 'application/json') !== false) {
                return true;
            }

            /*
             * fetch()/axios enviando JSON: o Accept padrão do fetch aceita
             * qualquer tipo e não há X-Requested-With, mas quem envia
             * JSON espera JSON de volta.
             */
            $contentType = isset($_SERVER['CONTENT_TYPE'])
                ? (string) $_SERVER['CONTENT_TYPE']
                : (isset($_SERVER['HTTP_CONTENT_TYPE']) ? (string) $_SERVER['HTTP_CONTENT_TYPE'] : '');

            return stripos($contentType, 'application/json') !== false;
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

        // Lista simples: array('msg 1', 'msg 2') → '_general'.
        if (!empty($errors) && array_keys($errors) === range(0, count($errors) - 1)) {
            $scalars = true;

            foreach ($errors as $message) {
                if (!is_scalar($message)) {
                    $scalars = false;
                    break;
                }
            }

            if ($scalars) {
                $errors = array('_general' => $errors);
            }
        }

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

    /*
     * Mascara dados sensíveis e reduz textos longos. Só para o LOG: a
     * sanitização do LogHelper converte os valores em texto, mascara
     * chaves com "token", "session"... e corta textos longos, o que
     * corromperia a resposta enviada ao cliente.
     */
    private static function sanitizeForLog($data)
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

    private static function encodeJson(array $payload)
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

        // PHP 7.2+: texto com UTF-8 inválido vira "\ufffd" em vez de derrubar tudo.
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        return json_encode($payload, $flags);
    }

    private static function setHeader($name, $value)
    {
        try {
            self::app()->setHeader($name, (string) $value, true);
        } catch (Throwable $error) {
        }
    }

    /*
     * Envia os cabeçalhos registrados no Joomla. Necessário quando a
     * aplicação é encerrada logo depois: $app->close() é só um exit, e o
     * Joomla só envia os cabeçalhos ao montar a página.
     */
    private static function sendHeaders(array $headers)
    {
        if (headers_sent()) {
            return;
        }

        try {
            self::app()->sendHeaders();

            return;
        } catch (Throwable $error) {
        }

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value, true);
        }
    }

    private static function outputJson(array $payload, $statusCode = self::HTTP_OK, $close = null, array $headers = array())
    {
        if ($close === null) {
            $close = self::$closeApplication;
        }

        $json = self::encodeJson($payload);

        if ($json === false) {
            self::logError(
                'Não foi possível converter a resposta para JSON.',
                array(
                    'erro' => json_last_error_msg(),
                    'http_status' => $statusCode
                )
            );

            $statusCode = self::HTTP_INTERNAL_SERVER_ERROR;
            $payload = self::buildPayload(
                false,
                self::STATUS_ERROR,
                'Não foi possível gerar a resposta.',
                null,
                array(),
                array()
            );
            $json = self::encodeJson($payload);
        }

        $headers = array_merge(
            array(
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store, no-cache, must-revalidate',
                'X-Request-Id' => $payload['request_id']
            ),
            $headers
        );

        self::setHttpStatus($statusCode);

        foreach ($headers as $name => $value) {
            self::setHeader($name, $value);
        }

        if ($close) {
            /*
             * Descarta o que já foi impresso (avisos do PHP, espaços,
             * HTML do componente): misturado ao JSON, impediria o
             * JavaScript de ler a resposta.
             */
            while (ob_get_level() > 0 && @ob_end_clean()) {
            }

            self::sendHeaders($headers);
        }

        echo $json;

        if ($close) {
            self::app()->close();
        }

        return $payload;
    }

    /*
     * Só redireciona para URLs do próprio site, a menos que
     * $allowExternal seja true: evita "open redirect" quando a URL vem
     * da requisição (parâmetro "return", por exemplo).
     */
    private static function redirect($url, $allowExternal = false)
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        if (!$allowExternal && class_exists('JUri') && !JUri::isInternal($url)) {
            self::logError(
                'Redirecionamento para URL externa bloqueado.',
                array(
                    'url' => $url
                )
            );

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
            $data,
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
                    'data' => self::sanitizeForLog($data)
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
                    : null,
                isset($options['headers']) && is_array($options['headers'])
                    ? $options['headers']
                    : array()
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

        // 'redirect' => false desliga o redirecionamento padrão.
        $redirect = array_key_exists('redirect', $options)
            ? $options['redirect']
            : self::$defaultRedirect;

        if (!empty($redirect)) {
            self::redirect(
                $redirect,
                !empty($options['allow_external_redirect'])
            );
        }

        return $payload;
    }

    /*
     * URL para onde as respostas HTML (não JSON) redirecionam depois de
     * registrar a mensagem. Pode ser trocada por resposta com a opção
     * 'redirect'. Vazio desliga.
     */
    public static function setDefaultRedirect($url)
    {
        $url = trim((string) $url);

        self::$defaultRedirect = $url === '' ? null : $url;
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

    public static function validationError(
    array $errors,
    $message = 'Corrija os campos informados.',
    array $options = array()
    ) {
        $httpStatus = isset($options['http_status'])
            ? (int) $options['http_status']
            : self::HTTP_UNPROCESSABLE_ENTITY;

        $options['http_status'] = $httpStatus;

        $options['log'] = isset($options['log'])
            ? (bool) $options['log']
            : false;

        return self::respond(
            false,
            self::STATUS_ERROR,
            $message,
            null,
            $errors,
            $httpStatus,
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

    /*
     * $options['retry_after']: segundos até poder tentar de novo
     * (cabeçalho Retry-After).
     */
    public static function tooManyRequests($message = 'Muitas tentativas. Aguarde alguns instantes e tente novamente.', array $options = array())
    {
        $options['http_status'] = self::HTTP_TOO_MANY_REQUESTS;

        if (isset($options['retry_after']) && (int) $options['retry_after'] > 0) {
            $options['headers'] = array_merge(
                isset($options['headers']) ? (array) $options['headers'] : array(),
                array('Retry-After' => (string) (int) $options['retry_after'])
            );
        }

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

        // Com o debug do Joomla ligado (desenvolvimento), devolve os detalhes.
        if (defined('JDEBUG') && JDEBUG) {
            $options['meta'] = array_merge(
                isset($options['meta']) && is_array($options['meta']) ? $options['meta'] : array(),
                array(
                    'debug' => array(
                        'tipo' => get_class($error),
                        'mensagem' => $error->getMessage(),
                        'arquivo' => $error->getFile(),
                        'linha' => $error->getLine()
                    )
                )
            );
        }

        return self::error(
            $publicMessage,
            null,
            array(),
            $options
        );
    }

    /*
     * Resposta de listagem a partir do resultado de OrmBase::paginate():
     * os itens vão em 'data' e os números da paginação em
     * 'meta.paginacao'. O objeto JPagination é descartado.
     */
    public static function paginated(array $result, $message = 'Registros carregados.', array $options = array())
    {
        $meta = isset($options['meta']) && is_array($options['meta'])
            ? $options['meta']
            : array();

        $meta['paginacao'] = array(
            'total' => isset($result['total']) ? (int) $result['total'] : 0,
            'por_pagina' => isset($result['por_pagina']) ? (int) $result['por_pagina'] : 0,
            'pagina_atual' => isset($result['pagina_atual']) ? (int) $result['pagina_atual'] : 1,
            'total_paginas' => isset($result['total_paginas']) ? (int) $result['total_paginas'] : 0
        );

        $options['meta'] = $meta;

        return self::success(
            $message,
            isset($result['itens']) ? $result['itens'] : array(),
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