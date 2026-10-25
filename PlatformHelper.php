<?php

// Bloqueia só o acesso direto pela URL; incluído pelo Joomla, Laravel ou PHP puro, carrega normalmente.
if (!defined('_JEXEC') && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    die('Acesso restrito.');
}

/*
 * Camada de compatibilidade dos helpers de arquivo, log e exportação.
 *
 * Descobre onde está rodando e usa o recurso nativo de cada ambiente:
 * - Joomla 3, 4, 5 e 6: JFile/JFolder/JLog/JFactory (Joomla 3) ou as
 *   classes com namespace (Joomla 4+), exatamente como antes;
 * - Laravel: base_path(), storage_path('logs'), url(), DB e auth();
 * - PHP puro: funções nativas, PDO informado em setPdo() e caminhos
 *   configuráveis.
 *
 * Detecção: _JEXEC definido → Joomla; app() do Laravel ativo → Laravel;
 * senão PHP puro. Para forçar: PlatformHelper::setPlatform('php').
 */
class PlatformHelper
{
    const JOOMLA = 'joomla';

    const LARAVEL = 'laravel';

    const PHP = 'php';

    // Mesmos valores das constantes do JLog / Joomla\CMS\Log\Log.
    const LOG_EMERGENCY = 1;

    const LOG_ALERT = 2;

    const LOG_CRITICAL = 4;

    const LOG_ERROR = 8;

    const LOG_WARNING = 16;

    const LOG_NOTICE = 32;

    const LOG_INFO = 64;

    const LOG_DEBUG = 128;

    const LOG_ALL = 30719;

    private static $platform = null;

    private static $rootPath = null;

    private static $rootUrl = null;

    private static $logPath = null;

    private static $publicPaths = array();

    private static $pdo = null;

    private static $userResolver = null;

    private static $loggers = array();

    private static $joomlaImported = false;

    private static $priorityNames = array(
        1 => 'EMERGENCY',
        2 => 'ALERT',
        4 => 'CRITICAL',
        8 => 'ERROR',
        16 => 'WARNING',
        32 => 'NOTICE',
        64 => 'INFO',
        128 => 'DEBUG'
    );

    /* =================================================================
     * Ambiente
     * ================================================================= */

    public static function setPlatform($platform)
    {
        $platform = strtolower(trim((string) $platform));

        if (!in_array($platform, array(self::JOOMLA, self::LARAVEL, self::PHP), true)) {
            throw new InvalidArgumentException(
                'Plataforma inválida: use joomla, laravel ou php.'
            );
        }

        self::$platform = $platform;
    }

    public static function platform()
    {
        if (self::$platform !== null) {
            return self::$platform;
        }

        if (defined('_JEXEC')) {
            self::$platform = self::JOOMLA;
        } elseif (self::laravelRunning()) {
            self::$platform = self::LARAVEL;
        } else {
            self::$platform = self::PHP;
        }

        return self::$platform;
    }

    public static function isJoomla()
    {
        return self::platform() === self::JOOMLA;
    }

    public static function isLaravel()
    {
        return self::platform() === self::LARAVEL;
    }

    // Versão principal do Joomla (3, 4, 5, 6...) ou 0 fora do Joomla.
    public static function joomlaVersion()
    {
        if (!self::isJoomla()) {
            return 0;
        }

        if (class_exists('Joomla\\CMS\\Version') && defined('Joomla\\CMS\\Version::MAJOR_VERSION')) {
            return (int) constant('Joomla\\CMS\\Version::MAJOR_VERSION');
        }

        if (defined('JVERSION')) {
            return (int) JVERSION;
        }

        return 3;
    }

    private static function laravelRunning()
    {
        if (
            !function_exists('app') ||
            !class_exists('Illuminate\\Foundation\\Application', false)
        ) {
            return false;
        }

        try {
            return app() instanceof Illuminate\Foundation\Application;
        } catch (Throwable $error) {
            return false;
        }
    }

