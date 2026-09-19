<?php

defined('_JEXEC') or die;

jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');

class ExportHelper
{
    private static $defaultBaseDirectory = null;

    private static $logCategory = 'export_helper';

    private static $logDirectory = null;

    private static $directoryPermission = 0755;

    private static $filePermission = 0644;

    private static $maxRows = 500000;

    private static $defaultDelimiter = ';';

    private static $defaultEnclosure = '"';

    private static $defaultEscape = '';

    private static $defaultEncoding = 'UTF-8';

    private static $csvInjectionPrefixes = array(
        '=',
        '+',
        '-',
        '@',
        "\t",
        "\r"
    );

    private static function log($level, $message, array $context = array())
    {
        try {
            $priority = JLog::INFO;

            switch (strtolower((string) $level)) {
                case 'debug':
                    $priority = JLog::DEBUG;
                    break;

                case 'notice':
                    $priority = JLog::NOTICE;
                    break;

                case 'warning':
                    $priority = JLog::WARNING;
                    break;

                case 'error':
                    $priority = JLog::ERROR;
                    break;

                case 'critical':
                    $priority = JLog::CRITICAL;
                    break;
            }

            $contextText = '';

            if (!empty($context)) {
                $json = json_encode(
                    $context,
                    JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                );

                if ($json !== false) {
                    $contextText = ' | Contexto: ' . $json;
                }
            }

            if (
                self::$logDirectory !== null &&
                self::$logDirectory !== ''
            ) {
                $directory = self::resolveLogDirectory(
                    self::$logDirectory
                );

                JLog::addLogger(
                    array(
                        'text_file' => self::$logCategory . '.php',
                        'text_file_path' => $directory,
                        'text_entry_format' => '{DATETIME} {PRIORITY} {CATEGORY} {MESSAGE}'
                    ),
                    $priority,
                    array(self::$logCategory)
                );
            }

            JLog::add(
                self::limitText(
                    (string) $message . $contextText,
                    20000
                ),
                $priority,
                self::$logCategory
            );
        } catch (Throwable $error) {
        }
    }

    private static function result($success, $message, array $data = array(), array $errors = array())
    {
        return array(
            'success' => (bool) $success,
            'status' => $success ? 'sucesso' : 'erro',
            'mensagem' => (string) $message,
            'data' => $data,
            'errors' => $errors
        );
    }

