<?php

defined('_JEXEC') or die;

jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');

class CsvHelper
{
    private static $defaultBaseDirectory = null;

    private static $logCategory = 'csv_helper';

    private static $logDirectory = null;

    private static $maxFileSize = 104857600;

    private static $defaultChunkSize = 500;

    private static $maxChunkSize = 5000;

    private static $defaultDelimiter = null;

    private static $defaultEnclosure = '"';

    private static $defaultEscape = '';

    private static $allowedExtensions = array(
        'csv',
        'txt'
    );

    private static $allowedMimes = array(
        'text/plain',
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
        'application/octet-stream'
    );

    private static $encodings = array(
        'UTF-8',
        'UTF-8-SIG',
        'Windows-1252',
        'ISO-8859-1',
        'ISO-8859-15'
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

    private static function resolveLogDirectory($directory)
    {
        $directory = trim((string) $directory);

        if ($directory === '') {
            throw new InvalidArgumentException(
                'Diretório de logs inválido.'
            );
        }

        $siteRoot = self::siteRoot();

        $directory = str_replace('\\', '/', $directory);

        if (
            strpos($directory, "\0") !== false ||
            strpos($directory, '../') !== false ||
            strpos($directory, '..\\') !== false
        ) {
            throw new RuntimeException(
                'Diretório de logs inválido.'
            );
        }

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
            if (!JFolder::create($directory, 0755)) {
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
                'Caminho de arquivo inválido.'
            );
        }

        if (
            strpos($path, "\0") !== false ||
            strpos($path, '../') !== false ||
            strpos($path, '..\\') !== false
        ) {
            throw new RuntimeException(
                'Caminho de arquivo inválido.'
            );
        }

        return str_replace('\\', '/', $path);
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
                'O diretório base precisa estar dentro de JPATH_SITE.'
            );
        }

        if (!JFolder::exists($baseDirectory)) {
            if (!JFolder::create($baseDirectory, 0755)) {
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

    private static function resolveFilePath($path, $baseDirectory = null, $mustExist = true)
    {
        $baseDirectory = self::resolveBaseDirectory($baseDirectory);
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

        if (!$mustExist) {
            $directory = dirname($filePath);

            if (!JFolder::exists($directory)) {
                if (!JFolder::create($directory, 0755)) {
                    throw new RuntimeException(
                        'Não foi possível criar o diretório de destino.'
                    );
                }
            }

            return $filePath;
        }

        if (!JFile::exists($filePath)) {
            throw new RuntimeException(
                'Arquivo CSV não encontrado.'
            );
        }

        $realPath = realpath($filePath);

        if ($realPath === false) {
            throw new RuntimeException(
                'Não foi possível validar o arquivo CSV.'
            );
        }

        $realPath = self::normalizePath($realPath);

        if (!self::pathInsideBase($realPath, $baseDirectory)) {
            throw new RuntimeException(
                'Arquivo CSV validado fora da base autorizada.'
            );
        }

        return $realPath;
    }

    private static function relativePath($filePath, $baseDirectory = null)
    {
        $baseDirectory = self::resolveBaseDirectory($baseDirectory);
        $filePath = self::normalizePath($filePath);

        if (!self::pathInsideBase($filePath, $baseDirectory)) {
            throw new RuntimeException(
                'Não foi possível gerar caminho relativo.'
            );
        }

        return ltrim(
            substr($filePath, strlen($baseDirectory)),
            '/'
        );
    }

    private static function getMimeType($filePath)
    {
        if (!function_exists('finfo_open')) {
            return 'application/octet-stream';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($finfo, $filePath);

        finfo_close($finfo);

        if ($mime === false || trim((string) $mime) === '') {
            return 'application/octet-stream';
        }

        return strtolower(trim((string) $mime));
    }

    private static function removeUtf8Bom($value)
    {
        if (substr($value, 0, 3) === "\xEF\xBB\xBF") {
            return substr($value, 3);
        }

        return $value;
    }

    private static function convertToUtf8($value, $sourceEncoding)
    {
        $value = (string) $value;
        $sourceEncoding = trim((string) $sourceEncoding);

        if (
            $value === '' ||
            $sourceEncoding === '' ||
            strtoupper($sourceEncoding) === 'UTF-8' ||
            strtoupper($sourceEncoding) === 'UTF-8-SIG'
        ) {
            return self::removeUtf8Bom($value);
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding(
                $value,
                'UTF-8',
                $sourceEncoding
            );

            if ($converted !== false) {
                return self::removeUtf8Bom($converted);
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv(
                $sourceEncoding,
                'UTF-8//IGNORE',
                $value
            );

            if ($converted !== false) {
                return self::removeUtf8Bom($converted);
            }
        }

        return self::removeUtf8Bom($value);
    }

    private static function removeAccents($value)
    {
        $value = (string) $value;

        if (function_exists('iconv')) {
            $converted = @iconv(
                'UTF-8',
                'ASCII//TRANSLIT//IGNORE',
                $value
            );

            if ($converted !== false) {
                return $converted;
            }
        }

        $map = array(
            'á' => 'a',
            'à' => 'a',
            'ã' => 'a',
            'â' => 'a',
            'ä' => 'a',
            'é' => 'e',
            'è' => 'e',
            'ê' => 'e',
            'ë' => 'e',
            'í' => 'i',
            'ì' => 'i',
            'î' => 'i',
            'ï' => 'i',
            'ó' => 'o',
            'ò' => 'o',
            'õ' => 'o',
            'ô' => 'o',
            'ö' => 'o',
            'ú' => 'u',
            'ù' => 'u',
            'û' => 'u',
            'ü' => 'u',
            'ç' => 'c',
            'ñ' => 'n',
            'Á' => 'A',
            'À' => 'A',
            'Ã' => 'A',
            'Â' => 'A',
            'Ä' => 'A',
            'É' => 'E',
            'È' => 'E',
            'Ê' => 'E',
            'Ë' => 'E',
            'Í' => 'I',
            'Ì' => 'I',
            'Î' => 'I',
            'Ï' => 'I',
            'Ó' => 'O',
            'Ò' => 'O',
            'Õ' => 'O',
            'Ô' => 'O',
            'Ö' => 'O',
            'Ú' => 'U',
            'Ù' => 'U',
            'Û' => 'U',
            'Ü' => 'U',
            'Ç' => 'C',
            'Ñ' => 'N'
        );

        return strtr($value, $map);
    }

    private static function normalizeHeader($header)
    {
        $header = self::removeUtf8Bom(
            trim((string) $header)
        );

        if (function_exists('mb_strtolower')) {
            $header = mb_strtolower($header, 'UTF-8');
        } else {
            $header = strtolower($header);
        }

        $header = self::removeAccents($header);
        $header = preg_replace('/[^a-z0-9]+/i', '_', $header);

        return trim($header, '_');
    }

    private static function normalizeCell($value)
    {
        if ($value === null) {
            return '';
        }

        if (is_array($value) || is_object($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private static function normalizeRowEncoding(array $row, $encoding)
    {
        $result = array();

        foreach ($row as $key => $value) {
            $result[$key] = self::convertToUtf8(
                self::normalizeCell($value),
                $encoding
            );
        }

        return $result;
    }

    private static function isEmptyRow(array $row)
    {
        foreach ($row as $value) {
            if (self::normalizeCell($value) !== '') {
                return false;
            }
        }

        return true;
    }

    private static function validateDelimiter($delimiter)
    {
        $delimiter = (string) $delimiter;

        if (strlen($delimiter) !== 1) {
            throw new InvalidArgumentException(
                'O delimitador deve possuir exatamente um caractere.'
            );
        }

        return $delimiter;
    }

    private static function validateEnclosure($enclosure)
    {
        $enclosure = (string) $enclosure;

        if (strlen($enclosure) !== 1) {
            throw new InvalidArgumentException(
                'O encapsulador deve possuir exatamente um caractere.'
            );
        }

        return $enclosure;
    }

    private static function validateEscape($escape)
    {
        $escape = (string) $escape;

        if ($escape !== '' && strlen($escape) !== 1) {
            throw new InvalidArgumentException(
                'O caractere de escape deve possuir um caractere ou ser vazio.'
            );
        }

        return $escape;
    }

    private static function delimiterOccurrences($line, $delimiter)
    {
        $count = 0;
        $insideQuotes = false;
        $length = strlen($line);

        for ($index = 0; $index < $length; $index++) {
            $character = $line[$index];

            if ($character === '"') {
                if (
                    $insideQuotes &&
                    isset($line[$index + 1]) &&
                    $line[$index + 1] === '"'
                ) {
                    $index++;

                    continue;
                }

                $insideQuotes = !$insideQuotes;

                continue;
            }

            if (!$insideQuotes && $character === $delimiter) {
                $count++;
            }
        }

        return $count;
    }

    private static function csvReader($filePath, $delimiter, $enclosure, $escape)
    {
        $csv = new SplFileObject($filePath, 'r');

        $csv->setFlags(
            SplFileObject::READ_CSV |
                SplFileObject::SKIP_EMPTY |
                SplFileObject::DROP_NEW_LINE
        );

        $csv->setCsvControl(
            $delimiter,
            $enclosure,
            $escape
        );

        return $csv;
    }

    private static function readHeaders($filePath, $delimiter, $enclosure, $escape, $encoding)
    {
        $csv = self::csvReader(
            $filePath,
            $delimiter,
            $enclosure,
            $escape
        );

        $headersOriginal = $csv->fgetcsv();

        if (
            $headersOriginal === false ||
            !is_array($headersOriginal) ||
            self::isEmptyRow($headersOriginal)
        ) {
            throw new RuntimeException(
                'O CSV não possui um cabeçalho válido.'
            );
        }

        $headersOriginal = self::normalizeRowEncoding(
            $headersOriginal,
            $encoding
        );

        $indexes = array();
        $headersNormalized = array();
        $duplicates = array();

        foreach ($headersOriginal as $index => $header) {
            $normalized = self::normalizeHeader($header);

            if ($normalized === '') {
                throw new RuntimeException(
                    'O CSV possui coluna sem nome no cabeçalho.'
                );
            }

            if (isset($indexes[$normalized])) {
                $duplicates[] = $normalized;
            }

            $indexes[$normalized] = $index;
            $headersNormalized[] = $normalized;
        }

        if (!empty($duplicates)) {
            throw new RuntimeException(
                'O CSV possui cabeçalhos duplicados: ' .
                    implode(', ', array_unique($duplicates))
            );
        }

        return array(
            'headers_original' => $headersOriginal,
            'headers_normalized' => $headersNormalized,
            'header_indexes' => $indexes
        );
    }

    private static function validateCsvFile($filePath, array $options = array())
    {
        if (!JFile::exists($filePath)) {
            throw new RuntimeException(
                'Arquivo CSV não encontrado.'
            );
        }

        $size = filesize($filePath);

        if ($size === false || $size <= 0) {
            throw new RuntimeException(
                'O arquivo CSV está vazio.'
            );
        }

        $maxFileSize = isset($options['max_file_size'])
            ? (int) $options['max_file_size']
            : self::$maxFileSize;

        if ($maxFileSize <= 0) {
            throw new InvalidArgumentException(
                'Tamanho máximo de arquivo inválido.'
            );
        }

        if ($size > $maxFileSize) {
            throw new RuntimeException(
                'O arquivo CSV excede o tamanho máximo permitido.'
            );
        }

        $extension = strtolower(
            JFile::getExt(basename($filePath))
        );

        $allowedExtensions = isset($options['allowed_extensions'])
            ? (array) $options['allowed_extensions']
            : self::$allowedExtensions;

        $allowedExtensions = array_map(
            function ($value) {
                return strtolower(trim((string) $value));
            },
            $allowedExtensions
        );

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException(
                'A extensão do arquivo não é permitida.'
            );
        }

        $mime = self::getMimeType($filePath);

        $allowedMimes = isset($options['allowed_mimes'])
            ? (array) $options['allowed_mimes']
            : self::$allowedMimes;

        $allowedMimes = array_map(
            function ($value) {
                return strtolower(trim((string) $value));
            },
            $allowedMimes
        );

        if (!in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException(
                'O tipo do arquivo não é permitido.'
            );
        }

        return array(
            'path' => $filePath,
            'file_name' => basename($filePath),
            'extension' => $extension,
            'mime' => $mime,
            'size' => (int) $size
        );
    }

    private static function normalizeMapping(array $mapping)
    {
        $result = array();

        foreach ($mapping as $source => $destination) {
            $source = self::normalizeHeader($source);
            $destination = trim((string) $destination);

            if ($source === '' || $destination === '') {
                continue;
            }

            $result[$source] = $destination;
        }

        return $result;
    }

    private static function mapRow(array $row, array $headerIndexes, array $mapping)
    {
        $mapped = array();

        foreach ($headerIndexes as $header => $index) {
            $destination = isset($mapping[$header])
                ? $mapping[$header]
                : $header;

            $mapped[$destination] = isset($row[$index])
                ? self::normalizeCell($row[$index])
                : '';
        }

        return $mapped;
    }

    private static function validateRequiredHeaders(array $headers, array $requiredHeaders)
    {
        $missing = array();

        foreach ($requiredHeaders as $header) {
            $header = self::normalizeHeader($header);

            if (!in_array($header, $headers, true)) {
                $missing[] = $header;
            }
        }

        if (!empty($missing)) {
            throw new RuntimeException(
                'Cabeçalhos obrigatórios não encontrados: ' .
                    implode(', ', $missing)
            );
        }

        return true;
    }

    private static function normalizeChunkSize($chunkSize)
    {
        $chunkSize = (int) $chunkSize;

        if ($chunkSize <= 0) {
            $chunkSize = self::$defaultChunkSize;
        }

        return min(
            $chunkSize,
            self::$maxChunkSize
        );
    }

    private static function reportError(array &$report, $line, $message, array $data = array())
    {
        $report['errors_count']++;

        if (
            count($report['errors']) >=
            $report['max_errors']
        ) {
            return;
        }

        $report['errors'][] = array(
            'line' => (int) $line,
            'message' => (string) $message,
            'data' => $data
        );
    }

    private static function applyChunkResult(array &$report, array $chunk, $chunkResult)
    {
        $totalChunk = count($chunk);

        if ($chunkResult === false) {
            $report['skipped_rows'] += $totalChunk;
            $report['processed_rows'] += $totalChunk;

            foreach ($chunk as $item) {
                self::reportError(
                    $report,
                    $item['line'],
                    'O lote não pôde ser processado.',
                    array(
                        'row' => $item['data']
                    )
                );
            }

            return;
        }

        if (is_array($chunkResult)) {
            $successRows = isset($chunkResult['success_rows'])
                ? max(0, (int) $chunkResult['success_rows'])
                : $totalChunk;

            $skippedRows = isset($chunkResult['skipped_rows'])
                ? max(0, (int) $chunkResult['skipped_rows'])
                : 0;

            $report['success_rows'] += $successRows;
            $report['skipped_rows'] += $skippedRows;
            $report['processed_rows'] += $successRows + $skippedRows;

            if (
                isset($chunkResult['errors']) &&
                is_array($chunkResult['errors'])
            ) {
                foreach ($chunkResult['errors'] as $error) {
                    self::reportError(
                        $report,
                        isset($error['line'])
                            ? $error['line']
                            : 0,
                        isset($error['message'])
                            ? $error['message']
                            : 'Erro ao processar linha CSV.',
                        isset($error['data'])
                            ? (array) $error['data']
                            : array()
                    );
                }
            }

            return;
        }

        $report['success_rows'] += $totalChunk;
        $report['processed_rows'] += $totalChunk;
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

    public static function setMaxFileSize($bytes)
    {
        $bytes = (int) $bytes;

        if ($bytes <= 0) {
            throw new InvalidArgumentException(
                'Tamanho máximo de CSV inválido.'
            );
        }

        self::$maxFileSize = $bytes;
    }

    public static function setDefaultChunkSize($chunkSize)
    {
        $chunkSize = (int) $chunkSize;

        if ($chunkSize <= 0) {
            throw new InvalidArgumentException(
                'Tamanho de lote inválido.'
            );
        }

        self::$defaultChunkSize = $chunkSize;
    }

    public static function setMaxChunkSize($chunkSize)
    {
        $chunkSize = (int) $chunkSize;

        if ($chunkSize <= 0) {
            throw new InvalidArgumentException(
                'Tamanho máximo de lote inválido.'
            );
        }

        self::$maxChunkSize = $chunkSize;
    }

    public static function detectEncoding($path, $baseDirectory = null)
    {
        try {
            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $handle = fopen($filePath, 'rb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Não foi possível abrir o CSV.'
                );
            }

            $sample = fread($handle, 65536);

            fclose($handle);

            if ($sample === false || $sample === '') {
                return 'UTF-8';
            }

            if (substr($sample, 0, 3) === "\xEF\xBB\xBF") {
                return 'UTF-8';
            }

            if (!function_exists('mb_detect_encoding')) {
                return 'UTF-8';
            }

            $encoding = mb_detect_encoding(
                $sample,
                self::$encodings,
                true
            );

            return $encoding !== false
                ? $encoding
                : 'UTF-8';
        } catch (Throwable $error) {
            self::log(
                'warning',
                'Falha ao detectar encoding do CSV.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return 'UTF-8';
        }
    }

    public static function detectDelimiter($path, $baseDirectory = null, array $options = array())
    {
        try {
            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $candidates = isset($options['candidates'])
                ? (array) $options['candidates']
                : array(
                    ';',
                    ',',
                    "\t",
                    '|'
                );

            $handle = fopen($filePath, 'rb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Não foi possível abrir o CSV.'
                );
            }

            $line = '';

            while (!feof($handle)) {
                $candidate = fgets($handle);

                if (
                    $candidate !== false &&
                    trim($candidate) !== ''
                ) {
                    $line = $candidate;

                    break;
                }
            }

            fclose($handle);

            if ($line === '') {
                throw new RuntimeException(
                    'Não foi possível identificar uma linha válida no CSV.'
                );
            }

            $bestDelimiter = ';';
            $bestCount = -1;

            foreach ($candidates as $delimiter) {
                $delimiter = self::validateDelimiter($delimiter);

                $count = self::delimiterOccurrences(
                    $line,
                    $delimiter
                );

                if ($count > $bestCount) {
                    $bestCount = $count;
                    $bestDelimiter = $delimiter;
                }
            }

            return $bestDelimiter;
        } catch (Throwable $error) {
            self::log(
                'warning',
                'Falha ao detectar delimitador CSV.',
                array(
                    'erro' => $error->getMessage()
                )
            );

            return ';';
        }
    }

    public static function headers($path, $baseDirectory = null, array $options = array())
    {
        try {
            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $fileData = self::validateCsvFile(
                $filePath,
                $options
            );

            $delimiter = isset($options['delimiter'])
                ? self::validateDelimiter(
                    $options['delimiter']
                )
                : (
                    self::$defaultDelimiter !== null
                    ? self::validateDelimiter(
                        self::$defaultDelimiter
                    )
                    : self::detectDelimiter(
                        $filePath,
                        $baseDirectory
                    )
                );

            $enclosure = isset($options['enclosure'])
                ? self::validateEnclosure(
                    $options['enclosure']
                )
                : self::$defaultEnclosure;

            $escape = isset($options['escape'])
                ? self::validateEscape(
                    $options['escape']
                )
                : self::$defaultEscape;

            $encoding = isset($options['encoding'])
                ? trim((string) $options['encoding'])
                : self::detectEncoding(
                    $filePath,
                    $baseDirectory
                );

            $headers = self::readHeaders(
                $filePath,
                $delimiter,
                $enclosure,
                $escape,
                $encoding
            );

            return self::result(
                true,
                'Cabeçalhos CSV carregados com sucesso.',
                array_merge(
                    $fileData,
                    $headers,
                    array(
                        'delimiter' => $delimiter,
                        'enclosure' => $enclosure,
                        'escape' => $escape,
                        'encoding' => $encoding,
                        'relative_path' => self::relativePath(
                            $filePath,
                            $baseDirectory
                        )
                    )
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao ler cabeçalhos CSV.',
                array(
                    'arquivo' => $path,
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível carregar os cabeçalhos do CSV.'
            );
        }
    }

    public static function preview($path, $baseDirectory = null, $limit = 10, array $options = array())
    {
        try {
            $limit = max(1, min(100, (int) $limit));

            $headersResult = self::headers(
                $path,
                $baseDirectory,
                $options
            );

            if (!$headersResult['success']) {
                return $headersResult;
            }

            $filePath = $headersResult['data']['path'];
            $delimiter = $headersResult['data']['delimiter'];
            $enclosure = $headersResult['data']['enclosure'];
            $escape = $headersResult['data']['escape'];
            $encoding = $headersResult['data']['encoding'];
            $headerIndexes = $headersResult['data']['header_indexes'];

            $mapping = isset($options['mapping'])
                ? self::normalizeMapping(
                    (array) $options['mapping']
                )
                : array();

            $csv = self::csvReader(
                $filePath,
                $delimiter,
                $enclosure,
                $escape
            );

            $csv->fgetcsv();

            $rows = array();
            $line = 1;

            while (
                !$csv->eof() &&
                count($rows) < $limit
            ) {
                $line++;

                $row = $csv->fgetcsv();

                if ($row === false || self::isEmptyRow($row)) {
                    continue;
                }

                $row = self::normalizeRowEncoding(
                    $row,
                    $encoding
                );

                $rows[] = array(
                    'line' => $line,
                    'raw' => $row,
                    'mapped' => self::mapRow(
                        $row,
                        $headerIndexes,
                        $mapping
                    )
                );
            }

            return self::result(
                true,
                'Prévia CSV carregada com sucesso.',
                array(
                    'headers' => $headersResult['data']['headers_normalized'],
                    'headers_original' => $headersResult['data']['headers_original'],
                    'delimiter' => $delimiter,
                    'encoding' => $encoding,
                    'rows' => $rows
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao gerar prévia CSV.',
                array(
                    'arquivo' => $path,
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível gerar a prévia do CSV.'
            );
        }
    }

    public static function process($path, $callback, $baseDirectory = null, array $options = array())
    {
        $report = array(
            'file' => null,
            'delimiter' => null,
            'encoding' => null,
            'headers' => array(),
            'total_rows' => 0,
            'processed_rows' => 0,
            'success_rows' => 0,
            'skipped_rows' => 0,
            'errors_count' => 0,
            'errors' => array(),
            'max_errors' => isset($options['max_errors'])
                ? max(1, (int) $options['max_errors'])
                : 1000,
            'started_at' => JFactory::getDate()->toSql(),
            'finished_at' => null
        );

        try {
            if (!is_callable($callback)) {
                throw new InvalidArgumentException(
                    'O callback de processamento CSV é obrigatório.'
                );
            }

            $headersResult = self::headers(
                $path,
                $baseDirectory,
                $options
            );

            if (!$headersResult['success']) {
                throw new RuntimeException(
                    $headersResult['mensagem']
                );
            }

            $filePath = $headersResult['data']['path'];
            $delimiter = $headersResult['data']['delimiter'];
            $enclosure = $headersResult['data']['enclosure'];
            $escape = $headersResult['data']['escape'];
            $encoding = $headersResult['data']['encoding'];
            $headers = $headersResult['data']['headers_normalized'];
            $headerIndexes = $headersResult['data']['header_indexes'];

            $requiredHeaders = isset($options['required_headers'])
                ? (array) $options['required_headers']
                : array();

            self::validateRequiredHeaders(
                $headers,
                $requiredHeaders
            );

            $mapping = isset($options['mapping'])
                ? self::normalizeMapping(
                    (array) $options['mapping']
                )
                : array();

            $chunkSize = self::normalizeChunkSize(
                isset($options['chunk_size'])
                    ? $options['chunk_size']
                    : self::$defaultChunkSize
            );

            $validateRow = isset($options['validate_row'])
                ? $options['validate_row']
                : null;

            $onChunk = isset($options['on_chunk'])
                ? $options['on_chunk']
                : null;

            $onProgress = isset($options['on_progress'])
                ? $options['on_progress']
                : null;

            $stopOnError = !empty($options['stop_on_error']);

            $enforceColumnCount = !empty($options['enforce_column_count']);

            $report['file'] = $headersResult['data'];
            $report['delimiter'] = $delimiter;
            $report['encoding'] = $encoding;
            $report['headers'] = $headers;

            $csv = self::csvReader(
                $filePath,
                $delimiter,
                $enclosure,
                $escape
            );

            $csv->fgetcsv();

            $chunk = array();
            $line = 1;

            while (!$csv->eof()) {
                $line++;

                $row = $csv->fgetcsv();

                if ($row === false || self::isEmptyRow($row)) {
                    continue;
                }

                $row = self::normalizeRowEncoding(
                    $row,
                    $encoding
                );

                $report['total_rows']++;

                if (
                    $enforceColumnCount &&
                    count($row) !== count($headers)
                ) {
                    self::reportError(
                        $report,
                        $line,
                        'Quantidade de colunas diferente do cabeçalho.',
                        array(
                            'expected_columns' => count($headers),
                            'received_columns' => count($row),
                            'row' => $row
                        )
                    );

                    $report['skipped_rows']++;
                    $report['processed_rows']++;

                    if ($stopOnError) {
                        throw new RuntimeException(
                            'Quantidade de colunas inválida na linha ' .
                                $line .
                                '.'
                        );
                    }

                    continue;
                }

                $mappedRow = self::mapRow(
                    $row,
                    $headerIndexes,
                    $mapping
                );

                if (is_callable($validateRow)) {
                    $validation = call_user_func(
                        $validateRow,
                        $mappedRow,
                        $line,
                        $row
                    );

                    if (is_array($validation)) {
                        $valid = isset($validation['valid'])
                            ? (bool) $validation['valid']
                            : false;

                        if (!$valid) {
                            self::reportError(
                                $report,
                                $line,
                                isset($validation['first_error'])
                                    ? $validation['first_error']
                                    : 'Dados inválidos na linha CSV.',
                                array(
                                    'errors' => isset($validation['errors'])
                                        ? $validation['errors']
                                        : array(),
                                    'row' => $mappedRow
                                )
                            );

                            $report['skipped_rows']++;
                            $report['processed_rows']++;

                            if ($stopOnError) {
                                throw new RuntimeException(
                                    'Falha de validação na linha ' .
                                        $line .
                                        '.'
                                );
                            }

                            continue;
                        }
                    } elseif ($validation !== true) {
                        self::reportError(
                            $report,
                            $line,
                            'Dados inválidos na linha CSV.',
                            array(
                                'row' => $mappedRow
                            )
                        );

                        $report['skipped_rows']++;
                        $report['processed_rows']++;

                        if ($stopOnError) {
                            throw new RuntimeException(
                                'Falha de validação na linha ' .
                                    $line .
                                    '.'
                            );
                        }

                        continue;
                    }
                }

                $chunk[] = array(
                    'line' => $line,
                    'data' => $mappedRow,
                    'raw' => $row
                );

                if (count($chunk) < $chunkSize) {
                    continue;
                }

                $chunkResult = call_user_func(
                    $callback,
                    $chunk,
                    $report
                );

                self::applyChunkResult(
                    $report,
                    $chunk,
                    $chunkResult
                );

                if (is_callable($onChunk)) {
                    call_user_func(
                        $onChunk,
                        $chunk,
                        $chunkResult,
                        $report
                    );
                }

                if (is_callable($onProgress)) {
                    call_user_func(
                        $onProgress,
                        $report
                    );
                }

                $chunk = array();
            }

            if (!empty($chunk)) {
                $chunkResult = call_user_func(
                    $callback,
                    $chunk,
                    $report
                );

                self::applyChunkResult(
                    $report,
                    $chunk,
                    $chunkResult
                );

                if (is_callable($onChunk)) {
                    call_user_func(
                        $onChunk,
                        $chunk,
                        $chunkResult,
                        $report
                    );
                }

                if (is_callable($onProgress)) {
                    call_user_func(
                        $onProgress,
                        $report
                    );
                }
            }

            $report['finished_at'] = JFactory::getDate()->toSql();

            self::log(
                'info',
                'Processamento CSV concluído.',
                array(
                    'arquivo' => $report['file']['relative_path'],
                    'total_rows' => $report['total_rows'],
                    'success_rows' => $report['success_rows'],
                    'skipped_rows' => $report['skipped_rows'],
                    'errors_count' => $report['errors_count']
                )
            );

            return self::result(
                $report['errors_count'] === 0,
                $report['errors_count'] === 0
                    ? 'CSV processado com sucesso.'
                    : 'CSV processado com erros em algumas linhas.',
                $report,
                $report['errors']
            );
        } catch (Throwable $error) {
            $report['finished_at'] = JFactory::getDate()->toSql();

            self::log(
                'error',
                'Falha ao processar CSV.',
                array(
                    'arquivo' => $path,
                    'erro' => $error->getMessage(),
                    'total_rows' => $report['total_rows'],
                    'success_rows' => $report['success_rows'],
                    'errors_count' => $report['errors_count']
                )
            );

            return self::result(
                false,
                'Não foi possível processar o arquivo CSV.',
                $report,
                $report['errors']
            );
        }
    }

    public static function writeRejectedCsv($destinationPath, array $errors, $baseDirectory = null, array $options = array())
    {
        try {
            if (empty($errors)) {
                throw new InvalidArgumentException(
                    'Não existem erros para exportar.'
                );
            }

            $filePath = self::resolveFilePath(
                $destinationPath,
                $baseDirectory,
                false
            );

            $delimiter = isset($options['delimiter'])
                ? self::validateDelimiter(
                    $options['delimiter']
                )
                : ';';

            $enclosure = isset($options['enclosure'])
                ? self::validateEnclosure(
                    $options['enclosure']
                )
                : '"';

            $handle = fopen($filePath, 'wb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Não foi possível criar o CSV de rejeitados.'
                );
            }

            if (!empty($options['utf8_bom'])) {
                fwrite($handle, "\xEF\xBB\xBF");
            }

            fputcsv(
                $handle,
                array(
                    'linha',
                    'erro',
                    'dados'
                ),
                $delimiter,
                $enclosure
            );

            foreach ($errors as $error) {
                $line = isset($error['line'])
                    ? (int) $error['line']
                    : 0;

                $message = isset($error['message'])
                    ? (string) $error['message']
                    : 'Erro não identificado.';

                $data = isset($error['data'])
                    ? json_encode(
                        $error['data'],
                        JSON_UNESCAPED_UNICODE |
                            JSON_UNESCAPED_SLASHES
                    )
                    : '';

                fputcsv(
                    $handle,
                    array(
                        $line,
                        $message,
                        $data
                    ),
                    $delimiter,
                    $enclosure
                );
            }

            fclose($handle);

            @chmod($filePath, 0644);

            return self::result(
                true,
                'CSV de rejeitados criado com sucesso.',
                array(
                    'path' => $filePath,
                    'relative_path' => self::relativePath(
                        $filePath,
                        $baseDirectory
                    ),
                    'file_name' => basename($filePath),
                    'total_errors' => count($errors)
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao criar CSV de rejeitados.',
                array(
                    'arquivo' => $destinationPath,
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível criar o CSV de rejeitados.'
            );
        }
    }
}
