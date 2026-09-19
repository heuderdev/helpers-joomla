<?php

defined('_JEXEC') or die;

class AuditHelper
{
    const LEVEL_DEBUG = 'debug';

    const LEVEL_INFO = 'info';

    const LEVEL_NOTICE = 'notice';

    const LEVEL_WARNING = 'warning';

    const LEVEL_ERROR = 'error';

    const LEVEL_CRITICAL = 'critical';

    const STATUS_SUCCESS = 'success';

    const STATUS_FAILURE = 'failure';

    const STATUS_DENIED = 'denied';

    const STATUS_PENDING = 'pending';

    const STATUS_CANCELLED = 'cancelled';

    const EVENT_CREATED = 'created';

    const EVENT_UPDATED = 'updated';

    const EVENT_DELETED = 'deleted';

    const EVENT_RESTORED = 'restored';

    const EVENT_VIEWED = 'viewed';

    const EVENT_DOWNLOADED = 'downloaded';

    const EVENT_EXPORTED = 'exported';

    const EVENT_IMPORTED = 'imported';

    const EVENT_UPLOADED = 'uploaded';

    const EVENT_LOGIN = 'login';

    const EVENT_LOGOUT = 'logout';

    const EVENT_LOGIN_FAILED = 'login_failed';

    const EVENT_PASSWORD_CHANGED = 'password_changed';

    const EVENT_PASSWORD_RESET = 'password_reset';

    const EVENT_PERMISSION_CHANGED = 'permission_changed';

    const EVENT_STATUS_CHANGED = 'status_changed';

    const EVENT_PAYMENT_CREATED = 'payment_created';

    const EVENT_PAYMENT_CONFIRMED = 'payment_confirmed';

    const EVENT_PAYMENT_CANCELLED = 'payment_cancelled';

    const EVENT_PAYMENT_REFUNDED = 'payment_refunded';

    const EVENT_WEBHOOK_RECEIVED = 'webhook_received';

    const EVENT_QUEUE_CREATED = 'queue_created';

    const EVENT_QUEUE_COMPLETED = 'queue_completed';

    const EVENT_QUEUE_FAILED = 'queue_failed';

    const EVENT_SECURITY_DENIED = 'security_denied';

    const EVENT_EXCEPTION = 'exception';

    const EVENT_CUSTOM = 'custom';

    private static $table = '#__audit_logs';

    private static $requestId = null;

    private static $sensitiveKeys = array(
        'password',
        'senha',
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

    private static $maxTextLength = 5000;

    private static $maxDataLength = 250000;

    private static $enabled = true;

    private static function db()
    {
        return JFactory::getDbo();
    }

    private static function app()
    {
        return JFactory::getApplication();
    }

    private static function agora()
    {
        return JFactory::getDate()->toSql();
    }

    private static function gerarUuid()
    {
        if (function_exists('random_bytes')) {
            $bytes = random_bytes(16);
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(16);
        } else {
            throw new RuntimeException('Não foi possível gerar UUID seguro.');
        }

        if ($bytes === false) {
            throw new RuntimeException('Não foi possível gerar UUID seguro.');
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split(bin2hex($bytes), 4)
        );
    }

    private static function registrarErroInterno($metodo, Throwable $erro)
    {
        JLog::add(
            '[' . $metodo . '] ' .
            $erro->getMessage() .
            ' | Arquivo: ' . $erro->getFile() .
            ' | Linha: ' . $erro->getLine(),
            JLog::ERROR,
            'audit_helper'
        );
    }

    private static function limitarTexto($valor, $limite = null)
    {
        if ($limite === null) {
            $limite = self::$maxTextLength;
        }

        $valor = (string) $valor;

        if (strlen($valor) <= $limite) {
            return $valor;
        }

        return substr($valor, 0, $limite) . '...[truncado]';
    }

    private static function jsonEncode($dados)
    {
        if ($dados === null) {
            return null;
        }

        $json = json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException(
                'Não foi possível converter os dados da auditoria para JSON.'
            );
        }

        return self::limitarTexto($json, self::$maxDataLength);
    }

