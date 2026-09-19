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
        'callback' => 'O campo :field é inválido.'
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

        return true;
    }

    private static function normalizeFieldLabel($field)
    {
        if (isset(self::$labels[$field])) {
            return self::$labels[$field];
        }

        $field = str_replace(
            array('_', '.', '-'),
            ' ',
            (string) $field
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

    private static function parseRules($rules)
    {
        if (is_string($rules)) {
            return explode('|', $rules);
        }

        if (is_array($rules)) {
            return $rules;
        }

        return array();
    }

    private static function parseRule($rule)
    {
        if (!is_string($rule)) {
            return array(
                'name' => '',
                'parameters' => array()
            );
        }

        $parts = explode(':', $rule, 2);

        $name = strtolower(trim($parts[0]));

        $parameters = isset($parts[1])
            ? explode(',', $parts[1])
            : array();

        $parameters = array_map(
            function ($parameter) {
                return trim($parameter);
            },
            $parameters
        );

        return array(
            'name' => $name,
            'parameters' => $parameters
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

    private static function isDecimal($value)
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        if (!is_string($value)) {
            return false;
        }

        $value = trim($value);

        if ($value === '') {
            return false;
        }

        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);

        return is_numeric($value);
    }

    private static function normalizeDecimal($value)
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $value = trim((string) $value);
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);

        return (float) $value;
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
            'sim',
            'nao',
            'não'
        );

        return in_array(
            is_string($value) ? strtolower(trim($value)) : $value,
            $allowed,
            true
        );
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

    private static function getMessage($field, $rule, array $replace = array(), $customMessage = null)
    {
        if ($customMessage !== null && trim((string) $customMessage) !== '') {
            return self::replaceMessage(
                $customMessage,
                $field,
                $replace
            );
        }

        $fieldRuleKey = $field . '.' . $rule;

        if (isset(self::$customMessages[$fieldRuleKey])) {
            return self::replaceMessage(
                self::$customMessages[$fieldRuleKey],
                $field,
                $replace
            );
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

    private static function compareNumericOrLength($value, $target, $operator)
    {
        if (is_array($value)) {
            $current = count($value);
        } elseif (is_numeric($value)) {
            $current = self::normalizeDecimal($value);
        } else {
            $current = mb_strlen((string) $value, 'UTF-8');
        }

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
                return filter_var(
                    (string) $value,
                    FILTER_VALIDATE_URL
                ) !== false;

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
                return self::isDate($value, 'H:i');

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

                if (is_array($value)) {
                    $current = count($value);
                } elseif (is_numeric($value)) {
                    $current = self::normalizeDecimal($value);
                } else {
                    $current = mb_strlen((string) $value, 'UTF-8');
                }

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
                    ? (int) $parameters[0]
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
                    '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i',
                    (string) $value
                ) === 1;

            case 'ip':
                return filter_var(
                    (string) $value,
                    FILTER_VALIDATE_IP
                ) !== false;

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
            foreach ($rules as $field => $fieldRules) {
                $value = self::getNestedValue(
                    $data,
                    $field
                );

                $parsedRules = self::parseRules($fieldRules);

                $nullable = false;
                $hasRequiredRule = false;

                foreach ($parsedRules as $rawRule) {
                    if (!is_string($rawRule)) {
                        continue;
                    }

                    $parsed = self::parseRule($rawRule);

                    if ($parsed['name'] === 'nullable') {
                        $nullable = true;
                    }

                    if (
                        $parsed['name'] === 'required' ||
                        $parsed['name'] === 'required_if' ||
                        $parsed['name'] === 'required_with'
                    ) {
                        $hasRequiredRule = true;
                    }
                }

                if (
                    $nullable &&
                    !self::valueExists($value)
                ) {
                    continue;
                }

                if (
                    !$hasRequiredRule &&
                    !self::valueExists($value)
                ) {
                    continue;
                }

                foreach ($parsedRules as $rawRule) {
                    if (is_callable($rawRule)) {
                        $isValid = (bool) call_user_func(
                            $rawRule,
                            $value,
                            $data,
                            $field
                        );

                        if (!$isValid) {
                            self::addError(
                                $errors,
                                $field,
                                self::getMessage(
                                    $field,
                                    'callback'
                                )
                            );
                        }

                        continue;
                    }

                    $parsed = self::parseRule($rawRule);
                    $rule = $parsed['name'];
                    $parameters = $parsed['parameters'];

                    if ($rule === '' || $rule === 'nullable') {
                        continue;
                    }

                    $isValid = self::validateRule(
                        $rule,
                        $value,
                        $parameters,
                        $data,
                        $field
                    );

                    if ($isValid) {
                        continue;
                    }

                    $replace = array();

                    if ($rule === 'min' || $rule === 'max') {
                        $replace['value'] = isset($parameters[0])
                            ? $parameters[0]
                            : '';
                    }

                    if ($rule === 'between') {
                        $replace['min'] = isset($parameters[0])
                            ? $parameters[0]
                            : '';

                        $replace['max'] = isset($parameters[1])
                            ? $parameters[1]
                            : '';
                    }

                    if (
                        $rule === 'min_length' ||
                        $rule === 'max_length' ||
                        $rule === 'length'
                    ) {
                        $replace['value'] = isset($parameters[0])
                            ? $parameters[0]
                            : '';
                    }

                    if (
                        $rule === 'same' ||
                        $rule === 'different' ||
                        $rule === 'required_with'
                    ) {
                        $replace['other'] = isset($parameters[0])
                            ? self::normalizeFieldLabel($parameters[0])
                            : '';
                    }

                    self::addError(
                        $errors,
                        $field,
                        self::getMessage(
                            $field,
                            $rule,
                            $replace
                        )
                    );
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
