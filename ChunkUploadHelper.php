<?php

defined('_JEXEC') or die;

jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');

class ChunkHelper
{
    private static $defaultBaseDirectory = null;

    private static $logCategory = 'chunk_helper';

    private static $logDirectory = null;

    private static $directoryPermission = 0755;

    private static $filePermission = 0644;

    private static $defaultReadSize = 8388608;

    private static $minReadSize = 1024;

    private static $maxReadSize = 67108864;

    private static $defaultRowsPerChunk = 1000;

    private static $maxLineLength = 10485760;

    private static $maxChunks = 10000000;

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

    private static function resolveFilePath($path, $baseDirectory = null, $mustExist = true)
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

        if (!$mustExist) {
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

        if (!JFile::exists($filePath)) {
            throw new RuntimeException(
                'Arquivo não encontrado.'
            );
        }

        $realPath = realpath($filePath);

        if ($realPath === false) {
            throw new RuntimeException(
                'Não foi possível validar o arquivo.'
            );
        }

        $realPath = self::normalizePath($realPath);

        if (!self::pathInsideBase($realPath, $baseDirectory)) {
            throw new RuntimeException(
                'Arquivo validado fora da base autorizada.'
            );
        }

        return $realPath;
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

    private static function normalizeReadSize($readSize)
    {
        $readSize = (int) $readSize;

        if ($readSize <= 0) {
            $readSize = self::$defaultReadSize;
        }

        if ($readSize < self::$minReadSize) {
            $readSize = self::$minReadSize;
        }

        if ($readSize > self::$maxReadSize) {
            $readSize = self::$maxReadSize;
        }

        return $readSize;
    }

    private static function normalizeRowsPerChunk($rowsPerChunk)
    {
        $rowsPerChunk = (int) $rowsPerChunk;

        if ($rowsPerChunk <= 0) {
            $rowsPerChunk = self::$defaultRowsPerChunk;
        }

        return $rowsPerChunk;
    }

    private static function normalizeOffset($offset)
    {
        if ($offset === null || $offset === '') {
            return 0;
        }

        if (!is_numeric($offset)) {
            throw new InvalidArgumentException(
                'Offset inválido.'
            );
        }

        $offset = (float) $offset;

        if ($offset < 0) {
            throw new InvalidArgumentException(
                'Offset inválido.'
            );
        }

        return $offset;
    }

    private static function getFileSize($filePath)
    {
        $size = filesize($filePath);

        if ($size === false || $size < 0) {
            throw new RuntimeException(
                'Não foi possível identificar o tamanho do arquivo.'
            );
        }

        return (float) $size;
    }

    private static function getFileFingerprint($filePath)
    {
        $size = self::getFileSize($filePath);
        $modified = filemtime($filePath);

        return sha1(
            $filePath .
                '|' .
                $size .
                '|' .
                ($modified !== false ? $modified : 0)
        );
    }

    private static function isValidCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException(
                'É obrigatório informar um callback válido para processar chunks.'
            );
        }