    private static function normalizarChave($chave)
    {
        $chave = strtolower((string) $chave);
        $chave = str_replace(array('-', ' '), '_', $chave);

        return $chave;
    }

    private static function chaveSensivel($chave)
    {
        $chave = self::normalizarChave($chave);

        foreach (self::$sensitiveKeys as $sensivel) {
            if (
                $chave === $sensivel ||
                strpos($chave, $sensivel . '_') === 0 ||
                substr($chave, -strlen('_' . $sensivel)) === '_' . $sensivel ||
                strpos($chave, $sensivel) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    private static function mascararValor($valor)
    {
        if (is_array($valor)) {
            return self::sanitizarDados($valor);
        }

        if (is_object($valor)) {
            return self::sanitizarDados((array) $valor);
        }

        if ($valor === null || $valor === '') {
            return $valor;
        }

        $valor = (string) $valor;
        $tamanho = strlen($valor);

        if ($tamanho <= 4) {
            return '****';
        }

        return str_repeat('*', max(4, $tamanho - 4)) . substr($valor, -4);
    }

    private static function sanitizarDados($dados)
    {
        if ($dados === null) {
            return null;
        }

        if (is_object($dados)) {
            $dados = (array) $dados;
        }

        if (!is_array($dados)) {
            return self::limitarTexto($dados);
        }

        $resultado = array();

        foreach ($dados as $chave => $valor) {
            if (self::chaveSensivel($chave)) {
                $resultado[$chave] = self::mascararValor($valor);

                continue;
            }

            if (is_array($valor) || is_object($valor)) {
                $resultado[$chave] = self::sanitizarDados($valor);

                continue;
            }

            $resultado[$chave] = self::limitarTexto($valor);
        }

        return $resultado;
    }

    private static function obterUsuario()
    {
        try {
            $usuario = JFactory::getUser();

            if (empty($usuario) || (int) $usuario->id <= 0) {
                return array(
                    'id'       => null,
                    'name'     => null,
                    'username' => null
                );
            }

            return array(
                'id'       => (int) $usuario->id,
                'name'     => self::limitarTexto($usuario->name, 255),
                'username' => self::limitarTexto($usuario->username, 255)
            );
        } catch (Throwable $erro) {
            self::registrarErroInterno(__METHOD__, $erro);

            return array(
                'id'       => null,
                'name'     => null,
                'username' => null
            );
        }
    }

    private static function obterIp()
    {
        $ip = '';

        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = trim((string) $_SERVER['REMOTE_ADDR']);
        }

        if ($ip === '') {
            return null;
        }

        return self::limitarTexto($ip, 64);
    }

    private static function obterUserAgent()
    {
        if (empty($_SERVER['HTTP_USER_AGENT'])) {
            return null;
        }

        return self::limitarTexto($_SERVER['HTTP_USER_AGENT'], 1000);
    }

    private static function obterReferer()
    {
        if (empty($_SERVER['HTTP_REFERER'])) {
            return null;
        }

        return self::limitarTexto($_SERVER['HTTP_REFERER'], 2000);
    }

    private static function obterMetodoRequisicao()
    {
        if (empty($_SERVER['REQUEST_METHOD'])) {
            return null;
        }

        return self::limitarTexto(
            strtoupper($_SERVER['REQUEST_METHOD']),
            10
        );
    }

    private static function obterUriRequisicao()
    {
        if (empty($_SERVER['REQUEST_URI'])) {
            return null;
        }

        return self::limitarTexto($_SERVER['REQUEST_URI'], 2000);
    }

    private static function validarEvento($evento)
    {
        $evento = trim((string) $evento);

        if ($evento === '') {
            throw new InvalidArgumentException('O evento da auditoria é obrigatório.');
        }

        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $evento)) {
            throw new InvalidArgumentException('O evento da auditoria possui caracteres inválidos.');
        }

        return self::limitarTexto($evento, 120);
    }

