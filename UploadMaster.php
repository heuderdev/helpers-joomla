<?php

defined('_JEXEC') or die;

jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');
jimport('joomla.filesystem.path');

/*
 * Erro com mensagem segura para mostrar ao usuário ("A extensão do
 * arquivo não é permitida", "O arquivo excede 5 MB"). Outros erros (do
 * PHP, do sistema de arquivos) ficam só no log.
 */
class UploadMasterException extends RuntimeException
{
}

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

    // MIMEs exigidos além do mapa de compatibilidade (setAllowedMimes / opção allowed_mimes).
    private static $customAllowedMimes = null;

    // index.html vazio em cada pasta criada: impede listar o conteúdo pelo navegador.
    private static $createIndexFiles = true;

    /*
     * Tipos reais (MIME) aceitos para cada extensão. Inclui as variações
     * que a biblioteca de detecção (libmagic) devolve em servidores
     * diferentes para o mesmo formato. Amplie com setMimeMap().
     */
    private static $mimeMap = array(
        'jpg' => array('image/jpeg', 'image/pjpeg'),
        'jpeg' => array('image/jpeg', 'image/pjpeg'),
        'png' => array('image/png'),
        'gif' => array('image/gif'),
        'webp' => array('image/webp'),
        'bmp' => array('image/bmp', 'image/x-ms-bmp'),
        'tif' => array('image/tiff'),
        'tiff' => array('image/tiff'),
        'avif' => array('image/avif'),
        'heic' => array('image/heic', 'image/heif'),
        'heif' => array('image/heif', 'image/heic'),
        'svg' => array('image/svg+xml', 'image/svg'),
        'pdf' => array('application/pdf', 'application/x-pdf'),
        'txt' => array('text/*'),
        'csv' => array('text/*', 'application/csv', 'application/vnd.ms-excel'),
        'tsv' => array('text/*'),
        'json' => array('application/json', 'text/*'),
        'rtf' => array('text/rtf', 'application/rtf'),
        'doc' => array('application/msword', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'),
        'xls' => array('application/vnd.ms-excel', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'),
        'ppt' => array('application/vnd.ms-powerpoint', 'application/vnd.ms-office', 'application/cdfv2', 'application/x-ole-storage'),
        'docx' => array('application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'ooxml:word/'),
        'xlsx' => array('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ooxml:xl/'),
        'pptx' => array('application/vnd.openxmlformats-officedocument.presentationml.presentation', 'ooxml:ppt/'),
        'odt' => array('application/vnd.oasis.opendocument.text', 'ooxml:content.xml'),
        'ods' => array('application/vnd.oasis.opendocument.spreadsheet', 'ooxml:content.xml'),
        'odp' => array('application/vnd.oasis.opendocument.presentation', 'ooxml:content.xml'),
        'zip' => array('application/zip', 'application/x-zip-compressed', 'application/x-zip'),
        '7z' => array('application/x-7z-compressed'),
        'rar' => array('application/x-rar', 'application/vnd.rar', 'application/x-rar-compressed'),
        'gz' => array('application/gzip', 'application/x-gzip'),
        'tar' => array('application/x-tar'),
        'mp3' => array('audio/mpeg', 'audio/mp3', 'audio/x-mpeg'),
        'wav' => array('audio/wav', 'audio/x-wav', 'audio/wave', 'audio/vnd.wave'),
        'ogg' => array('audio/ogg', 'video/ogg', 'application/ogg'),
        'm4a' => array('audio/mp4', 'audio/x-m4a', 'video/mp4'),
        'mp4' => array('video/mp4'),
        'webm' => array('video/webm', 'audio/webm'),
        'mov' => array('video/quicktime')
    );

    // Conteúdo que nunca é aceito em arquivos de texto, qualquer que seja a extensão.
    private static $forbiddenTextMimes = array(
        'text/html',
        'text/x-php',
        'text/x-shellscript',
        'text/x-perl',
        'text/x-python',
        'text/javascript',
        'text/x-msdos-batch'
    );

    /*
     * Arquivos que as operações sobre arquivos existentes (stream,
     * delete, move, copy, info) nunca tocam, mesmo dentro da base.
     */
    private static $protectedFileNames = array(
        'configuration.php',
        '.htaccess',
        '.htpasswd',
        'web.config',
        '.user.ini',
        'php.ini',
        '.env'
    );

    // Tipos que podem ser exibidos no navegador (inline) com segurança.
    private static $inlineSafeMimes = array(
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
        'image/bmp',
        'application/pdf',
        'text/plain',
        'video/',
        'audio/'
    );

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

        if ($error instanceof UploadMasterException) {
            $publicMessage .= ' ' . $error->getMessage();
            $errors['_general'] = array($error->getMessage());
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
            throw new UploadMasterException('Não foi possível localizar a raiz do site.');
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
            throw new UploadMasterException('Diretório base inválido.');
        }

        if (strpos($baseDirectory, '/') !== 0) {
            $baseDirectory = $siteRoot . '/' . trim($baseDirectory, '/');
        }

        $baseDirectory = self::normalizePath($baseDirectory);

        if (
            $baseDirectory !== $siteRoot &&
            strpos($baseDirectory, $siteRoot . '/') !== 0
        ) {
            throw new UploadMasterException(
                'O diretório base deve estar dentro de JPATH_SITE.'
            );
        }

        if (!JFolder::exists($baseDirectory)) {
            if (!self::createDirectory($baseDirectory)) {
                throw new UploadMasterException(
                    'Não foi possível criar o diretório base informado.'
                );
            }
        }

        $realBaseDirectory = realpath($baseDirectory);

        if ($realBaseDirectory === false) {
            throw new UploadMasterException(
                'Não foi possível validar o diretório base informado.'
            );
        }

        $realBaseDirectory = self::normalizePath($realBaseDirectory);

        if (
            $realBaseDirectory !== $siteRoot &&
            strpos($realBaseDirectory, $siteRoot . '/') !== 0
        ) {
            throw new UploadMasterException(
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
            throw new UploadMasterException(
                'Diretório de destino inválido.'
            );
        }

        $parts = explode('/', $directory);
        $safeParts = array();

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part === '' || $part === '.' || $part === '..') {
                throw new UploadMasterException(
                    'Diretório de destino inválido.'
                );
            }

            $safePart = JFolder::makeSafe($part);

            if ($safePart === '') {
                throw new UploadMasterException(
                    'Diretório de destino inválido.'
                );
            }

            $safeParts[] = $safePart;
        }

        return implode('/', $safeParts);
    }

    /*
     * Cria a pasta (e as intermediárias) com um index.html vazio em cada
     * uma, como o Joomla faz: o servidor não lista o conteúdo da pasta.
     */
    private static function createDirectory($directory)
    {
        $directory = self::normalizePath($directory);
        $missing = array();

        for ($current = $directory; $current !== '' && $current !== '/' && !is_dir($current); $current = dirname($current)) {
            $missing[] = $current;
        }

        if (!JFolder::create($directory, self::$directoryPermission)) {
            return false;
        }

        if (self::$createIndexFiles) {
            foreach ($missing as $created) {
                if (!is_file($created . '/index.html')) {
                    @file_put_contents($created . '/index.html', '<!DOCTYPE html><title></title>');
                }
            }
        }

        return true;
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
            throw new UploadMasterException(
                'O diretório de destino não está dentro da base autorizada.'
            );
        }

        if (!JFolder::exists($fullDirectory)) {
            if (!self::createDirectory($fullDirectory)) {
                throw new UploadMasterException(
                    'Não foi possível criar o diretório de destino.'
                );
            }
        }

        $realDirectory = realpath($fullDirectory);

        if ($realDirectory === false) {
            throw new UploadMasterException(
                'Não foi possível validar o diretório de destino.'
            );
        }

        $realDirectory = self::normalizePath($realDirectory);

        if (!self::pathInsideBase($realDirectory, $baseDirectory)) {
            throw new UploadMasterException(
                'O diretório de destino validado não está dentro da base autorizada.'
            );
        }

        return $realDirectory;
    }

    private static function removeAccents($value)
    {
        $map = array(
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N', 'º' => 'o', 'ª' => 'a'
        );

        return strtr((string) $value, $map);
    }

    /*
     * Nome seguro para gravar: sem acentos (o mesmo resultado no Joomla
     * 3 e 4), espaços viram "_", extensão em minúsculas, até 150
     * caracteres. Uma extensão perigosa no meio do nome
     * ("foto.php.jpg") perde o ponto ("foto_php.jpg"): alguns servidores
     * executariam o arquivo como PHP.
     */
    private static function normalizeFileName($fileName)
    {
        $fileName = basename(
            str_replace('\\', '/', (string) $fileName)
        );

        $fileName = self::removeAccents($fileName);
        $fileName = preg_replace('/\s+/', '_', trim($fileName));
        $fileName = JFile::makeSafe($fileName);
        $fileName = ltrim((string) $fileName, '.');

        $parts = explode('.', $fileName);

        if (count($parts) > 1) {
            $extension = strtolower(array_pop($parts));
            $name = '';

            foreach ($parts as $index => $part) {
                $separator = $index === 0
                    ? ''
                    : (in_array(strtolower($part), self::$dangerousExtensions, true) ? '_' : '.');

                $name .= $separator . $part;
            }

            $name = substr($name, 0, 150);
            $fileName = $name . '.' . $extension;
        }

        if ($fileName === '') {
            throw new UploadMasterException(
                'Nome de arquivo inválido.'
            );
        }

        if (
            $fileName === '' ||
            strpos($fileName, "\0") !== false ||
            strpos($fileName, '..') !== false
        ) {
            throw new UploadMasterException(
                'Nome de arquivo inválido.'
            );
        }

        return $fileName;
    }

    /*
     * Nome para o download: mantém acentos e espaços (vão no
     * filename* em UTF-8) e remove o que quebraria o cabeçalho.
     */
    private static function downloadFileName($fileName)
    {
        $fileName = basename(str_replace('\\', '/', (string) $fileName));
        $fileName = preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '', $fileName);
        $fileName = trim((string) $fileName, " .");

        if ($fileName === '') {
            throw new UploadMasterException('Nome de arquivo inválido.');
        }

        return function_exists('mb_substr') ? mb_substr($fileName, 0, 150, 'UTF-8') : substr($fileName, 0, 150);
    }

    private static function getExtension($fileName)
    {
        $extension = strtolower(
            trim((string) JFile::getExt($fileName))
        );

        if ($extension === '') {
            throw new UploadMasterException(
                'O arquivo não possui uma extensão válida.'
            );
        }

        return $extension;
    }

    private static function validateExtension($extension, array $allowedExtensions = array())
    {
        $extension = strtolower(trim((string) $extension));

        if (in_array($extension, self::$dangerousExtensions, true)) {
            throw new UploadMasterException(
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
            throw new UploadMasterException(
                'A extensão do arquivo não é permitida.'
            );
        }

        return true;
    }

    private static function getMimeType($path)
    {
        if (!function_exists('finfo_open')) {
            throw new UploadMasterException(
                'A extensão Fileinfo do PHP é obrigatória para validar uploads.'
            );
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            throw new UploadMasterException(
                'Não foi possível iniciar a validação MIME do arquivo.'
            );
        }

        $mime = finfo_file($finfo, $path);

        // Desde o PHP 8.1 o finfo é um objeto liberado sozinho.
        if (PHP_VERSION_ID < 80100) {
            finfo_close($finfo);
        }

        if ($mime === false || trim((string) $mime) === '') {
            throw new UploadMasterException(
                'Não foi possível identificar o tipo real do arquivo.'
            );
        }

        return strtolower(trim((string) $mime));
    }

    /*
     * Lista extra de MIMEs, além do mapa por extensão: só é aplicada se
     * definida com setAllowedMimes() ou pela opção 'allowed_mimes'.
     */
    private static function validateMime($mime, array $allowedMimes = array())
    {
        $mime = strtolower(trim((string) $mime));

        if (empty($allowedMimes)) {
            if (self::$customAllowedMimes === null) {
                return true;
            }

            $allowedMimes = self::$customAllowedMimes;
        }

        $allowedMimes = array_map(
            function ($item) {
                return strtolower(trim((string) $item));
            },
            $allowedMimes
        );

        if (!in_array($mime, $allowedMimes, true)) {
            throw new UploadMasterException(
                'O tipo real do arquivo não é permitido.'
            );
        }

        return true;
    }

    /*
     * Arquivos do Office modernos e do LibreOffice são ZIPs. Muitos
     * servidores os identificam só como application/zip ou
     * application/octet-stream: nesse caso, confere a estrutura interna
     * (a pasta word/ num .docx, xl/ num .xlsx...).
     */
    private static function zipContains($path, $entry)
    {
        $handle = @fopen($path, 'rb');
        $signature = $handle ? fread($handle, 4) : '';

        if ($handle) {
            fclose($handle);
        }

        if ($signature !== "PK\x03\x04") {
            return false;
        }

        if (!class_exists('ZipArchive')) {
            return true;
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            return false;
        }

        $found = false;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if ($name === $entry || strpos($name, $entry) === 0) {
                $found = true;
                break;
            }
        }

        $zip->close();

        return $found;
    }

    /*
     * O tipo real do conteúdo precisa ser compatível com a extensão:
     * um .jpg tem de ser JPEG, não PDF nem PHP.
     */
    private static function validateExtensionAndMimeCompatibility($extension, $mime, $path = null)
    {
        if (!isset(self::$mimeMap[$extension])) {
            throw new UploadMasterException(
                'Não há regra de tipo configurada para a extensão .' . $extension . '. Use UploadMaster::setMimeMap().'
            );
        }

        foreach (self::$mimeMap[$extension] as $accepted) {
            if ($accepted === $mime) {
                return true;
            }

            if ($accepted === 'text/*' && strpos($mime, 'text/') === 0 && !in_array($mime, self::$forbiddenTextMimes, true)) {
                return true;
            }

            if (
                strpos($accepted, 'ooxml:') === 0 &&
                $path !== null &&
                in_array($mime, array('application/zip', 'application/x-zip-compressed', 'application/octet-stream'), true) &&
                self::zipContains($path, substr($accepted, 6))
            ) {
                return true;
            }
        }

        throw new UploadMasterException(
            'A extensão do arquivo não corresponde ao seu tipo real.'
        );
    }

    private static function validateUploadedFile(array $file)
    {
        if (empty($file)) {
            throw new UploadMasterException(
                'Nenhum arquivo foi informado.'
            );
        }

        if (!isset($file['error'])) {
            throw new UploadMasterException(
                'Estrutura de upload inválida.'
            );
        }

        $uploadError = (int) $file['error'];

        if ($uploadError !== UPLOAD_ERR_OK) {
            $messages = array(
                UPLOAD_ERR_INI_SIZE => 'O arquivo excede o limite de ' . self::formatBytes(self::maxUploadSize(PHP_INT_MAX)) . ' definido no servidor.',
                UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite permitido pelo formulário.',
                UPLOAD_ERR_PARTIAL => 'O upload foi enviado parcialmente.',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi enviado.',
                UPLOAD_ERR_NO_TMP_DIR => 'Diretório temporário do servidor não encontrado.',
                UPLOAD_ERR_CANT_WRITE => 'Não foi possível gravar o arquivo temporário no servidor.',
                UPLOAD_ERR_EXTENSION => 'O upload foi bloqueado por uma extensão do PHP.'
            );

            throw new UploadMasterException(
                isset($messages[$uploadError])
                    ? $messages[$uploadError]
                    : 'Falha desconhecida no upload. Código: ' . $uploadError
            );
        }

        if (
            empty($file['tmp_name']) ||
            !is_uploaded_file($file['tmp_name'])
        ) {
            throw new UploadMasterException(
                'O arquivo temporário de upload é inválido.'
            );
        }

        if (empty($file['name'])) {
            throw new UploadMasterException(
                'O nome original do arquivo não foi informado.'
            );
        }

        if (!isset($file['size']) || (int) $file['size'] <= 0) {
            throw new UploadMasterException(
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
            throw new UploadMasterException(
                'O arquivo enviado está vazio.'
            );
        }

        if ($maxSize <= 0) {
            throw new UploadMasterException(
                'O limite de tamanho do arquivo é inválido.'
            );
        }

        if ($size > $maxSize) {
            throw new UploadMasterException(
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
            throw new UploadMasterException(
                'O arquivo informado não é uma imagem válida.'
            );
        }

        $width = isset($imageInfo[0])
            ? (int) $imageInfo[0]
            : 0;

        $height = isset($imageInfo[1])
            ? (int) $imageInfo[1]
            : 0;

        $pixels = $width * $height;

        if ($width <= 0 || $height <= 0) {
            throw new UploadMasterException(
                'A imagem possui dimensões inválidas.'
            );
        }

        if (
            $width > self::$imageMaxWidth ||
            $height > self::$imageMaxHeight
        ) {
            throw new UploadMasterException(
                'A imagem excede as dimensões máximas permitidas.'
            );
        }

        if ($pixels > self::$imageMaxPixels) {
            throw new UploadMasterException(
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
                throw new UploadMasterException(
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
                throw new UploadMasterException(
                    'A extensão do nome de destino deve ser igual à extensão do arquivo enviado.'
                );
            }

            self::validateExtension(
                $destinationExtension,
                isset($options['allowed_extensions']) ? (array) $options['allowed_extensions'] : array()
            );

            return $fileName;
        }

        if ($keepOriginalName) {
            $originalName = self::normalizeFileName($originalName);

            $originalExtension = self::getExtension($originalName);

            if ($originalExtension !== $extension) {
                throw new UploadMasterException(
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
            throw new UploadMasterException(
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
            throw new UploadMasterException(
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

                $data['height'] = isset($image[1])
                    ? (int) $image[1]
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
            throw new UploadMasterException(
                'Caminho do arquivo inválido.'
            );
        }

        if (!JFile::exists($absolutePath)) {
            throw new UploadMasterException(
                'Arquivo não encontrado.'
            );
        }

        $realPath = realpath($absolutePath);

        if ($realPath === false) {
            throw new UploadMasterException(
                'Não foi possível validar o arquivo.'
            );
        }

        $realPath = self::normalizePath($realPath);

        if (!self::pathInsideBase($realPath, $baseDirectory)) {
            throw new UploadMasterException(
                'O arquivo está fora do diretório base autorizado.'
            );
        }

        self::assertNotProtected($realPath);

        return $realPath;
    }

    /*
     * Nunca entrega, apaga ou move arquivos de configuração, ocultos
     * ou executáveis, mesmo dentro da base: com a base padrão (a raiz
     * do site), um caminho vindo da requisição alcançaria o
     * configuration.php, que tem a senha do banco.
     */
    private static function assertNotProtected($path)
    {
        $fileName = strtolower(basename($path));
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (
            in_array($fileName, self::$protectedFileNames, true) ||
            $fileName[0] === '.' ||
            in_array($extension, self::$dangerousExtensions, true)
        ) {
            throw new UploadMasterException(
                'Este arquivo não pode ser acessado por segurança.'
            );
        }
    }

    private static function resolveExistingFile($pathOrRelativePath, $baseDirectory = null)
    {
        $pathOrRelativePath = trim((string) $pathOrRelativePath);

        if ($pathOrRelativePath === '') {
            throw new UploadMasterException(
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
            throw new UploadMasterException(
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
                    throw new UploadMasterException(
                        'Suporte WEBP não disponível na instalação PHP.'
                    );
                }

                return imagecreatefromwebp($path);
        }

        throw new UploadMasterException(
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
                    throw new UploadMasterException(
                        'Suporte WEBP não disponível na instalação PHP.'
                    );
                }

                return imagewebp(
                    $resource,
                    $destination,
                    $quality
                );
        }

        throw new UploadMasterException(
            'Tipo de imagem não suportado para salvamento.'
        );
    }

    // imagedestroy() não faz nada desde o PHP 8.0 e está obsoleta no 8.5.
    private static function destroyImage($image)
    {
        if (PHP_VERSION_ID < 80000 && is_resource($image)) {
            imagedestroy($image);
        }
    }

    /*
     * Abrir uma imagem no GD usa cerca de 5 bytes por pixel. Verifica
     * antes, para não derrubar o PHP por falta de memória.
     */
    private static function assertImageMemory($width, $height)
    {
        $limit = self::iniBytes(ini_get('memory_limit'));

        if ($limit <= 0) {
            return;
        }

        $needed = (int) ($width * $height * 5 * 1.8) + 4194304;
        $available = $limit - memory_get_usage(true);

        if ($needed > $available) {
            throw new UploadMasterException(
                'A imagem é grande demais para ser processada neste servidor.'
            );
        }
    }

    /*
     * Fotos de celular são gravadas "deitadas" com uma marcação EXIF de
     * orientação. Aplica a rotação para a imagem ficar em pé.
     */
    private static function exifOrientation($path, $mime)
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);

        return isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;
    }

    private static function applyOrientation($image, $orientation)
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;

            case 3:
                return imagerotate($image, 180, 0);

            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                return $image;

            case 5:
                imageflip($image, IMG_FLIP_VERTICAL);
                return imagerotate($image, -90, 0);

            case 6:
                return imagerotate($image, -90, 0);

            case 7:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return imagerotate($image, -90, 0);

            case 8:
                return imagerotate($image, 90, 0);
        }

        return $image;
    }

    private static function prepareAlpha($image, $mime)
    {
        if (in_array($mime, array('image/png', 'image/gif', 'image/webp'), true)) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
    }

    /*
     * Regrava a imagem pelo GD: corrige a orientação, remove metadados
     * (EXIF, inclusive a localização GPS de fotos de celular) e
     * qualquer conteúdo escondido no arquivo. GIF não é regravado, para
     * não perder a animação.
     */
    private static function reencodeImage($path, $mime, $quality)
    {
        if (!in_array($mime, array('image/jpeg', 'image/png', 'image/webp'), true) || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $info = @getimagesize($path);

        if ($info === false) {
            throw new UploadMasterException('O arquivo informado não é uma imagem válida.');
        }

        self::assertImageMemory($info[0], $info[1]);

        $orientation = self::exifOrientation($path, $mime);
        $image = self::createImageResource($path, $mime);

        if ($image === false) {
            throw new UploadMasterException('Não foi possível processar a imagem.');
        }

        $image = self::applyOrientation($image, $orientation);
        self::prepareAlpha($image, $mime);

        $saved = self::saveImageResource($image, $path, $mime, $quality);
        $result = array('width' => imagesx($image), 'height' => imagesy($image), 'pixels' => imagesx($image) * imagesy($image));

        self::destroyImage($image);

        if (!$saved) {
            throw new UploadMasterException('Não foi possível processar a imagem.');
        }

        return $result;
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
            throw new UploadMasterException(
                'O tamanho máximo de arquivo deve ser maior que zero.'
            );
        }

        self::$maxFileSize = $bytes;
    }

    public static function setAllowedExtensions(array $extensions)
    {
        if (empty($extensions)) {
            throw new UploadMasterException(
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
            throw new UploadMasterException(
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

        self::$customAllowedMimes = self::$allowedMimes;
    }

    /*
     * Define ou amplia os tipos reais aceitos para extensões:
     *
     * UploadMaster::setMimeMap(array('mp3' => array('audio/mpeg')));
     *
     * Necessário ao liberar uma extensão que não está no mapa padrão.
     * Para .docx, .xlsx etc., 'ooxml:pasta/' aceita o ZIP que contém a pasta.
     */
    public static function setMimeMap(array $map, $replace = false)
    {
        foreach ($map as $extension => $mimes) {
            $extension = strtolower(trim((string) $extension, ". \t"));
            $mimes = array_values(array_filter(array_map(function ($mime) {
                return strtolower(trim((string) $mime));
            }, (array) $mimes)));

            if ($extension === '' || empty($mimes)) {
                continue;
            }

            self::$mimeMap[$extension] = $replace || !isset(self::$mimeMap[$extension])
                ? $mimes
                : array_values(array_unique(array_merge(self::$mimeMap[$extension], $mimes)));
        }
    }

    /*
     * Cria (padrão) ou não um index.html vazio nas pastas novas.
     */
    public static function setCreateIndexFiles($create)
    {
        self::$createIndexFiles = (bool) $create;
    }

    /*
     * Maior arquivo que pode ser enviado de fato: o menor entre o limite
     * do helper e os limites do PHP (upload_max_filesize e
     * post_max_size). Use para informar o usuário antes do envio.
     */
    public static function maxUploadSize($maxSize = null)
    {
        $limits = array($maxSize !== null ? (int) $maxSize : self::$maxFileSize);

        foreach (array('upload_max_filesize', 'post_max_size') as $directive) {
            $bytes = self::iniBytes(ini_get($directive));

            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    private static function iniBytes($value)
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '-1' || $value === '0') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        switch ($unit) {
            case 'g':
                return (int) ($number * 1073741824);
            case 'm':
                return (int) ($number * 1048576);
            case 'k':
                return (int) ($number * 1024);
        }

        return (int) $number;
    }

    /*
     * Só os dados que podem ir para o navegador: sem o caminho absoluto
     * nem o diretório base do servidor.
     */
    public static function publicData(array $data)
    {
        $keys = array('file_name', 'original_name', 'relative_path', 'url', 'extension', 'mime', 'size', 'size_formatted', 'width', 'height');

        return array_intersect_key($data, array_flip($keys));
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
        $app = JFactory::getApplication();
        $input = method_exists($app, 'getInput') ? $app->getInput() : $app->input;

        $files = $input->files->get($fieldName, null, 'array');

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
                : array();

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
                $mime,
                $file['tmp_name']
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
                    throw new UploadMasterException(
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
                throw new UploadMasterException(
                    'Não foi possível salvar o arquivo enviado.'
                );
            }

            @chmod(
                $destinationPath,
                self::$filePermission
            );

            if (!empty($options['reencode_images']) && !empty($imageData)) {
                try {
                    $reencoded = self::reencodeImage(
                        $destinationPath,
                        $mime,
                        isset($options['quality']) ? max(1, min(100, (int) $options['quality'])) : 90
                    );
                } catch (Throwable $error) {
                    @unlink($destinationPath);

                    throw $error;
                }

                if ($reencoded !== null) {
                    $imageData = $reencoded;
                }
            }

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
                throw new UploadMasterException(
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
                throw new UploadMasterException(
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
                    : array()
            );

            self::validateExtensionAndMimeCompatibility(
                $extension,
                $sourceData['mime'],
                $sourcePath
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
                    throw new UploadMasterException(
                        'Não foi possível substituir o arquivo de destino.'
                    );
                }
            }

            if (!JFile::copy($sourcePath, $destinationPath)) {
                throw new UploadMasterException(
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
                    : array()
            );

            self::validateExtensionAndMimeCompatibility(
                $extension,
                $sourceData['mime'],
                $sourcePath
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
                    throw new UploadMasterException(
                        'Não foi possível substituir o arquivo de destino.'
                    );
                }
            }

            if (!JFile::move($sourcePath, $destinationPath)) {
                throw new UploadMasterException(
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

    /*
     * Miniatura de uma imagem existente.
     *
     * Opções: crop (recorta para o tamanho exato), upscale (aumenta
     * imagens menores que a caixa; padrão false), quality (1-100),
     * format ('jpeg', 'png', 'webp': converte o formato), file_name,
     * prefix, overwrite, base_directory, source_base_directory,
     * destination_base_directory.
     */
    public static function thumbnail($pathOrRelativePath, $destinationDirectory, $width, $height, array $options = array())
    {
        $sourceImage = null;
        $targetImage = null;

        try {
            if (!function_exists('imagecreatetruecolor')) {
                throw new UploadMasterException(
                    'A extensão GD do PHP é necessária para gerar miniaturas.'
                );
            }

            $sourceBaseDirectory = isset($options['source_base_directory'])
                ? $options['source_base_directory']
                : (isset($options['base_directory']) ? $options['base_directory'] : null);

            $destinationBaseDirectory = isset($options['destination_base_directory'])
                ? $options['destination_base_directory']
                : (isset($options['base_directory']) ? $options['base_directory'] : null);

            $sourcePath = self::resolveExistingFile($pathOrRelativePath, $sourceBaseDirectory);
            $sourceMime = self::getMimeType($sourcePath);

            if (!in_array($sourceMime, self::$imageMimes, true)) {
                throw new UploadMasterException(
                    'A miniatura só pode ser gerada para imagens.'
                );
            }

            $info = @getimagesize($sourcePath);

            if ($info === false || empty($info[0]) || empty($info[1])) {
                throw new UploadMasterException(
                    'Não foi possível ler as dimensões da imagem.'
                );
            }

            if ($info[0] * $info[1] > self::$imageMaxPixels) {
                throw new UploadMasterException(
                    'A imagem excede a quantidade máxima de pixels permitida.'
                );
            }

            self::assertImageMemory($info[0], $info[1]);

            $width = max(1, (int) $width);
            $height = max(1, (int) $height);
            $crop = !empty($options['crop']);
            $upscale = !empty($options['upscale']);

            $quality = isset($options['quality'])
                ? max(1, min(100, (int) $options['quality']))
                : 85;

            $formats = array('jpeg' => 'image/jpeg', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif');
            $outputMime = $sourceMime;

            if (!empty($options['format'])) {
                $format = strtolower((string) $options['format']);

                if (!isset($formats[$format])) {
                    throw new UploadMasterException('Formato de miniatura inválido: use jpeg, png, webp ou gif.');
                }

                $outputMime = $formats[$format];
            }

            $sourceImage = self::createImageResource($sourcePath, $sourceMime);

            if ($sourceImage === false) {
                throw new UploadMasterException(
                    'Não foi possível abrir a imagem de origem.'
                );
            }

            $sourceImage = self::applyOrientation(
                $sourceImage,
                self::exifOrientation($sourcePath, $sourceMime)
            );

            $sourceWidth = imagesx($sourceImage);
            $sourceHeight = imagesy($sourceImage);

            if ($crop) {
                if (!$upscale) {
                    // Sem aumentar: a caixa encolhe, mantendo a proporção pedida.
                    $scale = min(1, $sourceWidth / $width, $sourceHeight / $height);
                    $width = max(1, (int) round($width * $scale));
                    $height = max(1, (int) round($height * $scale));
                }

                $sourceRatio = $sourceWidth / $sourceHeight;
                $targetRatio = $width / $height;

                if ($sourceRatio > $targetRatio) {
                    $cropHeight = $sourceHeight;
                    $cropWidth = (int) round($sourceHeight * $targetRatio);
                    $sourceX = (int) round(($sourceWidth - $cropWidth) / 2);
                    $sourceY = 0;
                } else {
                    $cropWidth = $sourceWidth;
                    $cropHeight = (int) round($sourceWidth / $targetRatio);
                    $sourceX = 0;
                    $sourceY = (int) round(($sourceHeight - $cropHeight) / 2);
                }

                $targetWidth = $width;
                $targetHeight = $height;
            } else {
                $ratio = min($width / $sourceWidth, $height / $sourceHeight);

                if (!$upscale) {
                    $ratio = min(1, $ratio);
                }

                $targetWidth = max(1, (int) round($sourceWidth * $ratio));
                $targetHeight = max(1, (int) round($sourceHeight * $ratio));
                $sourceX = 0;
                $sourceY = 0;
                $cropWidth = $sourceWidth;
                $cropHeight = $sourceHeight;
            }

            $targetImage = imagecreatetruecolor($targetWidth, $targetHeight);

            if ($targetImage === false) {
                throw new UploadMasterException(
                    'Não foi possível criar a imagem de destino.'
                );
            }

            if ($outputMime === 'image/jpeg') {
                // JPEG não tem transparência: fundo branco em vez de preto.
                imagefill($targetImage, 0, 0, imagecolorallocate($targetImage, 255, 255, 255));
            } else {
                imagealphablending($targetImage, false);
                imagesavealpha($targetImage, true);
                imagefilledrectangle(
                    $targetImage,
                    0,
                    0,
                    $targetWidth,
                    $targetHeight,
                    imagecolorallocatealpha($targetImage, 0, 0, 0, 127)
                );
            }

            if ($outputMime === 'image/jpeg') {
                imagealphablending($targetImage, true);
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
                throw new UploadMasterException(
                    'Não foi possível redimensionar a imagem.'
                );
            }

            $destinationDirectory = self::resolveDirectory($destinationDirectory, $destinationBaseDirectory);

            $extensions = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif');
            $extension = $outputMime === $sourceMime
                ? self::getExtension(basename($sourcePath))
                : $extensions[$outputMime];

            $destinationName = isset($options['file_name'])
                ? self::normalizeFileName($options['file_name'])
                : self::generateRandomName($extension, isset($options['prefix']) ? $options['prefix'] : 'thumb');

            $destinationExtension = self::getExtension($destinationName);
            $sameType = $destinationExtension === $extension
                || (in_array($destinationExtension, array('jpg', 'jpeg'), true) && in_array($extension, array('jpg', 'jpeg'), true));

            if (!$sameType) {
                throw new UploadMasterException(
                    'A extensão da miniatura deve corresponder ao formato da imagem (.' . $extension . ').'
                );
            }

            $destinationName = self::ensureUniqueFileName($destinationDirectory, $destinationName, !empty($options['overwrite']));
            $destinationPath = $destinationDirectory . '/' . $destinationName;

            if (!empty($options['overwrite']) && JFile::exists($destinationPath) && !JFile::delete($destinationPath)) {
                throw new UploadMasterException(
                    'Não foi possível substituir a miniatura existente.'
                );
            }

            if (!self::saveImageResource($targetImage, $destinationPath, $outputMime, $quality)) {
                throw new UploadMasterException(
                    'Não foi possível salvar a miniatura.'
                );
            }

            self::destroyImage($sourceImage);
            self::destroyImage($targetImage);
            $sourceImage = $targetImage = null;

            @chmod($destinationPath, self::$filePermission);

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
            if ($sourceImage) {
                self::destroyImage($sourceImage);
            }

            if ($targetImage) {
                self::destroyImage($targetImage);
            }

            return self::fail(
                __METHOD__,
                $error,
                'Não foi possível criar a miniatura.'
            );
        }
    }

    private static function isInlineSafe($mime)
    {
        foreach (self::$inlineSafeMimes as $safe) {
            if ($mime === $safe || (substr($safe, -1) === '/' && strpos($mime, $safe) === 0)) {
                return true;
            }
        }

        return false;
    }

    /*
     * Envia um arquivo ao navegador (download ou exibição) e, por padrão,
     * encerra a aplicação: sem isso, o Joomla continuaria e acrescentaria
     * o HTML da página ao fim do arquivo.
     *
     * Suporta pedidos parciais (Range), necessários para avançar vídeos
     * no Safari/iOS e para retomar downloads.
     *
     * Opções: inline (exibir no navegador, só para tipos seguros),
     * close (padrão true), base_directory, max_age (segundos de cache;
     * padrão 0 = sem cache).
     */
    public static function stream($pathOrRelativePath, $downloadName = null, array $options = array())
    {
        try {
            $baseDirectory = isset($options['base_directory'])
                ? $options['base_directory']
                : null;

            $filePath = self::resolveExistingFile($pathOrRelativePath, $baseDirectory);
            $mime = self::getMimeType($filePath);
            $size = (int) filesize($filePath);

            $downloadName = $downloadName !== null && trim((string) $downloadName) !== ''
                ? self::downloadFileName($downloadName)
                : basename($filePath);

            // HTML, SVG, XML... nunca são exibidos: sempre baixados.
            $inline = !empty($options['inline']) && self::isInlineSafe($mime);
            $close = !array_key_exists('close', $options) || !empty($options['close']);

            if (headers_sent()) {
                throw new UploadMasterException(
                    'Não foi possível enviar o arquivo: a resposta já foi iniciada.'
                );
            }

            $start = 0;
            $end = $size - 1;
            $partial = false;
            $range = isset($_SERVER['HTTP_RANGE']) ? trim((string) $_SERVER['HTTP_RANGE']) : '';

            if ($range !== '' && $size > 0 && preg_match('/^bytes=(\d*)-(\d*)$/', $range, $matches) && ($matches[1] !== '' || $matches[2] !== '')) {
                if ($matches[1] === '') {
                    $start = max(0, $size - (int) $matches[2]);
                } else {
                    $start = (int) $matches[1];

                    if ($matches[2] !== '') {
                        $end = min((int) $matches[2], $size - 1);
                    }
                }

                if ($start > $end || $start >= $size) {
                    while (ob_get_level() > 0 && @ob_end_clean()) {
                    }

                    http_response_code(416);
                    header('Content-Range: bytes */' . $size);

                    if ($close) {
                        JFactory::getApplication()->close();
                    }

                    return false;
                }

                $partial = true;
            }

            // Libera a sessão: downloads longos não travam outras abas do usuário.
            if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            @set_time_limit(0);

            while (ob_get_level() > 0 && @ob_end_clean()) {
            }

            $asciiName = preg_replace('/[^\x20-\x7E]/', '_', self::removeAccents($downloadName));
            $maxAge = isset($options['max_age']) ? max(0, (int) $options['max_age']) : 0;

            http_response_code($partial ? 206 : 200);
            header('Content-Type: ' . $mime);
            header('Content-Length: ' . ($size === 0 ? 0 : $end - $start + 1));
            header('Accept-Ranges: bytes');
            header('X-Content-Type-Options: nosniff');
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', filemtime($filePath)) . ' GMT');
            header(
                $maxAge > 0
                    ? 'Cache-Control: private, max-age=' . $maxAge
                    : 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0'
            );
            header(
                'Content-Disposition: ' .
                ($inline ? 'inline' : 'attachment') .
                '; filename="' . str_replace(array('"', '\\'), '', $asciiName) . '"' .
                "; filename*=UTF-8''" . rawurlencode($downloadName)
            );

            if ($partial) {
                header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            }

            $isHead = isset($_SERVER['REQUEST_METHOD']) && strtoupper($_SERVER['REQUEST_METHOD']) === 'HEAD';

            if (!$isHead && $size > 0) {
                $handle = fopen($filePath, 'rb');

                if ($handle === false) {
                    throw new UploadMasterException(
                        'Não foi possível abrir o arquivo para download.'
                    );
                }

                fseek($handle, $start);
                $remaining = $end - $start + 1;

                while ($remaining > 0 && !feof($handle)) {
                    $chunk = fread($handle, (int) min(8192, $remaining));

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    echo $chunk;
                    $remaining -= strlen($chunk);
                    flush();

                    if (connection_aborted()) {
                        break;
                    }
                }

                fclose($handle);
            }

            if ($close) {
                JFactory::getApplication()->close();
            }

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
                throw new UploadMasterException(
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
                throw new UploadMasterException(
                    'Diretório não autorizado.'
                );
            }

            $stopAtDirectory = $baseDirectory;

            if (isset($options['stop_at_directory']) && trim((string) $options['stop_at_directory']) !== '') {
                $stop = str_replace('\\', '/', trim((string) $options['stop_at_directory']));

                // Relativo à base, como o $directory.
                $stopAtDirectory = strpos($stop, '/') === 0
                    ? self::normalizePath($stop)
                    : self::normalizePath($baseDirectory . '/' . trim($stop, '/'));
            }

            if (!self::pathInsideBase($stopAtDirectory, $baseDirectory)) {
                throw new UploadMasterException(
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

                // O index.html criado pelo próprio helper não conta como conteúdo.
                $files = JFolder::files(
                    $directory,
                    '.',
                    false,
                    true,
                    array(
                        '.svn',
                        'CVS',
                        '.DS_Store',
                        '__MACOSX',
                        'index.html'
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