        return true;
    }

    private static function buildProgress($filePath, $offset, $chunkIndex, $startedAt, array $extra = array())
    {
        $size = self::getFileSize($filePath);

        $percentual = $size > 0
            ? round(($offset / $size) * 100, 4)
            : 100;

        if ($percentual > 100) {
            $percentual = 100;
        }

        return array_merge(
            array(
                'file_path' => $filePath,
                'file_size' => $size,
                'offset' => $offset,
                'chunk_index' => $chunkIndex,
                'percentual' => $percentual,
                'started_at' => $startedAt,
                'updated_at' => JFactory::getDate()->toSql()
            ),
            $extra
        );
    }

    private static function saveCheckpoint($checkpointPath, array $checkpoint, $baseDirectory = null)
    {
        $checkpointFile = self::resolveFilePath(
            $checkpointPath,
            $baseDirectory,
            false
        );

        $json = json_encode(
            $checkpoint,
            JSON_PRETTY_PRINT |
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
        );

        if ($json === false) {
            throw new RuntimeException(
                'Não foi possível gerar o checkpoint em JSON.'
            );
        }

        $temporaryFile = $checkpointFile .
            '.tmp_' .
            bin2hex(
                function_exists('random_bytes')
                    ? random_bytes(8)
                    : openssl_random_pseudo_bytes(8)
            );

        $bytes = file_put_contents(
            $temporaryFile,
            $json,
            LOCK_EX
        );

        if ($bytes === false) {
            throw new RuntimeException(
                'Não foi possível gravar o checkpoint temporário.'
            );
        }

        @chmod(
            $temporaryFile,
            self::$filePermission
        );

        if (JFile::exists($checkpointFile)) {
            JFile::delete($checkpointFile);
        }

        if (!JFile::move($temporaryFile, $checkpointFile)) {
            if (JFile::exists($temporaryFile)) {
                JFile::delete($temporaryFile);
            }

            throw new RuntimeException(
                'Não foi possível publicar o arquivo de checkpoint.'
            );
        }

        @chmod(
            $checkpointFile,
            self::$filePermission
        );

        return $checkpointFile;
    }

    private static function loadCheckpoint($checkpointPath, $baseDirectory = null)
    {
        $checkpointFile = self::resolveFilePath(
            $checkpointPath,
            $baseDirectory,
            true
        );

        $content = file_get_contents($checkpointFile);

        if ($content === false || trim($content) === '') {
            throw new RuntimeException(
                'Não foi possível ler o checkpoint.'
            );
        }

        $checkpoint = json_decode(
            $content,
            true
        );

        if (
            json_last_error() !== JSON_ERROR_NONE ||
            !is_array($checkpoint)
        ) {
            throw new RuntimeException(
                'O checkpoint não contém JSON válido.'
            );
        }

        return $checkpoint;
    }

    private static function normalizeLineEndings($line)
    {
        return rtrim(
            (string) $line,
            "\r\n"
        );
    }

    private static function splitBufferByDelimiter($buffer, $delimiter, $keepDelimiter = false)
    {
        $delimiterLength = strlen($delimiter);

        if ($delimiterLength <= 0) {
            throw new InvalidArgumentException(
                'Delimitador de registro inválido.'
            );
        }

        $parts = array();
        $offset = 0;

        while (true) {
            $position = strpos(
                $buffer,
                $delimiter,
                $offset
            );

            if ($position === false) {
                break;
            }

            $length = $position - $offset;

            if ($keepDelimiter) {
                $length += $delimiterLength;
            }

            $parts[] = substr(
                $buffer,
                $offset,
                $length
            );

            $offset = $position + $delimiterLength;
        }

        $remaining = substr($buffer, $offset);

        return array(
            'parts' => $parts,
            'remaining' => $remaining
        );
    }

    private static function ensureValidReadPosition($handle, $offset, $fileSize)
    {
        if ($offset > $fileSize) {
            throw new RuntimeException(
                'O offset informado é maior que o tamanho do arquivo.'
            );
        }

        if (fseek($handle, (int) $offset, SEEK_SET) !== 0) {
            throw new RuntimeException(
                'Não foi possível posicionar a leitura no offset informado.'
            );
        }

        return true;
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

    public static function setDefaultReadSize($readSize)
    {
        self::$defaultReadSize = self::normalizeReadSize(
            $readSize
        );
    }

    public static function setMaxReadSize($readSize)
    {
        $readSize = (int) $readSize;

        if ($readSize < self::$minReadSize) {
            throw new InvalidArgumentException(
                'Tamanho máximo de leitura inválido.'
            );
        }

        self::$maxReadSize = $readSize;
    }

    public static function setDefaultRowsPerChunk($rowsPerChunk)
    {
        self::$defaultRowsPerChunk = self::normalizeRowsPerChunk(
            $rowsPerChunk
        );
    }

    public static function setMaxLineLength($bytes)
    {
        $bytes = (int) $bytes;

        if ($bytes <= 0) {
            throw new InvalidArgumentException(
                'Tamanho máximo de linha inválido.'
            );
        }

        self::$maxLineLength = $bytes;
    }

    public static function info($path, $baseDirectory = null)
    {
        try {
            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $size = self::getFileSize($filePath);
            $modifiedAt = filemtime($filePath);

            return self::result(
                true,
                'Informações do arquivo carregadas com sucesso.',
                array(
                    'path' => $filePath,
                    'relative_path' => self::relativePath(
                        $filePath,
                        $baseDirectory
                    ),
                    'file_name' => basename($filePath),
                    'size' => $size,
                    'size_formatted' => self::formatBytes($size),
                    'modified_at' => $modifiedAt !== false
                        ? date('Y-m-d H:i:s', $modifiedAt)
                        : null,
                    'fingerprint' => self::getFileFingerprint(
                        $filePath
                    )
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao carregar informações do arquivo.',
                array(
                    'arquivo' => $path,
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível carregar informações do arquivo.'
            );
        }
    }

    public static function readBytes($path, $callback, $baseDirectory = null, array $options = array())
    {
        $startedAt = JFactory::getDate()->toSql();
        $handle = null;

        try {
            self::isValidCallback($callback);

            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $readSize = self::normalizeReadSize(
                isset($options['read_size'])
                    ? $options['read_size']
                    : self::$defaultReadSize
            );

            $offset = self::normalizeOffset(
                isset($options['offset'])
                    ? $options['offset']
                    : 0
            );

            $maxChunks = isset($options['max_chunks'])
                ? max(1, (int) $options['max_chunks'])
                : self::$maxChunks;

            $onProgress = isset($options['on_progress'])
                ? $options['on_progress']
                : null;

            $checkpointPath = isset($options['checkpoint_path'])
                ? $options['checkpoint_path']
                : null;

            $checkpointBaseDirectory = isset($options['checkpoint_base_directory'])
                ? $options['checkpoint_base_directory']
                : $baseDirectory;

            $fileSize = self::getFileSize($filePath);

            $handle = fopen($filePath, 'rb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Não foi possível abrir o arquivo para leitura.'
                );
            }

            self::ensureValidReadPosition(
                $handle,
                $offset,
                $fileSize
            );

            $chunkIndex = 0;
            $bytesProcessed = 0;
            $stopped = false;
            $lastResult = null;

            while (!feof($handle)) {
                if ($chunkIndex >= $maxChunks) {
                    throw new RuntimeException(
                        'Quantidade máxima de chunks atingida.'
                    );
                }

                $chunkStartOffset = ftell($handle);

                if ($chunkStartOffset === false) {
                    throw new RuntimeException(
                        'Não foi possível identificar a posição de leitura.'
                    );
                }

                $data = fread($handle, $readSize);

                if ($data === false) {
                    throw new RuntimeException(
                        'Falha ao ler o chunk do arquivo.'
                    );
                }

                if ($data === '') {
                    break;
                }

                $chunkEndOffset = ftell($handle);

                if ($chunkEndOffset === false) {
                    throw new RuntimeException(
                        'Não foi possível identificar o final do chunk.'
                    );
                }

                $chunkIndex++;
                $bytesProcessed += strlen($data);

                $metadata = self::buildProgress(
                    $filePath,
                    $chunkEndOffset,
                    $chunkIndex,
                    $startedAt,
                    array(
                        'chunk_start_offset' => $chunkStartOffset,
                        'chunk_end_offset' => $chunkEndOffset,
                        'chunk_bytes' => strlen($data),
                        'bytes_processed' => $bytesProcessed,
                        'fingerprint' => self::getFileFingerprint(
                            $filePath
                        )
                    )
                );

                $lastResult = call_user_func(
                    $callback,
                    $data,
                    $metadata
                );

                if (is_array($lastResult)) {
                    if (!empty($lastResult['stop'])) {
                        $stopped = true;
                    }

                    if (
                        isset($lastResult['next_offset']) &&
                        is_numeric($lastResult['next_offset'])
                    ) {
                        $nextOffset = self::normalizeOffset(
                            $lastResult['next_offset']
                        );

                        self::ensureValidReadPosition(
                            $handle,
                            $nextOffset,
                            $fileSize
                        );

                        $metadata['offset'] = $nextOffset;
                    }
                }

                if ($checkpointPath !== null) {
                    self::saveCheckpoint(
                        $checkpointPath,
                        array_merge(
                            $metadata,
                            array(
                                'mode' => 'bytes',
                                'checkpoint_version' => 1,
                                'stopped' => $stopped,
                                'last_result' => is_scalar($lastResult)
                                    ? $lastResult
                                    : null
                            )
                        ),
                        $checkpointBaseDirectory
                    );
                }

                if (is_callable($onProgress)) {
                    call_user_func(
                        $onProgress,
                        $metadata
                    );
                }

                if ($stopped) {
                    break;
                }
            }

            $finalOffset = ftell($handle);

            fclose($handle);
            $handle = null;

            $completed = !$stopped && $finalOffset >= $fileSize;

            $result = array(
                'file_path' => $filePath,
                'relative_path' => self::relativePath(
                    $filePath,
                    $baseDirectory
                ),
                'mode' => 'bytes',
                'completed' => $completed,
                'stopped' => $stopped,
                'file_size' => $fileSize,
                'offset' => $finalOffset,
                'chunk_index' => $chunkIndex,
                'bytes_processed' => $bytesProcessed,
                'percentual' => $fileSize > 0
                    ? round(($finalOffset / $fileSize) * 100, 4)
                    : 100,
                'started_at' => $startedAt,
                'finished_at' => JFactory::getDate()->toSql(),
                'last_result' => $lastResult,
                'fingerprint' => self::getFileFingerprint(
                    $filePath
                )
            );

            self::log(
                'info',
                'Leitura binária por chunks concluída.',
                $result
            );

            return self::result(
                true,
                $completed
                    ? 'Leitura por chunks concluída.'
                    : 'Leitura por chunks pausada.',
                $result
            );
        } catch (Throwable $error) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            self::log(
                'error',
                'Falha na leitura binária por chunks.',
                array(
                    'arquivo' => $path,
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível processar o arquivo por chunks.'
            );
        }
    }

    public static function readLines($path, $callback, $baseDirectory = null, array $options = array())
    {
        $startedAt = JFactory::getDate()->toSql();
        $handle = null;

        try {
            self::isValidCallback($callback);

            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $readSize = self::normalizeReadSize(
                isset($options['read_size'])
                    ? $options['read_size']
                    : self::$defaultReadSize
            );

            $rowsPerChunk = self::normalizeRowsPerChunk(
                isset($options['rows_per_chunk'])
                    ? $options['rows_per_chunk']
                    : self::$defaultRowsPerChunk
            );

            $offset = self::normalizeOffset(
                isset($options['offset'])
                    ? $options['offset']
                    : 0
            );

            $lineNumber = isset($options['line_number'])
                ? max(0, (int) $options['line_number'])
                : 0;

            $skipFirstLine = !empty($options['skip_first_line']);

            $delimiter = isset($options['line_delimiter'])
                ? (string) $options['line_delimiter']
                : "\n";

            if ($delimiter === '') {
                throw new InvalidArgumentException(
                    'Delimitador de linha inválido.'
                );
            }

            $keepDelimiter = !empty($options['keep_line_delimiter']);

            $normalizeLineEndings = isset($options['normalize_line_endings'])
                ? (bool) $options['normalize_line_endings']
                : true;

            $maxLineLength = isset($options['max_line_length'])
                ? max(1, (int) $options['max_line_length'])
                : self::$maxLineLength;

            $maxChunks = isset($options['max_chunks'])
                ? max(1, (int) $options['max_chunks'])
                : self::$maxChunks;

            $onProgress = isset($options['on_progress'])
                ? $options['on_progress']
                : null;

            $checkpointPath = isset($options['checkpoint_path'])
                ? $options['checkpoint_path']
                : null;

            $checkpointBaseDirectory = isset($options['checkpoint_base_directory'])
                ? $options['checkpoint_base_directory']
                : $baseDirectory;

            $fileSize = self::getFileSize($filePath);

            $handle = fopen($filePath, 'rb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Não foi possível abrir o arquivo para leitura.'
                );
            }

            self::ensureValidReadPosition(
                $handle,
                $offset,
                $fileSize
            );

            $buffer = '';
            $chunk = array();
            $chunkIndex = 0;
            $rowsProcessed = 0;
            $bytesProcessed = 0;
            $stopped = false;
            $lastResult = null;
            $firstLine = true;

            while (!feof($handle)) {
                $data = fread($handle, $readSize);

                if ($data === false) {
                    throw new RuntimeException(
                        'Falha ao ler parte do arquivo.'
                    );
                }

                if ($data === '') {
                    break;
                }

                $bytesProcessed += strlen($data);
                $buffer .= $data;

                if (strlen($buffer) > $maxLineLength) {
                    throw new RuntimeException(
                        'Uma linha ou registro excede o limite máximo permitido.'
                    );
                }

                $split = self::splitBufferByDelimiter(
                    $buffer,
                    $delimiter,
                    $keepDelimiter
                );

                $buffer = $split['remaining'];

                foreach ($split['parts'] as $line) {
                    $lineNumber++;

                    if (
                        $normalizeLineEndings &&
                        !$keepDelimiter
                    ) {
                        $line = self::normalizeLineEndings($line);
                    }

                    if ($skipFirstLine && $firstLine) {
                        $firstLine = false;

                        continue;
                    }

                    $firstLine = false;

                    $chunk[] = array(
                        'line' => $lineNumber,
                        'value' => $line
                    );

                    if (count($chunk) < $rowsPerChunk) {
                        continue;
                    }

                    $chunkIndex++;

                    if ($chunkIndex > $maxChunks) {
                        throw new RuntimeException(
                            'Quantidade máxima de chunks atingida.'
                        );
                    }

                    $currentOffset = ftell($handle);

                    if ($currentOffset === false) {
                        throw new RuntimeException(
                            'Não foi possível identificar o offset do arquivo.'
                        );
                    }

                    $metadata = self::buildProgress(
                        $filePath,
                        $currentOffset - strlen($buffer),
                        $chunkIndex,
                        $startedAt,
                        array(
                            'mode' => 'lines',
                            'line_number' => $lineNumber,
                            'rows_in_chunk' => count($chunk),
                            'rows_processed' => $rowsProcessed + count($chunk),
                            'bytes_processed' => $bytesProcessed,
                            'buffer_bytes' => strlen($buffer),
                            'fingerprint' => self::getFileFingerprint(
                                $filePath
                            )
                        )
                    );

                    $lastResult = call_user_func(
                        $callback,
                        $chunk,
                        $metadata
                    );

                    $rowsProcessed += count($chunk);

                    if (
                        is_array($lastResult) &&
                        !empty($lastResult['stop'])
                    ) {
                        $stopped = true;
                    }

                    if ($checkpointPath !== null) {
                        self::saveCheckpoint(
                            $checkpointPath,
                            array_merge(
                                $metadata,
                                array(
                                    'checkpoint_version' => 1,
                                    'stopped' => $stopped
                                )
                            ),
                            $checkpointBaseDirectory
                        );
                    }

                    if (is_callable($onProgress)) {
                        call_user_func(
                            $onProgress,
                            $metadata
                        );
                    }

                    $chunk = array();

                    if ($stopped) {
                        break 2;
                    }
                }
            }

            if (
                !$stopped &&
                $buffer !== ''
            ) {
                $lineNumber++;

                if (
                    $normalizeLineEndings &&
                    !$keepDelimiter
                ) {
                    $buffer = self::normalizeLineEndings($buffer);
                }

                if (!($skipFirstLine && $firstLine)) {
                    $chunk[] = array(
                        'line' => $lineNumber,
                        'value' => $buffer
                    );
                }
            }

            if (
                !$stopped &&
                !empty($chunk)
            ) {
                $chunkIndex++;

                $finalReadOffset = ftell($handle);

                if ($finalReadOffset === false) {
                    throw new RuntimeException(
                        'Não foi possível identificar o offset final.'
                    );
                }

                $metadata = self::buildProgress(
                    $filePath,
                    $finalReadOffset,
                    $chunkIndex,
                    $startedAt,
                    array(
                        'mode' => 'lines',
                        'line_number' => $lineNumber,
                        'rows_in_chunk' => count($chunk),
                        'rows_processed' => $rowsProcessed + count($chunk),
                        'bytes_processed' => $bytesProcessed,
                        'buffer_bytes' => 0,
                        'fingerprint' => self::getFileFingerprint(
                            $filePath
                        )
                    )
                );

                $lastResult = call_user_func(
                    $callback,
                    $chunk,
                    $metadata
                );

                $rowsProcessed += count($chunk);

                if (
                    is_array($lastResult) &&
                    !empty($lastResult['stop'])
                ) {
                    $stopped = true;
                }

                if ($checkpointPath !== null) {
                    self::saveCheckpoint(
                        $checkpointPath,
                        array_merge(
                            $metadata,
                            array(
                                'checkpoint_version' => 1,
                                'stopped' => $stopped
                            )
                        ),
                        $checkpointBaseDirectory
                    );
                }

                if (is_callable($onProgress)) {
                    call_user_func(
                        $onProgress,
                        $metadata
                    );
                }
            }

            $finalOffset = ftell($handle);

            fclose($handle);
            $handle = null;

            $completed = !$stopped && $finalOffset >= $fileSize;

            $result = array(
                'file_path' => $filePath,
                'relative_path' => self::relativePath(
                    $filePath,
                    $baseDirectory
                ),
                'mode' => 'lines',
                'completed' => $completed,
                'stopped' => $stopped,
                'file_size' => $fileSize,
                'offset' => $finalOffset,
                'line_number' => $lineNumber,
                'chunk_index' => $chunkIndex,
                'rows_processed' => $rowsProcessed,
                'bytes_processed' => $bytesProcessed,
                'percentual' => $fileSize > 0
                    ? round(($finalOffset / $fileSize) * 100, 4)
                    : 100,
                'started_at' => $startedAt,
                'finished_at' => JFactory::getDate()->toSql(),
                'last_result' => $lastResult,
                'fingerprint' => self::getFileFingerprint(
                    $filePath
                )
            );

            self::log(
                'info',
                'Leitura de linhas por chunks concluída.',
                $result
            );

            return self::result(
                true,
                $completed
                    ? 'Leitura de linhas concluída.'
                    : 'Leitura de linhas pausada.',
                $result
            );
        } catch (Throwable $error) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            self::log(
                'error',
                'Falha na leitura de linhas por chunks.',
                array(
                    'arquivo' => $path,
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível processar o arquivo por linhas.'
            );
        }
    }

    public static function resumeLines($path, $checkpointPath, $callback, $baseDirectory = null, array $options = array())
    {
        try {
            $checkpointBaseDirectory = isset($options['checkpoint_base_directory'])
                ? $options['checkpoint_base_directory']
                : $baseDirectory;

            $checkpoint = self::loadCheckpoint(
                $checkpointPath,
                $checkpointBaseDirectory
            );

            if (
                !isset($checkpoint['mode']) ||
                $checkpoint['mode'] !== 'lines'
            ) {
                throw new RuntimeException(
                    'Checkpoint incompatível com leitura por linhas.'
                );
            }

            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $fingerprint = self::getFileFingerprint(
                $filePath
            );

            if (
                isset($checkpoint['fingerprint']) &&
                $checkpoint['fingerprint'] !== $fingerprint
            ) {
                throw new RuntimeException(
                    'O arquivo foi alterado desde a criação do checkpoint.'
                );
            }

            $options['offset'] = isset($checkpoint['offset'])
                ? $checkpoint['offset']
                : 0;

            $options['line_number'] = isset($checkpoint['line_number'])
                ? $checkpoint['line_number']
                : 0;

            $options['checkpoint_path'] = $checkpointPath;
            $options['checkpoint_base_directory'] = $checkpointBaseDirectory;

            return self::readLines(
                $path,
                $callback,
                $baseDirectory,
                $options
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao retomar leitura por checkpoint.',
                array(
                    'arquivo' => $path,
                    'checkpoint' => $checkpointPath,
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível retomar o processamento do arquivo.'
            );
        }
    }

    public static function splitFile($sourcePath, $destinationDirectory, $baseDirectory = null, array $options = array())
    {
        $sourceHandle = null;
        $destinationHandle = null;

        try {
            $sourcePath = self::resolveFilePath(
                $sourcePath,
                $baseDirectory,
                true
            );

            $destinationBaseDirectory = isset($options['destination_base_directory'])
                ? $options['destination_base_directory']
                : $baseDirectory;

            $destinationBaseDirectory = self::resolveBaseDirectory(
                $destinationBaseDirectory
            );

            $destinationDirectory = trim((string) $destinationDirectory);

            if ($destinationDirectory === '') {
                throw new InvalidArgumentException(
                    'Diretório de destino inválido.'
                );
            }

            $destinationPath = self::resolveFilePath(
                rtrim($destinationDirectory, '/') .
                    '/.chunk_helper_directory_probe',
                $destinationBaseDirectory,
                false
            );

            $destinationDirectoryPath = dirname($destinationPath);

            if (JFile::exists($destinationPath)) {
                JFile::delete($destinationPath);
            }

            $readSize = self::normalizeReadSize(
                isset($options['read_size'])
                    ? $options['read_size']
                    : self::$defaultReadSize
            );

            $maxBytesPerFile = isset($options['max_bytes_per_file'])
                ? max(1, (int) $options['max_bytes_per_file'])
                : 1073741824;

            $splitByLines = isset($options['split_by_lines'])
                ? (bool) $options['split_by_lines']
                : true;

            $lineDelimiter = isset($options['line_delimiter'])
                ? (string) $options['line_delimiter']
                : "\n";

            if ($lineDelimiter === '') {
                throw new InvalidArgumentException(
                    'Delimitador de linha inválido.'
                );
            }

            $prefix = isset($options['prefix'])
                ? preg_replace(
                    '/[^a-zA-Z0-9_-]/',
                    '',
                    (string) $options['prefix']
                )
                : 'chunk';

            if ($prefix === '') {
                $prefix = 'chunk';
            }

            $extension = isset($options['extension'])
                ? trim(
                    ltrim((string) $options['extension'], '.')
                )
                : strtolower(
                    JFile::getExt(
                        basename($sourcePath)
                    )
                );

            if ($extension === '') {
                $extension = 'part';
            }

            $sourceHandle = fopen($sourcePath, 'rb');

            if ($sourceHandle === false) {
                throw new RuntimeException(
                    'Não foi possível abrir o arquivo de origem.'
                );
            }

            $sourceSize = self::getFileSize($sourcePath);
            $buffer = '';
            $files = array();
            $fileIndex = 0;
            $currentBytes = 0;
            $bytesRead = 0;
            $linesWritten = 0;

            $openDestination = function () use (
                &$destinationHandle,
                &$fileIndex,
                &$currentBytes,
                &$files,
                $destinationDirectoryPath,
                $prefix,
                $extension,
                $destinationBaseDirectory
            ) {
                if (is_resource($destinationHandle)) {
                    fclose($destinationHandle);
                }

                $fileIndex++;

                $fileName = $prefix .
                    '_' .
                    str_pad($fileIndex, 6, '0', STR_PAD_LEFT) .
                    '.' .
                    $extension;

                $filePath = self::resolveFilePath(
                    self::relativePath(
                        $destinationDirectoryPath,
                        $destinationBaseDirectory
                    ) .
                        '/' .
                        $fileName,
                    $destinationBaseDirectory,
                    false
                );

                $destinationHandle = fopen($filePath, 'wb');

                if ($destinationHandle === false) {
                    throw new RuntimeException(
                        'Não foi possível criar arquivo de chunk.'
                    );
                }

                $currentBytes = 0;

                $files[] = array(
                    'path' => $filePath,
                    'relative_path' => self::relativePath(
                        $filePath,
                        $destinationBaseDirectory
                    ),
                    'file_name' => $fileName,
                    'bytes' => 0,
                    'lines' => 0
                );
            };

            $openDestination();

            while (!feof($sourceHandle)) {
                $data = fread($sourceHandle, $readSize);

                if ($data === false) {
                    throw new RuntimeException(
                        'Falha ao ler o arquivo de origem.'
                    );
                }

                if ($data === '') {
                    break;
                }

                $bytesRead += strlen($data);

                if (!$splitByLines) {
                    $offset = 0;
                    $length = strlen($data);

                    while ($offset < $length) {
                        $remainingInFile = $maxBytesPerFile - $currentBytes;

                        if ($remainingInFile <= 0) {
                            $openDestination();
                            $remainingInFile = $maxBytesPerFile;
                        }

                        $lengthToWrite = min(
                            $remainingInFile,
                            $length - $offset
                        );

                        $piece = substr(
                            $data,
                            $offset,
                            $lengthToWrite
                        );

                        $written = fwrite(
                            $destinationHandle,
                            $piece
                        );

                        if ($written === false) {
                            throw new RuntimeException(
                                'Não foi possível gravar o chunk.'
                            );
                        }

                        $currentBytes += $written;
                        $files[count($files) - 1]['bytes'] += $written;
                        $offset += $written;
                    }

                    continue;
                }

                $buffer .= $data;

                if (strlen($buffer) > self::$maxLineLength) {
                    throw new RuntimeException(
                        'Uma linha excede o tamanho máximo permitido durante a divisão.'
                    );
                }

                $split = self::splitBufferByDelimiter(
                    $buffer,
                    $lineDelimiter,
                    true
                );

                $buffer = $split['remaining'];

                foreach ($split['parts'] as $line) {
                    $lineLength = strlen($line);

                    if (
                        $currentBytes > 0 &&
                        $currentBytes + $lineLength > $maxBytesPerFile
                    ) {
                        $openDestination();
                    }

                    $written = fwrite(
                        $destinationHandle,
                        $line
                    );

                    if ($written === false) {
                        throw new RuntimeException(
                            'Não foi possível gravar linha no chunk.'
                        );
                    }

                    $currentBytes += $written;
                    $files[count($files) - 1]['bytes'] += $written;
                    $files[count($files) - 1]['lines']++;
                    $linesWritten++;
                }
            }

            if ($splitByLines && $buffer !== '') {
                if (
                    $currentBytes > 0 &&
                    $currentBytes + strlen($buffer) > $maxBytesPerFile
                ) {
                    $openDestination();
                }

                $written = fwrite(
                    $destinationHandle,
                    $buffer
                );

                if ($written === false) {
                    throw new RuntimeException(
                        'Não foi possível gravar o último bloco.'
                    );
                }

                $currentBytes += $written;
                $files[count($files) - 1]['bytes'] += $written;
                $files[count($files) - 1]['lines']++;
                $linesWritten++;
            }

            fclose($sourceHandle);
            fclose($destinationHandle);

            $sourceHandle = null;
            $destinationHandle = null;

            foreach ($files as $index => $file) {
                @chmod(
                    $file['path'],
                    self::$filePermission
                );

                $files[$index]['size_formatted'] = self::formatBytes(
                    $file['bytes']
                );
            }

            $result = array(
                'source_path' => $sourcePath,
                'source_size' => $sourceSize,
                'source_size_formatted' => self::formatBytes(
                    $sourceSize
                ),
                'destination_directory' => $destinationDirectoryPath,
                'files_count' => count($files),
                'lines_written' => $linesWritten,
                'bytes_read' => $bytesRead,
                'files' => $files
            );

            self::log(
                'info',
                'Arquivo dividido em chunks.',
                $result
            );

            return self::result(
                true,
                'Arquivo dividido em chunks com sucesso.',
                $result
            );
        } catch (Throwable $error) {
            if (is_resource($sourceHandle)) {
                fclose($sourceHandle);
            }

            if (is_resource($destinationHandle)) {
                fclose($destinationHandle);
            }

            self::log(
                'error',
                'Falha ao dividir arquivo em chunks.',
                array(
                    'arquivo' => $sourcePath,
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível dividir o arquivo em chunks.'
            );
        }
    }

    public static function deleteCheckpoint($checkpointPath, $baseDirectory = null)
    {
        try {
            $checkpointFile = self::resolveFilePath(
                $checkpointPath,
                $baseDirectory,
                true
            );

            if (!JFile::delete($checkpointFile)) {
                throw new RuntimeException(
                    'Não foi possível excluir o checkpoint.'
                );
            }

            return self::result(
                true,
                'Checkpoint excluído com sucesso.',
                array(
                    'path' => $checkpointFile
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao excluir checkpoint.',
                array(
                    'checkpoint' => $checkpointPath,
                    'erro' => $error->getMessage()
                )
            );

            return self::result(
                false,
                'Não foi possível excluir o checkpoint.'
            );
        }
    }

    public static function formatBytes($bytes, $precision = 2)
    {
        $bytes = max(0, (float) $bytes);

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
}