    private static function validarCategoria($categoria)
    {
        $categoria = trim((string) $categoria);

        if ($categoria === '') {
            $categoria = 'sistema';
        }

        if (!preg_match('/^[a-zA-Z0-9_.:-]+$/', $categoria)) {
            $categoria = 'sistema';
        }

        return self::limitarTexto($categoria, 100);
    }

    private static function validarLevel($level)
    {
        $level = strtolower(trim((string) $level));

        $levelsPermitidos = array(
            self::LEVEL_DEBUG,
            self::LEVEL_INFO,
            self::LEVEL_NOTICE,
            self::LEVEL_WARNING,
            self::LEVEL_ERROR,
            self::LEVEL_CRITICAL
        );

        if (!in_array($level, $levelsPermitidos, true)) {
            return self::LEVEL_INFO;
        }

        return $level;
    }

    private static function validarStatus($status)
    {
        $status = strtolower(trim((string) $status));

        $statusPermitidos = array(
            self::STATUS_SUCCESS,
            self::STATUS_FAILURE,
            self::STATUS_DENIED,
            self::STATUS_PENDING,
            self::STATUS_CANCELLED
        );

        if (!in_array($status, $statusPermitidos, true)) {
            return self::STATUS_SUCCESS;
        }

        return $status;
    }

    private static function diferencaDados(array $antes, array $depois)
    {
        $resultado = array();

        $chaves = array_unique(
            array_merge(
                array_keys($antes),
                array_keys($depois)
            )
        );

        foreach ($chaves as $chave) {
            $existeAntes = array_key_exists($chave, $antes);
            $existeDepois = array_key_exists($chave, $depois);

            $valorAntes = $existeAntes ? $antes[$chave] : null;
            $valorDepois = $existeDepois ? $depois[$chave] : null;

            if ($valorAntes === $valorDepois) {
                continue;
            }

            if (
                is_array($valorAntes) ||
                is_object($valorAntes) ||
                is_array($valorDepois) ||
                is_object($valorDepois)
            ) {
                $valorAntesArray = is_array($valorAntes)
                    ? $valorAntes
                    : (array) $valorAntes;

                $valorDepoisArray = is_array($valorDepois)
                    ? $valorDepois
                    : (array) $valorDepois;

                $resultado[$chave] = self::diferencaDados(
                    $valorAntesArray,
                    $valorDepoisArray
                );

                continue;
            }

            $resultado[$chave] = array(
                'antes' => $valorAntes,
                'depois' => $valorDepois
            );
        }

        return $resultado;
    }

    private static function inserir(array $dados)
    {
        $db = self::db();

        $registro = new stdClass();

        $registro->uuid                  = $dados['uuid'];
        $registro->request_id            = $dados['request_id'];
        $registro->event                 = $dados['event'];
        $registro->category              = $dados['category'];
        $registro->level                 = $dados['level'];
        $registro->status                = $dados['status'];
        $registro->entity_type           = $dados['entity_type'];
        $registro->entity_id             = $dados['entity_id'];
        $registro->parent_entity_type    = $dados['parent_entity_type'];
        $registro->parent_entity_id      = $dados['parent_entity_id'];
        $registro->user_id               = $dados['user_id'];
        $registro->user_name             = $dados['user_name'];
        $registro->user_username         = $dados['user_username'];
        $registro->ip_address            = $dados['ip_address'];
        $registro->user_agent            = $dados['user_agent'];
        $registro->request_method        = $dados['request_method'];
        $registro->request_uri           = $dados['request_uri'];
        $registro->referer               = $dados['referer'];
        $registro->description           = $dados['description'];
        $registro->before_data           = $dados['before_data'];
        $registro->after_data            = $dados['after_data'];
        $registro->metadata              = $dados['metadata'];
        $registro->created_at            = $dados['created_at'];

        $db->insertObject(self::$table, $registro, 'id');

        return (int) $registro->id;
    }

    public static function setEnabled($enabled)
    {
        self::$enabled = (bool) $enabled;
    }