    // Primeira classe existente da lista (Joomla 4+ com namespace, depois o alias do Joomla 3).
    private static function joomlaClass(array $candidates)
    {
        if (!self::$joomlaImported) {
            self::$joomlaImported = true;

            // Joomla 3 precisa do jimport para achar JFile/JFolder/JLog.
            if (function_exists('jimport')) {
                jimport('joomla.filesystem.file');
                jimport('joomla.filesystem.folder');
                jimport('joomla.filesystem.path');
                jimport('joomla.log.log');
            }
        }

        foreach ($candidates as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }

        return null;
    }

    private static function joomlaMethod($kind, $method)
    {
        if (!self::isJoomla()) {
            return null;
        }

        $candidates = array(
            'file' => array('Joomla\\CMS\\Filesystem\\File', 'JFile', 'Joomla\\Filesystem\\File'),
            'folder' => array('Joomla\\CMS\\Filesystem\\Folder', 'JFolder', 'Joomla\\Filesystem\\Folder'),
            'log' => array('Joomla\\CMS\\Log\\Log', 'JLog'),
            'factory' => array('Joomla\\CMS\\Factory', 'JFactory'),
            'uri' => array('Joomla\\CMS\\Uri\\Uri', 'JUri')
        );

        foreach ($candidates[$kind] as $class) {
            if (self::joomlaClass(array($class)) !== null && method_exists($class, $method)) {
                return $class;
            }
        }

        return null;
    }

    /* =================================================================
     * Caminhos e URLs
     * ================================================================= */

    // Raiz permitida para os arquivos (JPATH_SITE no Joomla, base_path() no Laravel).
    public static function setRootPath($path)
    {
        $real = realpath((string) $path);

        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException(
                'Diretório raiz inválido.'
            );
        }

