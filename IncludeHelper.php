<?php

defined('_JEXEC') or die;

/**
 * IncludeHelper
 *
 * Gerenciador analítico de carregamento de helpers do Joomla.
 * Resolve a árvore de dependências de forma topológica, garantindo que
 * cada classe seja incluída na sequência correta (ex: DbConnectionHelper antes de DbTransactionHelper, etc.).
 */
class IncludeHelper
{
    /**
     * Tabela de dependências diretas de cada helper no projeto.
     * Mapeado analiticamente com base no uso interno e dependências físicas.
     *
     * @var array
     */
    private static $dependencies = array(
        'LogHelper'           => array(),
        'DateHelper'          => array(),
        'InputHelper'         => array(),
        'ValidationHelper'    => array(),
        'DbConnectionHelper'  => array(),
        'DbTransactionHelper' => array('DbConnectionHelper'),
        'OrmBase'             => array('DbConnectionHelper', 'DbTransactionHelper', 'DateHelper'),
        'OrmTables'           => array('OrmBase'),
        'ApiResponseHelper'   => array('LogHelper'),
        'PermissionHelper'    => array('LogHelper', 'ApiResponseHelper'),
        'FileHelper'          => array('LogHelper'),
        'AuditHelper'         => array('OrmTables', 'LogHelper'),
        'CsvHelper'           => array('OrmTables', 'DbTransactionHelper', 'ValidationHelper', 'LogHelper'),
        'ChunkUploadHelper'   => array('LogHelper', 'DateHelper'),
        'ExportHelper'        => array('LogHelper'),
        'QueueHelper'         => array('LogHelper', 'DateHelper'),
        'UploadMaster'        => array(),
        'HttpHelper'          => array('LogHelper'),
        'LockHelper'          => array('LogHelper', 'DbConnectionHelper'),
        'CacheHelper'         => array('LogHelper', 'LockHelper'),
        'MailHelper'          => array('LogHelper'),
        'RateLimitHelper'     => array('LogHelper', 'ApiResponseHelper', 'DbConnectionHelper'),
        'CryptoHelper'        => array('LogHelper'),
        'FormatHelper'        => array(),
        'WebhookHelper'       => array('LogHelper', 'ApiResponseHelper', 'CryptoHelper', 'DbConnectionHelper'),
        'SettingsHelper'      => array()
    );

    /**
     * Mapeamento de nome de arquivo para nome de classe interna (caso difira).
     *
     * @var array
     */
    private static $classMap = array(
        'ChunkUploadHelper' => 'ChunkHelper'
    );

    /**
     * Classes declaradas em um arquivo cujo nome difere do nome da classe
     * (classe => arquivo), usadas pelo autoloader para achar o arquivo certo.
     *
     * @var array
     */
    private static $autoloadMap = array(
        'ChunkHelper'           => 'ChunkUploadHelper',
        'CsvHelperException'    => 'CsvHelper',
        'UploadMasterException' => 'UploadMaster',
        'HttpClient'            => 'HttpHelper',
        'HttpHelperException'   => 'HttpHelper',
        'LockHelperException'   => 'LockHelper'
    );

    /**
     * Indica se o autoloader já foi registrado no spl_autoload.
     *
     * @var bool
     */
    private static $autoloaderRegistered = false;

    /**
     * Lista de arquivos fisicamente incluídos nesta execução.
     *
     * @var array
     */
    private static $included = array();

    /**
     * Carrega um ou mais helpers resolvendo todas as suas dependências na ordem analítica correta.
     *
     * @param string|array $helpers Nome do helper ou array de nomes.
     * @return array Lista de todos os helpers que foram ativados/incluídos (incluindo as dependências).
     */
    public static function load($helpers)
    {
        $helpers = (array) $helpers;
        $resolved = array();
        $visited = array();

        foreach ($helpers as $helper) {
            self::resolve($helper, $resolved, $visited);
        }

        $loaded = array();

        foreach ($resolved as $helper) {
            if (self::includeFile($helper)) {
                $loaded[] = $helper;
            }
        }

        return $loaded;
    }