    public static function setTable($table)
    {
        $table = trim((string) $table);

        if ($table === '') {
            throw new InvalidArgumentException('A tabela de auditoria é obrigatória.');
        }

        if (!preg_match('/^[#_a-zA-Z0-9]+$/', $table)) {
            throw new InvalidArgumentException('Nome de tabela de auditoria inválido.');
        }

        self::$table = $table;
    }

    public static function getRequestId()
    {
        if (self::$requestId === null) {
            self::$requestId = self::gerarUuid();
        }

        return self::$requestId;
    }

    public static function setRequestId($requestId)
    {
        $requestId = trim((string) $requestId);

        if ($requestId === '') {
            throw new InvalidArgumentException('Request ID inválido.');
        }

        self::$requestId = self::limitarTexto($requestId, 36);
    }

    public static function addSensitiveKey($key)
    {
        $key = self::normalizarChave($key);

        if ($key === '') {
            return;
        }

        if (!in_array($key, self::$sensitiveKeys, true)) {
            self::$sensitiveKeys[] = $key;
        }
    }

    public static function sanitize($dados)
    {
        return self::sanitizarDados($dados);
    }

    public static function diff(array $antes, array $depois)
    {
        return self::diferencaDados(
            self::sanitizarDados($antes),
            self::sanitizarDados($depois)
        );
    }

    public static function log($evento, array $opcoes = array())
    {
        if (!self::$enabled) {
            return 0;
        }

        try {
            $usuario = isset($opcoes['user'])
                ? (array) $opcoes['user']
                : self::obterUsuario();

            $antes = isset($opcoes['before'])
                ? self::sanitizarDados($opcoes['before'])
                : null;

            $depois = isset($opcoes['after'])
                ? self::sanitizarDados($opcoes['after'])
                : null;

            $metadata = isset($opcoes['metadata'])
                ? self::sanitizarDados($opcoes['metadata'])
                : array();

            if (
                isset($opcoes['only_changes']) &&
                $opcoes['only_changes'] === true &&
                is_array($antes) &&
                is_array($depois)
            ) {
                $metadata['changes'] = self::diferencaDados($antes, $depois);
            }

            $dados = array(
                'uuid'               => self::gerarUuid(),
                'request_id'         => isset($opcoes['request_id'])
                    ? self::limitarTexto($opcoes['request_id'], 36)
                    : self::getRequestId(),
                'event'              => self::validarEvento($evento),
                'category'           => self::validarCategoria(
                    isset($opcoes['category'])
                        ? $opcoes['category']
                        : 'sistema'
                ),
                'level'              => self::validarLevel(
                    isset($opcoes['level'])
                        ? $opcoes['level']
                        : self::LEVEL_INFO
                ),
                'status'             => self::validarStatus(
                    isset($opcoes['status'])
                        ? $opcoes['status']
                        : self::STATUS_SUCCESS
                ),
                'entity_type'        => isset($opcoes['entity_type'])
                    ? self::limitarTexto($opcoes['entity_type'], 120)
                    : null,
                'entity_id'          => isset($opcoes['entity_id'])
                    ? self::limitarTexto($opcoes['entity_id'], 120)
                    : null,
                'parent_entity_type' => isset($opcoes['parent_entity_type'])
                    ? self::limitarTexto($opcoes['parent_entity_type'], 120)
                    : null,
                'parent_entity_id'   => isset($opcoes['parent_entity_id'])
                    ? self::limitarTexto($opcoes['parent_entity_id'], 120)
                    : null,
                'user_id'            => isset($usuario['id']) && (int) $usuario['id'] > 0
                    ? (int) $usuario['id']
                    : null,
                'user_name'          => isset($usuario['name'])
                    ? self::limitarTexto($usuario['name'], 255)
                    : null,
                'user_username'      => isset($usuario['username'])
                    ? self::limitarTexto($usuario['username'], 255)
                    : null,
                'ip_address'         => isset($opcoes['ip_address'])
                    ? self::limitarTexto($opcoes['ip_address'], 64)
                    : self::obterIp(),
                'user_agent'         => isset($opcoes['user_agent'])
                    ? self::limitarTexto($opcoes['user_agent'], 1000)
                    : self::obterUserAgent(),
                'request_method'     => isset($opcoes['request_method'])
                    ? self::limitarTexto($opcoes['request_method'], 10)
                    : self::obterMetodoRequisicao(),
                'request_uri'        => isset($opcoes['request_uri'])
                    ? self::limitarTexto($opcoes['request_uri'], 2000)
                    : self::obterUriRequisicao(),
                'referer'            => isset($opcoes['referer'])
                    ? self::limitarTexto($opcoes['referer'], 2000)
                    : self::obterReferer(),
                'description'        => isset($opcoes['description'])
                    ? self::limitarTexto($opcoes['description'], 5000)
                    : null,
                'before_data'        => self::jsonEncode($antes),
                'after_data'         => self::jsonEncode($depois),
                'metadata'           => self::jsonEncode($metadata),
                'created_at'         => self::agora()
            );

            return self::inserir($dados);
        } catch (Throwable $erro) {
            self::registrarErroInterno(__METHOD__, $erro);

            return 0;
        }
    }

