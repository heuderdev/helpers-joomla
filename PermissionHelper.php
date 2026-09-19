<?php

defined('_JEXEC') or die;

class PermissionHelper
{
    private static $defaultComponent = 'com_generico';

    private static $logCategory = 'permission_helper';

    private static $logDirectory = null;

    private static $useApiResponse = true;

    private static $auditDenied = true;

    private static $logDenied = true;

    private static function app()
    {
        return JFactory::getApplication();
    }

    private static function log($message, array $context = array(), $level = 'warning')
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

            JLog::add(
                $message,
                JLog::WARNING,
                self::$logCategory
            );
        } catch (Throwable $error) {
        }
    }

    private static function normalizeComponent($component = null)
    {
        $component = trim((string) $component);

        if ($component === '') {
            $component = self::$defaultComponent;
        }

        if (!preg_match('/^com_[a-zA-Z0-9_]+$/', $component)) {
            throw new InvalidArgumentException(
                'Componente de permissão inválido.'
            );
        }

        return $component;
    }

    private static function normalizeAction($action)
    {
        $action = trim((string) $action);

        if ($action === '') {
            throw new InvalidArgumentException(
                'Ação de permissão inválida.'
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', $action)) {
            throw new InvalidArgumentException(
                'Ação de permissão contém caracteres inválidos.'
            );
        }

        return $action;
    }

    private static function normalizeEntityType($entityType)
    {
        $entityType = trim((string) $entityType);

        if ($entityType === '') {
            throw new InvalidArgumentException(
                'Tipo de entidade inválido.'
            );
        }

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $entityType)) {
            throw new InvalidArgumentException(
                'Tipo de entidade contém caracteres inválidos.'
            );
        }

        return $entityType;
    }

    private static function normalizeEntityId($entityId)
    {
        if (
            $entityId === null ||
            $entityId === '' ||
            (is_numeric($entityId) && (int) $entityId <= 0)
        ) {
            throw new InvalidArgumentException(
                'ID de entidade inválido.'
            );
        }

        $entityId = trim((string) $entityId);

        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $entityId)) {
            throw new InvalidArgumentException(
                'ID de entidade contém caracteres inválidos.'
            );
        }

        return $entityId;
    }

    private static function isJsonRequest()
    {
        try {
            if (class_exists('ApiResponseHelper')) {
                return ApiResponseHelper::isJson();
            }

            $format = strtolower(
                self::app()->input->getCmd('format', '')
            );

            if ($format === 'json') {
                return true;
            }

            $requestedWith = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
                ? strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH'])
                : '';

            if ($requestedWith === 'xmlhttprequest') {
                return true;
            }

            $accept = isset($_SERVER['HTTP_ACCEPT'])
                ? strtolower((string) $_SERVER['HTTP_ACCEPT'])
                : '';

            return strpos($accept, 'application/json') !== false;
        } catch (Throwable $error) {
            return false;
        }
    }

    private static function deniedContext($action, $asset, $message, array $context = array())
    {
        $user = self::user();

        return array_merge(
            array(
                'action' => $action,
                'asset' => $asset,
                'message' => $message,
                'user_id' => self::isLoggedIn($user)
                    ? (int) $user->id
                    : null,
                'username' => self::isLoggedIn($user)
                    ? (string) $user->username
                    : null,
                'groups' => self::isLoggedIn($user)
                    ? self::groups($user)
                    : array(),
                'ip' => isset($_SERVER['REMOTE_ADDR'])
                    ? $_SERVER['REMOTE_ADDR']
                    : null,
                'request_uri' => isset($_SERVER['REQUEST_URI'])
                    ? $_SERVER['REQUEST_URI']
                    : null
            ),
            $context
        );
    }

    private static function registerDenied($action, $asset, $message, array $context = array())
    {
        $context = self::deniedContext(
            $action,
            $asset,
            $message,
            $context
        );

        if (self::$logDenied) {
            self::log(
                'Acesso negado: ' . $message,
                $context,
                'warning'
            );
        }

        if (self::$auditDenied && class_exists('AuditHelper')) {
            try {
                AuditHelper::securityDenied(
                    AuditHelper::EVENT_SECURITY_DENIED,
                    array(
                        'category' => 'permissao',
                        'entity_type' => isset($context['entity_type'])
                            ? $context['entity_type']
                            : null,
                        'entity_id' => isset($context['entity_id'])
                            ? $context['entity_id']
                            : null,
                        'description' => $message,
                        'metadata' => $context
                    )
                );
            } catch (Throwable $error) {
                self::log(
                    'Falha ao registrar auditoria de acesso negado.',
                    array(
                        'erro' => $error->getMessage()
                    ),
                    'error'
                );
            }
        }
    }

    private static function respondDenied($httpStatus, $message, $redirect = null, array $context = array())
    {
        $isJson = self::isJsonRequest();

        if (
            self::$useApiResponse &&
            class_exists('ApiResponseHelper')
        ) {
            if ($httpStatus === 401) {
                return ApiResponseHelper::unauthorized(
                    $message,
                    array(
                        'redirect' => $redirect
                    )
                );
            }

            return ApiResponseHelper::forbidden(
                $message,
                array(
                    'redirect' => $redirect
                )
            );
        }

        if ($isJson) {
            self::app()->setHeader(
                'Content-Type',
                'application/json; charset=utf-8',
                true
            );

            http_response_code((int) $httpStatus);

            echo json_encode(
                array(
                    'success' => false,
                    'status' => 'erro',
                    'mensagem' => $message,
                    'context' => $context
                ),
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            );

            self::app()->close();
        }

        self::app()->enqueueMessage(
            $message,
            'error'
        );

        if ($redirect !== null && trim((string) $redirect) !== '') {
            self::app()->redirect($redirect);
        }

        return false;
    }

    public static function setDefaultComponent($component)
    {
        self::$defaultComponent = self::normalizeComponent(
            $component
        );
    }

    public static function getDefaultComponent()
    {
        return self::$defaultComponent;
    }

    public static function setLogDirectory($directory)
    {
        self::$logDirectory = trim((string) $directory);
    }

    public static function setUseApiResponse($useApiResponse)
    {
        self::$useApiResponse = (bool) $useApiResponse;
    }

    public static function setAuditDenied($auditDenied)
    {
        self::$auditDenied = (bool) $auditDenied;
    }

    public static function setLogDenied($logDenied)
    {
        self::$logDenied = (bool) $logDenied;
    }

    public static function user($user = null)
    {
        if ($user instanceof JUser) {
            return $user;
        }

        return JFactory::getUser();
    }

    public static function userId($user = null)
    {
        $user = self::user($user);

        return (int) $user->id;
    }

    public static function isLoggedIn($user = null)
    {
        return self::userId($user) > 0;
    }

    public static function isGuest($user = null)
    {
        return !self::isLoggedIn($user);
    }

    public static function groups($user = null)
    {
        try {
            $user = self::user($user);

            if (!self::isLoggedIn($user)) {
                return array();
            }

            $groups = $user->getAuthorisedGroups();

            if (!is_array($groups)) {
                return array();
            }

            return array_values(
                array_unique(
                    array_map('intval', $groups)
                )
            );
        } catch (Throwable $error) {
            self::log(
                'Falha ao recuperar grupos autorizados do usuário.',
                array(
                    'erro' => $error->getMessage()
                ),
                'error'
            );

            return array();
        }
    }

    public static function viewLevels($user = null)
    {
        try {
            $user = self::user($user);

            if (!self::isLoggedIn($user)) {
                return array();
            }

            $levels = $user->getAuthorisedViewLevels();

            if (!is_array($levels)) {
                return array();
            }

            return array_values(
                array_unique(
                    array_map('intval', $levels)
                )
            );
        } catch (Throwable $error) {
            self::log(
                'Falha ao recuperar níveis de visualização do usuário.',
                array(
                    'erro' => $error->getMessage()
                ),
                'error'
            );

            return array();
        }
    }

    public static function hasGroup($groupId, $user = null)
    {
        $groupId = (int) $groupId;

        if ($groupId <= 0) {
            return false;
        }

        return in_array(
            $groupId,
            self::groups($user),
            true
        );
    }

    public static function hasAnyGroup(array $groupIds, $user = null)
    {
        $userGroups = self::groups($user);

        foreach ($groupIds as $groupId) {
            if (in_array((int) $groupId, $userGroups, true)) {
                return true;
            }
        }

        return false;
    }

    public static function hasAllGroups(array $groupIds, $user = null)
    {
        $userGroups = self::groups($user);

        foreach ($groupIds as $groupId) {
            if (!in_array((int) $groupId, $userGroups, true)) {
                return false;
            }
        }

        return true;
    }

    public static function canView($accessLevel, $user = null)
    {
        $accessLevel = (int) $accessLevel;

        if ($accessLevel <= 0) {
            return false;
        }

        return in_array(
            $accessLevel,
            self::viewLevels($user),
            true
        );
    }

    public static function asset($component = null, $entityType = null, $entityId = null)
    {
        $component = self::normalizeComponent($component);

        if ($entityType === null || trim((string) $entityType) === '') {
            return $component;
        }

        $entityType = self::normalizeEntityType($entityType);

        if ($entityId === null || $entityId === '') {
            return $component . '.' . $entityType;
        }

        $entityId = self::normalizeEntityId($entityId);

        return $component .
            '.' .
            $entityType .
            '.' .
            $entityId;
    }

    public static function can($action, $asset = null, $user = null)
    {
        try {
            $user = self::user($user);
            $action = self::normalizeAction($action);

            if (!self::isLoggedIn($user)) {
                return false;
            }

            if ($asset === null || trim((string) $asset) === '') {
                $asset = self::$defaultComponent;
            }

            return (bool) $user->authorise(
                $action,
                (string) $asset
            );
        } catch (Throwable $error) {
            self::log(
                'Falha ao validar permissão Joomla.',
                array(
                    'erro' => $error->getMessage(),
                    'action' => $action,
                    'asset' => $asset
                ),
                'error'
            );

            return false;
        }
    }

    public static function canComponent($action, $component = null, $user = null)
    {
        $component = self::normalizeComponent($component);

        return self::can(
            $action,
            $component,
            $user
        );
    }

    public static function canEntity($action, $entityType, $entityId, $component = null, $user = null)
    {
        $asset = self::asset(
            $component,
            $entityType,
            $entityId
        );

        return self::can(
            $action,
            $asset,
            $user
        );
    }

    public static function canAny(array $actions, $asset = null, $user = null)
    {
        foreach ($actions as $action) {
            if (self::can($action, $asset, $user)) {
                return true;
            }
        }

        return false;
    }

    public static function canAll(array $actions, $asset = null, $user = null)
    {
        foreach ($actions as $action) {
            if (!self::can($action, $asset, $user)) {
                return false;
            }
        }

        return true;
    }

    public static function isAdmin($component = null, $user = null)
    {
        $component = self::normalizeComponent($component);

        return (
            self::can('core.admin', $component, $user) ||
            self::can('core.admin', 'root.1', $user)
        );
    }

    public static function isManager($component = null, $user = null)
    {
        $component = self::normalizeComponent($component);

        return (
            self::can('core.manage', $component, $user) ||
            self::isAdmin($component, $user)
        );
    }

    public static function isAllowedUser(array $userIds, $user = null)
    {
        $userId = self::userId($user);

        if ($userId <= 0) {
            return false;
        }

        $userIds = array_map(
            'intval',
            $userIds
        );

        return in_array(
            $userId,
            $userIds,
            true
        );
    }

    public static function allowsUser($userId, array $userIds)
    {
        $userId = (int) $userId;

        if ($userId <= 0) {
            return false;
        }

        $userIds = array_map(
            'intval',
            $userIds
        );

        return in_array(
            $userId,
            $userIds,
            true
        );
    }

    public static function evaluate($callback, $user = null, array $context = array())
    {
        try {
            if (!is_callable($callback)) {
                return false;
            }

            $user = self::user($user);

            return (bool) call_user_func(
                $callback,
                $user,
                $context
            );
        } catch (Throwable $error) {
            self::log(
                'Falha ao executar regra de permissão customizada.',
                array(
                    'erro' => $error->getMessage()
                ),
                'error'
            );

            return false;
        }
    }

    public static function canAccessProject($idProjeto, $component = 'com_sourural', $user = null)
    {
        try {
            $user = self::user($user);
            $idProjeto = (int) $idProjeto;

            if (
                !self::isLoggedIn($user) ||
                $idProjeto <= 0
            ) {
                return false;
            }

            if (
                self::isAdmin($component, $user) ||
                self::canEntity(
                    'core.edit',
                    'projeto',
                    $idProjeto,
                    $component,
                    $user
                ) ||
                self::canEntity(
                    'core.view',
                    'projeto',
                    $idProjeto,
                    $component,
                    $user
                )
            ) {
                return true;
            }

            if (!method_exists('JFactory', 'getDadosPessoais')) {
                return false;
            }

            $dadosPessoais = JFactory::getDadosPessoais();

            if (empty($dadosPessoais)) {
                return false;
            }

            $central = isset($dadosPessoais->central)
                ? (int) $dadosPessoais->central
                : 0;

            $singular = isset($dadosPessoais->singular)
                ? (int) $dadosPessoais->singular
                : 0;

            if ($central <= 0 && $singular <= 0) {
                return false;
            }

            $db = JFactory::getDbo();

            $query = $db->getQuery(true)
                ->select(
                    array(
                        $db->quoteName('NUM_CENTRAL'),
                        $db->quoteName('NUM_SINGULAR')
                    )
                )
                ->from(
                    $db->quoteName(
                        '#__premio_produtor_rural_sourural_cadastro_6'
                    )
                )
                ->where(
                    $db->quoteName('ID_CADASTRO_PROJETO') .
                    ' = ' .
                    $idProjeto
                );

            $db->setQuery($query);

            $projeto = $db->loadObject();

            if (!$projeto) {
                return false;
            }

            if (
                $singular > 1000 &&
                $singular < 3000 &&
                (int) $projeto->NUM_CENTRAL === $singular
            ) {
                return true;
            }

            if (
                $singular > 3000 &&
                (int) $projeto->NUM_SINGULAR === $singular
            ) {
                return true;
            }

            if (
                $central > 0 &&
                (int) $projeto->NUM_CENTRAL === $central
            ) {
                return true;
            }

            return false;
        } catch (Throwable $error) {
            self::log(
                'Falha ao validar acesso ao projeto.',
                array(
                    'erro' => $error->getMessage(),
                    'id_projeto' => $idProjeto
                ),
                'error'
            );

            return false;
        }
    }

    public static function requireLogin($message = 'Usuário não autenticado.', $redirect = null, array $context = array())
    {
        $user = self::user();

        if (self::isLoggedIn($user)) {
            return $user;
        }

        self::registerDenied(
            'login',
            null,
            $message,
            $context
        );

        self::respondDenied(
            401,
            $message,
            $redirect,
            $context
        );

        return false;
    }

    public static function require($action, $asset = null, $message = 'Você não possui permissão para esta operação.', $redirect = null, array $context = array())
    {
        $user = self::user();

        if (!self::isLoggedIn($user)) {
            return self::requireLogin(
                'Usuário não autenticado.',
                $redirect,
                $context
            );
        }

        if (self::can($action, $asset, $user)) {
            return true;
        }

        self::registerDenied(
            $action,
            $asset,
            $message,
            $context
        );

        self::respondDenied(
            403,
            $message,
            $redirect,
            $context
        );

        return false;
    }

    public static function requireComponent($action, $component = null, $message = 'Você não possui permissão para esta operação.', $redirect = null, array $context = array())
    {
        $component = self::normalizeComponent($component);

        return self::require(
            $action,
            $component,
            $message,
            $redirect,
            $context
        );
    }

    public static function requireEntity($action, $entityType, $entityId, $component = null, $message = 'Você não possui permissão para acessar este registro.', $redirect = null, array $context = array())
    {
        $asset = self::asset(
            $component,
            $entityType,
            $entityId
        );

        $context = array_merge(
            $context,
            array(
                'entity_type' => $entityType,
                'entity_id' => $entityId
            )
        );

        return self::require(
            $action,
            $asset,
            $message,
            $redirect,
            $context
        );
    }

    public static function requireAny(array $actions, $asset = null, $message = 'Você não possui permissão para esta operação.', $redirect = null, array $context = array())
    {
        $user = self::user();

        if (!self::isLoggedIn($user)) {
            return self::requireLogin(
                'Usuário não autenticado.',
                $redirect,
                $context
            );
        }

        if (self::canAny($actions, $asset, $user)) {
            return true;
        }

        self::registerDenied(
            implode('|', $actions),
            $asset,
            $message,
            $context
        );

        self::respondDenied(
            403,
            $message,
            $redirect,
            $context
        );

        return false;
    }

    public static function requireAll(array $actions, $asset = null, $message = 'Você não possui todas as permissões necessárias para esta operação.', $redirect = null, array $context = array())
    {
        $user = self::user();

        if (!self::isLoggedIn($user)) {
            return self::requireLogin(
                'Usuário não autenticado.',
                $redirect,
                $context
            );
        }

        if (self::canAll($actions, $asset, $user)) {
            return true;
        }

        self::registerDenied(
            implode('&', $actions),
            $asset,
            $message,
            $context
        );

        self::respondDenied(
            403,
            $message,
            $redirect,
            $context
        );

        return false;
    }

    public static function requireGroup($groupId, $message = 'Você não possui permissão para esta operação.', $redirect = null, array $context = array())
    {
        $user = self::user();

        if (!self::isLoggedIn($user)) {
            return self::requireLogin(
                'Usuário não autenticado.',
                $redirect,
                $context
            );
        }

        if (self::hasGroup($groupId, $user)) {
            return true;
        }

        self::registerDenied(
            'group.' . (int) $groupId,
            null,
            $message,
            $context
        );

        self::respondDenied(
            403,
            $message,
            $redirect,
            $context
        );

        return false;
    }

    public static function requireAnyGroup(array $groupIds, $message = 'Você não possui permissão para esta operação.', $redirect = null, array $context = array())
    {
        $user = self::user();

        if (!self::isLoggedIn($user)) {
            return self::requireLogin(
                'Usuário não autenticado.',
                $redirect,
                $context
            );
        }

        if (self::hasAnyGroup($groupIds, $user)) {
            return true;
        }

        self::registerDenied(
            'groups.' . implode(',', $groupIds),
            null,
            $message,
            $context
        );

        self::respondDenied(
            403,
            $message,
            $redirect,
            $context
        );

        return false;
    }

    public static function requireAllowedUser(array $userIds, $message = 'Você não possui permissão para esta operação.', $redirect = null, array $context = array())
    {
        $user = self::user();

        if (!self::isLoggedIn($user)) {
            return self::requireLogin(
                'Usuário não autenticado.',
                $redirect,
                $context
            );
        }

        if (self::isAllowedUser($userIds, $user)) {
            return true;
        }

        self::registerDenied(
            'allowed_users',
            null,
            $message,
            $context
        );

        self::respondDenied(
            403,
            $message,
            $redirect,
            $context
        );

        return false;
    }

    public static function requireCallback($callback, $message = 'Você não possui permissão para esta operação.', $redirect = null, array $context = array())
    {
        $user = self::user();

        if (!self::isLoggedIn($user)) {
            return self::requireLogin(
                'Usuário não autenticado.',
                $redirect,
                $context
            );
        }

        if (self::evaluate($callback, $user, $context)) {
            return true;
        }

        self::registerDenied(
            'callback',
            null,
            $message,
            $context
        );

        self::respondDenied(
            403,
            $message,
            $redirect,
            $context
        );

        return false;
    }

    public static function requireProjectAccess($idProjeto, $component = 'com_sourural', $message = 'Você não possui permissão para acessar este projeto.', $redirect = null)
    {
        $idProjeto = (int) $idProjeto;

        if ($idProjeto <= 0) {
            throw new InvalidArgumentException(
                'ID do projeto inválido.'
            );
        }

        if (self::canAccessProject($idProjeto, $component)) {
            return true;
        }

        $context = array(
            'entity_type' => 'projeto',
            'entity_id' => $idProjeto
        );

        self::registerDenied(
            'core.view',
            self::asset(
                $component,
                'projeto',
                $idProjeto
            ),
            $message,
            $context
        );

        self::respondDenied(
            403,
            $message,
            $redirect,
            $context
        );

        return false;
    }
}