<?php

defined('_JEXEC') or die;

jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');
jimport('joomla.filesystem.path');

class UploadMaster
{
    private static $logCategory = 'upload_master';

    private static $baseDirectory = null;

    private static $maxFileSize = 52428800;

    private static $allowedExtensions = array(
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp',
        'pdf',
        'txt',
        'csv',
        'xlsx',
        'xls',
        'doc',
        'docx',
        'zip',
        'mp4',
        'webm',
        'mov'
    );

    private static $allowedMimes = array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'text/plain',
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/x-zip-compressed',
        'video/mp4',
        'video/webm',
        'video/quicktime'
    );

    private static $imageExtensions = array(
        'jpg',
        'jpeg',
        'png',
        'gif',
        'webp'
    );

    private static $imageMimes = array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp'
    );

    private static $dangerousExtensions = array(
        'php',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'phtml',
        'phar',
        'cgi',
        'pl',
        'py',
        'sh',
        'bash',
        'zsh',
        'exe',
        'com',
        'bat',
        'cmd',
        'msi',
        'dll',
        'so',
        'jar',
        'jsp',
        'asp',
        'aspx',
        'htaccess',
        'htpasswd',
        'ini',
        'env',
        'sql'
    );

    private static $imageMaxWidth = 8000;

    private static $imageMaxHeight = 8000;

    private static $imageMaxPixels = 40000000;

    private static $directoryPermission = 0755;

    private static $filePermission = 0644;

    private static $throwExceptions = false;

    private static function log($message, $level = JLog::ERROR)
    {
        JLog::add(
            self::truncate($message, 5000),
            $level,
            self::$logCategory
        );
    }

    private static function truncate($value, $limit)
    {
        $value = (string) $value;

        if (strlen($value) <= (int) $limit) {
            return $value;
        }

        return substr($value, 0, (int) $limit) . '...[truncado]';
    }

    private static function result($success, $message, array $data = array(), array $errors = array())
    {
        return array(
            'success'  => (bool) $success,
            'status'   => $success ? 'sucesso' : 'erro',
            'mensagem' => (string) $message,
            'data'     => $data,
            'errors'   => $errors
        );
    }

    private static function fail($method, Throwable $error, $publicMessage, array $errors = array())
    {
        self::log(
            '[' . $method . '] ' .
            $error->getMessage() .
            ' | Arquivo: ' . $error->getFile() .
            ' | Linha: ' . $error->getLine(),
            JLog::ERROR
        );

        if (self::$throwExceptions) {
            throw $error;
        }

        return self::result(
            false,
            $publicMessage,
            array(),
            $errors
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
            throw new RuntimeException('Não foi possível localizar a raiz do site.');
        }

        return self::normalizePath($siteRoot);
    }

    private static function baseDirectory()
    {
        if (self::$baseDirectory !== null) {
            return self::$baseDirectory;
        }

        self::$baseDirectory = self::siteRoot();

        return self::$baseDirectory;
    }

    private static function resolveBaseDirectory($baseDirectory = null)
    {
        $siteRoot = self::siteRoot();

        if ($baseDirectory === null || trim((string) $baseDirectory) === '') {
            return self::baseDirectory();
        }

        $baseDirectory = trim((string) $baseDirectory);
        $baseDirectory = str_replace('\\', '/', $baseDirectory);

        if (
            strpos($baseDirectory, "\0") !== false ||
            strpos($baseDirectory, '../') !== false ||
            strpos($baseDirectory, '..\\') !== false
        ) {
            throw new InvalidArgumentException('Diretório base inválido.');
        }

        if (strpos($baseDirectory, '/') !== 0) {
            $baseDirectory = $siteRoot . '/' . trim($baseDirectory, '/');
        }

        $baseDirectory = self::normalizePath($baseDirectory);

        if (
            $baseDirectory !== $siteRoot &&
            strpos($baseDirectory, $siteRoot . '/') !== 0
        ) {
            throw new RuntimeException(
                'O diretório base deve estar dentro de JPATH_SITE.'
            );
        }

        if (!JFolder::exists($baseDirectory)) {
            if (!JFolder::create($baseDirectory, self::$directoryPermission)) {
                throw new RuntimeException(
                    'Não foi possível criar o diretório base informado.'
                );
            }
        }

        $realBaseDirectory = realpath($baseDirectory);

        if ($realBaseDirectory === false) {
            throw new RuntimeException(
                'Não foi possível validar o diretório base informado.'
            );
        }

        $realBaseDirectory = self::normalizePath($realBaseDirectory);

        if (
            $realBaseDirectory !== $siteRoot &&
            strpos($realBaseDirectory, $siteRoot . '/') !== 0
        ) {
            throw new RuntimeException(
                'O diretório base informado não é permitido.'
            );
        }

        return $realBaseDirectory;
    }

    private static function sanitizeRelativeDirectory($directory)
    {
        $directory = trim((string) $directory);
        $directory = str_replace('\\', '/', $directory);
        $directory = preg_replace('#/+#', '/', $directory);
        $directory = trim($directory, '/');

        if (
            $directory === '' ||
            strpos($directory, "\0") !== false ||
            strpos($directory, '../') !== false ||
            strpos($directory, '..\\') !== false ||
            $directory === '..'
        ) {
            throw new InvalidArgumentException(
                'Diretório de destino inválido.'
            );
        }

        $parts = explode('/', $directory);
        $safeParts = array();

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '' || $part === '.' || $part === '..') {
                throw new InvalidArgumentException(
                    'Diretório de destino inválido.'
                );
            }

            $safePart = JFolder::makeSafe($part);

            if ($safePart === '') {
                throw new InvalidArgumentException(
                    'Diretório de destino inválido.'
                );
            }

            $safeParts[] = $safePart;
        }

        return implode('/', $safeParts);
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

    private static function resolveDirectory($directory, $baseDirectory = null)
    {
        $relativeDirectory = self::sanitizeRelativeDirectory($directory);

        $baseDirectory = self::resolveBaseDirectory(
            $baseDirectory
        );

        $fullDirectory = self::normalizePath(
            $baseDirectory . '/' . $relativeDirectory
        );

        if (!self::pathInsideBase($fullDirectory, $baseDirectory)) {
            throw new RuntimeException(
                'O diretório de destino não está dentro da base autorizada.'
            );
        }

        if (!JFolder::exists($fullDirectory)) {
            if (!JFolder::create($fullDirectory, self::$directoryPermission)) {
                throw new RuntimeException(
                    'Não foi possível criar o diretório de destino.'
                );
            }
        }

        $realDirectory = realpath($fullDirectory);

        if ($realDirectory === false) {
            throw new RuntimeException(
                'Não foi possível validar o diretório de destino.'
            );
        }

        $realDirectory = self::normalizePath($realDirectory);

        if (!self::pathInsideBase($realDirectory, $baseDirectory)) {
            throw new RuntimeException(
                'O diretório de destino validado não está dentro da base autorizada.'
            );
        }

        return $realDirectory;
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

        if (
            strpos($fileName, "\0") !== false ||
            strpos($fileName, '..') !== false
        ) {
            throw new InvalidArgumentException(
                'Nome de arquivo inválido.'
            );
        }

        return $fileName;
    }

    private static function getExtension($fileName)
    {
        $extension = strtolower(
            trim((string) JFile::getExt($fileName))
        );

        if ($extension === '') {
            throw new RuntimeException(
                'O arquivo não possui uma extensão válida.'
            );
        }

        return $extension;
    }

    private static function validateExtension($extension, array $allowedExtensions = array())
    {
        $extension = strtolower(trim((string) $extension));

        if (in_array($extension, self::$dangerousExtensions, true)) {
            throw new RuntimeException(
                'A extensão do arquivo é proibida por segurança.'
            );
        }

        if (empty($allowedExtensions)) {
            $allowedExtensions = self::$allowedExtensions;
        }

        $allowedExtensions = array_map(
            function ($item) {
                return strtolower(trim((string) $item));
            },
            $allowedExtensions
        );

        if (!in_array($extension, $allowedExtensions, true)) {
            throw new RuntimeException(
                'A extensão do arquivo não é permitida.'
            );
        }

        return true;
    }

    private static function getMimeType($path)
    {
        if (!function_exists('finfo_open')) {
            throw new RuntimeException(
                'A extensão Fileinfo do PHP é obrigatória para validar uploads.'
            );
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new RuntimeException(
                'Não foi possível iniciar a validação MIME do arquivo.'
            );
        }

        $mime = finfo_file($finfo, $path);

        finfo_close($finfo);

        if ($mime === false || trim((string) $mime) === '') {
            throw new RuntimeException(
                'Não foi possível identificar o tipo real do arquivo.'
            );
        }

        return strtolower(trim((string) $mime));
    }

    private static function validateMime($mime, array $allowedMimes = array())
    {
        $mime = strtolower(trim((string) $mime));

        if (empty($allowedMimes)) {
            $allowedMimes = self::$allowedMimes;
        }

        $allowedMimes = array_map(
            function ($item) {
                return strtolower(trim((string) $item));
            },
            $allowedMimes
        );

        if (!in_array($mime, $allowedMimes, true)) {
            throw new RuntimeException(
                'O tipo real do arquivo não é permitido.'
            );
        }

        return true;
    }

    private static function validateExtensionAndMimeCompatibility($extension, $mime)
    {
        $allowed = array(
            'jpg' => array(
                'image/jpeg'
            ),
            'jpeg' => array(
                'image/jpeg'
            ),
            'png' => array(
                'image/png'
            ),
            'gif' => array(
                'image/gif'
            ),
            'webp' => array(
                'image/webp'
            ),
            'pdf' => array(
                'application/pdf'
            ),
            'txt' => array(
                'text/plain'
            ),
            'csv' => array(
                'text/plain',
                'text/csv',
                'application/csv',
                'application/vnd.ms-excel'
            ),
            'xls' => array(
                'application/vnd.ms-excel'
            ),
            'xlsx' => array(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
                'application/x-zip-compressed'
            ),
            'doc' => array(
                'application/msword'
            ),
            'docx' => array(
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
                'application/x-zip-compressed'
            ),
            'zip' => array(
                'application/zip',
                'application/x-zip-compressed'
            ),
            'mp4' => array(
                'video/mp4'
            ),
            'webm' => array(
                'video/webm'
            ),
            'mov' => array(
                'video/quicktime'
            )
        );

        if (!isset($allowed[$extension])) {
            throw new RuntimeException(
                'Não há regra de compatibilidade configurada para esta extensão.'
            );
        }

        if (!in_array($mime, $allowed[$extension], true)) {
            throw new RuntimeException(
                'A extensão do arquivo não corresponde ao seu tipo real.'
            );
        }

        return true;
    }

    private static function validateUploadedFile(array $file)
    {
        if (empty($file)) {
            throw new InvalidArgumentException(
                'Nenhum arquivo foi informado.'
            );
        }

        if (!isset($file['error'])) {
            throw new InvalidArgumentException(
                'Estrutura de upload inválida.'
            );
        }

        $uploadError = (int) $file['error'];

        if ($uploadError !== UPLOAD_ERR_OK) {
            $messages = array(
                UPLOAD_ERR_INI_SIZE => 'O arquivo excede o limite definido no servidor.',
                UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite permitido pelo formulário.',
                UPLOAD_ERR_PARTIAL => 'O upload foi enviado parcialmente.',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado.',
                UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário do servidor não encontrado.',
                UPLOAD_ERR_CANT_WRITE => 'Não foi possível gravar o arquivo temporário no servidor.',
                UPLOAD_ERR_EXTENSION => 'O upload foi bloqueado por uma extensão do PHP.'
            );

            throw new RuntimeException(
                isset($messages[$uploadError])
                    ? $messages[$uploadError]
                    : 'Falha desconhecida no upload. Código: ' . $uploadError
            );
        }

        if (
            empty($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            throw new RuntimeException(
                'O arquivo temporário de upload é inválido.'
            );
        }

        if (empty($file['name'])) {
            throw new RuntimeException(
                'O nome original do arquivo não foi informado.'
            );
        }

        if (!isset($file['size']) || (int) $file['size'] <= 0) {
            throw new RuntimeException(
                'O arquivo enviado está vazio.'
            );
        }

        return true;
    }

    private static function validateFileSize($size, $maxSize)
    {
        $size = (int) $size;
        $maxSize = (int) $maxSize;

        if ($size <= 0) {
            throw new RuntimeException(
                'O arquivo enviado está vazio.'
            );
        }

        if ($maxSize <= 0) {
            throw new RuntimeException(
                'O limite de tamanho do arquivo é inválido.'
            );
        }

        if ($size > $maxSize) {
            throw new RuntimeException(
                'O arquivo excede o tamanho máximo permitido de ' .
                self::formatBytes($maxSize) .
                '.'
            );
        }

        return true;
    }

    private static function validateImage($path, $mime)
    {
        if (!in_array($mime, self::$imageMimes, true)) {
            return array();
        }

        $imageInfo = @getimagesize($path);

        if ($imageInfo === false) {
            throw new RuntimeException(
                'O arquivo informado não é uma imagem válida.'
            );
        }

        $width = isset($imageInfo[0])
            ? (int) $imageInfo[0]
            : 0;

        $height = isset($imageInfo [manual.joomla](https://manual.joomla.org/docs/next/general-concepts/logging/))
            ? (int) $imageInfo [manual.joomla](https://manual.joomla.org/docs/next/general-concepts/logging/)
            : 0;

        $pixels = $width * $height;

        if ($width <= 0 || $height <= 0) {
            throw new RuntimeException(
                'A imagem possui dimensões inválidas.'
            );
        }

        if (
            $width > self::$imageMaxWidth ||
            $height > self::$imageMaxHeight
        ) {
            throw new RuntimeException(
                'A imagem excede as dimensões máximas permitidas.'
            );
        }

        if ($pixels > self::$imageMaxPixels) {
            throw new RuntimeException(
                'A imagem excede a quantidade máxima de pixels permitida.'
            );
        }

        return array(
            'width' => $width,
            'height' => $height,
            'pixels' => $pixels
        );
    }

    private static function generateRandomName($extension, $prefix = 'arquivo')
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
                throw new RuntimeException(
                    'Não foi possível gerar nome seguro para o arquivo.'
                );
            }

            $random = bin2hex($bytes);
        } else {
            $random = sha1(
                uniqid($prefix, true) .
                mt_rand()
            );
        }

        return $prefix .
            '_' .
            date('YmdHis') .
            '_' .
            $random .
            '.' .
            $extension;
    }

    private static function destinationFileName($requestedName, $originalName, $extension, array $options)
    {
        $prefix = isset($options['prefix'])
            ? $options['prefix']
            : 'arquivo';

        $keepOriginalName = !empty($options['keep_original_name']);

        if ($requestedName !== null && trim((string) $requestedName) !== '') {
            $fileName = self::normalizeFileName($requestedName);

            $destinationExtension = self::getExtension($fileName);

            if ($destinationExtension !== $extension) {
                throw new RuntimeException(
                    'A extensão do nome de destino deve ser igual à extensão do arquivo enviado.'
                );
            }

            self::validateExtension($destinationExtension);

            return $fileName;
        }

        if ($keepOriginalName) {
            $originalName = self::normalizeFileName($originalName);

            $originalExtension = self::getExtension($originalName);

            if ($originalExtension !== $extension) {
                throw new RuntimeException(
                    'A extensão do arquivo original é inválida.'
                );
            }

            return $originalName;
        }

        return self::generateRandomName($extension, $prefix);
    }

    private static function ensureUniqueFileName($directory, $fileName, $overwrite)
    {
        $directory = self::normalizePath($directory);
        $fileName = self::normalizeFileName($fileName);
        $destination = $directory . '/' . $fileName;

        if (!JFile::exists($destination)) {
            return $fileName;
        }

        if ($overwrite) {
            return $fileName;
        }

        $extension = JFile::getExt($fileName);
        $baseName = JFile::stripExt($fileName);
        $suffix = 1;

        do {
            $candidate = $baseName .
                '_' .
                $suffix .
                '.' .
                $extension;

            $destination = $directory . '/' . $candidate;

            $suffix++;
        } while (JFile::exists($destination));

        return $candidate;
    }

    private static function relativePathByBase($absolutePath, $baseDirectory = null)
    {
        $absolutePath = self::normalizePath($absolutePath);

        $baseDirectory = self::resolveBaseDirectory(
            $baseDirectory
        );

        if (!self::pathInsideBase($absolutePath, $baseDirectory)) {
            throw new RuntimeException(
                'Não foi possível gerar o caminho relativo do arquivo.'
            );
        }

        return ltrim(
            substr($absolutePath, strlen($baseDirectory)),
            '/'
        );
    }

    private static function relativePathBySite($absolutePath)
    {
        $absolutePath = self::normalizePath($absolutePath);
        $siteRoot = self::siteRoot();

        if (!self::pathInsideBase($absolutePath, $siteRoot)) {
            return null;
        }

        return ltrim(
            substr($absolutePath, strlen($siteRoot)),
            '/'
        );
    }

    private static function buildUrl($absolutePath)
    {
        $relativePath = self::relativePathBySite($absolutePath);

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

    private static function fileData($absolutePath, array $extra = array(), $baseDirectory = null)
    {
        $absolutePath = self::normalizePath($absolutePath);

        if (!JFile::exists($absolutePath)) {
            throw new RuntimeException(
                'Arquivo não encontrado após a operação.'
            );
        }

        $fileName = basename($absolutePath);
        $extension = strtolower(JFile::getExt($fileName));
        $size = filesize($absolutePath);
        $mime = self::getMimeType($absolutePath);

        $data = array(
            'path' => $absolutePath,
            'relative_path' => self::relativePathByBase(
                $absolutePath,
                $baseDirectory
            ),
            'site_relative_path' => self::relativePathBySite(
                $absolutePath
            ),
            'url' => self::buildUrl($absolutePath),
            'base_directory' => self::resolveBaseDirectory(
                $baseDirectory
            ),
            'file_name' => $fileName,
            'extension' => $extension,
            'mime' => $mime,
            'size' => (int) $size,
            'size_formatted' => self::formatBytes($size),
            'created_at' => date(
                'Y-m-d H:i:s',
                filemtime($absolutePath)
            )
        );

        if (in_array($extension, self::$imageExtensions, true)) {
            $image = @getimagesize($absolutePath);

            if ($image !== false) {
                $data['width'] = isset($image[0])
                    ? (int) $image[0]
                    : null;

                $data['height'] = isset($image [manual.joomla](https://manual.joomla.org/docs/next/general-concepts/logging/))
                    ? (int) $image [manual.joomla](https://manual.joomla.org/docs/next/general-concepts/logging/)
                    : null;
            }
        }

        return array_merge($data, $extra);
    }

    private static function validateExistingFile($absolutePath, $baseDirectory = null)
    {
        $absolutePath = self::normalizePath($absolutePath);

        $baseDirectory = self::resolveBaseDirectory(
            $baseDirectory
        );

        if (
            $absolutePath === '' ||
            strpos($absolutePath, "\0") !== false ||
            strpos($absolutePath, '../') !== false ||
            strpos($absolutePath, '..\\') !== false
        ) {
            throw new InvalidArgumentException(
                'Caminho do arquivo inválido.'
            );
        }

        if (!JFile::exists($absolutePath)) {
            throw new RuntimeException(
                'Arquivo não encontrado.'
            );
        }

        $realPath = realpath($absolutePath);

        if ($realPath === false) {
            throw new RuntimeException(
                'Não foi possível validar o arquivo.'
            );
        }

        $realPath = self::normalizePath($realPath);

        if (!self::pathInsideBase($realPath, $baseDirectory)) {
            throw new RuntimeException(
                'O arquivo está fora do diretório base autorizado.'
            );
        }

        return $realPath;
    }

    private static function resolveExistingFile($pathOrRelativePath, $baseDirectory = null)
    {
        $pathOrRelativePath = trim((string) $pathOrRelativePath);

        if ($pathOrRelativePath === '') {
            throw new InvalidArgumentException(
                'Caminho do arquivo é obrigatório.'
            );
        }

        $pathOrRelativePath = str_replace(
            '\\',
            '/',
            $pathOrRelativePath
        );

        if (
            strpos($pathOrRelativePath, "\0") !== false ||
            strpos($pathOrRelativePath, '../') !== false ||
            strpos($pathOrRelativePath, '..\\') !== false
        ) {
            throw new RuntimeException(
                'Caminho do arquivo inválido.'
            );
        }

        $baseDirectory = self::resolveBaseDirectory(
            $baseDirectory
        );

        if (strpos($pathOrRelativePath, '/') === 0) {
            return self::validateExistingFile(
                $pathOrRelativePath,
                $baseDirectory
            );
        }

        return self::validateExistingFile(
            $baseDirectory .
            '/' .
            ltrim($pathOrRelativePath, '/'),
            $baseDirectory
        );
    }

    private static function createImageResource($path, $mime)
    {
        switch ($mime) {
            case 'image/jpeg':
                return imagecreatefromjpeg($path);

            case 'image/png':
                return imagecreatefrompng($path);

            case 'image/gif':
                return imagecreatefromgif($path);

            case 'image/webp':
                if (!function_exists('imagecreatefromwebp')) {
                    throw new RuntimeException(
                        'Suporte WEBP não disponível na instalação PHP.'
                    );
                }

                return imagecreatefromwebp($path);
        }

        throw new RuntimeException(
            'Tipo de imagem não suportado para processamento.'
        );
    }

    private static function saveImageResource($resource, $destination, $mime, $quality)
    {
        switch ($mime) {
            case 'image/jpeg':
                return imagejpeg(
                    $resource,
                    $destination,
                    $quality
                );

            case 'image/png':
                $compression = 9 - (int) round(
                    ($quality / 100) * 9
                );

                return imagepng(
                    $resource,
                    $destination,
                    $compression
                );

            case 'image/gif':
                return imagegif(
                    $resource,
                    $destination
                );

            case 'image/webp':
                if (!function_exists('imagewebp')) {
                    throw new RuntimeException(
                        'Suporte WEBP não disponível na instalação PHP.'
                    );
                }

                return imagewebp(
                    $resource,
                    $destination,
                    $quality
                );
        }

        throw new RuntimeException(
            'Tipo de imagem não suportado para salvamento.'
        );
    }

    public static function setBaseDirectory($directory)
    {
        try {
            self::$baseDirectory = self::resolveBaseDirectory(
                $directory
            );

            return true;
        } catch (Throwable $error) {
            self::log(
                '[' . __METHOD__ . '] ' .
                $error->getMessage(),
                JLog::ERROR
            );

            if (self::$throwExceptions) {
                throw $error;
            }

            return false;
        }
    }

    public static function getBaseDirectory()
    {
        return self::baseDirectory();
    }

    public static function setMaxFileSize($bytes)
    {
        $bytes = (int) $bytes;

        if ($bytes <= 0) {
            throw new InvalidArgumentException(
                'O tamanho máximo de arquivo deve ser maior que zero.'
            );
        }

        self::$maxFileSize = $bytes;
    }

    public static function setAllowedExtensions(array $extensions)
    {
        if (empty($extensions)) {
            throw new InvalidArgumentException(
                'Informe ao menos uma extensão permitida.'
            );
        }

        $extensions = array_map(
            function ($extension) {
                return strtolower(trim((string) $extension));
            },
            $extensions
        );

        self::$allowedExtensions = array_values(
            array_unique(
                array_filter($extensions)
            )
        );
    }

    public static function setAllowedMimes(array $mimes)
    {
        if (empty($mimes)) {
            throw new InvalidArgumentException(
                'Informe ao menos um MIME permitido.'
            );
        }

        $mimes = array_map(
            function ($mime) {
                return strtolower(trim((string) $mime));
            },
            $mimes
        );

        self::$allowedMimes = array_values(
            array_unique(
                array_filter($mimes)
            )
        );
    }

    public static function setThrowExceptions($throwExceptions)
    {
        self::$throwExceptions = (bool) $throwExceptions;
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

    public static function getFileFromInput($fieldName, $index = null)
    {
        $files = JFactory::getApplication()
            ->input
            ->files
            ->get($fieldName, null, 'array');

        if (empty($files)) {
            return null;
        }

        if ($index === null) {
            return $files;
        }

        if (isset($files[$index]) && is_array($files[$index])) {
            return $files[$index];
        }

        return null;
    }

    public static function upload(array $file, $directory, $fileName = null, array $options = array())
    {
        try {
            self::validateUploadedFile($file);

            $baseDirectory = isset($options['base_directory'])
                ? $options['base_directory']
                : null;

            $maxSize = isset($options['max_size'])
                ? (int) $options['max_size']
                : self::$maxFileSize;

            $allowedExtensions = isset($options['allowed_extensions'])
                ? (array) $options['allowed_extensions']
                : self::$allowedExtensions;

            $allowedMimes = isset($options['allowed_mimes'])
                ? (array) $options['allowed_mimes']
                : self::$allowedMimes;

            $overwrite = !empty($options['overwrite']);

            self::validateFileSize(
                $file['size'],
                $maxSize
            );

            $originalName = self::normalizeFileName(
                $file['name']
            );

            $extension = self::getExtension(
                $originalName
            );

            self::validateExtension(
                $extension,
                $allowedExtensions
            );

            $mime = self::getMimeType(
                $file['tmp_name']
            );

            self::validateMime(
                $mime,
                $allowedMimes
            );

            self::validateExtensionAndMimeCompatibility(
                $extension,
                $mime
            );

            $imageData = self::validateImage(
                $file['tmp_name'],
                $mime
            );

            $destinationDirectory = self::resolveDirectory(
                $directory,
                $baseDirectory
            );

            $destinationName = self::destinationFileName(
                $fileName,
                $originalName,
                $extension,
                $options
            );

            $destinationName = self::ensureUniqueFileName(
                $destinationDirectory,
                $destinationName,
                $overwrite
            );

            $destinationPath = $destinationDirectory .
                '/' .
                $destinationName;

            if ($overwrite && JFile::exists($destinationPath)) {
                if (!JFile::delete($destinationPath)) {
                    throw new RuntimeException(
                        'Não foi possível substituir o arquivo já existente.'
                    );
                }
            }

            if (!JFile::upload(
                $file['tmp_name'],
                $destinationPath,
                false,
                false
            )) {
                throw new RuntimeException(
                    'Não foi possível salvar o arquivo enviado.'
                );
            }

            @chmod(
                $destinationPath,
                self::$filePermission
            );

            $data = self::fileData(
                $destinationPath,
                array(
                    'original_name' => $originalName,
                    'upload_name' => $destinationName,
                    'image' => $imageData
                ),
                $baseDirectory
            );

            return self::result(
                true,
                'Arquivo enviado com sucesso.',
                $data
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível enviar o arquivo.'
            );
        }
    }

    public static function uploadMany(array $files, $directory, array $options = array())
    {
        $results = array();
        $successCount = 0;
        $errorCount = 0;

        try {
            if (empty($files)) {
                throw new InvalidArgumentException(
                    'Nenhum arquivo foi informado.'
                );
            }

            foreach ($files as $index => $file) {
                if (!is_array($file)) {
                    $results[] = self::result(
                        false,
                        'Estrutura de arquivo inválida.',
                        array(
                            'index' => $index
                        )
                    );

                    $errorCount++;

                    continue;
                }

                $result = self::upload(
                    $file,
                    $directory,
                    null,
                    $options
                );

                $result['index'] = $index;

                $results[] = $result;

                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
            }

            return self::result(
                $errorCount === 0,
                $errorCount === 0
                    ? 'Todos os arquivos foram enviados com sucesso.'
                    : 'Alguns arquivos não puderam ser enviados.',
                array(
                    'total' => count($files),
                    'success_count' => $successCount,
                    'error_count' => $errorCount,
                    'files' => $results
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível enviar os arquivos.'
            );
        }
    }

    public static function delete($pathOrRelativePath, array $options = array())
    {
        try {
            $baseDirectory = isset($options['base_directory'])
                ? $options['base_directory']
                : null;

            $filePath = self::resolveExistingFile(
                $pathOrRelativePath,
                $baseDirectory
            );

            $fileData = self::fileData(
                $filePath,
                array(),
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

    public static function exists($pathOrRelativePath, array $options = array())
    {
        try {
            $baseDirectory = isset($options['base_directory'])
                ? $options['base_directory']
                : null;

            self::resolveExistingFile(
                $pathOrRelativePath,
                $baseDirectory
            );

            return true;
        } catch (Throwable $error) {
            return false;
        }
    }

    public static function info($pathOrRelativePath, array $options = array())
    {
        try {
            $baseDirectory = isset($options['base_directory'])
                ? $options['base_directory']
                : null;

            $filePath = self::resolveExistingFile(
                $pathOrRelativePath,
                $baseDirectory
            );

            return self::result(
                true,
                'Informações do arquivo carregadas com sucesso.',
                self::fileData(
                    $filePath,
                    array(),
                    $baseDirectory
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível carregar as informações do arquivo.'
            );
        }
    }

    public static function copy($sourcePath, $destinationDirectory, $destinationFileName = null, array $options = array())
    {
        try {
            $sourceBaseDirectory = isset($options['source_base_directory'])
                ? $options['source_base_directory']
                : (
                    isset($options['base_directory'])
                        ? $options['base_directory']
                        : null
                );

            $destinationBaseDirectory = isset($options['destination_base_directory'])
                ? $options['destination_base_directory']
                : (
                    isset($options['base_directory'])
                        ? $options['base_directory']
                        : null
                );

            $sourcePath = self::resolveExistingFile(
                $sourcePath,
                $sourceBaseDirectory
            );

            $sourceData = self::fileData(
                $sourcePath,
                array(),
                $sourceBaseDirectory
            );

            $destinationDirectory = self::resolveDirectory(
                $destinationDirectory,
                $destinationBaseDirectory
            );

            $extension = self::getExtension(
                $sourceData['file_name']
            );

            self::validateExtension(
                $extension,
                isset($options['allowed_extensions'])
                    ? (array) $options['allowed_extensions']
                    : self::$allowedExtensions
            );

            self::validateMime(
                $sourceData['mime'],
                isset($options['allowed_mimes'])
                    ? (array) $options['allowed_mimes']
                    : self::$allowedMimes
            );

            $destinationFileName = self::destinationFileName(
                $destinationFileName,
                $sourceData['file_name'],
                $extension,
                $options
            );

            $destinationFileName = self::ensureUniqueFileName(
                $destinationDirectory,
                $destinationFileName,
                !empty($options['overwrite'])
            );

            $destinationPath = $destinationDirectory .
                '/' .
                $destinationFileName;

            if (
                !empty($options['overwrite']) &&
                JFile::exists($destinationPath)
            ) {
                if (!JFile::delete($destinationPath)) {
                    throw new RuntimeException(
                        'Não foi possível substituir o arquivo de destino.'
                    );
                }
            }

            if (!JFile::copy($sourcePath, $destinationPath)) {
                throw new RuntimeException(
                    'Não foi possível copiar o arquivo.'
                );
            }

            @chmod(
                $destinationPath,
                self::$filePermission
            );

            return self::result(
                true,
                'Arquivo copiado com sucesso.',
                self::fileData(
                    $destinationPath,
                    array(
                        'source_path' => $sourcePath
                    ),
                    $destinationBaseDirectory
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

    public static function move($sourcePath, $destinationDirectory, $destinationFileName = null, array $options = array())
    {
        try {
            $sourceBaseDirectory = isset($options['source_base_directory'])
                ? $options['source_base_directory']
                : (
                    isset($options['base_directory'])
                        ? $options['base_directory']
                        : null
                );

            $destinationBaseDirectory = isset($options['destination_base_directory'])
                ? $options['destination_base_directory']
                : (
                    isset($options['base_directory'])
                        ? $options['base_directory']
                        : null
                );

            $sourcePath = self::resolveExistingFile(
                $sourcePath,
                $sourceBaseDirectory
            );

            $sourceData = self::fileData(
                $sourcePath,
                array(),
                $sourceBaseDirectory
            );

            $destinationDirectory = self::resolveDirectory(
                $destinationDirectory,
                $destinationBaseDirectory
            );

            $extension = self::getExtension(
                $sourceData['file_name']
            );

            self::validateExtension(
                $extension,
                isset($options['allowed_extensions'])
                    ? (array) $options['allowed_extensions']
                    : self::$allowedExtensions
            );

            self::validateMime(
                $sourceData['mime'],
                isset($options['allowed_mimes'])
                    ? (array) $options['allowed_mimes']
                    : self::$allowedMimes
            );

            $destinationFileName = self::destinationFileName(
                $destinationFileName,
                $sourceData['file_name'],
                $extension,
                $options
            );

            $destinationFileName = self::ensureUniqueFileName(
                $destinationDirectory,
                $destinationFileName,
                !empty($options['overwrite'])
            );

            $destinationPath = $destinationDirectory .
                '/' .
                $destinationFileName;

            if (
                !empty($options['overwrite']) &&
                JFile::exists($destinationPath)
            ) {
                if (!JFile::delete($destinationPath)) {
                    throw new RuntimeException(
                        'Não foi possível substituir o arquivo de destino.'
                    );
                }
            }

            if (!JFile::move($sourcePath, $destinationPath)) {
                throw new RuntimeException(
                    'Não foi possível mover o arquivo.'
                );
            }

            @chmod(
                $destinationPath,
                self::$filePermission
            );

            return self::result(
                true,
                'Arquivo movido com sucesso.',
                self::fileData(
                    $destinationPath,
                    array(
                        'source_path' => $sourcePath
                    ),
                    $destinationBaseDirectory
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

    public static function thumbnail($pathOrRelativePath, $destinationDirectory, $width, $height, array $options = array())
    {
        try {
            if (!function_exists('imagecreatetruecolor')) {
                throw new RuntimeException(
                    'A extensão GD do PHP é necessária para gerar miniaturas.'
                );
            }

            $sourceBaseDirectory = isset($options['source_base_directory'])
                ? $options['source_base_directory']
                : (
                    isset($options['base_directory'])
                        ? $options['base_directory']
                        : null
                );

            $destinationBaseDirectory = isset($options['destination_base_directory'])
                ? $options['destination_base_directory']
                : (
                    isset($options['base_directory'])
                        ? $options['base_directory']
                        : null
                );

            $sourcePath = self::resolveExistingFile(
                $pathOrRelativePath,
                $sourceBaseDirectory
            );

            $sourceData = self::fileData(
                $sourcePath,
                array(),
                $sourceBaseDirectory
            );

            if (!in_array($sourceData['mime'], self::$imageMimes, true)) {
                throw new RuntimeException(
                    'A miniatura só pode ser gerada para imagens.'
                );
            }

            $width = max(1, (int) $width);
            $height = max(1, (int) $height);

            $sourceImage = self::createImageResource(
                $sourcePath,
                $sourceData['mime']
            );

            if ($sourceImage === false) {
                throw new RuntimeException(
                    'Não foi possível abrir a imagem de origem.'
                );
            }

            $sourceWidth = (int) $sourceData['width'];
            $sourceHeight = (int) $sourceData['height'];

            $crop = !empty($options['crop']);

            $quality = isset($options['quality'])
                ? max(1, min(100, (int) $options['quality']))
                : 85;

            if ($crop) {
                $sourceRatio = $sourceWidth / $sourceHeight;
                $targetRatio = $width / $height;

                if ($sourceRatio > $targetRatio) {
                    $cropHeight = $sourceHeight;
                    $cropWidth = (int) round(
                        $sourceHeight * $targetRatio
                    );
                    $sourceX = (int) round(
                        ($sourceWidth - $cropWidth) / 2
                    );
                    $sourceY = 0;
                } else {
                    $cropWidth = $sourceWidth;
                    $cropHeight = (int) round(
                        $sourceWidth / $targetRatio
                    );
                    $sourceX = 0;
                    $sourceY = (int) round(
                        ($sourceHeight - $cropHeight) / 2
                    );
                }

                $targetWidth = $width;
                $targetHeight = $height;
            } else {
                $ratio = min(
                    $width / $sourceWidth,
                    $height / $sourceHeight
                );

                $targetWidth = max(
                    1,
                    (int) round($sourceWidth * $ratio)
                );

                $targetHeight = max(
                    1,
                    (int) round($sourceHeight * $ratio)
                );

                $sourceX = 0;
                $sourceY = 0;
                $cropWidth = $sourceWidth;
                $cropHeight = $sourceHeight;
            }

            $targetImage = imagecreatetruecolor(
                $targetWidth,
                $targetHeight
            );

            if ($targetImage === false) {
                imagedestroy($sourceImage);

                throw new RuntimeException(
                    'Não foi possível criar a imagem de destino.'
                );
            }

            if (
                $sourceData['mime'] === 'image/png' ||
                $sourceData['mime'] === 'image/gif' ||
                $sourceData['mime'] === 'image/webp'
            ) {
                imagealphablending($targetImage, false);
                imagesavealpha($targetImage, true);

                $transparent = imagecolorallocatealpha(
                    $targetImage,
                    0,
                    0,
                    0,
                    127
                );

                imagefilledrectangle(
                    $targetImage,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    $transparent
                );
            }

            if (!imagecopyresampled(
                $targetImage,
                $sourceImage,
                0,
                0,
                $sourceX,
                $sourceY,
                $targetWidth,
                $targetHeight,
                $cropWidth,
                $cropHeight
            )) {
                imagedestroy($sourceImage);
                imagedestroy($targetImage);

                throw new RuntimeException(
                    'Não foi possível redimensionar a imagem.'
                );
            }

            $destinationDirectory = self::resolveDirectory(
                $destinationDirectory,
                $destinationBaseDirectory
            );

            $extension = self::getExtension(
                $sourceData['file_name']
            );

            $prefix = isset($options['prefix'])
                ? $options['prefix']
                : 'thumb';

            $destinationName = isset($options['file_name'])
                ? self::normalizeFileName($options['file_name'])
                : self::generateRandomName(
                    $extension,
                    $prefix
                );

            $destinationExtension = self::getExtension(
                $destinationName
            );

            if ($destinationExtension !== $extension) {
                imagedestroy($sourceImage);
                imagedestroy($targetImage);

                throw new RuntimeException(
                    'A extensão da miniatura deve ser igual à imagem original.'
                );
            }

            $destinationName = self::ensureUniqueFileName(
                $destinationDirectory,
                $destinationName,
                !empty($options['overwrite'])
            );

            $destinationPath = $destinationDirectory .
                '/' .
                $destinationName;

            if (
                !empty($options['overwrite']) &&
                JFile::exists($destinationPath)
            ) {
                if (!JFile::delete($destinationPath)) {
                    imagedestroy($sourceImage);
                    imagedestroy($targetImage);

                    throw new RuntimeException(
                        'Não foi possível substituir a miniatura existente.'
                    );
                }
            }

            if (!self::saveImageResource(
                $targetImage,
                $destinationPath,
                $sourceData['mime'],
                $quality
            )) {
                imagedestroy($sourceImage);
                imagedestroy($targetImage);

                throw new RuntimeException(
                    'Não foi possível salvar a miniatura.'
                );
            }

            imagedestroy($sourceImage);
            imagedestroy($targetImage);

            @chmod(
                $destinationPath,
                self::$filePermission
            );

            return self::result(
                true,
                'Miniatura criada com sucesso.',
                self::fileData(
                    $destinationPath,
                    array(
                        'source_path' => $sourcePath,
                        'crop' => $crop
                    ),
                    $destinationBaseDirectory
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível criar a miniatura.'
            );
        }
    }

    public static function stream($pathOrRelativePath, $downloadName = null, array $options = array())
    {
        try {
            $baseDirectory = isset($options['base_directory'])
                ? $options['base_directory']
                : null;

            $filePath = self::resolveExistingFile(
                $pathOrRelativePath,
                $baseDirectory
            );

            $fileData = self::fileData(
                $filePath,
                array(),
                $baseDirectory
            );

            $downloadName = $downloadName !== null
                ? self::normalizeFileName($downloadName)
                : $fileData['file_name'];

            $inline = !empty($options['inline']);

            $disposition = $inline
                ? 'inline'
                : 'attachment';

            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: ' . $fileData['mime']);
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
                '[' . __METHOD__ . '] ' .
                $error->getMessage(),
                JLog::ERROR
            );

            if (self::$throwExceptions) {
                throw $error;
            }

            return false;
        }
    }

    public static function removeEmptyDirectories($directory, array $options = array())
    {
        try {
            $baseDirectory = isset($options['base_directory'])
                ? self::resolveBaseDirectory(
                    $options['base_directory']
                )
                : self::baseDirectory();

            $directory = trim((string) $directory);

            if ($directory === '') {
                throw new InvalidArgumentException(
                    'Diretório inválido.'
                );
            }

            $directory = str_replace(
                '\\',
                '/',
                $directory
            );

            if (strpos($directory, '/') === 0) {
                $directory = self::normalizePath($directory);
            } else {
                $directory = self::normalizePath(
                    $baseDirectory .
                    '/' .
                    trim($directory, '/')
                );
            }

            if (!self::pathInsideBase($directory, $baseDirectory)) {
                throw new RuntimeException(
                    'Diretório não autorizado.'
                );
            }

            $stopAtDirectory = isset($options['stop_at_directory'])
                ? self::normalizePath(
                    $options['stop_at_directory']
                )
                : $baseDirectory;

            if (!self::pathInsideBase($stopAtDirectory, $baseDirectory)) {
                throw new RuntimeException(
                    'Diretório de parada não autorizado.'
                );
            }

            $removed = 0;

            while (
                $directory !== '' &&
                $directory !== $stopAtDirectory &&
                self::pathInsideBase(
                    $directory,
                    $baseDirectory
                )
            ) {
                if (!JFolder::exists($directory)) {
                    $directory = self::normalizePath(
                        dirname($directory)
                    );

                    continue;
                }

                $files = JFolder::files(
                    $directory,
                    '.',
                    false,
                    true,
                    array(
                        '.svn',
                        'CVS',
                        '.DS_Store',
                        '__MACOSX'
                    ),
                    array()
                );

                $folders = JFolder::folders(
                    $directory,
                    '.',
                    false,
                    true,
                    array(
                        '.svn',
                        'CVS',
                        '.DS_Store',
                        '__MACOSX'
                    ),
                    array()
                );

                if (!empty($files) || !empty($folders)) {
                    break;
                }

                if (!JFolder::delete($directory)) {
                    break;
                }

                $removed++;

                $directory = self::normalizePath(
                    dirname($directory)
                );
            }

            return self::result(
                true,
                'Limpeza de diretórios concluída.',
                array(
                    'removed_directories' => $removed
                )
            );
        } catch (Throwable $error) {
            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível limpar os diretórios vazios.'
            );
        }
    }
}