    public static function created($entityType, $entityId, $dadosDepois = array(), array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['entity_id'] = $entityId;
        $opcoes['after'] = $dadosDepois;
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'crud';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Registro criado.';

        return self::log(self::EVENT_CREATED, $opcoes);
    }

    public static function updated($entityType, $entityId, array $dadosAntes, array $dadosDepois, array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['entity_id'] = $entityId;
        $opcoes['before'] = $dadosAntes;
        $opcoes['after'] = $dadosDepois;
        $opcoes['only_changes'] = true;
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'crud';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Registro atualizado.';

        return self::log(self::EVENT_UPDATED, $opcoes);
    }

    public static function deleted($entityType, $entityId, $dadosAntes = array(), array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['entity_id'] = $entityId;
        $opcoes['before'] = $dadosAntes;
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'crud';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Registro excluído.';

        return self::log(self::EVENT_DELETED, $opcoes);
    }

    public static function restored($entityType, $entityId, $dadosDepois = array(), array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['entity_id'] = $entityId;
        $opcoes['after'] = $dadosDepois;
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'crud';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Registro restaurado.';

        return self::log(self::EVENT_RESTORED, $opcoes);
    }

    public static function viewed($entityType, $entityId, array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['entity_id'] = $entityId;
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'consulta';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Registro visualizado.';

        return self::log(self::EVENT_VIEWED, $opcoes);
    }

    public static function uploaded($entityType, $entityId, array $arquivo, array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['entity_id'] = $entityId;
        $opcoes['after'] = $arquivo;
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'arquivo';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Arquivo enviado.';

        return self::log(self::EVENT_UPLOADED, $opcoes);
    }

    public static function downloaded($entityType, $entityId, array $arquivo = array(), array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['entity_id'] = $entityId;
        $opcoes['metadata'] = array_merge(
            isset($opcoes['metadata']) && is_array($opcoes['metadata'])
                ? $opcoes['metadata']
                : array(),
            array(
                'arquivo' => $arquivo
            )
        );
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'arquivo';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Arquivo baixado.';

        return self::log(self::EVENT_DOWNLOADED, $opcoes);
    }

    public static function exported($entityType, array $dadosExportacao = array(), array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['metadata'] = array_merge(
            isset($opcoes['metadata']) && is_array($opcoes['metadata'])
                ? $opcoes['metadata']
                : array(),
            array(
                'exportacao' => $dadosExportacao
            )
        );
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'exportacao';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Dados exportados.';

        return self::log(self::EVENT_EXPORTED, $opcoes);
    }