    private static function limitText($value, $limit)
    {
        $value = (string) $value;
        $limit = (int) $limit;

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit) . '...[truncado]';
    }

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

    private static function pathInsideBase($path, $baseDirectory)
    {
        $path = self::normalizePath($path);

        $baseDirectory = rtrim(
            self::normalizePath($baseDirectory),
            '/'
        );

        return (
            $path === $baseDirectory ||
            strpos($path, $baseDirectory . '/') === 0
        );
    }

    private static function validateRawPath($path)
    {
        $path = trim((string) $path);

        if ($path === '') {
            throw new InvalidArgumentException(
                'Caminho inválido.'
            );
        }

        if (
            strpos($path, "\0") !== false ||
            strpos($path, '../') !== false ||
            strpos($path, '..\\') !== false
        ) {
            throw new RuntimeException(
                'Caminho inválido.'
            );
        }

        return str_replace('\\', '/', $path);
    }

    private static function resolveLogDirectory($directory)
    {
        $directory = self::validateRawPath($directory);
        $siteRoot = self::siteRoot();

        if (strpos($directory, '/') !== 0) {
            $directory = $siteRoot .
                '/' .
                trim($directory, '/');
        }

        $directory = self::normalizePath($directory);

        if (!self::pathInsideBase($directory, $siteRoot)) {
            throw new RuntimeException(
                'Diretório de logs fora de JPATH_SITE.'
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

        return self::normalizePath($realDirectory);
    }

    private static function resolveBaseDirectory($baseDirectory = null)
    {
        $siteRoot = self::siteRoot();

        if ($baseDirectory === null || trim((string) $baseDirectory) === '') {
            if (self::$defaultBaseDirectory !== null) {
                return self::$defaultBaseDirectory;
            }

            return $siteRoot;
        }

        $baseDirectory = self::validateRawPath($baseDirectory);

        if (strpos($baseDirectory, '/') !== 0) {
            $baseDirectory = $siteRoot .
                '/' .
                trim($baseDirectory, '/');
        }

        $baseDirectory = self::normalizePath($baseDirectory);

        if (!self::pathInsideBase($baseDirectory, $siteRoot)) {
            throw new RuntimeException(
                'O diretório base deve estar dentro de JPATH_SITE.'
            );
        }

        if (!JFolder::exists($baseDirectory)) {
            if (!JFolder::create(
                $baseDirectory,
                self::$directoryPermission
            )) {
                throw new RuntimeException(
                    'Não foi possível criar o diretório base.'
                );
            }
        }

        $realBaseDirectory = realpath($baseDirectory);

        if ($realBaseDirectory === false) {
            throw new RuntimeException(
                'Não foi possível validar o diretório base.'
            );
        }

        $realBaseDirectory = self::normalizePath($realBaseDirectory);

        if (!self::pathInsideBase($realBaseDirectory, $siteRoot)) {
            throw new RuntimeException(
                'Diretório base validado não permitido.'
            );
        }

        return $realBaseDirectory;
    }

    private static function sanitizeRelativePath($path)
    {
        $path = self::validateRawPath($path);
        $path = trim($path, '/');

        if ($path === '') {
            throw new InvalidArgumentException(
                'Caminho relativo inválido.'
            );
        }

        $parts = explode('/', $path);
        $safeParts = array();

        foreach ($parts as $part) {
            $part = trim($part);

            if (
                $part === '' ||
                $part === '.' ||
                $part === '..'
            ) {
                throw new RuntimeException(
                    'Caminho relativo inválido.'
                );
            }

            $safePart = JFile::makeSafe($part);

            if ($safePart === '') {
                throw new RuntimeException(
                    'Caminho relativo inválido.'
                );
            }

            $safeParts[] = $safePart;
        }

        return implode('/', $safeParts);
    }

    private static function resolveFilePath($path, $baseDirectory = null)
    {
        $baseDirectory = self::resolveBaseDirectory(
            $baseDirectory
        );

        $path = self::validateRawPath($path);

        if (strpos($path, '/') === 0) {
            $filePath = self::normalizePath($path);
        } else {
            $filePath = self::normalizePath(
                $baseDirectory .
                    '/' .
                    self::sanitizeRelativePath($path)
            );
        }

        if (!self::pathInsideBase($filePath, $baseDirectory)) {
            throw new RuntimeException(
                'Arquivo fora da base autorizada.'
            );
        }

        $directory = dirname($filePath);

        if (!JFolder::exists($directory)) {
            if (!JFolder::create(
                $directory,
                self::$directoryPermission
            )) {
                throw new RuntimeException(
                    'Não foi possível criar o diretório de destino.'
                );
            }
        }

        return $filePath;
    }

    private static function relativePath($filePath, $baseDirectory = null)
    {
        $baseDirectory = self::resolveBaseDirectory(
            $baseDirectory
        );

        $filePath = self::normalizePath($filePath);

        if (!self::pathInsideBase($filePath, $baseDirectory)) {
            throw new RuntimeException(
                'Não foi possível gerar o caminho relativo.'
            );
        }

        return ltrim(
            substr($filePath, strlen($baseDirectory)),
            '/'
        );
    }

    private static function siteRelativePath($filePath)
    {
        $siteRoot = self::siteRoot();
        $filePath = self::normalizePath($filePath);

        if (!self::pathInsideBase($filePath, $siteRoot)) {
            return null;
        }

        return ltrim(
            substr($filePath, strlen($siteRoot)),
            '/'
        );
    }

    private static function buildUrl($filePath)
    {
        $relativePath = self::siteRelativePath($filePath);

        if ($relativePath === null) {
            return null;
        }

        return rtrim(JUri::root(), '/') .
            '/' .
            str_replace(
                '%2F',
                '/',
                rawurlencode($relativePath)
            );
    }

    private static function normalizeDelimiter($delimiter)
    {
        $delimiter = (string) $delimiter;

        if (strlen($delimiter) !== 1) {
            throw new InvalidArgumentException(
                'O delimitador deve possuir exatamente um caractere.'
            );
        }

        return $delimiter;
    }

    private static function normalizeEnclosure($enclosure)
    {
        $enclosure = (string) $enclosure;

        if (strlen($enclosure) !== 1) {
            throw new InvalidArgumentException(
                'O encapsulador deve possuir exatamente um caractere.'
            );
        }

        return $enclosure;
    }

    private static function normalizeEscape($escape)
    {
        $escape = (string) $escape;

        if ($escape !== '' && strlen($escape) !== 1) {
            throw new InvalidArgumentException(
                'O escape deve possuir um caractere ou ser vazio.'
            );
        }

        return $escape;
    }

    private static function normalizeFileName($fileName)
    {
        $fileName = basename(
            str_replace('\\', '/', (string) $fileName)
        );

        $fileName = JFile::makeSafe($fileName);

        if ($fileName === '') {
            throw new InvalidArgumentException(
                'Nome de arquivo inválido.'
            );
        }

        if (strtolower(JFile::getExt($fileName)) !== 'csv') {
            $fileName .= '.csv';
        }

        return $fileName;
    }

    private static function normalizeValue($value)
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        if (is_scalar($value)) {
            return trim((string) $value);
        }

        if ($value instanceof DateTime) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value)) {
            $value = (array) $value;
        }

        if (is_array($value)) {
            $json = json_encode(
                $value,
                JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
            );

            return $json !== false
                ? $json
                : '';
        }

        return '';
    }

    private static function protectCsvInjection($value, $enabled)
    {
        $value = self::normalizeValue($value);

        if (!$enabled || $value === '') {
            return $value;
        }

        $firstCharacter = substr($value, 0, 1);

        if (
            in_array(
                $firstCharacter,
                self::$csvInjectionPrefixes,
                true
            )
        ) {
            return "'" . $value;
        }

        return $value;
    }

    private static function getValue($row, $key, $default = '')
    {
        if (is_object($row)) {
            $row = (array) $row;
        }

        if (!is_array($row)) {
            return $default;
        }

        if (array_key_exists($key, $row)) {
            return $row[$key];
        }

        $parts = explode('.', (string) $key);
        $value = $row;

        foreach ($parts as $part) {
            if (
                !is_array($value) ||
                !array_key_exists($part, $value)
            ) {
                return $default;
            }

            $value = $value[$part];
        }

        return $value;
    }

    private static function normalizeColumns(array $columns)
    {
        $normalized = array();

        foreach ($columns as $key => $column) {
            if (is_int($key)) {
                if (is_string($column)) {
                    $normalized[] = array(
                        'key' => $column,
                        'label' => $column,
                        'callback' => null
                    );

                    continue;
                }

                if (is_array($column)) {
                    $columnKey = isset($column['key'])
                        ? trim((string) $column['key'])
                        : '';

                    if ($columnKey === '') {
                        throw new InvalidArgumentException(
                            'Coluna de exportação sem chave.'
                        );
                    }

                    $normalized[] = array(
                        'key' => $columnKey,
                        'label' => isset($column['label'])
                            ? (string) $column['label']
                            : $columnKey,
                        'callback' => isset($column['callback'])
                            ? $column['callback']
                            : null
                    );

                    continue;
                }

                throw new InvalidArgumentException(
                    'Configuração de coluna inválida.'
                );
            }

            if (is_callable($column)) {
                $normalized[] = array(
                    'key' => $key,
                    'label' => $key,
                    'callback' => $column
                );

                continue;
            }

            if (is_array($column)) {
                $normalized[] = array(
                    'key' => isset($column['key'])
                        ? (string) $column['key']
                        : $key,
                    'label' => isset($column['label'])
                        ? (string) $column['label']
                        : $key,
                    'callback' => isset($column['callback'])
                        ? $column['callback']
                        : null
                );

                continue;
            }

            $normalized[] = array(
                'key' => $key,
                'label' => (string) $column,
                'callback' => null
            );
        }

        if (empty($normalized)) {
            throw new InvalidArgumentException(
                'Informe ao menos uma coluna para exportação.'
            );
        }

        return $normalized;
    }

    private static function normalizeRows($rows)
    {
        if ($rows instanceof Traversable) {
            return $rows;
        }

        if (is_array($rows)) {
            return new ArrayIterator($rows);
        }

        throw new InvalidArgumentException(
            'Os dados da exportação devem ser array, Traversable ou generator.'
        );
    }

    private static function rowToCsv(array $columns, $row, $rowIndex, $protectCsvInjection)
    {
        $line = array();

        foreach ($columns as $column) {
            if (
                isset($column['callback']) &&
                is_callable($column['callback'])
            ) {
                $value = call_user_func(
                    $column['callback'],
                    $row,
                    $rowIndex
                );
            } else {
                $value = self::getValue(
                    $row,
                    $column['key']
                );
            }

            $line[] = self::protectCsvInjection(
                $value,
                $protectCsvInjection
            );
        }

        return $line;
    }

    private static function headersToCsv(array $columns, $protectCsvInjection)
    {
        $headers = array();

        foreach ($columns as $column) {
            $headers[] = self::protectCsvInjection(
                $column['label'],
                $protectCsvInjection
            );
        }

        return $headers;
    }

    private static function writeCsvLine($handle, array $line, $delimiter, $enclosure, $escape)
    {
        $result = fputcsv(
            $handle,
            $line,
            $delimiter,
            $enclosure,
            $escape
        );

        if ($result === false) {
            throw new RuntimeException(
                'Não foi possível gravar uma linha no CSV.'
            );
        }

        return $result;
    }

    private static function writeRows($handle, $rows, array $columns, array $options = array())
    {
        $delimiter = isset($options['delimiter'])
            ? self::normalizeDelimiter($options['delimiter'])
            : self::$defaultDelimiter;

        $enclosure = isset($options['enclosure'])
            ? self::normalizeEnclosure($options['enclosure'])
            : self::$defaultEnclosure;

        $escape = isset($options['escape'])
            ? self::normalizeEscape($options['escape'])
            : self::$defaultEscape;

        $writeHeaders = isset($options['headers'])
            ? (bool) $options['headers']
            : true;

        $protectCsvInjection = isset($options['protect_csv_injection'])
            ? (bool) $options['protect_csv_injection']
            : true;

        $maxRows = isset($options['max_rows'])
            ? max(1, (int) $options['max_rows'])
            : self::$maxRows;

        $onRow = isset($options['on_row'])
            ? $options['on_row']
            : null;

        $onProgress = isset($options['on_progress'])
            ? $options['on_progress']
            : null;

        $progressEvery = isset($options['progress_every'])
            ? max(1, (int) $options['progress_every'])
            : 1000;

        $rowsWritten = 0;
        $bytesWritten = 0;

        if ($writeHeaders) {
            $bytesWritten += self::writeCsvLine(
                $handle,
                self::headersToCsv(
                    $columns,
                    $protectCsvInjection
                ),
                $delimiter,
                $enclosure,
                $escape
            );
        }

        foreach (self::normalizeRows($rows) as $rowIndex => $row) {
            if ($rowsWritten >= $maxRows) {
                throw new RuntimeException(
                    'A exportação excedeu o número máximo de linhas permitido.'
                );
            }

            $line = self::rowToCsv(
                $columns,
                $row,
                $rowIndex,
                $protectCsvInjection
            );

            $bytesWritten += self::writeCsvLine(
                $handle,
                $line,
                $delimiter,
                $enclosure,
                $escape
            );

            $rowsWritten++;

            if (is_callable($onRow)) {
                call_user_func(
                    $onRow,
                    $row,
                    $rowIndex,
                    $rowsWritten
                );
            }

            if (
                is_callable($onProgress) &&
                $rowsWritten % $progressEvery === 0
            ) {
                call_user_func(
                    $onProgress,
                    $rowsWritten
                );
            }
        }

        if (is_callable($onProgress)) {
            call_user_func(
                $onProgress,
                $rowsWritten
            );
        }

        return array(
            'rows_written' => $rowsWritten,
            'bytes_written' => $bytesWritten,
            'delimiter' => $delimiter,
            'enclosure' => $enclosure,
            'escape' => $escape
        );
    }

    private static function fileData($filePath, $baseDirectory = null)
    {
        if (!JFile::exists($filePath)) {
            throw new RuntimeException(
                'Arquivo de exportação não encontrado.'
            );
        }

        $size = filesize($filePath);

        return array(
            'path' => $filePath,
            'relative_path' => self::relativePath(
                $filePath,
                $baseDirectory
            ),
            'site_relative_path' => self::siteRelativePath(
                $filePath
            ),
            'url' => self::buildUrl($filePath),
            'base_directory' => self::resolveBaseDirectory(
                $baseDirectory
            ),
            'file_name' => basename($filePath),
            'extension' => strtolower(
                JFile::getExt(basename($filePath))
            ),
            'size' => $size !== false ? (int) $size : 0,
            'size_formatted' => self::formatBytes(
                $size !== false ? $size : 0
            ),
            'created_at' => date(
                'Y-m-d H:i:s',
                filemtime($filePath)
            )
        );
    }

    private static function clearOutputBuffers()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    public static function setBaseDirectory($directory)
    {
        self::$defaultBaseDirectory = self::resolveBaseDirectory(
            $directory
        );

        return self::$defaultBaseDirectory;
    }

    public static function getBaseDirectory()
    {
        return self::resolveBaseDirectory(
            self::$defaultBaseDirectory
        );
    }

    public static function setLogCategory($category)
    {
        $category = trim((string) $category);

        if ($category === '') {
            throw new InvalidArgumentException(
                'Categoria de log inválida.'
            );
        }

        self::$logCategory = preg_replace(
            '/[^a-zA-Z0-9_.-]/',
            '_',
            $category
        );
    }

    public static function setLogDirectory($directory)
    {
        self::$logDirectory = self::resolveLogDirectory(
            $directory
        );

        return self::$logDirectory;
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

    public static function setFilePermission($permission)
    {
        $permission = (int) $permission;

        if ($permission <= 0) {
            throw new InvalidArgumentException(
                'Permissão de arquivo inválida.'
            );
        }

        self::$filePermission = $permission;
    }

    public static function setMaxRows($maxRows)
    {
        $maxRows = (int) $maxRows;

        if ($maxRows <= 0) {
            throw new InvalidArgumentException(
                'Quantidade máxima de linhas inválida.'
            );
        }

        self::$maxRows = $maxRows;
    }

    public static function formatBytes($bytes, $precision = 2)
    {
        $bytes = max(0, (int) $bytes);

        $units = array(
            'B',
            'KB',
            'MB',
            'GB',
            'TB'
        );

        $power = $bytes > 0
            ? floor(log($bytes, 1024))
            : 0;

        $power = min(
            $power,
            count($units) - 1
        );

        return round(
            $bytes / pow(1024, $power),
            (int) $precision
        ) .
            ' ' .
            $units[$power];
    }

    public static function csv($filePath, $rows, array $columns, $baseDirectory = null, array $options = array())
    {
        try {
            $filePath = self::resolveFilePath(
                $filePath,
                $baseDirectory
            );

            $columns = self::normalizeColumns($columns);

            $overwrite = isset($options['overwrite'])
                ? (bool) $options['overwrite']
                : true;

            if (
                JFile::exists($filePath) &&
                !$overwrite
            ) {
                throw new RuntimeException(
                    'Já existe um arquivo de exportação com este nome.'
                );
            }

            $handle = fopen($filePath, 'wb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Não foi possível criar o arquivo CSV.'
                );
            }

            if (!empty($options['utf8_bom'])) {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $export = self::writeRows(
                $handle,
                $rows,
                $columns,
                $options
            );

            fclose($handle);

            @chmod(
                $filePath,
                self::$filePermission
            );

            $file = self::fileData(
                $filePath,
                $baseDirectory
            );

            self::log(
                'info',
                'Arquivo CSV exportado com sucesso.',
                array(
                    'arquivo' => $file['relative_path'],
                    'linhas' => $export['rows_written'],
                    'tamanho' => $file['size']
                )
            );

            return self::result(
                true,
                'Arquivo CSV exportado com sucesso.',
                array(
                    'file' => $file,
                    'export' => $export
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao exportar arquivo CSV.',
                array(
                    'erro' => $error->getMessage(),
                    'arquivo' => $filePath
                )
            );

            return self::result(
                false,
                'Não foi possível exportar o arquivo CSV.'
            );
        }
    }

    public static function download($fileName, $rows, array $columns, array $options = array())
    {
        try {
            $fileName = self::normalizeFileName($fileName);
            $columns = self::normalizeColumns($columns);

            $delimiter = isset($options['delimiter'])
                ? self::normalizeDelimiter($options['delimiter'])
                : self::$defaultDelimiter;

            $enclosure = isset($options['enclosure'])
                ? self::normalizeEnclosure($options['enclosure'])
                : self::$defaultEnclosure;

            $escape = isset($options['escape'])
                ? self::normalizeEscape($options['escape'])
                : self::$defaultEscape;

            self::clearOutputBuffers();

            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Transfer-Encoding: binary');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header(
                'Content-Disposition: attachment; filename="' .
                    addslashes($fileName) .
                    '"; filename*=UTF-8\'\'' .
                    rawurlencode($fileName)
            );

            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Não foi possível abrir a saída para download.'
                );
            }

            if (!empty($options['utf8_bom'])) {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $options['delimiter'] = $delimiter;
            $options['enclosure'] = $enclosure;
            $options['escape'] = $escape;

            $export = self::writeRows(
                $handle,
                $rows,
                $columns,
                $options
            );

            fclose($handle);

            self::log(
                'info',
                'Download CSV gerado com sucesso.',
                array(
                    'arquivo' => $fileName,
                    'linhas' => $export['rows_written']
                )
            );

            return true;
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao gerar download CSV.',
                array(
                    'erro' => $error->getMessage(),
                    'arquivo' => $fileName
                )
            );

            return false;
        }
    }

    public static function fromQuery($filePath, $query, array $columns, $baseDirectory = null, array $options = array())
    {
        try {
            $db = JFactory::getDbo();

            if ($query instanceof JDatabaseQuery) {
                $db->setQuery($query);
            } elseif (is_string($query) && trim($query) !== '') {
                $db->setQuery($query);
            } else {
                throw new InvalidArgumentException(
                    'Query de exportação inválida.'
                );
            }

            $rows = $db->loadAssocList();

            if (!is_array($rows)) {
                $rows = array();
            }

            return self::csv(
                $filePath,
                $rows,
                $columns,
                $baseDirectory,
                $options
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao exportar query para CSV.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível exportar os dados da consulta.'
            );
        }
    }

    public static function downloadFromQuery($fileName, $query, array $columns, array $options = array())
    {
        try {
            $db = JFactory::getDbo();

            if ($query instanceof JDatabaseQuery) {
                $db->setQuery($query);
            } elseif (is_string($query) && trim($query) !== '') {
                $db->setQuery($query);
            } else {
                throw new InvalidArgumentException(
                    'Query de exportação inválida.'
                );
            }

            $rows = $db->loadAssocList();

            if (!is_array($rows)) {
                $rows = array();
            }

            return self::download(
                $fileName,
                $rows,
                $columns,
                $options
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao gerar download da query CSV.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return false;
        }
    }

    public static function generatorFromQuery($query, $batchSize = 1000)
    {
        $db = JFactory::getDbo();

        if ($query instanceof JDatabaseQuery) {
            $query = (string) $query;
        }

        if (!is_string($query) || trim($query) === '') {
            throw new InvalidArgumentException(
                'Query de exportação inválida.'
            );
        }

        $batchSize = max(1, min(10000, (int) $batchSize));
        $offset = 0;

        while (true) {
            $db->setQuery(
                $query,
                $offset,
                $batchSize
            );

            $rows = $db->loadAssocList();

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                yield $row;
            }

            $count = count($rows);

            if ($count < $batchSize) {
                break;
            }

            $offset += $batchSize;
        }
    }

    public static function temporaryFileName($prefix = 'exportacao')
    {
        $prefix = preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '',
            (string) $prefix
        );

        if ($prefix === '') {
            $prefix = 'exportacao';
        }

        if (function_exists('random_bytes')) {
            $random = bin2hex(random_bytes(16));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(16);

            $random = $bytes !== false
                ? bin2hex($bytes)
                : sha1(uniqid('', true) . mt_rand());
        } else {
            $random = sha1(
                uniqid('', true) .
                    mt_rand()
            );
        }

        return $prefix .
            '_' .
            date('YmdHis') .
            '_' .
            $random .
            '.csv';
    }
}