        self::$rootPath = self::normalizePath($real);
    }

    public static function rootPath()
    {
        if (self::$rootPath !== null) {
            return self::$rootPath;
        }

        if (self::isJoomla() && defined('JPATH_SITE')) {
            return JPATH_SITE;
        }

        if (self::isLaravel() && function_exists('base_path')) {
            return base_path();
        }

        if (PHP_SAPI !== 'cli' && !empty($_SERVER['DOCUMENT_ROOT'])) {
            return $_SERVER['DOCUMENT_ROOT'];
        }

        return getcwd();
    }

    // URL da raiz do site, sempre terminando em "/" (como o Uri::root() do Joomla).
    public static function setRootUrl($url)
    {
        self::$rootUrl = rtrim((string) $url, '/') . '/';
    }

    public static function rootUrl()
    {
        if (self::$rootUrl !== null) {
            return self::$rootUrl;
        }

        $uri = self::joomlaMethod('uri', 'root');

        if ($uri !== null) {
            return $uri::root();
        }

        if (self::isLaravel() && function_exists('url')) {
            return rtrim(url('/'), '/') . '/';
        }

        // PHP puro: URL do DOCUMENT_ROOT (esquema + host da requisição).
        if (empty($_SERVER['HTTP_HOST'])) {
            return '/';
        }

        $https = (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') ||
            (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443) ||
            (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

        return ($https ? 'https' : 'http') .
            '://' .
            $_SERVER['HTTP_HOST'] .
            '/';
    }

    /*
     * Pasta servida na web e a URL dela. No PHP puro, as pastas
     * informadas aqui substituem o padrão (só elas geram URL); no Joomla
     * e no Laravel elas se somam às pastas públicas do framework.
     */
    public static function addPublicPath($path, $url)
    {
        $real = realpath((string) $path);

        if ($real === false) {
            throw new InvalidArgumentException(
                'Diretório público inválido.'
            );
        }

        array_unshift(
            self::$publicPaths,
            array(self::normalizePath($real), rtrim((string) $url, '/'))
        );
    }

    // URL pública de um arquivo absoluto, ou null se ele não está numa pasta servida na web.
    public static function url($absolutePath)
    {
        $path = realpath((string) $absolutePath);
        $path = self::normalizePath($path !== false ? $path : $absolutePath);

        foreach (self::publicPaths() as $public) {
            if ($path === $public[0] || strpos($path, $public[0] . '/') !== 0) {
                continue;
            }

            $relativePath = substr($path, strlen($public[0]) + 1);

            return $public[1] .
                '/' .
                str_replace(
                    '%2F',
                    '/',
                    rawurlencode($relativePath)
                );
        }

        return null;
    }

    private static function publicPaths()
    {
        $paths = self::$publicPaths;
        $candidates = array();

        if (self::isLaravel() && function_exists('public_path') && function_exists('url')) {
            $candidates[] = array(public_path(), rtrim(url('/'), '/'));
            $candidates[] = array(storage_path('app/public'), rtrim(url('storage'), '/'));
        } elseif (self::isJoomla()) {
            $candidates[] = array(self::rootPath(), rtrim(self::rootUrl(), '/'));
        } elseif (!empty($paths)) {
            return $paths;
        } elseif (self::$rootUrl !== null) {
            $candidates[] = array(self::rootPath(), rtrim(self::rootUrl(), '/'));
        } elseif (PHP_SAPI !== 'cli' && !empty($_SERVER['DOCUMENT_ROOT'])) {
            $candidates[] = array($_SERVER['DOCUMENT_ROOT'], rtrim(self::rootUrl(), '/'));
        } else {
            $candidates[] = array(self::rootPath(), rtrim(self::rootUrl(), '/'));
        }

        foreach ($candidates as $candidate) {
            $real = realpath($candidate[0]);

            if ($real !== false) {
                $paths[] = array(self::normalizePath($real), $candidate[1]);
            }
        }

        return $paths;
    }

    public static function setLogPath($path)
    {
        self::$logPath = rtrim(str_replace('\\', '/', (string) $path), '/');
    }

    // Pasta padrão de logs: log_path do Joomla, storage/logs do Laravel, <raiz>/logs no PHP puro.
    public static function logPath()
    {
        if (self::$logPath !== null) {
            return self::$logPath;
        }

        if (self::isJoomla()) {
            $default = defined('JPATH_ADMINISTRATOR')
                ? JPATH_ADMINISTRATOR . '/logs'
                : self::rootPath() . '/logs';

            return (string) self::joomlaConfig('log_path', $default);
        }

        if (self::isLaravel() && function_exists('storage_path')) {
            return storage_path('logs');
        }

        return rtrim(str_replace('\\', '/', self::rootPath()), '/') . '/logs';
    }

    private static function joomlaConfig($name, $default = null)
    {
        $factory = self::joomlaMethod('factory', 'getApplication');

        try {
            $app = $factory !== null ? $factory::getApplication() : null;

            if (is_object($app) && method_exists($app, 'get')) {
                return $app->get($name, $default);
            }
        } catch (Throwable $error) {
        }

        $factory = self::joomlaMethod('factory', 'getConfig');

        if ($factory !== null) {
            try {
                return $factory::getConfig()->get($name, $default);
            } catch (Throwable $error) {
            }
        }

        return $default;
    }

    private static function normalizePath($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $path = preg_replace('#/+#', '/', $path);

        return rtrim($path, '/');
    }

    /* =================================================================
     * Data e usuário
     * ================================================================= */

    // Data e hora atuais em UTC no formato do banco (igual ao Factory::getDate()->toSql()).
    public static function nowSql()
    {
        return gmdate('Y-m-d H:i:s');
    }

    // Função que devolve o id do usuário logado (PHP puro).
    public static function setUserResolver(callable $resolver)
    {
        self::$userResolver = $resolver;
    }

    public static function userId()
    {
        try {
            if (self::$userResolver !== null) {
                $id = call_user_func(self::$userResolver);

                return (int) $id > 0 ? (int) $id : null;
            }

            if (self::isJoomla()) {
                $factory = self::joomlaMethod('factory', 'getApplication');
                $user = null;

                if ($factory !== null) {
                    $app = $factory::getApplication();

                    if (is_object($app) && method_exists($app, 'getIdentity')) {
                        $user = $app->getIdentity();
                    }
                }

                if ($user === null && self::joomlaMethod('factory', 'getUser') !== null) {
                    $factory = self::joomlaMethod('factory', 'getUser');
                    $user = $factory::getUser();
                }

                return !empty($user) && (int) $user->id > 0
                    ? (int) $user->id
                    : null;
            }

            if (self::isLaravel() && function_exists('auth')) {
                $id = auth()->id();

                return (int) $id > 0 ? (int) $id : null;
            }
        } catch (Throwable $error) {
        }

        return null;
    }

    /* =================================================================
     * Requisição
     * ================================================================= */

    // Arquivo(s) enviados num campo, no formato do $_FILES (lista quando o campo é múltiplo).
    public static function uploadedFiles($fieldName)
    {
        if (self::isJoomla()) {
            $factory = self::joomlaMethod('factory', 'getApplication');
            $app = $factory::getApplication();
            $input = method_exists($app, 'getInput') ? $app->getInput() : $app->input;

            return $input->files->get($fieldName, null, 'array');
        }

        if (self::isLaravel() && function_exists('request')) {
            $files = request()->file($fieldName);

            if ($files !== null) {
                return self::laravelFiles($files);
            }
        }

        if (!isset($_FILES[$fieldName]) || !is_array($_FILES[$fieldName])) {
            return null;
        }

        $file = $_FILES[$fieldName];

        if (!isset($file['name']) || !is_array($file['name'])) {
            return $file;
        }

        return self::decodeFiles(
            $file['name'],
            $file['type'],
            $file['tmp_name'],
            $file['error'],
            $file['size']
        );
    }

    private static function laravelFiles($files)
    {
        if (is_array($files)) {
            $result = array();

            foreach ($files as $key => $file) {
                $result[$key] = self::laravelFiles($file);
            }

            return $result;
        }

        return array(
            'name' => $files->getClientOriginalName(),
            'type' => $files->getClientMimeType(),
            'tmp_name' => $files->getPathname(),
            'error' => $files->getError(),
            'size' => $files->getSize()
        );
    }

    private static function decodeFiles($names, $types, $tmpNames, $errors, $sizes)
    {
        $result = array();

        foreach ($names as $key => $name) {
            if (is_array($name)) {
                $result[$key] = self::decodeFiles(
                    $name,
                    $types[$key],
                    $tmpNames[$key],
                    $errors[$key],
                    $sizes[$key]
                );

                continue;
            }

            $result[$key] = array(
                'name' => $name,
                'type' => $types[$key],
                'tmp_name' => $tmpNames[$key],
                'error' => $errors[$key],
                'size' => $sizes[$key]
            );
        }

        return $result;
    }

    // Encerra a requisição (no Joomla pelo próprio app, para fechar a sessão direito).
    public static function close()
    {
        $factory = self::joomlaMethod('factory', 'getApplication');

        if ($factory !== null) {
            $factory::getApplication()->close();
        }

        exit;
    }

    /* =================================================================
     * Banco de dados
     * ================================================================= */

    // Conexão usada no PHP puro (no Joomla e no Laravel vem do framework).
    public static function setPdo(PDO $pdo)
    {
        self::$pdo = $pdo;
    }

    /*
     * Linhas de uma consulta como arrays associativos.
     * - Joomla: string SQL ou objeto de query do Joomla;
     * - Laravel: string SQL, array(sql, bindings) ou Query/Eloquent Builder;
     * - PHP puro: string SQL ou array(sql, bindings), no PDO de setPdo().
     */
    public static function queryRows($query, $offset = 0, $limit = 0)
    {
        $offset = max(0, (int) $offset);
        $limit = max(0, (int) $limit);

        if (self::isJoomla()) {
            $db = self::joomlaDatabase();
            $db->setQuery($query, $offset, $limit);
            $rows = $db->loadAssocList();

            return is_array($rows) ? $rows : array();
        }

        if (self::isLaravel() && is_object($query) && method_exists($query, 'toBase')) {
            $query = $query->toBase();
        }

        if (self::isLaravel() && is_object($query) && method_exists($query, 'offset')) {
            $builder = clone $query;

            if ($limit > 0) {
                $builder->offset($offset)->limit($limit);
            }

            $rows = array();

            foreach ($builder->get() as $row) {
                $rows[] = (array) $row;
            }

            return $rows;
        }

        $bindings = array();

        if (is_array($query)) {
            $bindings = isset($query[1]) ? (array) $query[1] : array();
            $query = isset($query[0]) ? $query[0] : '';
        }

        if (!is_string($query) || trim($query) === '') {
            throw new InvalidArgumentException(
                'Query inválida.'
            );
        }

        if ($limit > 0) {
            $query = rtrim(trim($query), ';') . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }

        if (self::isLaravel() && class_exists('Illuminate\\Support\\Facades\\DB')) {
            $rows = array();

            foreach (Illuminate\Support\Facades\DB::select($query, $bindings) as $row) {
                $rows[] = (array) $row;
            }

            return $rows;
        }

        if (self::$pdo === null) {
            throw new RuntimeException(
                'Sem conexão: chame PlatformHelper::setPdo($pdo) antes de consultar.'
            );
        }

        $statement = self::$pdo->prepare($query);
        $statement->execute($bindings);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function joomlaDatabase()
    {
        $factory = self::joomlaMethod('factory', 'getContainer');

        if ($factory !== null && interface_exists('Joomla\\Database\\DatabaseInterface')) {
            return $factory::getContainer()->get('Joomla\\Database\\DatabaseInterface');
        }

        $factory = self::joomlaMethod('factory', 'getDbo');

        return $factory::getDbo();
    }

    /* =================================================================
     * Arquivos e pastas (mesmo comportamento do JFile/JFolder)
     * ================================================================= */

    public static function fileExists($path)
    {
        $class = self::joomlaMethod('file', 'exists');

        if ($class !== null) {
            return $class::exists($path);
        }

        return is_file((string) $path);
    }

    public static function folderExists($path)
    {
        $class = self::joomlaMethod('folder', 'exists');

        if ($class !== null) {
            return $class::exists($path);
        }

        return is_dir((string) $path);
    }

    public static function folderCreate($path, $mode = 0755)
    {
        $class = self::joomlaMethod('folder', 'create');

        if ($class !== null) {
            return $class::create($path, $mode);
        }

        $path = (string) $path;

        if (is_dir($path)) {
            return true;
        }

        // Como o Joomla: zera o umask para a permissão ficar exatamente a pedida.
        $umask = umask(0);
        $created = @mkdir($path, $mode, true);
        umask($umask);

        return $created || is_dir($path);
    }

    public static function fileDelete($path)
    {
        $class = self::joomlaMethod('file', 'delete');

        if ($class !== null) {
            return $class::delete($path);
        }

        foreach ((array) $path as $file) {
            $file = (string) $file;

            if (!is_file($file) && !is_link($file)) {
                return false;
            }

            @chmod($file, 0777);

            if (!@unlink($file)) {
                return false;
            }
        }

        return true;
    }

    public static function folderDelete($path)
    {
        $class = self::joomlaMethod('folder', 'delete');

        if ($class !== null) {
            return $class::delete($path);
        }

        $path = rtrim((string) $path, '/\\');

        if ($path === '' || !is_dir($path) || is_link($path)) {
            return false;
        }

        $items = scandir($path);

        if ($items === false) {
            return false;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path . '/' . $item;

            if (is_dir($itemPath) && !is_link($itemPath)) {
                if (!self::folderDelete($itemPath)) {
                    return false;
                }
            } elseif (!self::fileDelete($itemPath)) {
                return false;
            }
        }

        return @rmdir($path);
    }

    public static function fileCopy($source, $destination)
    {
        $class = self::joomlaMethod('file', 'copy');

        if ($class !== null) {
            return $class::copy($source, $destination);
        }

        return is_readable((string) $source) && @copy($source, $destination);
    }

    public static function fileMove($source, $destination)
    {
        $class = self::joomlaMethod('file', 'move');

        if ($class !== null) {
            return $class::move($source, $destination);
        }

        return is_readable((string) $source) && @rename($source, $destination);
    }

    // Move o arquivo recebido por upload (sem as checagens extras do Joomla, que o helper já faz).
    public static function fileUpload($source, $destination)
    {
        $class = self::joomlaMethod('file', 'upload');

        if ($class === 'Joomla\\Filesystem\\File') {
            return $class::upload($source, $destination, false);
        }

        if ($class !== null) {
            return $class::upload($source, $destination, false, false);
        }

        if (!self::folderCreate(dirname((string) $destination))) {
            return false;
        }

        if (!@move_uploaded_file($source, $destination)) {
            return false;
        }

        @chmod($destination, 0644);

        return true;
    }

    public static function makeSafe($fileName)
    {
        $class = self::joomlaMethod('file', 'makeSafe');

        if ($class !== null) {
            return $class::makeSafe($fileName);
        }

        $fileName = rtrim((string) $fileName, '.');

        if (function_exists('transliterator_transliterate') && function_exists('iconv')) {
            $ascii = iconv(
                'UTF-8',
                'ASCII//TRANSLIT//IGNORE',
                (string) transliterator_transliterate('Any-Latin; Latin-ASCII', $fileName)
            );

            if ($ascii !== false) {
                $fileName = $ascii;
            }
        }

        return trim(preg_replace(
            array('#(\.){2,}#', '#[^A-Za-z0-9\.\_\- ]#', '#^\.#'),
            '',
            $fileName
        ));
    }

    public static function folderMakeSafe($path)
    {
        $class = self::joomlaMethod('folder', 'makeSafe');

        if ($class !== null) {
            return $class::makeSafe($path);
        }

        return preg_replace(
            '#[^A-Za-z0-9_\\\/\(\)\[\]\{\}\#\$\^\+\.\'~`!@&=;,-]#',
            '',
            (string) $path
        );
    }

    public static function getExt($fileName)
    {
        $class = self::joomlaMethod('file', 'getExt');

        if ($class !== null) {
            return $class::getExt($fileName);
        }

        $fileName = (string) $fileName;
        $dot = strrpos($fileName, '.');

        if ($dot === false) {
            return '';
        }

        $extension = substr($fileName, $dot + 1);

        return strpbrk($extension, '/\\') === false ? $extension : '';
    }

    public static function stripExt($fileName)
    {
        $class = self::joomlaMethod('file', 'stripExt');

        if ($class !== null) {
            return $class::stripExt($fileName);
        }

        return preg_replace('#\.[^.]*$#', '', (string) $fileName);
    }

    public static function files($path, $filter = '.', $recurse = false, $full = false, array $exclude = array('.svn', 'CVS', '.DS_Store', '__MACOSX'), array $excludeFilter = array('^\..*', '.*~'))
    {
        $class = self::joomlaMethod('folder', 'files');

        if ($class !== null) {
            return $class::files($path, $filter, $recurse, $full, $exclude, $excludeFilter);
        }

        return self::items($path, $filter, $recurse, $full, $exclude, $excludeFilter, true);
    }

    public static function folders($path, $filter = '.', $recurse = false, $full = false, array $exclude = array('.svn', 'CVS', '.DS_Store', '__MACOSX'), array $excludeFilter = array('^\..*'))
    {
        $class = self::joomlaMethod('folder', 'folders');

        if ($class !== null) {
            return $class::folders($path, $filter, $recurse, $full, $exclude, $excludeFilter);
        }

        return self::items($path, $filter, $recurse, $full, $exclude, $excludeFilter, false);
    }

    private static function items($path, $filter, $recurse, $full, array $exclude, array $excludeFilter, $findFiles)
    {
        $path = rtrim(str_replace('\\', '/', (string) $path), '/');

        if (!is_dir($path)) {
            return false;
        }

        $items = self::scanItems($path, $filter, $recurse, $full, $exclude, $excludeFilter, $findFiles);

        asort($items);

        return array_values($items);
    }

    private static function scanItems($path, $filter, $recurse, $full, array $exclude, array $excludeFilter, $findFiles)
    {
        $items = array();
        $excludePattern = !empty($excludeFilter)
            ? '/(' . implode('|', $excludeFilter) . ')/'
            : '';

        $handle = @opendir($path);

        if ($handle === false) {
            return $items;
        }

        while (($item = readdir($handle)) !== false) {
            if (
                $item === '.' ||
                $item === '..' ||
                in_array($item, $exclude, true) ||
                ($excludePattern !== '' && preg_match($excludePattern, $item))
            ) {
                continue;
            }

            $itemPath = $path . '/' . $item;
            $isDirectory = is_dir($itemPath);

            if (($isDirectory xor $findFiles) && preg_match('/' . $filter . '/', $item)) {
                $items[] = $full ? $itemPath : $item;
            }

            if ($isDirectory && $recurse) {
                $items = array_merge(
                    $items,
                    self::scanItems(
                        $itemPath,
                        $filter,
                        is_int($recurse) ? $recurse - 1 : $recurse,
                        $full,
                        $exclude,
                        $excludeFilter,
                        $findFiles
                    )
                );
            }
        }

        closedir($handle);

        return $items;
    }

    /* =================================================================
     * Log (mesmas opções do JLog::addLogger / JLog::add)
     * ================================================================= */

    /*
     * Registra um arquivo de log para as prioridades e categorias dadas.
     * Opções usadas: text_file, text_file_path, text_entry_format.
     */
    public static function addLogger(array $options, $priorities = self::LOG_ALL, array $categories = array())
    {
        $class = self::joomlaMethod('log', 'addLogger');

        if ($class !== null) {
            $class::addLogger($options, $priorities, $categories);

            return;
        }

        $directory = isset($options['text_file_path']) && $options['text_file_path'] !== ''
            ? $options['text_file_path']
            : self::logPath();

        $file = isset($options['text_file']) && $options['text_file'] !== ''
            ? $options['text_file']
            : 'error.php';

        $path = rtrim(str_replace('\\', '/', (string) $directory), '/') . '/' . $file;
        $key = md5($path . '|' . (int) $priorities . '|' . implode(',', $categories));

        self::$loggers[$key] = array(
            'path' => $path,
            'format' => isset($options['text_entry_format'])
                ? (string) $options['text_entry_format']
                : '{DATETIME} {PRIORITY} {CATEGORY} {MESSAGE}',
            'priorities' => (int) $priorities,
            'categories' => $categories
        );
    }

    /*
     * Grava a mensagem nos logs registrados para a prioridade e a categoria.
     * Fora do Joomla, sem log registrado, grava em <logPath>/<categoria>.php.
     */
    public static function log($message, $priority = self::LOG_INFO, $category = '')
    {
        $class = self::joomlaMethod('log', 'add');

        if ($class !== null) {
            $class::add($message, $priority, $category);

            return;
        }

        $priority = (int) $priority;
        $written = false;

        foreach (self::$loggers as $logger) {
            if (
                ($logger['priorities'] & $priority) === 0 ||
                (!empty($logger['categories']) && !in_array($category, $logger['categories'], true))
            ) {
                continue;
            }

            self::writeLogLine($logger['path'], $logger['format'], $message, $priority, $category);
            $written = true;
        }

        if (!$written) {
            $file = preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string) $category);

            self::writeLogLine(
                self::logPath() . '/' . ($file !== '' ? $file : 'helpers') . '.php',
                '{DATETIME} {PRIORITY} {CATEGORY} {MESSAGE}',
                $message,
                $priority,
                $category
            );
        }
    }

    private static function writeLogLine($path, $format, $message, $priority, $category)
    {
        try {
            $directory = dirname($path);

            if (!is_dir($directory) && !self::folderCreate($directory)) {
                return;
            }

            $line = strtr(
                $format,
                array(
                    '{DATETIME}' => gmdate('c'),
                    '{DATE}' => gmdate('Y-m-d'),
                    '{TIME}' => gmdate('H:i:s'),
                    '{PRIORITY}' => isset(self::$priorityNames[$priority])
                        ? self::$priorityNames[$priority]
                        : 'INFO',
                    '{CATEGORY}' => (string) $category,
                    '{CLIENTIP}' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
                    '{MESSAGE}' => (string) $message
                )
            );

            // Mesmo cabeçalho do Joomla: o .php não mostra o conteúdo se aberto pela URL.
            $header = '';

            if (!is_file($path)) {
                $header = "#\n#<?php die('Forbidden.'); ?>\n#Date: " .
                    gmdate('Y-m-d H:i:s') .
                    " UTC\n#Software: helpersJoomla\n\n#Fields: " .
                    strtolower(str_replace(array('{', '}'), '', $format)) .
                    "\n";
            }

            @file_put_contents($path, $header . $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (Throwable $error) {
        }
    }
}