    public static function imported($entityType, array $dadosImportacao = array(), array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['metadata'] = array_merge(
            isset($opcoes['metadata']) && is_array($opcoes['metadata'])
                ? $opcoes['metadata']
                : array(),
            array(
                'importacao' => $dadosImportacao
            )
        );
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'importacao';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Dados importados.';

        return self::log(self::EVENT_IMPORTED, $opcoes);
    }

    public static function statusChanged($entityType, $entityId, $statusAnterior, $statusNovo, array $opcoes = array())
    {
        $opcoes['entity_type'] = $entityType;
        $opcoes['entity_id'] = $entityId;
        $opcoes['before'] = array(
            'status' => $statusAnterior
        );
        $opcoes['after'] = array(
            'status' => $statusNovo
        );
        $opcoes['only_changes'] = true;
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'status';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Status alterado.';

        return self::log(self::EVENT_STATUS_CHANGED, $opcoes);
    }

    public static function payment($evento, $entityType, $entityId, array $dadosPagamento = array(), array $opcoes = array())
    {
        $eventosPermitidos = array(
            self::EVENT_PAYMENT_CREATED,
            self::EVENT_PAYMENT_CONFIRMED,
            self::EVENT_PAYMENT_CANCELLED,
            self::EVENT_PAYMENT_REFUNDED
        );

        if (!in_array($evento, $eventosPermitidos, true)) {
            $evento = self::EVENT_PAYMENT_CREATED;
        }

        $opcoes['entity_type'] = $entityType;
        $opcoes['entity_id'] = $entityId;
        $opcoes['metadata'] = array_merge(
            isset($opcoes['metadata']) && is_array($opcoes['metadata'])
                ? $opcoes['metadata']
                : array(),
            array(
                'pagamento' => $dadosPagamento
            )
        );
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'pagamento';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Evento de pagamento registrado.';

        return self::log($evento, $opcoes);
    }

    public static function securityDenied($evento, array $opcoes = array())
    {
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'seguranca';
        $opcoes['level'] = isset($opcoes['level'])
            ? $opcoes['level']
            : self::LEVEL_WARNING;
        $opcoes['status'] = self::STATUS_DENIED;
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Ação bloqueada por política de segurança.';

        return self::log($evento, $opcoes);
    }

    public static function login($userId, $sucesso = true, array $opcoes = array())
    {
        $opcoes['user'] = array(
            'id' => (int) $userId,
            'name' => isset($opcoes['user_name']) ? $opcoes['user_name'] : null,
            'username' => isset($opcoes['user_username']) ? $opcoes['user_username'] : null
        );
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'autenticacao';
        $opcoes['status'] = $sucesso
            ? self::STATUS_SUCCESS
            : self::STATUS_FAILURE;
        $opcoes['level'] = $sucesso
            ? self::LEVEL_INFO
            : self::LEVEL_WARNING;
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : ($sucesso ? 'Login realizado.' : 'Tentativa de login sem sucesso.');

        return self::log(
            $sucesso ? self::EVENT_LOGIN : self::EVENT_LOGIN_FAILED,
            $opcoes
        );
    }

    public static function logout($userId, array $opcoes = array())
    {
        $opcoes['user'] = array(
            'id' => (int) $userId,
            'name' => isset($opcoes['user_name']) ? $opcoes['user_name'] : null,
            'username' => isset($opcoes['user_username']) ? $opcoes['user_username'] : null
        );
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'autenticacao';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Logout realizado.';

        return self::log(self::EVENT_LOGOUT, $opcoes);
    }

    public static function queue($evento, $jobId, array $dadosJob = array(), array $opcoes = array())
    {
        $eventosPermitidos = array(
            self::EVENT_QUEUE_CREATED,
            self::EVENT_QUEUE_COMPLETED,
            self::EVENT_QUEUE_FAILED
        );

        if (!in_array($evento, $eventosPermitidos, true)) {
            $evento = self::EVENT_QUEUE_CREATED;
        }

        $opcoes['entity_type'] = 'queue_job';
        $opcoes['entity_id'] = $jobId;
        $opcoes['metadata'] = array_merge(
            isset($opcoes['metadata']) && is_array($opcoes['metadata'])
                ? $opcoes['metadata']
                : array(),
            array(
                'job' => $dadosJob
            )
        );
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'fila';
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Evento da fila registrado.';

        return self::log($evento, $opcoes);
    }

