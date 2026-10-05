<?php

defined('_JEXEC') or die;

class ValidationHelper
{
    private static $messages = array(
        'required' => 'O campo :field é obrigatório.',
        'string' => 'O campo :field deve ser um texto válido.',
        'integer' => 'O campo :field deve ser um número inteiro válido.',
        'numeric' => 'O campo :field deve ser numérico.',
        'decimal' => 'O campo :field deve ser um valor decimal válido.',
        'boolean' => 'O campo :field deve ser verdadeiro ou falso.',
        'email' => 'O campo :field deve conter um e-mail válido.',
        'url' => 'O campo :field deve conter uma URL válida.',
        'cpf' => 'O campo :field deve conter um CPF válido.',
        'cnpj' => 'O campo :field deve conter um CNPJ válido.',
        'cpf_cnpj' => 'O campo :field deve conter um CPF ou CNPJ válido.',
        'phone' => 'O campo :field deve conter um telefone válido.',
        'cep' => 'O campo :field deve conter um CEP válido.',
        'date' => 'O campo :field deve conter uma data válida.',
        'date_br' => 'O campo :field deve conter uma data válida no formato DD/MM/AAAA.',
        'datetime' => 'O campo :field deve conter uma data e hora válidas.',
        'time' => 'O campo :field deve conter um horário válido.',
        'array' => 'O campo :field deve ser uma lista válida.',
        'json' => 'O campo :field deve conter um JSON válido.',
        'min' => 'O campo :field deve ser maior ou igual a :value.',
        'max' => 'O campo :field deve ser menor ou igual a :value.',
        'between' => 'O campo :field deve estar entre :min e :max.',
        'min_length' => 'O campo :field deve ter pelo menos :value caracteres.',
        'max_length' => 'O campo :field deve ter no máximo :value caracteres.',
        'length' => 'O campo :field deve ter exatamente :value caracteres.',
        'in' => 'O valor informado no campo :field não é permitido.',
        'not_in' => 'O valor informado no campo :field não é permitido.',
        'regex' => 'O formato informado no campo :field é inválido.',
        'same' => 'O campo :field deve ser igual ao campo :other.',
        'different' => 'O campo :field deve ser diferente do campo :other.',
        'required_if' => 'O campo :field é obrigatório nesta situação.',
        'required_with' => 'O campo :field é obrigatório quando :other for informado.',
        'nullable' => '',
        'unique' => 'O valor informado no campo :field já está em uso.',
        'exists' => 'O valor informado no campo :field não foi encontrado.',
        'file' => 'O campo :field deve conter um arquivo válido.',
        'file_size' => 'O arquivo do campo :field excede o tamanho permitido.',
        'file_extension' => 'A extensão do arquivo informado no campo :field não é permitida.',
        'file_mime' => 'O tipo do arquivo informado no campo :field não é permitido.',
        'image' => 'O campo :field deve conter uma imagem válida.',
        'image_dimensions' => 'A imagem do campo :field possui dimensões inválidas.',
        'accepted' => 'O campo :field deve ser aceito.',
        'alpha' => 'O campo :field deve conter apenas letras.',
        'alpha_num' => 'O campo :field deve conter apenas letras e números.',
        'alpha_dash' => 'O campo :field deve conter apenas letras, números, hífen e underline.',
        'uuid' => 'O campo :field deve conter um UUID válido.',
        'ip' => 'O campo :field deve conter um endereço IP válido.',
        'after' => 'O campo :field deve ser uma data posterior a :date.',
        'after_or_equal' => 'O campo :field deve ser uma data igual ou posterior a :date.',
        'before' => 'O campo :field deve ser uma data anterior a :date.',
        'before_or_equal' => 'O campo :field deve ser uma data igual ou anterior a :date.',
        'callback' => 'O campo :field é inválido.'
    );

    /*
     * Regras avaliadas mesmo com o campo vazio. Com o campo vazio, só
     * elas rodam: as demais (email, cpf, min...) só valem quando há valor.
     */
    private static $presenceRules = array(
        'required',
        'required_if',
        'required_with',
        'accepted'
    );

    /*
     * Regras que só fazem sentido com um valor simples. Uma lista
     * (campo[]=...) nelas é inválida, em vez de virar "Array".
     */
    private static $scalarRules = array(
        'integer', 'int', 'numeric', 'decimal', 'boolean', 'bool', 'email', 'url',
        'cpf', 'cnpj', 'cpf_cnpj', 'phone', 'telefone', 'cep', 'date', 'date_br',
        'datetime', 'time', 'min_length', 'max_length', 'length', 'in', 'not_in',
        'regex', 'same', 'different', 'accepted', 'alpha', 'alpha_num', 'alpha_dash',
        'uuid', 'ip', 'after', 'after_or_equal', 'before', 'before_or_equal'
    );