    /**
     * Carrega de forma topológica absoluta TODOS os helpers do ecossistema.
     *
     * @return array Lista de todos os helpers carregados.
     */
    public static function loadAll()
    {
        return self::load(array_keys(self::$dependencies));
    }

    /**
     * Registra um autoloader que inclui cada helper (com suas dependências)
     * na primeira vez em que a classe é usada. Chamadas repetidas são ignoradas.
     *
     * @return void
     */
    public static function registerAutoloader()
    {
        if (self::$autoloaderRegistered) {
            return;
        }

        spl_autoload_register(array(__CLASS__, 'autoload'));

        self::$autoloaderRegistered = true;
    }

    /**
     * Callback do spl_autoload. Ignora classes que não pertencem aos helpers,
     * deixando-as para os demais autoloaders (Joomla, Composer etc.).
     *
     * @param string $class Nome da classe solicitada.
     * @return void
     */
    public static function autoload($class)
    {
        $helper = isset(self::$autoloadMap[$class]) ? self::$autoloadMap[$class] : $class;

        if (isset(self::$dependencies[$helper])) {
            self::load($helper);
        }
    }

    /**
     * Retorna a lista de dependências diretas de um determinado helper.
     *
     * @param string $helper Nome do helper.
     * @return array
     */
    public static function getDependencies($helper)
    {
        return isset(self::$dependencies[$helper]) ? self::$dependencies[$helper] : array();
    }

    /**
     * Retorna a ordem topológica completa (sequência correta de carregamento) de todos os helpers.
     *
     * @return array
     */
    public static function getTopologicalOrder()
    {
        $resolved = array();
        $visited = array();

        foreach (array_keys(self::$dependencies) as $helper) {
            self::resolve($helper, $resolved, $visited);
        }

        return $resolved;
    }

    /**
     * Algoritmo Depth-First Search (DFS) com pós-ordem para ordenação topológica.
     * Previne e detecta dependências circulares de forma analítica.
     */
    private static function resolve($helper, &$resolved, &$visited)
    {
        // Se o helper for desconhecido na tabela, tratamos como folha sem dependências
        if (!isset(self::$dependencies[$helper])) {
            if (!in_array($helper, $resolved, true)) {
                $resolved[] = $helper;
            }
            return;
        }

        if (isset($visited[$helper])) {
            if ($visited[$helper] === 'visiting') {
                throw new RuntimeException(
                    'Dependência cíclica detectada envolvendo o helper: ' . $helper
                );
            }
            return;
        }

        $visited[$helper] = 'visiting';

        foreach (self::$dependencies[$helper] as $dependency) {
            self::resolve($dependency, $resolved, $visited);
        }

        $visited[$helper] = 'visited';

        if (!in_array($helper, $resolved, true)) {
            $resolved[] = $helper;
        }
    }

    /**
     * Inclui fisicamente o arquivo do helper, prevenindo redeclarações e verificando existência.
     */
    private static function includeFile($helper)
    {
        $className = isset(self::$classMap[$helper]) ? self::$classMap[$helper] : $helper;

        // Se a classe já existe na memória do PHP, pulamos (sem disparar o autoload)
        if (class_exists($className, false)) {
            return false;
        }

        $filePath = __DIR__ . '/' . $helper . '.php';

        if (!is_file($filePath)) {
            throw new RuntimeException(
                'Arquivo físico do helper não encontrado: ' . $filePath
            );
        }

        require_once $filePath;

        if (!class_exists($className, false)) {
            throw new RuntimeException(
                'O arquivo ' . $helper . '.php foi incluído com sucesso, mas a classe ' . $className . ' não foi declarada.'
            );
        }

        self::$included[$helper] = true;

        return true;
    }
}