    public static function exception(Throwable $erro, array $opcoes = array())
    {
        $metadata = isset($opcoes['metadata']) && is_array($opcoes['metadata'])
            ? $opcoes['metadata']
            : array();

        $metadata['exception'] = array(
            'classe' => get_class($erro),
            'codigo' => (int) $erro->getCode(),
            'mensagem' => $erro->getMessage(),
            'arquivo' => $erro->getFile(),
            'linha' => $erro->getLine()
        );

        $opcoes['metadata'] = $metadata;
        $opcoes['category'] = isset($opcoes['category'])
            ? $opcoes['category']
            : 'erro';
        $opcoes['level'] = isset($opcoes['level'])
            ? $opcoes['level']
            : self::LEVEL_ERROR;
        $opcoes['status'] = self::STATUS_FAILURE;
        $opcoes['description'] = isset($opcoes['description'])
            ? $opcoes['description']
            : 'Exceção capturada pela aplicação.';

        return self::log(self::EVENT_EXCEPTION, $opcoes);
    }

    public static function custom($evento, array $opcoes = array())
    {
        return self::log($evento, $opcoes);
    }

    public static function find($id)
    {
        try {
            $id = (int) $id;

            if ($id <= 0) {
                return null;
            }

            $db = self::db();

            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::$table))
                ->where($db->quoteName('id') . ' = ' . $id);

            $db->setQuery($query);