    private static $customMessages = array();

    private static $labels = array();

    private static function valueExists($value)
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value) && trim($value) === '') {
            return false;
        }

        if (is_array($value) && empty($value)) {
            return false;
        }

        // <input type="file"> enviado sem arquivo
        if (is_array($value)
            && isset($value['error'], $value['tmp_name'])
            && (int) $value['error'] === UPLOAD_ERR_NO_FILE) {
            return false;
        }

        return true;
    }

    /*
     * 'itens.2.quantidade' → 'itens.*.quantidade'
     */
    private static function wildcardPattern($field)
    {
        return preg_replace('/(?<=^|\.)\d+(?=\.|$)/', '*', (string) $field);
    }

    private static function normalizeFieldLabel($field)
    {
        $field = (string) $field;

        if (isset(self::$labels[$field])) {
            return self::$labels[$field];
        }

        /*
         * Campo de um item de lista ('itens.2.quantidade'): usa o rótulo
         * de 'itens.*.quantidade' (ou o nome do campo) e o número do
         * item, contando a partir de 1: "Quantidade (item 3)".
         */
        if (preg_match('/(?:^|\.)(\d+)(?:\.|$)/', $field, $matches)) {
            $pattern = self::wildcardPattern($field);
            $segments = explode('.', $field);
            $last = end($segments);

            $label = isset(self::$labels[$pattern])
                ? self::$labels[$pattern]
                : ucfirst(str_replace(array('_', '-'), ' ', ctype_digit($last) ? $segments[0] : $last));

            return $label . ' (item ' . ((int) $matches[1] + 1) . ')';
        }

        $field = str_replace(
            array('_', '.', '-'),
            ' ',
            $field
        );

        return ucfirst($field);
    }

    private static function replaceMessage($message, $field, array $replace = array())
    {
        $replace['field'] = self::normalizeFieldLabel($field);

        foreach ($replace as $key => $value) {
            $message = str_replace(
                ':' . $key,
                (string) $value,
                $message
            );
        }

        return $message;
    }

    private static function addError(array &$errors, $field, $message)
    {
        if (!isset($errors[$field])) {
            $errors[$field] = array();
        }

        $errors[$field][] = $message;
    }

    /*
     * Converte as regras de um campo numa lista de
     * array('name' => ..., 'parameters' => array(...), 'callable' => ...).
     *
     * Formatos aceitos:
     * - string:  'required|email|max_length:150'
     *            'regex:...' deve ser a última regra da string (o padrão
     *            pode conter | e vírgulas);
     * - array:   array('required', 'email', 'max_length:150')
     * - chave => valor no array:
     *            'in' => array('aberto', 'pago')   valores com vírgula
     *            'regex' => '/^a|b$/'
     *            'unique' => function ($valor, $dados, $campo) {...}
     * - closure sem chave: regra 'callback'.
     *
     * Strings nunca são executadas como função: 'date' e 'time' são
     * regras, não as funções date() e time() do PHP. Para um método por
     * nome, use 'callback:Classe::metodo'.
     */
    private static function parseRules($rules)
    {
        $list = array();

        if (is_string($rules)) {
            $regexPosition = stripos($rules, 'regex:');
            $before = $regexPosition === false ? $rules : substr($rules, 0, $regexPosition);

            foreach (explode('|', $before) as $rule) {
                if (trim($rule) !== '') {
                    $list[] = self::parseRule($rule);
                }
            }

            if ($regexPosition !== false) {
                $list[] = self::parseRule(substr($rules, $regexPosition));
            }

            return $list;
        }

        if (!is_array($rules)) {
            return $list;
        }

        foreach ($rules as $key => $rule) {
            if (is_string($key)) {
                $name = strtolower(trim($key));

                if (is_object($rule) && is_callable($rule) || is_array($rule) && is_callable($rule)) {
                    $list[] = array('name' => $name, 'parameters' => array(), 'callable' => $rule);
                } else {
                    $list[] = array(
                        'name' => $name,
                        'parameters' => is_array($rule) ? array_values($rule) : array($rule),
                        'callable' => null
                    );
                }

                continue;
            }

            if (is_string($rule)) {
                if (trim($rule) !== '') {
                    $list[] = self::parseRule($rule);
                }

                continue;
            }

            if (is_callable($rule)) {
                $list[] = array('name' => 'callback', 'parameters' => array(), 'callable' => $rule);
            }
        }

        return $list;
    }

    private static function parseRule($rule)
    {
        $parts = explode(':', trim((string) $rule), 2);

        $name = strtolower(trim($parts[0]));

        if (!isset($parts[1])) {
            $parameters = array();
        } elseif ($name === 'regex') {
            // O padrão pode conter vírgulas: não é dividido.
            $parameters = array($parts[1]);
        } else {
            $parameters = array_map('trim', explode(',', $parts[1]));
        }

        return array(
            'name' => $name,
            'parameters' => $parameters,
            'callable' => null
        );
    }

    private static function getNestedValue(array $data, $field, $default = null)
    {
        if (array_key_exists($field, $data)) {
            return $data[$field];
        }

        $keys = explode('.', $field);
        $value = $data;

        foreach ($keys as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return $default;
            }

            $value = $value[$key];
        }

        return $value;
    }

    private static function isInteger($value)
    {
        if (is_int($value)) {
            return true;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value)) {
            return true;
        }

        return false;
    }

    /*
     * Número digitado por pessoas, no formato brasileiro ou americano:
     * "10,5", "10.5", "1.234,56", "1,234.56", "R$ 99,90". Com um único
     * separador, ele é o decimal ("1.500" = 1,5; "1,500" = 1,5), como no
     * InputHelper::decimal(). Separadores de milhar precisam formar
     * grupos de 3 dígitos: "1.2.3" é inválido. Devolve null se inválido.
     */
    private static function toNumber($value)
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

        if (preg_match('/^[+-]?(\d+([.,]\d+)?|[.,]\d+)$/', $value)) {
            return (float) str_replace(',', '.', $value);
        }

        if (preg_match('/^[+-]?\d{1,3}(\.\d{3})+(,\d+)?$/', $value)) {
            return (float) str_replace(array('.', ','), array('', '.'), $value);
        }

        if (preg_match('/^[+-]?\d{1,3}(,\d{3})+(\.\d+)?$/', $value)) {
            return (float) str_replace(',', '', $value);
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }

    private static function isDecimal($value)
    {
        return self::toNumber($value) !== null;
    }

    private static function isBoolean($value)
    {
        if (is_bool($value)) {
            return true;
        }

        $allowed = array(
            0,
            1,
            '0',
            '1',
            'true',
            'false',
            'on',
            'off',
            'yes',
            'no',
            'y',
            'n',
            'sim',
            's',
            'nao',
            'não'
        );

        if (is_string($value)) {
            $value = trim($value);
            $value = function_exists('mb_strtolower')
                ? mb_strtolower($value, 'UTF-8')
                : strtolower($value);
        }

        return in_array($value, $allowed, true);
    }

    private static function isDate($value, $format)
    {
        if (!is_string($value) || trim($value) === '') {
            return false;
        }

        $date = DateTime::createFromFormat(
            $format,
            $value
        );

        $errors = DateTime::getLastErrors();

        if ($errors === false) {
            return $date !== false && $date->format($format) === $value;
        }

        return (
            $date !== false &&
            $errors['warning_count'] === 0 &&
            $errors['error_count'] === 0 &&
            $date->format($format) === $value
        );
    }

    private static function validateCpf($cpf)
    {
        $cpf = preg_replace('/\D+/', '', (string) $cpf);

        if (strlen($cpf) !== 11) {
            return false;
        }

        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($position = 9; $position < 11; $position++) {
            $sum = 0;

            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $cpf[$index] * (($position + 1) - $index);
            }

            $digit = (($sum * 10) % 11) % 10;

            if ((int) $cpf[$position] !== $digit) {
                return false;
            }
        }

        return true;
    }

    private static function validateCnpj($cnpj)
    {
        $cnpj = preg_replace('/\D+/', '', (string) $cnpj);

        if (strlen($cnpj) !== 14) {
            return false;
        }

        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $weightsFirst = array(
            5,
            4,
            3,
            2,
            9,
            8,
            7,
            6,
            5,
            4,
            3,
            2
        );

        $weightsSecond = array(
            6,
            5,
            4,
            3,
            2,
            9,
            8,
            7,
            6,
            5,
            4,
            3,
            2
        );

        $sum = 0;

        foreach ($weightsFirst as $index => $weight) {
            $sum += (int) $cnpj[$index] * $weight;
        }

        $remainder = $sum % 11;
        $digitOne = $remainder < 2 ? 0 : 11 - $remainder;

        if ((int) $cnpj[12] !== $digitOne) {
            return false;
        }

        $sum = 0;

        foreach ($weightsSecond as $index => $weight) {
            $sum += (int) $cnpj[$index] * $weight;
        }

        $remainder = $sum % 11;
        $digitTwo = $remainder < 2 ? 0 : 11 - $remainder;

        if ((int) $cnpj[13] !== $digitTwo) {
            return false;
        }

        return true;
    }

    private static function validatePhone($phone)
    {
        $phone = preg_replace('/\D+/', '', (string) $phone);

        $length = strlen($phone);

        return $length >= 10 && $length <= 13;
    }

    private static function validateCep($cep)
    {
        $cep = preg_replace('/\D+/', '', (string) $cep);

        return strlen($cep) === 8;
    }

    private static function validateFile($value)
    {
        if (!is_array($value)) {
            return false;
        }

        if (!isset($value['error'])) {
            return false;
        }

        return (int) $value['error'] === UPLOAD_ERR_OK;
    }

    private static function fileExtension($file)
    {
        if (!is_array($file) || empty($file['name'])) {
            return '';
        }

        return strtolower(
            pathinfo(
                basename((string) $file['name']),
                PATHINFO_EXTENSION
            )
        );
    }

    private static function fileMime($file)
    {
        if (
            !is_array($file) ||
            empty($file['tmp_name']) ||
            !is_file($file['tmp_name']) ||
            !function_exists('finfo_open')
        ) {
            return '';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return '';
        }

        $mime = finfo_file(
            $finfo,
            $file['tmp_name']
        );

        finfo_close($finfo);

        return strtolower(trim((string) $mime));
    }

    private static function validateImage($file)
    {
        if (!self::validateFile($file)) {
            return false;
        }

        if (empty($file['tmp_name'])) {
            return false;
        }

        return @getimagesize($file['tmp_name']) !== false;
    }

    /*
     * Tamanho em bytes: 2048, "500K", "2M", "1G" (base 1024).
     */
    private static function parseSize($size)
    {
        $size = strtoupper(trim((string) $size));

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)B?$/', $size, $matches)) {
            return 0;
        }

        $multipliers = array('' => 1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824);

        return (int) round((float) $matches[1] * $multipliers[$matches[2]]);
    }

    /*
     * Referência de after/before: nome de outro campo dos dados ou uma
     * data ("today", "now", "2026-01-01", "01/01/2026").
     */
    private static function dateReference($reference, array $data)
    {
        $fromField = self::getNestedValue($data, $reference);

        return $fromField !== null ? $fromField : $reference;
    }

    /*
     * Data para timestamp. Datas brasileiras (DD/MM/AAAA) são lidas
     * explicitamente: o strtotime() as interpretaria como MM/DD/AAAA.
     */
    private static function toTimestamp($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (preg_match('#^\d{2}/\d{2}/\d{4}#', $value)) {
            foreach (array('d/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y') as $format) {
                $date = DateTime::createFromFormat('!' . $format, $value);

                if ($date !== false && $date->format($format) === $value) {
                    return $date->getTimestamp();
                }
            }

            return null;
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private static function getMessage($field, $rule, array $replace = array(), $customMessage = null)
    {
        if ($customMessage !== null && trim((string) $customMessage) !== '') {
            return self::replaceMessage(
                $customMessage,
                $field,
                $replace
            );
        }

        $keys = array(
            $field . '.' . $rule,
            self::wildcardPattern($field) . '.' . $rule
        );

        foreach ($keys as $fieldRuleKey) {
            if (isset(self::$customMessages[$fieldRuleKey])) {
                return self::replaceMessage(
                    self::$customMessages[$fieldRuleKey],
                    $field,
                    $replace
                );
            }
        }

        if (isset(self::$customMessages[$rule])) {
            return self::replaceMessage(
                self::$customMessages[$rule],
                $field,
                $replace
            );
        }

        $message = isset(self::$messages[$rule])
            ? self::$messages[$rule]
            : 'O campo :field é inválido.';

        return self::replaceMessage(
            $message,
            $field,
            $replace
        );
    }

    /*
     * Grandeza usada por min, max e between: quantidade de itens numa
     * lista, o valor de um número (inclusive "1.234,56") ou o tamanho de
     * um texto.
     */
    private static function measure($value)
    {
        if (is_array($value)) {
            return count($value);
        }

        $number = self::toNumber($value);

        if ($number !== null) {
            return $number;
        }

        return mb_strlen((string) $value, 'UTF-8');
    }

    private static function compareNumericOrLength($value, $target, $operator)
    {
        $current = self::measure($value);
        $target = (float) $target;

        switch ($operator) {
            case 'min':
                return $current >= $target;

            case 'max':
                return $current <= $target;

            default:
                return false;
        }
    }

    private static function validateRule($rule, $value, array $parameters, array $data, $field)
    {
        if ((is_array($value) || is_object($value)) && in_array($rule, self::$scalarRules, true)) {
            return false;
        }

        switch ($rule) {
            case 'required':
                return self::valueExists($value);

            case 'string':
                return is_string($value);

            case 'integer':
            case 'int':
                return self::isInteger($value);

            case 'numeric':
                return is_numeric($value);

            case 'decimal':
                return self::isDecimal($value);

            case 'boolean':
            case 'bool':
                return self::isBoolean($value);

            case 'email':
                return filter_var(
                    (string) $value,
                    FILTER_VALIDATE_EMAIL
                ) !== false;

            case 'url':
                if (filter_var((string) $value, FILTER_VALIDATE_URL) === false) {
                    return false;
                }

                // Sem parâmetros, só http/https: evita "javascript:..." em links.
                $schemes = empty($parameters)
                    ? array('http', 'https')
                    : array_map('strtolower', $parameters);

                return in_array(
                    strtolower((string) parse_url((string) $value, PHP_URL_SCHEME)),
                    $schemes,
                    true
                );

            case 'cpf':
                return self::validateCpf($value);

            case 'cnpj':
                return self::validateCnpj($value);

            case 'cpf_cnpj':
                return (
                    self::validateCpf($value) ||
                    self::validateCnpj($value)
                );

            case 'phone':
            case 'telefone':
                return self::validatePhone($value);

            case 'cep':
                return self::validateCep($value);

            case 'date':
                $format = isset($parameters[0])
                    ? $parameters[0]
                    : 'Y-m-d';

                return self::isDate($value, $format);

            case 'date_br':
                return self::isDate($value, 'd/m/Y');

            case 'datetime':
                $format = isset($parameters[0])
                    ? $parameters[0]
                    : 'Y-m-d H:i:s';

                return self::isDate($value, $format);

            case 'time':
                if (isset($parameters[0])) {
                    return self::isDate($value, $parameters[0]);
                }

                return self::isDate($value, 'H:i') || self::isDate($value, 'H:i:s');

            case 'array':
                return is_array($value);

            case 'json':
                if (is_array($value) || is_object($value)) {
                    return true;
                }

                if (!is_string($value) || trim($value) === '') {
                    return false;
                }

                json_decode($value, true);

                return json_last_error() === JSON_ERROR_NONE;

            case 'min':
                return self::compareNumericOrLength(
                    $value,
                    isset($parameters[0]) ? $parameters[0] : 0,
                    'min'
                );

            case 'max':
                return self::compareNumericOrLength(
                    $value,
                    isset($parameters[0]) ? $parameters[0] : 0,
                    'max'
                );

            case 'between':
                if (!isset($parameters[0], $parameters[1])) {
                    return false;
                }

                $current = self::measure($value);

                return (
                    $current >= (float) $parameters[0] &&
                    $current <= (float) $parameters[1]
                );

            case 'min_length':
                return mb_strlen(
                    (string) $value,
                    'UTF-8'
                ) >= (int) $parameters[0];

            case 'max_length':
                return mb_strlen(
                    (string) $value,
                    'UTF-8'
                ) <= (int) $parameters[0];

            case 'length':
                return mb_strlen(
                    (string) $value,
                    'UTF-8'
                ) === (int) $parameters[0];

            case 'in':
                return in_array(
                    (string) $value,
                    array_map('strval', $parameters),
                    true
                );

            case 'not_in':
                return !in_array(
                    (string) $value,
                    array_map('strval', $parameters),
                    true
                );

            case 'regex':
                if (empty($parameters[0])) {
                    return false;
                }

                return @preg_match(
                    $parameters[0],
                    (string) $value
                ) === 1;

            case 'same':
                if (empty($parameters[0])) {
                    return false;
                }

                return $value === self::getNestedValue(
                    $data,
                    $parameters[0]
                );

            case 'different':
                if (empty($parameters[0])) {
                    return false;
                }

                return $value !== self::getNestedValue(
                    $data,
                    $parameters[0]
                );

            case 'required_if':
                if (count($parameters) < 2) {
                    return false;
                }

                $other = self::getNestedValue(
                    $data,
                    $parameters[0]
                );

                $expectedValues = array_slice($parameters, 1);

                if (
                    in_array(
                        (string) $other,
                        array_map('strval', $expectedValues),
                        true
                    )
                ) {
                    return self::valueExists($value);
                }

                return true;

            case 'required_with':
                if (empty($parameters[0])) {
                    return false;
                }

                foreach ($parameters as $otherField) {
                    $otherValue = self::getNestedValue(
                        $data,
                        $otherField
                    );

                    if (self::valueExists($otherValue)) {
                        return self::valueExists($value);
                    }
                }

                return true;

            case 'unique':
            case 'exists':
                if (
                    empty($parameters[0]) ||
                    !is_callable($parameters[0])
                ) {
                    return false;
                }

                return (bool) call_user_func(
                    $parameters[0],
                    $value,
                    $data,
                    $field
                );

            case 'file':
                return self::validateFile($value);

            case 'file_size':
                if (!self::validateFile($value)) {
                    return false;
                }

                $maxSize = isset($parameters[0])
                    ? self::parseSize($parameters[0])
                    : 0;

                return (
                    $maxSize > 0 &&
                    isset($value['size']) &&
                    (int) $value['size'] <= $maxSize
                );

            case 'file_extension':
                if (!self::validateFile($value)) {
                    return false;
                }

                return in_array(
                    self::fileExtension($value),
                    array_map('strtolower', $parameters),
                    true
                );

            case 'file_mime':
                if (!self::validateFile($value)) {
                    return false;
                }

                return in_array(
                    self::fileMime($value),
                    array_map('strtolower', $parameters),
                    true
                );

            case 'image':
                return self::validateImage($value);

            case 'image_dimensions':
                if (!self::validateImage($value)) {
                    return false;
                }

                $image = @getimagesize($value['tmp_name']);

                if ($image === false) {
                    return false;
                }

                $width = isset($image[0])
                    ? (int) $image[0]
                    : 0;

                $height = isset($image[1])
                    ? (int) $image[1]
                    : 0;

                $maxWidth = isset($parameters[0])
                    ? (int) $parameters[0]
                    : 0;

                $maxHeight = isset($parameters[1])
                    ? (int) $parameters[1]
                    : 0;

                if ($maxWidth > 0 && $width > $maxWidth) {
                    return false;
                }

                if ($maxHeight > 0 && $height > $maxHeight) {
                    return false;
                }

                return true;

            case 'accepted':
                return in_array(
                    is_string($value)
                        ? strtolower(trim($value))
                        : $value,
                    array(
                        1,
                        '1',
                        true,
                        'true',
                        'on',
                        'yes',
                        'sim'
                    ),
                    true
                );

            case 'alpha':
                return preg_match(
                    '/^[\p{L}\s]+$/u',
                    (string) $value
                ) === 1;

            case 'alpha_num':
                return preg_match(
                    '/^[\p{L}\p{N}]+$/u',
                    (string) $value
                ) === 1;

            case 'alpha_dash':
                return preg_match(
                    '/^[\p{L}\p{N}_-]+$/u',
                    (string) $value
                ) === 1;

            case 'uuid':
                return preg_match(
                    '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
                    (string) $value
                ) === 1;

            case 'ip':
                return filter_var(
                    (string) $value,
                    FILTER_VALIDATE_IP
                ) !== false;

            case 'after':
            case 'after_or_equal':
            case 'before':
            case 'before_or_equal':
                if (!isset($parameters[0])) {
                    return false;
                }

                $current = self::toTimestamp($value);
                $reference = self::toTimestamp(
                    self::dateReference($parameters[0], $data)
                );

                if ($current === null || $reference === null) {
                    return false;
                }

                switch ($rule) {
                    case 'after':
                        return $current > $reference;

                    case 'after_or_equal':
                        return $current >= $reference;

                    case 'before':
                        return $current < $reference;

                    default:
                        return $current <= $reference;
                }

            case 'callback':
                if (
                    empty($parameters[0]) ||
                    !is_callable($parameters[0])
                ) {
                    return false;
                }

                return (bool) call_user_func(
                    $parameters[0],
                    $value,
                    $data,
                    $field
                );
        }

        return true;
    }

    /*
     * 'itens.*.quantidade' => regras vira uma entrada por item existente
     * nos dados: 'itens.0.quantidade', 'itens.1.quantidade'...
     */
    private static function expandRules(array $data, array $rules)
    {
        $expanded = array();

        foreach ($rules as $field => $fieldRules) {
            $field = (string) $field;

            if (strpos($field, '*') === false) {
                $expanded[$field] = $fieldRules;

                continue;
            }

            foreach (self::expandField($data, explode('.', $field), '') as $concrete) {
                $expanded[$concrete] = $fieldRules;
            }
        }

        return $expanded;
    }

    private static function expandField($value, array $segments, $prefix)
    {
        if (empty($segments)) {
            return array($prefix);
        }

        $segment = array_shift($segments);

        if ($segment !== '*') {
            $next = is_array($value) && array_key_exists($segment, $value)
                ? $value[$segment]
                : null;

            return self::expandField($next, $segments, $prefix === '' ? $segment : $prefix . '.' . $segment);
        }

        if (!is_array($value)) {
            return array();
        }

        $fields = array();

        foreach (array_keys($value) as $key) {
            $fields = array_merge(
                $fields,
                self::expandField($value[$key], $segments, $prefix === '' ? (string) $key : $prefix . '.' . $key)
            );
        }

        return $fields;
    }

    /*
     * Valores usados nas mensagens (:value, :min, :max, :other, :date).
     */
    private static function messageReplacements($rule, array $parameters, array $data)
    {
        $first = isset($parameters[0]) && is_scalar($parameters[0]) ? (string) $parameters[0] : '';

        switch ($rule) {
            case 'min':
            case 'max':
            case 'min_length':
            case 'max_length':
            case 'length':
            case 'file_size':
                return array('value' => $first);

            case 'between':
                return array(
                    'min' => $first,
                    'max' => isset($parameters[1]) ? (string) $parameters[1] : ''
                );

            case 'same':
            case 'different':
            case 'required_with':
                return array('other' => $first === '' ? '' : self::normalizeFieldLabel($first));

            case 'after':
            case 'after_or_equal':
            case 'before':
            case 'before_or_equal':
                if ($first !== '' && self::getNestedValue($data, $first) !== null) {
                    return array('date' => self::normalizeFieldLabel($first));
                }

                $labels = array('today' => 'hoje', 'now' => 'agora', 'tomorrow' => 'amanhã', 'yesterday' => 'ontem');

                return array('date' => isset($labels[strtolower($first)]) ? $labels[strtolower($first)] : $first);
        }

        return array();
    }

    /*
     * Executa uma regra. Devolve null se passou, ou a mensagem de erro.
     */
    private static function checkRule(array $rule, $value, array $data, $field)
    {
        if ($rule['callable'] !== null) {
            /*
             * O callable devolve true (válido), false (inválido, com a
             * mensagem da regra) ou uma string: a mensagem de erro.
             * Sem return (null) conta como inválido.
             */
            $result = call_user_func($rule['callable'], $value, $data, $field);

            if (is_string($result)) {
                return trim($result) === ''
                    ? self::getMessage($field, $rule['name'])
                    : self::replaceMessage($result, $field);
            }

            return $result ? null : self::getMessage($field, $rule['name']);
        }

        if (self::validateRule($rule['name'], $value, $rule['parameters'], $data, $field)) {
            return null;
        }

        return self::getMessage(
            $field,
            $rule['name'],
            self::messageReplacements($rule['name'], $rule['parameters'], $data)
        );
    }

    public static function setMessages(array $messages)
    {
        self::$messages = array_merge(
            self::$messages,
            $messages
        );
    }

    public static function setCustomMessages(array $messages)
    {
        self::$customMessages = array_merge(
            self::$customMessages,
            $messages
        );
    }

    public static function setLabels(array $labels)
    {
        self::$labels = array_merge(
            self::$labels,
            $labels
        );
    }

    public static function reset()
    {
        self::$customMessages = array();
        self::$labels = array();
    }

    public static function validate(array $data, array $rules, array $messages = array(), array $labels = array())
    {
        $oldMessages = self::$customMessages;
        $oldLabels = self::$labels;

        self::setCustomMessages($messages);
        self::setLabels($labels);

        $errors = array();

        try {
            foreach (self::expandRules($data, $rules) as $field => $fieldRules) {
                $value = self::getNestedValue(
                    $data,
                    $field
                );

                $isEmpty = !self::valueExists($value);

                foreach (self::parseRules($fieldRules) as $rule) {
                    if ($rule['name'] === '' || $rule['name'] === 'nullable') {
                        continue;
                    }

                    /*
                     * Campo vazio: só as regras de presença (required,
                     * required_if, required_with, accepted) se aplicam.
                     * Um campo opcional vazio não é "e-mail inválido".
                     */
                    if ($isEmpty && !in_array($rule['name'], self::$presenceRules, true)) {
                        continue;
                    }

                    $message = self::checkRule($rule, $value, $data, $field);

                    if ($message !== null) {
                        self::addError($errors, $field, $message);
                    }
                }
            }

            return array(
                'valid' => empty($errors),
                'errors' => $errors,
                'first_error' => self::firstError(
                    $errors
                )
            );
        } catch (Throwable $error) {
            JLog::add(
                '[' . __METHOD__ . '] ' .
                    $error->getMessage() .
                    ' | Arquivo: ' . $error->getFile() .
                    ' | Linha: ' . $error->getLine(),
                JLog::ERROR,
                'validation_helper'
            );

            self::addError(
                $errors,
                '_system',
                'Não foi possível validar os dados informados.'
            );

            return array(
                'valid' => false,
                'errors' => $errors,
                'first_error' => self::firstError(
                    $errors
                )
            );
        } finally {
            self::$customMessages = $oldMessages;
            self::$labels = $oldLabels;
        }
    }

    /*
     * Regras, mensagens e rótulos prontos para o Vigia.js (js/vigia.js),
     * para declarar as regras uma vez só, no PHP:
     *
     *     <form data-vigia='<?php echo htmlspecialchars(json_encode(
     *         ValidationHelper::clientConfig($regras, $mensagens, $rotulos)
     *     ), ENT_QUOTES, 'UTF-8'); ?>'>
     *
     * Ficam de fora as regras com closure/callable e as que só o servidor
     * confere (unique, exists, callback). O servidor continua validando
     * tudo no envio: a validação no navegador é conforto, não segurança.
     */
    public static function clientConfig(array $rules, array $messages = array(), array $labels = array())
    {
        $serverOnly = array('', 'nullable', 'unique', 'exists', 'callback');
        $clientRules = array();

        foreach ($rules as $field => $fieldRules) {
            $list = array();

            foreach (self::parseRules($fieldRules) as $rule) {
                if ($rule['callable'] !== null || in_array($rule['name'], $serverOnly, true)) {
                    continue;
                }

                $parameters = array();

                foreach ($rule['parameters'] as $parameter) {
                    if (!is_scalar($parameter)) {
                        continue 2;
                    }

                    $parameters[] = (string) $parameter;
                }

                $list[] = empty($parameters)
                    ? $rule['name']
                    : $rule['name'] . ':' . implode(',', $parameters);
            }

            if (!empty($list)) {
                $clientRules[(string) $field] = $list;
            }
        }

        $onlyStrings = function (array $values) {
            return array_filter($values, function ($value) {
                return is_string($value) && trim($value) !== '';
            });
        };

        return array(
            'rules' => (object) $clientRules,
            'messages' => (object) $onlyStrings(array_merge(self::$customMessages, $messages)),
            'labels' => (object) $onlyStrings(array_merge(self::$labels, $labels))
        );
    }

    public static function firstError(array $errors)
    {
        foreach ($errors as $fieldErrors) {
            if (!empty($fieldErrors)) {
                return reset($fieldErrors);
            }
        }

        return null;
    }

    public static function passes(array $data, array $rules, array $messages = array(), array $labels = array())
    {
        $result = self::validate(
            $data,
            $rules,
            $messages,
            $labels
        );

        return $result['valid'];
    }

    public static function fails(array $data, array $rules, array $messages = array(), array $labels = array())
    {
        return !self::passes(
            $data,
            $rules,
            $messages,
            $labels
        );
    }

    public static function cpf($cpf)
    {
        return self::validateCpf($cpf);
    }

    public static function cnpj($cnpj)
    {
        return self::validateCnpj($cnpj);
    }

    public static function email($email)
    {
        return filter_var(
            (string) $email,
            FILTER_VALIDATE_EMAIL
        ) !== false;
    }

    public static function date($date, $format = 'Y-m-d')
    {
        return self::isDate($date, $format);
    }

    public static function file($file)
    {
        return self::validateFile($file);
    }

    public static function image($file)
    {
        return self::validateImage($file);
    }
}
