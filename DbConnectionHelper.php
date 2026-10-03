<?php

defined('_JEXEC') or die;

/*
 * Registro de conexões de banco.
 *
 * Por padrão tudo usa a conexão do Joomla (JFactory::getDbo()). Para
 * consultar outro banco, registre a conexão uma vez com um nome:
 *
 * DbConnectionHelper::register('erp', [
 *     'driver' => 'pgsql',        // mysql, mysqli, pdomysql, pgsql, postgresql
 *     'host' => '10.0.0.5',
 *     'port' => 5432,
 *     'user' => 'app',
 *     'password' => '...',
 *     'database' => 'erp',
 *     'prefix' => 'erp_',         // substitui o #__ nas consultas desta conexão
 * ]);
 *
 * e use o nome onde for preciso:
 *
 * OrmTables::table('#__clientes', ['connection' => 'erp']);
 * DbTransactionHelper::run($callback, ['connection' => 'erp']);
 * class ClienteErpModel extends OrmBase { protected $connection = 'erp'; }
 *
 * A conexão só é aberta no primeiro uso, e a mesma instância é
 * reaproveitada nas chamadas seguintes. Também é possível registrar um
 * driver já criado: DbConnectionHelper::register('erp', $driver).
 *
 * Funciona no Joomla 3 (JDatabaseDriver) e no Joomla 4/5
 * (Joomla\Database\DatabaseFactory).
 */
class DbConnectionHelper
{
    const PADRAO = 'default';

    const MYSQL = 'mysql';

    const POSTGRESQL = 'postgresql';

    private static $configuracoes = [];

    private static $conexoes = [];

    public static function register($nome, $configuracaoOuDriver)
    {
        $nome = self::validarNome($nome);

        if (is_object($configuracaoOuDriver)) {
            self::$conexoes[$nome] = $configuracaoOuDriver;
            unset(self::$configuracoes[$nome]);

            return;
        }

        if (!is_array($configuracaoOuDriver)
            || empty($configuracaoOuDriver['driver'])) {
            throw new InvalidArgumentException(
                'A conexão "' . $nome . '" deve ser um driver ou um array com a chave "driver".'
            );
        }

        self::$configuracoes[$nome] = $configuracaoOuDriver;
        unset(self::$conexoes[$nome]);
    }

    public static function has($nome)
    {
        $nome = trim((string) $nome);

        return $nome === self::PADRAO
            || isset(self::$conexoes[$nome])
            || isset(self::$configuracoes[$nome]);
    }

    /*
     * Devolve o driver da conexão. Sem nome (ou 'default'), devolve a
     * conexão do Joomla.
     */
    public static function get($nome = null)
    {
        $nome = $nome === null ? self::PADRAO : trim((string) $nome);

        if ($nome === self::PADRAO || $nome === '') {
            return JFactory::getDbo();
        }

        if (isset(self::$conexoes[$nome])) {
            return self::$conexoes[$nome];
        }

        if (!isset(self::$configuracoes[$nome])) {
            throw new InvalidArgumentException(
                'Conexão de banco não registrada: "' . $nome . '". ' .
                'Use DbConnectionHelper::register() antes.'
            );
        }

        self::$conexoes[$nome] = self::criarDriver(self::$configuracoes[$nome]);

        return self::$conexoes[$nome];
    }

    /*
     * Aceita o que os helpers recebem em 'connection': null (padrão do
     * Joomla), o nome de uma conexão registrada ou um driver pronto.
     */
    public static function resolve($conexao = null)
    {
        if (is_object($conexao)) {
            return $conexao;
        }

        return self::get($conexao);
    }

    /*
     * Fecha a conexão (se o driver permitir) e remove o registro.
     */
    public static function forget($nome)
    {
        $nome = trim((string) $nome);

        if (isset(self::$conexoes[$nome]) && method_exists(self::$conexoes[$nome], 'disconnect')) {
            try {
                self::$conexoes[$nome]->disconnect();
            } catch (Throwable $e) {
            }
        }

        $existia = isset(self::$conexoes[$nome]) || isset(self::$configuracoes[$nome]);

        unset(self::$conexoes[$nome], self::$configuracoes[$nome]);

        return $existia;
    }

    public static function all()
    {
        return array_values(array_unique(array_merge(
            [self::PADRAO],
            array_keys(self::$configuracoes),
            array_keys(self::$conexoes)
        )));
    }

    /*
     * Tipo do servidor: DbConnectionHelper::MYSQL, ::POSTGRESQL ou o
     * valor informado pelo driver para outros bancos. MariaDB conta como
     * MySQL.
     */
    public static function serverType($conexao = null)
    {
        $db = self::resolve($conexao);

        $tipo = method_exists($db, 'getServerType')
            ? (string) $db->getServerType()
            : '';

        if ($tipo === '' && method_exists($db, 'getName')) {
            $tipo = (string) $db->getName();
        }

        $tipo = strtolower($tipo);

        if (strpos($tipo, 'postgres') !== false || strpos($tipo, 'pgsql') !== false) {
            return self::POSTGRESQL;
        }

        if (strpos($tipo, 'mysql') !== false || strpos($tipo, 'mariadb') !== false) {
            return self::MYSQL;
        }

        return $tipo;
    }

    public static function isPostgres($conexao = null)
    {
        return self::serverType($conexao) === self::POSTGRESQL;
    }

    public static function isMysql($conexao = null)
    {
        return self::serverType($conexao) === self::MYSQL;
    }

    private static function validarNome($nome)
    {
        $nome = trim((string) $nome);

        if ($nome === '') {
            throw new InvalidArgumentException('O nome da conexão é obrigatório.');
        }

        if ($nome === self::PADRAO) {
            throw new InvalidArgumentException(
                'O nome "' . self::PADRAO . '" é reservado para a conexão do Joomla.'
            );
        }

        return $nome;
    }

    private static function criarDriver(array $opcoes)
    {
        $joomla4 = class_exists('Joomla\\Database\\DatabaseFactory');

        $opcoes['driver'] = self::nomeDoDriver($opcoes['driver'], $joomla4);

        if ($joomla4) {
            $fabrica = new Joomla\Database\DatabaseFactory();

            return $fabrica->getDriver($opcoes['driver'], $opcoes);
        }

        return JDatabaseDriver::getInstance($opcoes);
    }

    /*
     * Aceita os nomes de driver das duas versões e converte para o nome
     * que a versão em execução espera.
     */
    private static function nomeDoDriver($driver, $joomla4)
    {
        $driver = strtolower(trim((string) $driver));

        $mapa = [
            'mysql' => 'mysqli',
            'mysqli' => 'mysqli',
            'mariadb' => 'mysqli',
            'pdomysql' => $joomla4 ? 'mysql' : 'pdomysql',
            'pgsql' => $joomla4 ? 'pgsql' : 'postgresql',
            'postgres' => $joomla4 ? 'pgsql' : 'postgresql',
            'postgresql' => $joomla4 ? 'pgsql' : 'postgresql',
        ];

        return isset($mapa[$driver]) ? $mapa[$driver] : $driver;
    }
}
