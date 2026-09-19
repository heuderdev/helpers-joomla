<?php

defined('_JEXEC') or die;

class InputHelper
{
    private static $logCategory = 'input_helper';

    private static function input()
    {
        return JFactory::getApplication()->input;
    }

    private static function log($method, Throwable $error)
    {
        JLog::add(
            '[' . $method . '] ' .
            $error->getMessage() .
            ' | Arquivo: ' . $error->getFile() .
            ' | Linha: ' . $error->getLine(),
            JLog::ERROR,
            self::$logCategory
        );
    }

    private static function source($source = 'request')
    {
        $source = strtolower(trim((string) $source));
        $input = self::input();

        switch ($source) {
            case 'get':
                return $input->get;

            case 'post':
                return $input->post;

            case 'files':
                return $input->files;

            case 'server':
                return $input->server;

            case 'cookie':
                return $input->cookie;

            case 'request':
            default:
                return $input;
        }
    }

    private static function normalizeName($name)
    {
        $name = trim((string) $name);

        if ($name === '') {
            throw new InvalidArgumentException('Nome do campo de entrada inválido.');
        }

        return $name;
    }

    private static function normalizeString($value, $trim = true)
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        $value = (string) $value;

        if ($trim) {
            $value = trim($value);
        }

        return $value;
    }

    private static function get($name, $default = null, $filter = 'cmd', $source = 'request')
    {
        $name = self::normalizeName($name);
        $sourceObject = self::source($source);

        return $sourceObject->get($name, $default, $filter);
    }

    private static function normalizeArray(array $data)
    {
        $normalized = array();

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $normalized[$key] = self::normalizeArray($value);

                continue;
            }

            if (is_object($value)) {
                $normalized[$key] = self::normalizeArray((array) $value);

                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public static function value($name, $default = null, $filter = 'cmd', $source = 'request')
    {
        try {
            return self::get($name, $default, $filter, $source);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return $default;
        }
    }

    public static function string($name, $default = '', $source = 'request', $trim = true)
    {
        try {
            $value = self::get($name, $default, 'string', $source);

            return self::normalizeString($value, $trim);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return self::normalizeString($default, $trim);
        }
    }

    public static function raw($name, $default = '', $source = 'request', $trim = true)
    {
        try {
            $value = self::get($name, $default, 'raw', $source);

            return self::normalizeString($value, $trim);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return self::normalizeString($default, $trim);
        }
    }

    public static function html($name, $default = '', $source = 'request')
    {
        try {
            $value = self::get($name, $default, 'html', $source);

            return self::normalizeString($value, false);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return self::normalizeString($default, false);
        }
    }

    public static function int($name, $default = 0, $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return (int) $sourceObject->getInt($name, (int) $default);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return (int) $default;
        }
    }

    public static function uint($name, $default = 0, $source = 'request')
    {
        $value = self::int($name, $default, $source);

        return $value < 0 ? 0 : $value;
    }

    public static function float($name, $default = 0.0, $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return (float) $sourceObject->getFloat($name, (float) $default);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return (float) $default;
        }
    }

    public static function decimal($name, $default = 0.0, $source = 'request')
    {
        try {
            $value = self::string($name, '', $source);

            if ($value === '') {
                return (float) $default;
            }

            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);

            if (!is_numeric($value)) {
                return (float) $default;
            }

            return (float) $value;
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return (float) $default;
        }
    }

    public static function bool($name, $default = false, $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return (bool) $sourceObject->getBool($name, (bool) $default);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return (bool) $default;
        }
    }

    public static function cmd($name, $default = '', $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return trim((string) $sourceObject->getCmd($name, $default));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return trim((string) $default);
        }
    }

    public static function word($name, $default = '', $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return trim((string) $sourceObject->getWord($name, $default));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return trim((string) $default);
        }
    }

    public static function alnum($name, $default = '', $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return trim((string) $sourceObject->getAlnum($name, $default));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return trim((string) $default);
        }
    }

    public static function base64($name, $default = '', $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return trim((string) $sourceObject->getBase64($name, $default));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return trim((string) $default);
        }
    }

    public static function email($name, $default = '', $source = 'request')
    {
        try {
            $email = self::string($name, '', $source);

            if ($email === '') {
                return (string) $default;
            }

            $email = filter_var($email, FILTER_SANITIZE_EMAIL);

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return (string) $default;
            }

            return strtolower($email);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return (string) $default;
        }
    }

    public static function username($name, $default = '', $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return trim((string) $sourceObject->getUsername($name, $default));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return trim((string) $default);
        }
    }

    public static function path($name, $default = '', $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return trim((string) $sourceObject->getPath($name, $default));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return trim((string) $default);
        }
    }

    public static function array($name, array $default = array(), $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            $value = $sourceObject->get($name, $default, 'array');

            if (!is_array($value)) {
                return $default;
            }

            return self::normalizeArray($value);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return $default;
        }
    }

    public static function arrayOfInt($name, array $default = array(), $source = 'request', $unique = true)
    {
        $values = self::array($name, $default, $source);
        $result = array();

        foreach ($values as $value) {
            if (!is_numeric($value)) {
                continue;
            }

            $value = (int) $value;

            if ($value <= 0) {
                continue;
            }

            $result[] = $value;
        }

        if ($unique) {
            $result = array_values(array_unique($result));
        }

        return $result;
    }

    public static function arrayOfString($name, array $default = array(), $source = 'request', $unique = true)
    {
        $values = self::array($name, $default, $source);
        $result = array();

        foreach ($values as $value) {
            if (is_array($value) || is_object($value)) {
                continue;
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $result[] = $value;
        }

        if ($unique) {
            $result = array_values(array_unique($result));
        }

        return $result;
    }

    public static function only(array $fields, $source = 'request')
    {
        $data = array();

        foreach ($fields as $field => $filter) {
            if (is_int($field)) {
                $field = $filter;
                $filter = 'string';
            }

            if (is_array($filter)) {
                $data[$field] = self::map(
                    array(
                        $field => $filter
                    ),
                    $source
                );

                $data[$field] = isset($data[$field][$field])
                    ? $data[$field][$field]
                    : array();

                continue;
            }

            $data[$field] = self::value(
                $field,
                null,
                $filter,
                $source
            );
        }

        return $data;
    }

    public static function map(array $map, $source = 'request')
    {
        try {
            $sourceObject = self::source($source);

            if (!method_exists($sourceObject, 'getArray')) {
                throw new RuntimeException(
                    'A fonte informada não permite leitura em lote.'
                );
            }

            $data = $sourceObject->getArray($map);

            if (!is_array($data)) {
                return array();
            }

            return self::normalizeArray($data);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return array();
        }
    }

    public static function post(array $map)
    {
        return self::map($map, 'post');
    }

    public static function query(array $map)
    {
        return self::map($map, 'get');
    }

    public static function request(array $map)
    {
        return self::map($map, 'request');
    }

    public static function has($name, $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::source($source);

            return $sourceObject->exists($name);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return false;
        }
    }

    public static function isPost()
    {
        try {
            return strtoupper(self::input()->getMethod()) === 'POST';
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return false;
        }
    }

    public static function isGet()
    {
        try {
            return strtoupper(self::input()->getMethod()) === 'GET';
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return false;
        }
    }

    public static function method()
    {
        try {
            return strtoupper(self::input()->getMethod());
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return '';
        }
    }

    public static function isAjax()
    {
        try {
            $requestedWith = self::server(
                'HTTP_X_REQUESTED_WITH',
                ''
            );

            if (strtolower($requestedWith) === 'xmlhttprequest') {
                return true;
            }

            $format = self::cmd('format', '', 'request');

            if (strtolower($format) === 'json') {
                return true;
            }

            $accept = self::server(
                'HTTP_ACCEPT',
                ''
            );

            return stripos($accept, 'application/json') !== false;
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return false;
        }
    }

    public static function expectsJson()
    {
        return self::isAjax();
    }

    public static function server($name, $default = '')
    {
        try {
            return self::string($name, $default, 'server');
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return (string) $default;
        }
    }

    public static function ip()
    {
        try {
            $ip = self::server('REMOTE_ADDR', '');

            if ($ip === '') {
                return null;
            }

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return null;
            }

            return $ip;
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return null;
        }
    }

    public static function userAgent()
    {
        return self::server('HTTP_USER_AGENT', '');
    }

    public static function referer()
    {
        return self::server('HTTP_REFERER', '');
    }

    public static function file($name, $default = null)
    {
        try {
            $name = self::normalizeName($name);

            $file = self::source('files')->get(
                $name,
                $default,
                'array'
            );

            if (!is_array($file)) {
                return $default;
            }

            return $file;
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return $default;
        }
    }

    public static function files($name, array $default = array())
    {
        try {
            $name = self::normalizeName($name);

            $files = self::source('files')->get(
                $name,
                $default,
                'array'
            );

            if (!is_array($files)) {
                return $default;
            }

            if (
                isset($files['name']) &&
                isset($files['tmp_name']) &&
                isset($files['error'])
            ) {
                return array($files);
            }

            return $files;
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return $default;
        }
    }

    public static function json($name = null, $default = array(), $source = 'request')
    {
        try {
            if ($name === null || trim((string) $name) === '') {
                $raw = file_get_contents('php://input');
            } else {
                $raw = self::string($name, '', $source, false);
            }

            if ($raw === false || trim((string) $raw) === '') {
                return $default;
            }

            $decoded = json_decode($raw, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $default;
            }

            return is_array($decoded)
                ? $decoded
                : $default;
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return $default;
        }
    }

    public static function pagination($defaultLimit = 20, $maxLimit = 100, $source = 'request')
    {
        $defaultLimit = max(1, (int) $defaultLimit);
        $maxLimit = max($defaultLimit, (int) $maxLimit);

        $page = self::uint('page', 1, $source);

        if ($page <= 0) {
            $page = 1;
        }

        $limit = self::uint('limit', $defaultLimit, $source);

        if ($limit <= 0) {
            $limit = $defaultLimit;
        }

        if ($limit > $maxLimit) {
            $limit = $maxLimit;
        }

        $offset = ($page - 1) * $limit;

        return array(
            'page' => $page,
            'limit' => $limit,
            'offset' => $offset
        );
    }

    public static function sorting(array $allowedFields, $defaultField, $defaultDirection = 'ASC', $source = 'request')
    {
        $defaultField = trim((string) $defaultField);
        $defaultDirection = strtoupper(
            trim((string) $defaultDirection)
        );

        if (!in_array($defaultDirection, array('ASC', 'DESC'), true)) {
            $defaultDirection = 'ASC';
        }

        $field = self::cmd(
            'sort',
            $defaultField,
            $source
        );

        if (!in_array($field, $allowedFields, true)) {
            $field = $defaultField;
        }

        $direction = strtoupper(
            self::cmd(
                'direction',
                $defaultDirection,
                $source
            )
        );

        if (!in_array($direction, array('ASC', 'DESC'), true)) {
            $direction = $defaultDirection;
        }

        return array(
            'field' => $field,
            'direction' => $direction
        );
    }

    public static function filters(array $map, $source = 'request', $removeEmpty = true)
    {
        $filters = self::map($map, $source);

        if (!$removeEmpty) {
            return $filters;
        }

        foreach ($filters as $key => $value) {
            if (is_array($value)) {
                if (empty($value)) {
                    unset($filters[$key]);
                }

                continue;
            }

            if ($value === null || $value === '') {
                unset($filters[$key]);
            }
        }

        return $filters;
    }

    public static function cpf($name, $default = '', $source = 'request')
    {
        $cpf = self::string($name, '', $source);

        if ($cpf === '') {
            return $default;
        }

        return preg_replace('/\D+/', '', $cpf);
    }

    public static function cnpj($name, $default = '', $source = 'request')
    {
        $cnpj = self::string($name, '', $source);

        if ($cnpj === '') {
            return $default;
        }

        return preg_replace('/\D+/', '', $cnpj);
    }

    public static function phone($name, $default = '', $source = 'request')
    {
        $phone = self::string($name, '', $source);

        if ($phone === '') {
            return $default;
        }

        return preg_replace('/\D+/', '', $phone);
    }

    public static function date($name, $default = '', $source = 'request')
    {
        $date = self::string($name, '', $source);

        if ($date === '') {
            return $default;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $default;
        }

        $dateTime = DateTime::createFromFormat(
            'Y-m-d',
            $date
        );

        if (!$dateTime || $dateTime->format('Y-m-d') !== $date) {
            return $default;
        }

        return $date;
    }

    public static function dateBr($name, $default = '', $source = 'request')
    {
        $date = self::string($name, '', $source);

        if ($date === '') {
            return $default;
        }

        if (!preg_match('/^\d{2}\/\d{2}\/\d{4}$/', $date)) {
            return $default;
        }

        $dateTime = DateTime::createFromFormat(
            'd/m/Y',
            $date
        );

        if (!$dateTime || $dateTime->format('d/m/Y') !== $date) {
            return $default;
        }

        return $dateTime->format('Y-m-d');
    }
}