            return $db->loadObject();
        } catch (Throwable $erro) {
            self::registrarErroInterno(__METHOD__, $erro);

            return null;
        }
    }

    public static function list(array $filters = array(), $limit = 50, $offset = 0)
    {
        try {
            $limit = min(200, max(1, (int) $limit));
            $offset = max(0, (int) $offset);
            $db = self::db();

            $query = $db->getQuery(true)
                ->select('*')
                ->from($db->quoteName(self::$table))
                ->order($db->quoteName('id') . ' DESC');

            if (isset($filters['event']) && trim((string) $filters['event']) !== '') {
                $query->where(
                    $db->quoteName('event') .
                    ' = ' .
                    $db->quote(trim((string) $filters['event']))
                );
            }

            if (isset($filters['category']) && trim((string) $filters['category']) !== '') {
                $query->where(
                    $db->quoteName('category') .
                    ' = ' .
                    $db->quote(trim((string) $filters['category']))
                );
            }

            if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
                $query->where(
                    $db->quoteName('status') .
                    ' = ' .
                    $db->quote(trim((string) $filters['status']))
                );
            }

            if (isset($filters['level']) && trim((string) $filters['level']) !== '') {
                $query->where(
                    $db->quoteName('level') .
                    ' = ' .
                    $db->quote(trim((string) $filters['level']))
                );
            }

            if (isset($filters['entity_type']) && trim((string) $filters['entity_type']) !== '') {
                $query->where(
                    $db->quoteName('entity_type') .
                    ' = ' .
                    $db->quote(trim((string) $filters['entity_type']))
                );
            }

            if (isset($filters['entity_id']) && trim((string) $filters['entity_id']) !== '') {
                $query->where(
                    $db->quoteName('entity_id') .
                    ' = ' .
                    $db->quote(trim((string) $filters['entity_id']))
                );
            }

            if (isset($filters['user_id']) && (int) $filters['user_id'] > 0) {
                $query->where(
                    $db->quoteName('user_id') .
                    ' = ' .
                    (int) $filters['user_id']
                );
            }

            if (isset($filters['request_id']) && trim((string) $filters['request_id']) !== '') {
                $query->where(
                    $db->quoteName('request_id') .
                    ' = ' .
                    $db->quote(trim((string) $filters['request_id']))
                );
            }

            if (isset($filters['date_start']) && trim((string) $filters['date_start']) !== '') {
                $query->where(
                    $db->quoteName('created_at') .
                    ' >= ' .
                    $db->quote(trim((string) $filters['date_start']))
                );
            }

            if (isset($filters['date_end']) && trim((string) $filters['date_end']) !== '') {
                $query->where(
                    $db->quoteName('created_at') .
                    ' <= ' .
                    $db->quote(trim((string) $filters['date_end']))
                );
            }

            if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
                $search = '%' . $db->escape(trim((string) $filters['search']), true) . '%';

                $query->where(
                    '(' .
                    $db->quoteName('description') . ' LIKE ' . $db->quote($search, false) .
                    ' OR ' .
                    $db->quoteName('event') . ' LIKE ' . $db->quote($search, false) .
                    ' OR ' .
                    $db->quoteName('entity_id') . ' LIKE ' . $db->quote($search, false) .
                    ' OR ' .
                    $db->quoteName('user_name') . ' LIKE ' . $db->quote($search, false) .
                    ' OR ' .
                    $db->quoteName('user_username') . ' LIKE ' . $db->quote($search, false) .
                    ')'
                );
            }

            $db->setQuery($query, $offset, $limit);

            return $db->loadObjectList();
        } catch (Throwable $erro) {
            self::registrarErroInterno(__METHOD__, $erro);

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

            if (isset($filters['event']) && trim((string) $filters['event']) !== '') {
                $query->where(
                    $db->quoteName('event') .
                    ' = ' .
                    $db->quote(trim((string) $filters['event']))
                );
            }

            if (isset($filters['category']) && trim((string) $filters['category']) !== '') {
                $query->where(
                    $db->quoteName('category') .
                    ' = ' .
                    $db->quote(trim((string) $filters['category']))
                );
            }

            if (isset($filters['status']) && trim((string) $filters['status']) !== '') {
                $query->where(
                    $db->quoteName('status') .
                    ' = ' .
                    $db->quote(trim((string) $filters['status']))
                );
            }

            if (isset($filters['entity_type']) && trim((string) $filters['entity_type']) !== '') {
                $query->where(
                    $db->quoteName('entity_type') .
                    ' = ' .
                    $db->quote(trim((string) $filters['entity_type']))
                );
            }

            if (isset($filters['entity_id']) && trim((string) $filters['entity_id']) !== '') {
                $query->where(
                    $db->quoteName('entity_id') .
                    ' = ' .
                    $db->quote(trim((string) $filters['entity_id']))
                );
            }

            if (isset($filters['user_id']) && (int) $filters['user_id'] > 0) {
                $query->where(
                    $db->quoteName('user_id') .
                    ' = ' .
                    (int) $filters['user_id']
                );
            }

            $db->setQuery($query);

            return (int) $db->loadResult();
        } catch (Throwable $erro) {
            self::registrarErroInterno(__METHOD__, $erro);

            return 0;
        }
    }

    public static function purge($dateBefore, $limit = 1000)
    {
        try {
            $dateBefore = trim((string) $dateBefore);
            $limit = min(10000, max(1, (int) $limit));

            if ($dateBefore === '') {
                throw new InvalidArgumentException('A data limite para limpeza é obrigatória.');
            }

            $db = self::db();

            $query = $db->getQuery(true)
                ->select($db->quoteName('id'))
                ->from($db->quoteName(self::$table))
                ->where($db->quoteName('created_at') . ' < ' . $db->quote($dateBefore))
                ->order($db->quoteName('id') . ' ASC');

            $db->setQuery($query, 0, $limit);

            $ids = $db->loadColumn();

            if (empty($ids)) {
                return 0;
            }

            $ids = array_map('intval', $ids);

            $delete = $db->getQuery(true)
                ->delete($db->quoteName(self::$table))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')');

            $db->setQuery($delete);
            $db->execute();

            return (int) $db->getAffectedRows();
        } catch (Throwable $erro) {
            self::registrarErroInterno(__METHOD__, $erro);

            return 0;
        }
    }
}