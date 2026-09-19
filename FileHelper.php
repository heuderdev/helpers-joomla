<?php

defined('_JEXEC') or die;

jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');
jimport('joomla.filesystem.path');

class FileHelper
{
    private static $defaultBaseDirectory = null;

    private static $directoryPermission = 0755;

    private static $filePermission = 0644;

    private static $throwExceptions = false;

    private static $logCategory = 'file_helper';

    private static $maxReadSize = 52428800;

    private static function log($method, Throwable $error, array $context = array())
    {
        try {
            if (class_exists('LogHelper')) {
                LogHelper::error(
                    '[' . $method . '] ' . $error->getMessage(),
                    self::$logCategory,
                    array_merge(
                        $context,
                        array(
                            'file' => $error->getFile(),
                            'line' => $error->getLine()
                        )
                    )
                );

                return;
            }

            JLog::add(
                '[' . $method . '] ' .
                    $error->getMessage() .
                    ' | Arquivo: ' . $error->getFile() .
                    ' | Linha: ' . $error->getLine(),
                JLog::ERROR,
                self::$logCategory
            );
        } catch (Throwable $logError) {
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

    private static function fail($method, Throwable $error, $message, array $context = array())
    {
        self::log($method, $error, $context);

        if (self::$throwExceptions) {
            throw $error;
        }

        return self::result(
            false,
            $message
        );
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
                'O diretório base validado não é permitido.'
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

    private static function sanitizeDirectoryRelativePath($directory)
    {
        $directory = self::validateRawPath($directory);
        $directory = trim($directory, '/');

        if ($directory === '') {
            throw new InvalidArgumentException(
                'Diretório relativo inválido.'
            );
        }

        $parts = explode('/', $directory);
        $safeParts = array();

        foreach ($parts as $part) {
            $part = trim($part);

            if (
                $part === '' ||
                $part === '.' ||
                $part === '..'
            ) {
                throw new RuntimeException(
                    'Diretório relativo inválido.'
                );
            }

            $safePart = JFolder::makeSafe($part);

            if ($safePart === '') {
                throw new RuntimeException(
                    'Diretório relativo inválido.'
                );
            }

            $safeParts[] = $safePart;
        }

        return implode('/', $safeParts);
    }

    private static function resolveDirectory($directory, $baseDirectory = null, $create = true)
    {
        $baseDirectory = self::resolveBaseDirectory(
            $baseDirectory
        );

        $directory = trim((string) $directory);

        if ($directory === '') {
            return $baseDirectory;
        }

        $directory = str_replace('\\', '/', $directory);

        if (strpos($directory, '/') === 0) {
            $directory = self::normalizePath($directory);
        } else {
            $directory = self::normalizePath(
                $baseDirectory .
                    '/' .
                    self::sanitizeDirectoryRelativePath($directory)
            );
        }

        if (!self::pathInsideBase($directory, $baseDirectory)) {
            throw new RuntimeException(
                'Diretório fora da base autorizada.'
            );
        }

        if (!JFolder::exists($directory)) {
            if (!$create) {
                throw new RuntimeException(
                    'Diretório não encontrado.'
                );
            }

            if (!JFolder::create(
                $directory,
                self::$directoryPermission
            )) {
                throw new RuntimeException(
                    'Não foi possível criar o diretório.'
                );
            }
        }

        $realDirectory = realpath($directory);

        if ($realDirectory === false) {
            throw new RuntimeException(
                'Não foi possível validar o diretório.'
            );
        }

        $realDirectory = self::normalizePath($realDirectory);

        if (!self::pathInsideBase($realDirectory, $baseDirectory)) {
            throw new RuntimeException(
                'Diretório validado fora da base autorizada.'
            );
        }

        return $realDirectory;
    }

    private static function resolveFilePath($path, $baseDirectory = null, $mustExist = false)
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

        if ($mustExist) {
            if (!JFile::exists($filePath)) {
                throw new RuntimeException(
                    'Arquivo não encontrado.'
                );
            }

            $realFilePath = realpath($filePath);

            if ($realFilePath === false) {
                throw new RuntimeException(
                    'Não foi possível validar o arquivo.'
                );
            }

            $realFilePath = self::normalizePath($realFilePath);

            if (!self::pathInsideBase($realFilePath, $baseDirectory)) {
                throw new RuntimeException(
                    'Arquivo validado fora da base autorizada.'
                );
            }

            return $realFilePath;
        }

        $directory = dirname($filePath);

        self::resolveDirectory(
            $directory,
            $baseDirectory,
            true
        );

        return $filePath;
    }

    private static function relativePath($absolutePath, $baseDirectory = null)
    {
        $baseDirectory = self::resolveBaseDirectory(
            $baseDirectory
        );

        $absolutePath = self::normalizePath($absolutePath);

        if (!self::pathInsideBase($absolutePath, $baseDirectory)) {
            throw new RuntimeException(
                'Não foi possível gerar caminho relativo.'
            );
        }

        return ltrim(
            substr(
                $absolutePath,
                strlen($baseDirectory)
            ),
            '/'
        );
    }

    private static function siteRelativePath($absolutePath)
    {
        $siteRoot = self::siteRoot();
        $absolutePath = self::normalizePath($absolutePath);

        if (!self::pathInsideBase($absolutePath, $siteRoot)) {
            return null;
        }

        return ltrim(
            substr(
                $absolutePath,
                strlen($siteRoot)
            ),
            '/'
        );
    }

    private static function buildUrl($absolutePath)
    {
        $relativePath = self::siteRelativePath($absolutePath);

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

    private static function fileInfo($filePath, $baseDirectory = null)
    {
        $filePath = self::resolveFilePath(
            $filePath,
            $baseDirectory,
            true
        );

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
            'modified_at' => date(
                'Y-m-d H:i:s',
                filemtime($filePath)
            ),
            'created_at' => date(
                'Y-m-d H:i:s',
                filectime($filePath)
            )
        );
    }

    private static function fileName($fileName)
    {
        $fileName = basename(
            str_replace('\\', '/', (string) $fileName)
        );

        $fileName = JFile::makeSafe($fileName);

        if ($fileName === '') {
            throw new InvalidArgumentException(
                'Nome do arquivo inválido.'
            );
        }

        return $fileName;
    }

    private static function randomName($prefix = 'arquivo', $extension = '')
    {
        $prefix = preg_replace(
            '/[^a-zA-Z0-9_-]/',
            '',
            (string) $prefix
        );

        if ($prefix === '') {
            $prefix = 'arquivo';
        }

        if (function_exists('random_bytes')) {
            $random = bin2hex(random_bytes(16));
        } elseif (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(16);

            if ($bytes === false) {
                $random = sha1(
                    uniqid('', true) .
                        mt_rand()
                );
            } else {
                $random = bin2hex($bytes);
            }
        } else {
            $random = sha1(
                uniqid('', true) .
                    mt_rand()
            );
        }

        $extension = trim(
            ltrim((string) $extension, '.')
        );

        return $prefix .
            '_' .
            date('YmdHis') .
            '_' .
            $random .
            ($extension !== '' ? '.' . $extension : '');
    }

    private static function isFileOlderThan($filePath, $timestamp)
    {
        $modifiedAt = filemtime($filePath);

        if ($modifiedAt === false) {
            return false;
        }

        return $modifiedAt < $timestamp;
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

    public static function setMaxReadSize($bytes)
    {
        $bytes = (int) $bytes;

        if ($bytes <= 0) {
            throw new InvalidArgumentException(
                'Tamanho máximo de leitura inválido.'
            );
        }

        self::$maxReadSize = $bytes;
    }

    public static function setThrowExceptions($throwExceptions)
    {
        self::$throwExceptions = (bool) $throwExceptions;
    }

    public static function exists($path, $baseDirectory = null)
    {
        try {
            self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            return true;
        } catch (Throwable $error) {
            return false;
        }
    }

    public static function directoryExists($directory, $baseDirectory = null)
    {
        try {
            self::resolveDirectory(
                $directory,
                $baseDirectory,
                false
            );

            return true;
        } catch (Throwable $error) {
            return false;
        }
    }

    public static function ensureDirectory($directory, $baseDirectory = null)
    {
        try {
            $path = self::resolveDirectory(
                $directory,
                $baseDirectory,
                true
            );

            return self::result(
                true,
                'Diretório preparado com sucesso.',
                array(
                    'path' => $path,
                    'relative_path' => self::relativePath(
                        $path,
                        $baseDirectory
                    )
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível preparar o diretório.'
            );
        }
    }

    public static function info($path, $baseDirectory = null)
    {
        try {
            return self::result(
                true,
                'Informações do arquivo carregadas com sucesso.',
                self::fileInfo(
                    $path,
                    $baseDirectory
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível carregar informações do arquivo.'
            );
        }
    }

    public static function read($path, $baseDirectory = null, $maxBytes = null)
    {
        try {
            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $size = filesize($filePath);

            if ($size === false) {
                throw new RuntimeException(
                    'Não foi possível identificar o tamanho do arquivo.'
                );
            }

            $maxBytes = $maxBytes === null
                ? self::$maxReadSize
                : (int) $maxBytes;

            if ($maxBytes <= 0) {
                throw new InvalidArgumentException(
                    'Limite de leitura inválido.'
                );
            }

            if ($size > $maxBytes) {
                throw new RuntimeException(
                    'O arquivo excede o limite permitido para leitura.'
                );
            }

            $content = file_get_contents($filePath);

            if ($content === false) {
                throw new RuntimeException(
                    'Não foi possível ler o arquivo.'
                );
            }

            return self::result(
                true,
                'Arquivo lido com sucesso.',
                array(
                    'content' => $content,
                    'file' => self::fileInfo(
                        $filePath,
                        $baseDirectory
                    )
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível ler o arquivo.'
            );
        }
    }

    public static function readJson($path, $baseDirectory = null, $maxBytes = null)
    {
        try {
            $result = self::read(
                $path,
                $baseDirectory,
                $maxBytes
            );

            if (!$result['success']) {
                return $result;
            }

            $data = json_decode(
                $result['data']['content'],
                true
            );

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'O arquivo não contém JSON válido.'
                );
            }

            unset($result['data']['content']);

            $result['data']['json'] = $data;
            $result['mensagem'] = 'Arquivo JSON lido com sucesso.';

            return $result;
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível ler o arquivo JSON.'
            );
        }
    }

    public static function write($path, $content, $baseDirectory = null, array $options = array())
    {
        try {
            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                false
            );

            $append = !empty($options['append']);

            $overwrite = isset($options['overwrite'])
                ? (bool) $options['overwrite']
                : true;

            if (
                JFile::exists($filePath) &&
                !$overwrite &&
                !$append
            ) {
                throw new RuntimeException(
                    'Já existe um arquivo com este nome.'
                );
            }

            $content = is_scalar($content) || $content === null
                ? (string) $content
                : json_encode(
                    $content,
                    JSON_UNESCAPED_UNICODE |
                        JSON_UNESCAPED_SLASHES
                );

            if ($content === false) {
                throw new RuntimeException(
                    'Não foi possível converter o conteúdo para gravação.'
                );
            }

            if ($append) {
                $bytes = file_put_contents(
                    $filePath,
                    $content,
                    FILE_APPEND | LOCK_EX
                );
            } else {
                $bytes = file_put_contents(
                    $filePath,
                    $content,
                    LOCK_EX
                );
            }

            if ($bytes === false) {
                throw new RuntimeException(
                    'Não foi possível gravar o arquivo.'
                );
            }

            @chmod(
                $filePath,
                self::$filePermission
            );

            return self::result(
                true,
                'Arquivo gravado com sucesso.',
                array(
                    'bytes_written' => (int) $bytes,
                    'file' => self::fileInfo(
                        $filePath,
                        $baseDirectory
                    )
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível gravar o arquivo.'
            );
        }
    }

    public static function writeJson($path, $data, $baseDirectory = null, array $options = array())
    {
        try {
            $json = json_encode(
                $data,
                JSON_PRETTY_PRINT |
                    JSON_UNESCAPED_UNICODE |
                    JSON_UNESCAPED_SLASHES
            );

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException(
                    'Não foi possível converter os dados para JSON.'
                );
            }

            return self::write(
                $path,
                $json,
                $baseDirectory,
                $options
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível gravar o arquivo JSON.'
            );
        }
    }

    public static function append($path, $content, $baseDirectory = null)
    {
        return self::write(
            $path,
            $content,
            $baseDirectory,
            array(
                'append' => true
            )
        );
    }

    public static function copy($sourcePath, $destinationPath, $sourceBaseDirectory = null, $destinationBaseDirectory = null, array $options = array())
    {
        try {
            $source = self::resolveFilePath(
                $sourcePath,
                $sourceBaseDirectory,
                true
            );

            $destination = self::resolveFilePath(
                $destinationPath,
                $destinationBaseDirectory,
                false
            );

            $overwrite = !empty($options['overwrite']);

            if (
                JFile::exists($destination) &&
                !$overwrite
            ) {
                throw new RuntimeException(
                    'Já existe um arquivo no destino.'
                );
            }

            if (
                $overwrite &&
                JFile::exists($destination) &&
                !JFile::delete($destination)
            ) {
                throw new RuntimeException(
                    'Não foi possível substituir o arquivo de destino.'
                );
            }

            if (!JFile::copy($source, $destination)) {
                throw new RuntimeException(
                    'Não foi possível copiar o arquivo.'
                );
            }

            @chmod(
                $destination,
                self::$filePermission
            );

            return self::result(
                true,
                'Arquivo copiado com sucesso.',
                array(
                    'source' => self::fileInfo(
                        $source,
                        $sourceBaseDirectory
                    ),
                    'destination' => self::fileInfo(
                        $destination,
                        $destinationBaseDirectory
                    )
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível copiar o arquivo.'
            );
        }
    }

    public static function move($sourcePath, $destinationPath, $sourceBaseDirectory = null, $destinationBaseDirectory = null, array $options = array())
    {
        try {
            $source = self::resolveFilePath(
                $sourcePath,
                $sourceBaseDirectory,
                true
            );

            $destination = self::resolveFilePath(
                $destinationPath,
                $destinationBaseDirectory,
                false
            );

            $overwrite = !empty($options['overwrite']);

            if (
                JFile::exists($destination) &&
                !$overwrite
            ) {
                throw new RuntimeException(
                    'Já existe um arquivo no destino.'
                );
            }

            if (
                $overwrite &&
                JFile::exists($destination) &&
                !JFile::delete($destination)
            ) {
                throw new RuntimeException(
                    'Não foi possível substituir o arquivo de destino.'
                );
            }

            if (!JFile::move($source, $destination)) {
                throw new RuntimeException(
                    'Não foi possível mover o arquivo.'
                );
            }

            @chmod(
                $destination,
                self::$filePermission
            );

            return self::result(
                true,
                'Arquivo movido com sucesso.',
                array(
                    'source_path' => $source,
                    'destination' => self::fileInfo(
                        $destination,
                        $destinationBaseDirectory
                    )
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível mover o arquivo.'
            );
        }
    }

    public static function delete($path, $baseDirectory = null)
    {
        try {
            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $fileData = self::fileInfo(
                $filePath,
                $baseDirectory
            );

            if (!JFile::delete($filePath)) {
                throw new RuntimeException(
                    'Não foi possível excluir o arquivo.'
                );
            }

            return self::result(
                true,
                'Arquivo excluído com sucesso.',
                $fileData
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível excluir o arquivo.'
            );
        }
    }

    public static function deleteDirectory($directory, $baseDirectory = null, array $options = array())
    {
        try {
            $baseDirectory = self::resolveBaseDirectory(
                $baseDirectory
            );

            $directoryPath = self::resolveDirectory(
                $directory,
                $baseDirectory,
                false
            );

            $allowBaseDirectory = !empty($options['allow_base_directory']);

            if (
                $directoryPath === $baseDirectory &&
                !$allowBaseDirectory
            ) {
                throw new RuntimeException(
                    'A exclusão do diretório base não é permitida.'
                );
            }

            if (!JFolder::delete($directoryPath)) {
                throw new RuntimeException(
                    'Não foi possível excluir o diretório.'
                );
            }

            return self::result(
                true,
                'Diretório excluído com sucesso.',
                array(
                    'path' => $directoryPath
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível excluir o diretório.'
            );
        }
    }

    public static function listFiles($directory = '', $baseDirectory = null, array $options = array())
    {
        try {
            $directoryPath = self::resolveDirectory(
                $directory,
                $baseDirectory,
                false
            );

            $filter = isset($options['filter'])
                ? (string) $options['filter']
                : '.';

            $recursive = isset($options['recursive'])
                ? $options['recursive']
                : false;

            $fullPath = true;

            $exclude = isset($options['exclude'])
                ? (array) $options['exclude']
                : array(
                    '.svn',
                    'CVS',
                    '.DS_Store',
                    '__MACOSX'
                );

            $excludeFilter = isset($options['exclude_filter'])
                ? $options['exclude_filter']
                : array(
                    '^..*',
                    '.*~'
                );

            $files = JFolder::files(
                $directoryPath,
                $filter,
                $recursive,
                $fullPath,
                $exclude,
                $excludeFilter
            );

            if (!is_array($files)) {
                $files = array();
            }

            $result = array();

            foreach ($files as $filePath) {
                try {
                    $result[] = self::fileInfo(
                        $filePath,
                        $baseDirectory
                    );
                } catch (Throwable $ignored) {
                }
            }

            return self::result(
                true,
                'Arquivos listados com sucesso.',
                array(
                    'directory' => $directoryPath,
                    'total' => count($result),
                    'files' => $result
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível listar os arquivos.'
            );
        }
    }

    public static function listDirectories($directory = '', $baseDirectory = null, array $options = array())
    {
        try {
            $directoryPath = self::resolveDirectory(
                $directory,
                $baseDirectory,
                false
            );

            $filter = isset($options['filter'])
                ? (string) $options['filter']
                : '.';

            $recursive = isset($options['recursive'])
                ? $options['recursive']
                : false;

            $fullPath = true;

            $exclude = isset($options['exclude'])
                ? (array) $options['exclude']
                : array(
                    '.svn',
                    'CVS',
                    '.DS_Store',
                    '__MACOSX'
                );

            $excludeFilter = isset($options['exclude_filter'])
                ? $options['exclude_filter']
                : array(
                    '^..*',
                    '.*~'
                );

            $directories = JFolder::folders(
                $directoryPath,
                $filter,
                $recursive,
                $fullPath,
                $exclude,
                $excludeFilter
            );

            if (!is_array($directories)) {
                $directories = array();
            }

            $result = array();

            foreach ($directories as $directoryItem) {
                $result[] = array(
                    'path' => self::normalizePath($directoryItem),
                    'relative_path' => self::relativePath(
                        $directoryItem,
                        $baseDirectory
                    ),
                    'name' => basename($directoryItem)
                );
            }

            return self::result(
                true,
                'Diretórios listados com sucesso.',
                array(
                    'directory' => $directoryPath,
                    'total' => count($result),
                    'directories' => $result
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível listar os diretórios.'
            );
        }
    }

    public static function cleanOlderThan($directory, $days, $baseDirectory = null, array $options = array())
    {
        try {
            $days = (int) $days;

            if ($days <= 0) {
                throw new InvalidArgumentException(
                    'Quantidade de dias inválida.'
                );
            }

            $directoryPath = self::resolveDirectory(
                $directory,
                $baseDirectory,
                false
            );

            $recursive = isset($options['recursive'])
                ? $options['recursive']
                : true;

            $filter = isset($options['filter'])
                ? (string) $options['filter']
                : '.';

            $timestamp = time() - ($days * 86400);

            $files = JFolder::files(
                $directoryPath,
                $filter,
                $recursive,
                true
            );

            if (!is_array($files)) {
                $files = array();
            }

            $deleted = array();
            $failed = array();

            foreach ($files as $filePath) {
                try {
                    $filePath = self::resolveFilePath(
                        $filePath,
                        $baseDirectory,
                        true
                    );

                    if (!self::isFileOlderThan($filePath, $timestamp)) {
                        continue;
                    }

                    $data = self::fileInfo(
                        $filePath,
                        $baseDirectory
                    );

                    if (!JFile::delete($filePath)) {
                        $failed[] = $data;

                        continue;
                    }

                    $deleted[] = $data;
                } catch (Throwable $ignored) {
                }
            }

            return self::result(
                true,
                'Limpeza de arquivos concluída.',
                array(
                    'deleted_count' => count($deleted),
                    'failed_count' => count($failed),
                    'deleted' => $deleted,
                    'failed' => $failed
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível limpar arquivos antigos.'
            );
        }
    }

    public static function createZip($zipPath, array $files, $baseDirectory = null, array $options = array())
    {
        try {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException(
                    'A extensão ZipArchive do PHP não está disponível.'
                );
            }

            if (empty($files)) {
                throw new InvalidArgumentException(
                    'Informe ao menos um arquivo para gerar o ZIP.'
                );
            }

            $zipAbsolutePath = self::resolveFilePath(
                $zipPath,
                $baseDirectory,
                false
            );

            $overwrite = isset($options['overwrite'])
                ? (bool) $options['overwrite']
                : true;

            if (
                JFile::exists($zipAbsolutePath) &&
                !$overwrite
            ) {
                throw new RuntimeException(
                    'Já existe um arquivo ZIP com este nome.'
                );
            }

            if (
                $overwrite &&
                JFile::exists($zipAbsolutePath)
            ) {
                JFile::delete($zipAbsolutePath);
            }

            $zip = new ZipArchive();

            $openResult = $zip->open(
                $zipAbsolutePath,
                ZipArchive::CREATE
            );

            if ($openResult !== true) {
                throw new RuntimeException(
                    'Não foi possível criar o arquivo ZIP.'
                );
            }

            $added = 0;
            $ignored = array();

            foreach ($files as $item) {
                $sourceBaseDirectory = isset($options['source_base_directory'])
                    ? $options['source_base_directory']
                    : $baseDirectory;

                try {
                    $sourcePath = self::resolveFilePath(
                        $item,
                        $sourceBaseDirectory,
                        true
                    );

                    $entryName = self::relativePath(
                        $sourcePath,
                        $sourceBaseDirectory
                    );

                    if (
                        isset($options['flat']) &&
                        $options['flat']
                    ) {
                        $entryName = basename($sourcePath);
                    }

                    if (
                        !$zip->addFile(
                            $sourcePath,
                            $entryName
                        )
                    ) {
                        $ignored[] = $sourcePath;

                        continue;
                    }

                    $added++;
                } catch (Throwable $ignoredError) {
                    $ignored[] = (string) $item;
                }
            }

            $zip->close();

            if ($added === 0) {
                if (JFile::exists($zipAbsolutePath)) {
                    JFile::delete($zipAbsolutePath);
                }

                throw new RuntimeException(
                    'Nenhum arquivo pôde ser adicionado ao ZIP.'
                );
            }

            @chmod(
                $zipAbsolutePath,
                self::$filePermission
            );

            return self::result(
                true,
                'Arquivo ZIP criado com sucesso.',
                array(
                    'zip' => self::fileInfo(
                        $zipAbsolutePath,
                        $baseDirectory
                    ),
                    'added_count' => $added,
                    'ignored_count' => count($ignored),
                    'ignored' => $ignored
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível criar o arquivo ZIP.'
            );
        }
    }

    public static function stream($path, $downloadName = null, $baseDirectory = null, array $options = array())
    {
        try {
            $filePath = self::resolveFilePath(
                $path,
                $baseDirectory,
                true
            );

            $fileData = self::fileInfo(
                $filePath,
                $baseDirectory
            );

            $downloadName = $downloadName === null
                ? $fileData['file_name']
                : self::fileName($downloadName);

            $mime = isset($options['mime'])
                ? trim((string) $options['mime'])
                : '';

            if ($mime === '') {
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);

                    if ($finfo !== false) {
                        $mime = finfo_file(
                            $finfo,
                            $filePath
                        );

                        finfo_close($finfo);
                    }
                }
            }

            if ($mime === false || $mime === '') {
                $mime = 'application/octet-stream';
            }

            $inline = !empty($options['inline']);

            $disposition = $inline
                ? 'inline'
                : 'attachment';

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: ' . $mime);
            header('Content-Length: ' . (int) $fileData['size']);
            header('Content-Transfer-Encoding: binary');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header(
                'Content-Disposition: ' .
                    $disposition .
                    '; filename="' .
                    addslashes($downloadName) .
                    '"' .
                    '; filename*=UTF-8\'\'' .
                    rawurlencode($downloadName)
            );

            $handle = fopen($filePath, 'rb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Não foi possível abrir o arquivo para download.'
                );
            }

            while (!feof($handle)) {
                echo fread($handle, 8192);

                flush();
            }

            fclose($handle);

            return true;
        } catch (Throwable $error) {
            self::log(
                __METHOD__,
                $error
            );

            if (self::$throwExceptions) {
                throw $error;
            }

            return false;
        }
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

    public static function randomFileName($prefix = 'arquivo', $extension = '')
    {
        return self::randomName(
            $prefix,
            $extension
        );
    }
}
