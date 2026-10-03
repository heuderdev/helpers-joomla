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

    private static $missing = null;

    private static function sourceName($source)
    {
        return strtolower(trim((string) $source));
    }

    private static function source($source = 'request')
    {
        $input = self::input();

        switch (self::sourceName($source)) {
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

            // Corpo da requisição em JSON (fetch/axios com application/json).
            case 'json':
                return $input->json;

            case 'request':
            default:
                return $input;
        }
    }

    /*
     * Marcador de "campo não enviado". Permite diferenciar um campo
     * ausente de um campo enviado vazio ('') ou com valor 0.
     */
    private static function missing()
    {
        if (self::$missing === null) {
            self::$missing = new stdClass();
        }

        return self::$missing;
    }

    private static function existsIn($sourceObject, $name)
    {
        $missing = self::missing();

        return $sourceObject->get($name, $missing, 'raw') !== $missing;
    }

    private static function isJsonBody()
    {
        $contentType = (string) self::source('server')->get('CONTENT_TYPE', '', 'string');

        if ($contentType === '') {
            $contentType = (string) self::source('server')->get('HTTP_CONTENT_TYPE', '', 'string');
        }

        return stripos($contentType, 'json') !== false;
    }

    /*
     * Fonte onde o campo será lido. Na fonte 'request', se o campo não
     * veio no formulário/query string e o corpo da requisição é JSON,
     * lê do corpo. Assim o mesmo controller atende formulários e
     * requisições fetch() com JSON sem mudar nada.
     */
    private static function resolveSource($name, $source)
    {
        $sourceObject = self::source($source);

        if (self::sourceName($source) !== 'request'
            || self::existsIn($sourceObject, $name)
            || !self::isJsonBody()) {
            return $sourceObject;
        }

        $json = self::source('json');

        return self::existsIn($json, $name) ? $json : $sourceObject;
    }

    /*
     * Valor sem filtro, ou o marcador missing() se o campo não veio.
     */
    private static function rawValue($name, $source)
    {
        $name = self::normalizeName($name);

        return self::resolveSource($name, $source)->get($name, self::missing(), 'raw');
    }

    private static function parseInt($value)
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return floor($value) == $value ? (int) $value : null;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[+-]?\d+$/', $value) ? (int) $value : null;
    }

    private static function parseFloat($value)
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return preg_match('/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/', $value)
            ? (float) $value
            : null;
    }

    /*
     * Número digitado por pessoas: aceita "1.234,56", "1,234.56",
     * "10,5", "10.5", "R$ 1.234,56". Com um único ponto e sem vírgula
     * ("1.500"), o ponto é tratado como decimal, como o
     * <input type="number"> envia.
     */
    private static function parseDecimal($value)
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = preg_replace('/R\$|US\$|\$|€|\s|\x{00A0}/u', '', $value);

        if ($value === null || $value === '') {
            return null;
        }

        $comma = strrpos($value, ',');
        $dot = strrpos($value, '.');

        if ($comma !== false && $dot !== false) {
            $thousands = $comma > $dot ? '.' : ',';
            $value = str_replace($thousands, '', $value);
            $value = str_replace(',', '.', $value);
        } elseif ($comma !== false) {
            $value = substr_count($value, ',') > 1
                ? str_replace(',', '', $value)
                : str_replace(',', '.', $value);
        } elseif ($dot !== false && substr_count($value, '.') > 1) {
            $value = str_replace('.', '', $value);
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private static function parseBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        $value = function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);

        if (in_array($value, array('1', 'true', 'on', 'yes', 'y', 'sim', 's'), true)) {
            return true;
        }

        if (in_array($value, array('0', 'false', 'off', 'no', 'n', 'nao', 'não', ''), true)) {
            return false;
        }

        return null;
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

        return self::resolveSource($name, $source)->get($name, $default, $filter);
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

    /*
     * Texto livre (observações, descrições, mensagens) sem remover
     * "<" e ">". O filtro do string() trata "a < b e b > c" como tag e
     * devolve "a  c". Aqui só são removidos caracteres de controle e
     * texto com UTF-8 inválido.
     *
     * O valor NÃO é seguro para imprimir como HTML: escape na saída
     * (htmlspecialchars, $this->escape() nas views; respostas JSON já
     * são seguras).
     */
    public static function text($name, $default = '', $source = 'request', $trim = true)
    {
        try {
            $value = self::rawValue($name, $source);

            if ($value === self::missing() || !is_scalar($value)) {
                return self::normalizeString($default, $trim);
            }

            $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value);

            if ($value === null) {
                return self::normalizeString($default, $trim);
            }

            return self::normalizeString($value, $trim);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return self::normalizeString($default, $trim);
        }
    }

    /*
     * HTML vindo de um editor (TinyMCE, CodeMirror...). Aplica os
     * "Filtros de texto" da Configuração Global do Joomla para o grupo
     * do usuário logado: remove <script>, eventos como onclick etc. e
     * mantém a formatação.
     *
     * Sem o JComponentHelper (fora do CMS), cai no filtro "html" do
     * Joomla, que sem configuração remove todas as tags.
     */
    public static function html($name, $default = '', $source = 'request')
    {
        try {
            if (class_exists('JComponentHelper') && method_exists('JComponentHelper', 'filterText')) {
                $value = self::rawValue($name, $source);

                if ($value === self::missing() || !is_scalar($value)) {
                    return self::normalizeString($default, false);
                }

                return self::normalizeString(JComponentHelper::filterText((string) $value), false);
            }

            $value = self::get($name, $default, 'html', $source);

            return self::normalizeString($value, false);
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return self::normalizeString($default, false);
        }
    }

    /*
     * Números e booleanos seguem a mesma regra:
     * - campo não enviado, vazio ou inválido ("abc", "12abc") → $default;
     * - $default pode ser null, para diferenciar "não informado" de 0.
     */
    public static function int($name, $default = 0, $source = 'request')
    {
        try {
            $value = self::parseInt(self::rawValue($name, $source));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            $value = null;
        }

        if ($value === null) {
            return $default === null ? null : (int) $default;
        }

        return $value;
    }

    /*
     * Inteiro maior ou igual a zero. Negativo conta como inválido.
     */
    public static function uint($name, $default = 0, $source = 'request')
    {
        $value = self::int($name, null, $source);

        if ($value === null || $value < 0) {
            return $default === null ? null : max(0, (int) $default);
        }

        return $value;
    }

    /*
     * Número no formato de máquina: "10.5", "-3", "1e3". Para valores
     * digitados por pessoas ("1.234,56"), use decimal().
     */
    public static function float($name, $default = 0.0, $source = 'request')
    {
        try {
            $value = self::parseFloat(self::rawValue($name, $source));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            $value = null;
        }

        if ($value === null) {
            return $default === null ? null : (float) $default;
        }

        return $value;
    }

    /*
     * Valor monetário ou decimal digitado por pessoas, no formato
     * brasileiro ou americano: "1.234,56", "1,234.56", "10,5", "10.5",
     * "R$ 99,90".
     */
    public static function decimal($name, $default = 0.0, $source = 'request')
    {
        try {
            $value = self::parseDecimal(self::rawValue($name, $source));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            $value = null;
        }

        if ($value === null) {
            return $default === null ? null : (float) $default;
        }

        return $value;
    }

    /*
     * Verdadeiro: 1, true, on, yes, y, sim, s.
     * Falso: 0, false, off, no, n, nao, não, vazio.
     * Qualquer outro valor → $default. (O filtro BOOL do Joomla faz um
     * simples (bool), em que "false" vira true.)
     */
    public static function bool($name, $default = false, $source = 'request')
    {
        try {
            $value = self::parseBool(self::rawValue($name, $source));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            $value = null;
        }

        if ($value === null) {
            return $default === null ? null : (bool) $default;
        }

        return $value;
    }

    public static function cmd($name, $default = '', $source = 'request')
    {
        try {
            $name = self::normalizeName($name);
            $sourceObject = self::resolveSource($name, $source);

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
            $sourceObject = self::resolveSource($name, $source);

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
            $sourceObject = self::resolveSource($name, $source);

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
            $sourceObject = self::resolveSource($name, $source);

            return trim((string) $sourceObject->getBase64($name, $default));
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return trim((string) $default);
        }
    }

    /*
     * Valor que precisa estar numa lista permitida (status, ordenação,
     * tipos). Devolve o item da lista, com o tipo dele, ou $default.
     *
     * InputHelper::oneOf('status', array('aberto', 'pago'), '')
     */
    public static function oneOf($name, array $allowed, $default = null, $source = 'request')
    {
        try {
            $value = self::rawValue($name, $source);

            if ($value === self::missing() || !is_scalar($value)) {
                return $default;
            }

            $value = trim((string) $value);

            foreach ($allowed as $option) {
                if ((string) $option === $value) {
                    return $option;
                }
            }

            return $default;
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return $default;
        }
    }

    public static function email($name, $default = '', $source = 'request')
    {
        try {
            $email = self::string($name, '', $source);

            if ($email === '') {
                return (string) $default;
            }

            /*
             * Valida o que foi digitado, sem FILTER_SANITIZE_EMAIL: ele
             * remove caracteres e transformaria "ana silva@x.com" num
             * e-mail diferente, "anasilva@x.com", em vez de recusá-lo.
             */
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
            $sourceObject = self::resolveSource($name, $source);

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
            $sourceObject = self::resolveSource($name, $source);

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
            $sourceObject = self::resolveSource($name, $source);

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
        /*
         * Com mapa vazio, o getArray() do Joomla devolve a requisição
         * inteira sem filtro nenhum.
         */
        if (empty($map)) {
            return array();
        }

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

            // Campos ausentes no formulário são lidos do corpo JSON, se houver.
            if (self::sourceName($source) === 'request' && self::isJsonBody()) {
                $json = self::source('json');

                foreach ($map as $field => $filter) {
                    if (!self::existsIn($sourceObject, $field) && self::existsIn($json, $field)) {
                        $fromJson = $json->getArray(array($field => $filter));
                        $data[$field] = isset($fromJson[$field]) ? $fromJson[$field] : null;
                    }
                }
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

            return self::existsIn(self::resolveSource($name, $source), $name);
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

            if (!is_array($file) || self::isEmptyUpload($file)) {
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
                $files = array($files);
            }

            $sent = array();

            foreach ($files as $file) {
                if (is_array($file) && !self::isEmptyUpload($file)) {
                    $sent[] = $file;
                }
            }

            return empty($sent) ? $default : $sent;
        } catch (Throwable $error) {
            self::log(__METHOD__, $error);

            return $default;
        }
    }

    /*
     * Um <input type="file"> enviado sem arquivo chega com
     * error = UPLOAD_ERR_NO_FILE.
     */
    private static function isEmptyUpload(array $file)
    {
        return isset($file['error']) && (int) $file['error'] === UPLOAD_ERR_NO_FILE;
    }

    public static function json($name = null, $default = array(), $source = 'request')
    {
        try {
            if ($name === null || trim((string) $name) === '') {
                $raw = file_get_contents('php://input');
            } else {
                $raw = self::raw($name, '', $source, false);
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

        $cpf = preg_replace('/\D+/', '', $cpf);

        return $cpf === '' ? $default : $cpf;
    }

    public static function cnpj($name, $default = '', $source = 'request')
    {
        $cnpj = self::string($name, '', $source);

        if ($cnpj === '') {
            return $default;
        }

        $cnpj = preg_replace('/\D+/', '', $cnpj);

        return $cnpj === '' ? $default : $cnpj;
    }

    public static function phone($name, $default = '', $source = 'request')
    {
        $phone = self::string($name, '', $source);

        if ($phone === '') {
            return $default;
        }

        $phone = preg_replace('/\D+/', '', $phone);

        return $phone === '' ? $default : $phone;
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