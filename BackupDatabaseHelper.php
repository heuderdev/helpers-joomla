<?php

// Bloqueia só o acesso direto pela URL; carrega no Joomla, Laravel ou PHP puro.
if (!defined('_JEXEC') && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    die('Acesso restrito.');
}

if (!class_exists('PlatformHelper', false)) {
    require_once __DIR__ . '/PlatformHelper.php';
}

if (!class_exists('DbConnectionHelper', false)) {
    require_once __DIR__ . '/DbConnectionHelper.php';
}

/*
 * Backup do banco MySQL/MariaDB ou PostgreSQL (10+) em arquivo .sql (ou
 * .sql.gz), em PHP puro (sem mysqldump/pg_dump), em dois modos:
 *
 *   - schema: só a estrutura (tabelas vazias, views e triggers);
 *   - full:   estrutura + todos os dados.
 *
 * Salvar no servidor:
 *
 *   $r = BackupDatabaseHelper::schema();
 *   $r = BackupDatabaseHelper::full(['connection' => 'erp', 'gzip' => true]);
 *   // $r['data']['caminho'] => /var/www/site/backups/database/erp_completo_20261112_101500_a1b2c3.sql.gz
 *
 * Baixar pelo navegador (chame PlatformHelper::close() logo depois, para o
 * Joomla não enviar o template junto com o arquivo):
 *
 *   BackupDatabaseHelper::downloadFull(['gzip' => true, 'save_copy' => true]);
 *   PlatformHelper::close();
 *
 * Opções (todas opcionais):
 *
 *   connection    nome registrado no DbConnectionHelper ou driver; padrão: Joomla
 *   tables        só estas tabelas/views (aceita '#__'); padrão: todas do banco.
 *                 No PostgreSQL aceita 'schema.tabela', e tabela particionada
 *                 leva junto as partições
 *   schemas       (PostgreSQL) só estes schemas; padrão: todos, menos os do sistema
 *   exclude       tabelas/views que ficam de fora
 *   exclude_data  tabelas que entram só com a estrutura (ex.: '#__session')
 *   prefix_only   true = só tabelas com o prefixo da conexão (ex.: jos_)
 *   views         inclui views (padrão true)
 *   triggers      inclui triggers (padrão true)
 *   gzip          compacta em .sql.gz (padrão false)
 *   consistent    leitura num snapshot único, sem travar tabelas (padrão true)
 *   directory     subpasta dentro do diretório base
 *   file_name     nome do arquivo (a extensão é ajustada)
 *   save_copy     no download, também guarda uma cópia no servidor
 *
 * NUNCA APAGA NADA, nem agora nem ao restaurar:
 *
 *   - no banco, só executa SELECT e SHOW (mais o fuso da sessão e a
 *     transação de leitura, que são desfeitos no final). Qualquer outro
 *     comando é recusado antes de chegar ao banco;
 *   - no PostgreSQL, além disso, tudo roda numa transação READ ONLY: o
 *     próprio servidor recusa qualquer escrita, e a transação termina em
 *     ROLLBACK. Se a conexão já estiver numa transação, o backup recusa;
 *   - o arquivo gerado não tem DROP, DELETE, TRUNCATE, REPLACE nem UPDATE.
 *     MySQL: CREATE TABLE IF NOT EXISTS e INSERT IGNORE. PostgreSQL:
 *     IF NOT EXISTS, blocos que ignoram objeto já existente, ON CONFLICT
 *     DO NOTHING, ALTER só para ADD CONSTRAINT (chave estrangeira) e
 *     sequências que só avançam. Restaurar sobre um banco existente não
 *     remove nem sobrescreve registros;
 *   - nunca sobrescreve nem remove arquivos: cada backup ganha um nome
 *     único, e um backup que falha no meio fica como ".parcial" para
 *     análise. Não há método de limpeza de backups antigos de propósito.
 *
 * O backup contém dados sensíveis. A pasta recebe .htaccess, web.config e
 * index.html bloqueando o acesso pela web, mas o ideal é apontar o
 * diretório base para fora da pasta pública com setBaseDirectory(), e
 * checar a permissão do usuário (PermissionHelper) antes de chamar.
 */
class BackupDatabaseHelper
{
    const SCHEMA = 'schema';

    const FULL = 'full';

    private static $baseDirectory = null;

    private static $logCategory = 'backup_database_helper';

    private static $chunkSize = 1000;

    private static $rowsPerInsert = 100;

    private static $maxInsertBytes = 1048576;

    private static $directoryPermission = 0750;

    private static $filePermission = 0640;

    private static $binaryTypes = '/^(binary|varbinary|tinyblob|blob|mediumblob|longblob|bit|geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection|geomcollection)\b/i';

    // ------------------------------------------------------------------
    // Configuração
    // ------------------------------------------------------------------

    /*
     * Pasta onde os backups são salvos. Caminho absoluto ou relativo à
     * raiz do site. Prefira uma pasta fora da área pública.
     */
    public static function setBaseDirectory($directory)
    {
        self::$baseDirectory = self::prepareDirectory(self::absolutePath($directory));
    }

    public static function getBaseDirectory()
    {
        if (self::$baseDirectory === null) {
            self::$baseDirectory = self::prepareDirectory(
                self::normalizePath(PlatformHelper::rootPath()) . '/backups/database'
            );
        }

        return self::$baseDirectory;
    }

    public static function setLogCategory($category)
    {
        $category = trim((string) $category);

        if ($category !== '') {
            self::$logCategory = $category;
        }
    }

    /*
     * Quantas linhas são lidas do banco por vez (padrão 1000). Diminua
     * para tabelas com BLOBs grandes.
     */
    public static function setChunkSize($rows)
    {
        self::$chunkSize = max(1, (int) $rows);
    }

    /*
     * Quantas linhas vão em cada INSERT do arquivo (padrão 100). Cada
     * INSERT também é limitado a ~1 MB, para caber no max_allowed_packet.
     */
    public static function setRowsPerInsert($rows)
    {
        self::$rowsPerInsert = max(1, (int) $rows);
    }

    // ------------------------------------------------------------------
    // Salvar no servidor
    // ------------------------------------------------------------------

    /*
     * Só a estrutura: tabelas vazias (sem AUTO_INCREMENT acumulado),
     * views e triggers.
     */
    public static function schema(array $options = [])
    {
        return self::save(self::SCHEMA, $options);
    }

    /*
     * Estrutura + dados.
     */
    public static function full(array $options = [])
    {
        return self::save(self::FULL, $options);
    }

    public static function save($mode, array $options = [])
    {
        $started = microtime(true);
        $partial = null;
        $writer = null;

        try {
            $mode = self::normalizeMode($mode);
            $plan = self::plan($mode, $options);

            $directory = self::targetDirectory($options);
            $final = self::uniquePath($directory, self::fileName($plan, $options));
            $partial = $final . '.parcial';

            $writer = self::openWriter([self::createFile($partial)], !empty($options['gzip']));
            $stats = self::dump($plan, $writer);
            self::closeWriter($writer);
            $writer = null;

            // Sem sobrescrever: se outro processo criou o mesmo nome, gera outro.
            if (file_exists($final)) {
                $final = self::uniquePath($directory, basename($final));
            }

            if (!@rename($partial, $final)) {
                throw new RuntimeException('Backup gerado, mas não foi possível renomear o arquivo .parcial.');
            }

            @chmod($final, self::$filePermission);

            $data = self::summary($plan, $stats, $started) + [
                'arquivo' => basename($final),
                'caminho' => $final,
                'bytes' => (int) filesize($final),
            ];

            self::log('info', 'Backup do banco salvo.', $data);

            return self::result(true, 'Backup gerado com sucesso.', $data);
        } catch (Throwable $error) {
            if ($writer !== null) {
                self::markIncomplete($writer, $error);
            }

            $data = ['modo' => $mode, 'arquivo_parcial' => $partial !== null && file_exists($partial) ? $partial : null];

            self::log('error', 'Falha no backup do banco.', $data + ['erro' => $error->getMessage()]);

            return self::result(false, 'Falha ao gerar o backup: ' . $error->getMessage(), $data, [$error->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Download
    // ------------------------------------------------------------------

    public static function downloadSchema(array $options = [])
    {
        return self::download(self::SCHEMA, $options);
    }

    public static function downloadFull(array $options = [])
    {
        return self::download(self::FULL, $options);
    }

    /*
     * Envia o backup direto para o navegador, gerando enquanto lê o banco
     * (não guarda tudo na memória). Com 'save_copy' => true, grava ao
     * mesmo tempo uma cópia no servidor.
     *
     * Erros de validação (tabela inexistente, banco não suportado) voltam
     * no resultado antes de qualquer cabeçalho ser enviado. Se o banco
     * falhar no meio do envio, o arquivo termina com um aviso
     * "BACKUP INCOMPLETO".
     */
    public static function download($mode, array $options = [])
    {
        $started = microtime(true);
        $writer = null;
        $copy = null;

        try {
            $mode = self::normalizeMode($mode);
            $plan = self::plan($mode, $options);
            $fileName = self::fileName($plan, $options);
            $handles = [];

            if (!empty($options['save_copy'])) {
                $copy = self::uniquePath(self::targetDirectory($options), $fileName);
                $handles[] = self::createFile($copy . '.parcial');
            }

            $output = fopen('php://output', 'wb');

            if ($output === false) {
                throw new RuntimeException('Não foi possível abrir a saída para download.');
            }

            $handles[] = $output;

            self::clearOutputBuffers();

            if (!headers_sent()) {
                header('Content-Type: ' . (!empty($options['gzip']) ? 'application/gzip' : 'application/sql; charset=UTF-8'));
                header('Content-Transfer-Encoding: binary');
                header('X-Content-Type-Options: nosniff');
                header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
                header(
                    'Content-Disposition: attachment; filename="' . addslashes($fileName) .
                    '"; filename*=UTF-8\'\'' . rawurlencode($fileName)
                );
            }

            $writer = self::openWriter($handles, !empty($options['gzip']));
            $stats = self::dump($plan, $writer);
            self::closeWriter($writer);
            $writer = null;

            $data = self::summary($plan, $stats, $started) + ['arquivo' => $fileName];

            if ($copy !== null) {
                if (file_exists($copy)) {
                    $copy = self::uniquePath(dirname($copy), basename($copy));
                }

                if (@rename($copy . '.parcial', $copy)) {
                    @chmod($copy, self::$filePermission);
                    $data['copia'] = $copy;
                } else {
                    $data['copia'] = $copy . '.parcial';
                }
            }

            self::log('info', 'Backup do banco baixado.', $data);

            return self::result(true, 'Backup enviado com sucesso.', $data);
        } catch (Throwable $error) {
            if ($writer !== null) {
                self::markIncomplete($writer, $error);
            }

            $data = ['modo' => $mode, 'copia_parcial' => $copy !== null && file_exists($copy . '.parcial') ? $copy . '.parcial' : null];

            self::log('error', 'Falha no download do backup.', $data + ['erro' => $error->getMessage()]);

            return self::result(false, 'Falha ao gerar o backup: ' . $error->getMessage(), $data, [$error->getMessage()]);
        }
    }

    // ------------------------------------------------------------------
    // Consulta
    // ------------------------------------------------------------------

    /*
     * Tabelas e views do banco, com tipo, engine, linhas (estimativa do
     * MySQL) e tamanho. Útil para escolher o que entra no backup.
     */
    public static function tables(array $options = [])
    {
        $db = self::connection($options);
        $list = [];

        if (self::engine($db) === DbConnectionHelper::POSTGRESQL) {
            return self::pgTables($db, $options);
        }

        foreach (self::query($db, 'SHOW TABLE STATUS', 'loadAssocList') as $row) {
            $view = isset($row['Comment']) && strtoupper((string) $row['Comment']) === 'VIEW' && $row['Engine'] === null;

            $list[] = [
                'nome' => $row['Name'],
                'tipo' => $view ? 'view' : 'tabela',
                'engine' => $row['Engine'],
                'linhas_aprox' => $row['Rows'] === null ? null : (int) $row['Rows'],
                'tamanho_bytes' => (int) $row['Data_length'] + (int) $row['Index_length'],
            ];
        }

        return $list;
    }

    // ------------------------------------------------------------------
    // Planejamento: valida tudo antes de abrir arquivo ou enviar cabeçalho
    // ------------------------------------------------------------------

    private static function plan($mode, array $options)
    {
        $db = self::connection($options);
        $engine = self::engine($db);
        $prefix = method_exists($db, 'getPrefix') ? (string) $db->getPrefix() : '';

        // Nome exibido => ['kind' => 'table'|'view', 'name' => nome sem schema, ...]
        if ($engine === DbConnectionHelper::POSTGRESQL) {
            $schemas = self::pgSchemas($db, $options);
            $objects = self::pgObjects($db, $schemas);
            $database = (string) self::pgValue($db, 'SELECT current_database()');
        } else {
            $schemas = [];
            $objects = [];

            foreach (self::query($db, 'SHOW FULL TABLES', 'loadRowList') as $row) {
                $objects[$row[0]] = [
                    'kind' => strtoupper((string) $row[1]) === 'VIEW' ? 'view' : 'table',
                    'name' => $row[0],
                ];
            }

            $database = (string) self::query($db, 'SELECT DATABASE()', 'loadResult');
        }

        $selected = array_keys($objects);

        if (!empty($options['tables'])) {
            $wanted = self::names($options['tables'], $prefix);
            $selected = [];

            foreach ($wanted as $name) {
                $found = self::matchObjects($objects, $name);

                if (!$found) {
                    throw new InvalidArgumentException('Tabela não encontrada: ' . $name);
                }

                $selected = array_merge($selected, $found);
            }

            $selected = array_values(array_unique($selected));

            // Tabela particionada (PostgreSQL) leva junto as partições, onde
            // ficam os dados.
            do {
                $added = false;
                $selectedOids = [];

                foreach ($selected as $key) {
                    if (isset($objects[$key]['oid'])) {
                        $selectedOids[$objects[$key]['oid']] = true;
                    }
                }

                foreach ($objects as $key => $object) {
                    if (!empty($object['partition']) && isset($selectedOids[$object['parents'][0]]) && !in_array($key, $selected, true)) {
                        $selected[] = $key;
                        $added = true;
                    }
                }
            } while ($added);
        }

        if (!empty($options['prefix_only']) && $prefix !== '') {
            $selected = array_values(array_filter($selected, function ($key) use ($objects, $prefix) {
                return strpos($objects[$key]['name'], $prefix) === 0;
            }));
        }

        if (!empty($options['exclude'])) {
            foreach (self::names($options['exclude'], $prefix) as $name) {
                $selected = array_values(array_diff($selected, self::matchObjects($objects, $name)));
            }
        }

        $tables = [];
        $views = [];

        foreach ($selected as $key) {
            if ($objects[$key]['kind'] === 'view') {
                $views[] = $key;
            } else {
                $tables[] = $key;
            }
        }

        if (array_key_exists('views', $options) && !$options['views']) {
            $views = [];
        }

        if (!$tables && !$views) {
            throw new InvalidArgumentException('Nenhuma tabela para o backup.');
        }

        $excludeData = [];

        foreach (empty($options['exclude_data']) ? [] : self::names($options['exclude_data'], $prefix) as $name) {
            $excludeData = array_merge($excludeData, self::matchObjects($objects, $name));
        }

        return [
            'db' => $db,
            'engine' => $engine,
            'mode' => $mode,
            'database' => $database,
            'schemas' => $schemas,
            'objects' => $objects,
            'tables' => $tables,
            'views' => $views,
            'exclude_data' => $excludeData,
            'triggers' => !array_key_exists('triggers', $options) || $options['triggers'],
            'consistent' => !array_key_exists('consistent', $options) || $options['consistent'],
        ];
    }

    /*
     * 'clientes' casa com a tabela de mesmo nome em qualquer schema;
     * 'vendas.clientes' só com a do schema vendas.
     */
    private static function matchObjects(array $objects, $name)
    {
        if (isset($objects[$name])) {
            return [$name];
        }

        $found = [];

        foreach ($objects as $key => $object) {
            if ($object['name'] === $name
                || (isset($object['schema']) && $object['schema'] . '.' . $object['name'] === $name)) {
                $found[] = $key;
            }
        }

        return $found;
    }

    private static function connection(array $options)
    {
        $db = DbConnectionHelper::resolve(isset($options['connection']) ? $options['connection'] : null);

        self::engine($db);

        return $db;
    }

    private static function engine($db)
    {
        $type = DbConnectionHelper::serverType($db);

        if ($type !== DbConnectionHelper::MYSQL && $type !== DbConnectionHelper::POSTGRESQL) {
            throw new RuntimeException('BackupDatabaseHelper suporta MySQL/MariaDB e PostgreSQL.');
        }

        return $type;
    }

    private static function names($names, $prefix)
    {
        $result = [];

        foreach ((array) $names as $name) {
            $name = trim((string) $name);

            if ($name !== '') {
                $result[] = strpos($name, '#__') === 0 ? $prefix . substr($name, 3) : $name;
            }
        }

        return array_values(array_unique($result));
    }

    private static function normalizeMode($mode)
    {
        $mode = strtolower(trim((string) $mode));

        if ($mode === 'estrutura' || $mode === 'structure') {
            return self::SCHEMA;
        }

        if ($mode === 'completo' || $mode === 'data') {
            return self::FULL;
        }

        if ($mode !== self::SCHEMA && $mode !== self::FULL) {
            throw new InvalidArgumentException('Modo inválido: use BackupDatabaseHelper::SCHEMA ou ::FULL.');
        }

        return $mode;
    }

    // ------------------------------------------------------------------
    // Geração do SQL
    // ------------------------------------------------------------------

    private static function dump(array $plan, array &$writer)
    {
        $db = $plan['db'];

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if ($plan['engine'] === DbConnectionHelper::POSTGRESQL) {
            return self::pgDump($plan, $writer);
        }

        $stats = ['tabelas' => 0, 'views' => 0, 'triggers' => 0, 'linhas' => 0, 'snapshot' => false];

        // Lê em UTC (TIMESTAMP volta igual em qualquer servidor) e em
        // utf8mb4 (emojis não viram "?"). A sessão volta ao que era no final.
        $session = self::query(
            $db,
            'SELECT @@session.time_zone AS time_zone, @@session.character_set_client AS charset, @@session.collation_connection AS collation',
            'loadAssoc'
        );

        self::session($db, "SET SESSION time_zone = '+00:00'");
        self::session($db, "SET NAMES 'utf8mb4'");

        try {
            if ($plan['consistent'] && self::canUseSnapshot($db)) {
                try {
                    self::session($db, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
                    self::session($db, 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
                    $stats['snapshot'] = true;
                } catch (Throwable $e) {
                    // Servidor antigo sem READ ONLY: segue sem snapshot.
                }
            }

            try {
                self::write($writer, self::header($plan));

                $triggers = $plan['triggers'] ? self::triggersByTable($db) : [];

                foreach ($plan['tables'] as $table) {
                    self::write($writer, self::createTable($db, $table, $plan['mode']));
                    $stats['tabelas']++;

                    if ($plan['mode'] === self::FULL && !in_array($table, $plan['exclude_data'], true)) {
                        $stats['linhas'] += self::dumpRows($db, $table, $writer);
                    }
                }

                // Triggers depois dos dados (não disparam na restauração) e
                // views por último (dependem das tabelas).
                foreach ($plan['tables'] as $table) {
                    if (isset($triggers[$table])) {
                        foreach ($triggers[$table] as $trigger) {
                            self::write($writer, self::createTrigger($db, $trigger));
                            $stats['triggers']++;
                        }
                    }
                }

                $views = [];

                foreach ($plan['views'] as $view) {
                    $views[$view] = self::createView($db, $view);
                }

                foreach (self::orderViews($views) as $sql) {
                    self::write($writer, $sql);
                    $stats['views']++;
                }

                self::write($writer, self::footer());
            } finally {
                // Transação só de leitura: desfaz em vez de confirmar.
                if ($stats['snapshot']) {
                    self::session($db, 'ROLLBACK');
                }
            }
        } finally {
            self::restoreSession($db, $session);
        }

        return $stats;
    }

    private static function header(array $plan)
    {
        $db = $plan['db'];
        $version = (string) self::query($db, 'SELECT VERSION()', 'loadResult');
        $charset = (string) self::query($db, 'SELECT @@character_set_results', 'loadResult');

        if ($charset === '' || !preg_match('/^\w+$/', $charset)) {
            $charset = 'utf8mb4';
        }

        return "-- Backup gerado por BackupDatabaseHelper\n" .
            '-- Banco: ' . $plan['database'] . "\n" .
            '-- Servidor: ' . $version . "\n" .
            '-- Modo: ' . ($plan['mode'] === self::FULL ? 'estrutura + dados' : 'somente estrutura (tabelas vazias)') . "\n" .
            '-- Gerado em: ' . gmdate('Y-m-d H:i:s') . " UTC\n" .
            "--\n" .
            "-- Este arquivo NÃO apaga nada ao ser restaurado: não há DROP, DELETE,\n" .
            "-- TRUNCATE, REPLACE nem ALTER. Tabelas usam CREATE TABLE IF NOT EXISTS\n" .
            "-- e os dados usam INSERT IGNORE (registros existentes são mantidos).\n" .
            "-- Atenção: tabelas sem chave primária/única recebem as linhas de novo\n" .
            "-- se o arquivo for restaurado duas vezes no mesmo banco.\n\n" .
            "/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n" .
            "/*!40101 SET NAMES " . $charset . " */;\n" .
            "/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n" .
            "/*!40103 SET TIME_ZONE='+00:00' */;\n" .
            "/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n" .
            "/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n" .
            "/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n\n";
    }

    private static function footer()
    {
        return "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n" .
            "/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n" .
            "/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n" .
            "/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n" .
            "/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n\n" .
            "-- Fim do backup\n";
    }

    private static function createTable($db, $table, $mode)
    {
        $row = self::query($db, 'SHOW CREATE TABLE ' . $db->quoteName($table), 'loadRow');
        $sql = isset($row[1]) ? (string) $row[1] : '';

        if (stripos($sql, 'CREATE TABLE ') !== 0) {
            throw new RuntimeException('Não foi possível ler a estrutura da tabela ' . $table . '.');
        }

        $sql = 'CREATE TABLE IF NOT EXISTS ' . substr($sql, strlen('CREATE TABLE '));

        // Tabela vazia começa a contagem do zero.
        if ($mode === self::SCHEMA) {
            $end = strrpos($sql, ')');
            $sql = substr($sql, 0, $end) . preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', substr($sql, $end));
        }

        return "--\n-- Tabela " . $table . "\n--\n\n" . self::safeStatement($sql) . ";\n\n";
    }

    private static function createView($db, $view)
    {
        $row = self::query($db, 'SHOW CREATE VIEW ' . $db->quoteName($view), 'loadRow');
        $sql = isset($row[1]) ? (string) $row[1] : '';

        if ($sql === '') {
            throw new RuntimeException('Não foi possível ler a view ' . $view . '.');
        }

        $sql = self::safeStatement(self::withoutDefiner($sql));

        // IF NOT EXISTS só no MariaDB (o MySQL não tem para views e ignora
        // o comentário /*M! */). No MySQL, view já existente dá erro e não
        // é alterada; por isso as views ficam no fim do arquivo.
        $sql = preg_replace('/^(CREATE\s.*?\bVIEW)\s+/is', '$1 /*M!100103 IF NOT EXISTS */ ', $sql, 1);

        return "--\n-- View " . $view . "\n--\n\n" . $sql . ";\n\n";
    }

    /*
     * O MySQL lista as views em ordem alfabética; uma view que lê outra
     * precisa vir depois dela, senão a restauração falha. A dependência é
     * achada pelo nome entre crases na definição (SHOW CREATE VIEW sempre
     * usa crases). Num ciclo (não deveria existir), mantém a ordem original.
     */
    private static function orderViews(array $views)
    {
        $deps = [];

        foreach ($views as $name => $sql) {
            $body = substr($sql, strpos($sql, ' AS ') ?: 0);

            foreach (array_keys($views) as $other) {
                if ($other !== $name && strpos($body, '`' . str_replace('`', '``', $other) . '`') !== false) {
                    $deps[$name][] = $other;
                }
            }
        }

        $ordered = [];
        $pending = array_keys($views);

        while ($pending) {
            $progress = false;

            foreach ($pending as $i => $name) {
                if (!isset($deps[$name]) || !array_diff($deps[$name], array_keys($ordered))) {
                    $ordered[$name] = $views[$name];
                    unset($pending[$i]);
                    $progress = true;
                }
            }

            if (!$progress) {
                foreach ($pending as $name) {
                    $ordered[$name] = $views[$name];
                }

                break;
            }
        }

        return $ordered;
    }

    private static function createTrigger($db, $trigger)
    {
        $row = self::query($db, 'SHOW CREATE TRIGGER ' . $db->quoteName($trigger), 'loadAssoc');
        $sql = isset($row['SQL Original Statement']) ? (string) $row['SQL Original Statement'] : '';

        if ($sql === '') {
            throw new RuntimeException('Não foi possível ler o trigger ' . $trigger . '.');
        }

        $sql = self::safeStatement(self::withoutDefiner($sql));

        // IF NOT EXISTS no MySQL 8.0.29+ e no MariaDB; versões antigas ignoram.
        $sql = preg_replace('/^CREATE\s+TRIGGER\s+/i', 'CREATE TRIGGER /*!80029 IF NOT EXISTS */ ', $sql, 1);

        return "--\n-- Trigger " . $trigger . "\n--\n\nDELIMITER ;;\n" . $sql . " ;;\nDELIMITER ;\n\n";
    }

    private static function triggersByTable($db)
    {
        $result = [];

        foreach (self::query($db, 'SHOW TRIGGERS', 'loadAssocList') as $row) {
            $result[$row['Table']][] = $row['Trigger'];
        }

        return $result;
    }

    /*
     * Escreve os INSERTs lendo a tabela em blocos. Com chave primária de
     * uma coluna, pagina pela chave (rápido em tabelas grandes); sem ela,
     * usa LIMIT/OFFSET.
     */
    private static function dumpRows($db, $table, array &$writer)
    {
        $columns = [];
        $kinds = [];
        $expressions = [];

        foreach (self::query($db, 'SHOW FULL COLUMNS FROM ' . $db->quoteName($table), 'loadAssocList') as $column) {
            // Colunas geradas são calculadas pelo banco e não aceitam INSERT.
            if (preg_match('/\b(GENERATED|VIRTUAL|PERSISTENT|STORED)\b/i', (string) $column['Extra'])) {
                continue;
            }

            $quoted = $db->quoteName($column['Field']);
            $type = (string) $column['Type'];

            // Binários vêm em hexadecimal e BIT como número: o driver não
            // devolve esses tipos de forma confiável.
            if (preg_match('/^bit\b/i', $type)) {
                $kinds[] = 'bit';
                $expressions[] = '(' . $quoted . ' + 0)';
            } elseif (preg_match(self::$binaryTypes, $type)) {
                $kinds[] = 'binary';
                $expressions[] = 'HEX(' . $quoted . ')';
            } else {
                $kinds[] = 'text';
                $expressions[] = $quoted;
            }

            $columns[] = $column['Field'];
        }

        if (!$columns) {
            return 0;
        }

        $primary = [];

        foreach (self::query($db, 'SHOW KEYS FROM ' . $db->quoteName($table), 'loadAssocList') as $key) {
            if ($key['Key_name'] === 'PRIMARY') {
                $primary[(int) $key['Seq_in_index']] = $key['Column_name'];
            }
        }

        ksort($primary);
        $primary = array_values($primary);

        $keyIndex = count($primary) === 1 ? array_search($primary[0], $columns, true) : false;

        $quotedTable = $db->quoteName($table);
        $select = 'SELECT ' . implode(', ', $expressions) . ' FROM ' . $quotedTable;
        $insert = 'INSERT IGNORE INTO ' . $quotedTable . ' (' . implode(', ', array_map([$db, 'quoteName'], $columns)) . ') VALUES';

        $total = 0;
        $offset = 0;
        $lastKey = null;
        $values = [];
        $size = 0;

        self::write($writer, "--\n-- Dados de " . $table . "\n--\n\n");

        while (true) {
            if ($keyIndex !== false) {
                $sql = $select .
                    ($lastKey === null ? '' : ' WHERE ' . $db->quoteName($primary[0]) . ' > ' . $lastKey) .
                    ' ORDER BY ' . $db->quoteName($primary[0]) .
                    ' LIMIT ' . self::$chunkSize;
            } else {
                $sql = $select .
                    ($primary ? ' ORDER BY ' . implode(', ', array_map([$db, 'quoteName'], $primary)) : '') .
                    ' LIMIT ' . $offset . ', ' . self::$chunkSize;
            }

            $rows = self::query($db, $sql, 'loadRowList');

            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $encoded = [];

                foreach ($row as $i => $value) {
                    $encoded[] = self::encodeValue($db, $value, $kinds[$i]);
                }

                $tuple = '(' . implode(',', $encoded) . ')';
                $values[] = $tuple;
                $size += strlen($tuple);

                if (count($values) >= self::$rowsPerInsert || $size >= self::$maxInsertBytes) {
                    self::write($writer, $insert . "\n" . implode(",\n", $values) . ";\n");
                    $values = [];
                    $size = 0;
                }

                if ($keyIndex !== false) {
                    $lastKey = $encoded[$keyIndex];
                }
            }

            $total += count($rows);
            $offset += count($rows);

            if (count($rows) < self::$chunkSize) {
                break;
            }
        }

        if ($values) {
            self::write($writer, $insert . "\n" . implode(",\n", $values) . ";\n");
        }

        self::write($writer, "\n");

        return $total;
    }

    private static function encodeValue($db, $value, $kind)
    {
        if ($value === null) {
            return 'NULL';
        }

        $value = (string) $value;

        if ($kind === 'binary') {
            if (!preg_match('/^[0-9A-Fa-f]*$/', $value)) {
                throw new RuntimeException('Valor binário inesperado na leitura do banco.');
            }

            return $value === '' ? "''" : '0x' . $value;
        }

        if ($kind === 'bit') {
            if (!ctype_digit($value)) {
                throw new RuntimeException('Valor BIT inesperado na leitura do banco.');
            }

            return $value;
        }

        return $db->quote($value);
    }

    private static function withoutDefiner($sql)
    {
        return preg_replace('/\s+DEFINER\s*=\s*(`[^`]*`|\'[^\']*\'|\S+?)@(`[^`]*`|\'[^\']*\'|\S+)/i', '', $sql, 1);
    }

    /*
     * Última barreira: nenhum comando do arquivo pode começar com algo
     * que apague ou altere dados existentes.
     */
    private static function safeStatement($sql)
    {
        $sql = trim((string) $sql);

        if (!preg_match('/^CREATE\s+(TABLE\s+IF\s+NOT\s+EXISTS|(ALGORITHM\s*=\s*\w+\s+)?(SQL\s+SECURITY\s+\w+\s+)?VIEW|TRIGGER)\s/i', $sql)) {
            throw new LogicException('Comando inesperado no backup: ' . substr($sql, 0, 60));
        }

        return $sql;
    }

    // ------------------------------------------------------------------
    // PostgreSQL
    //
    // O PostgreSQL não tem SHOW CREATE TABLE: a estrutura é montada a
    // partir do catálogo (pg_catalog). Tudo é lido dentro de uma transação
    // READ ONLY, em que o próprio servidor recusa qualquer escrita, e que
    // é desfeita (ROLLBACK) no final.
    //
    // Ordem do arquivo: schemas, extensões, tipos, funções, sequências,
    // tabelas, dados, valores das sequências, índices, chaves
    // estrangeiras, triggers e views.
    // ------------------------------------------------------------------

    private static function pgSchemas($db, array $options)
    {
        $rows = self::pgRows(
            $db,
            "SELECT n.oid, n.nspname FROM pg_catalog.pg_namespace n
             WHERE n.nspname NOT LIKE 'pg\\_%' AND n.nspname <> 'information_schema'
               AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend d
                               WHERE d.classid = 'pg_catalog.pg_namespace'::pg_catalog.regclass
                                 AND d.objid = n.oid AND d.deptype = 'e')
             ORDER BY n.nspname"
        );

        $schemas = [];

        foreach ($rows as $row) {
            $schemas[(int) $row['oid']] = $row['nspname'];
        }

        if (!empty($options['schemas'])) {
            $wanted = array_map('strval', (array) $options['schemas']);
            $missing = array_diff($wanted, $schemas);

            if ($missing) {
                throw new InvalidArgumentException('Schema(s) não encontrado(s): ' . implode(', ', $missing));
            }

            $schemas = array_intersect($schemas, $wanted);
        }

        if (!$schemas) {
            throw new InvalidArgumentException('Nenhum schema para o backup.');
        }

        return $schemas;
    }

    private static function pgObjects($db, array $schemas)
    {
        if ((int) self::pgValue($db, "SELECT pg_catalog.current_setting('server_version_num')") < 100000) {
            throw new RuntimeException('BackupDatabaseHelper precisa do PostgreSQL 10 ou mais novo.');
        }

        $rows = self::pgRows(
            $db,
            "SELECT c.oid, n.nspname, c.relname, c.relkind, c.relpersistence, c.relispartition,
                    pg_catalog.pg_get_expr(c.relpartbound, c.oid) AS partbound,
                    CASE WHEN c.relkind = 'p' THEN pg_catalog.pg_get_partkeydef(c.oid) END AS partkey,
                    pg_catalog.array_to_string(c.reloptions, ', ') AS options,
                    (SELECT pg_catalog.string_agg(i.inhparent::text, ',' ORDER BY i.inhseqno)
                       FROM pg_catalog.pg_inherits i WHERE i.inhrelid = c.oid) AS parents
             FROM pg_catalog.pg_class c
             JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind IN ('r', 'p', 'v', 'm') AND c.relnamespace IN (" . self::pgOids(array_keys($schemas)) . ")
               AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend d
                               WHERE d.classid = 'pg_catalog.pg_class'::pg_catalog.regclass
                                 AND d.objid = c.oid AND d.deptype = 'e')
             ORDER BY n.nspname, c.relname"
        );

        $objects = [];

        foreach ($rows as $row) {
            $key = $row['nspname'] === 'public' ? $row['relname'] : $row['nspname'] . '.' . $row['relname'];

            $objects[$key] = [
                'kind' => in_array($row['relkind'], ['v', 'm'], true) ? 'view' : 'table',
                'name' => $row['relname'],
                'schema' => $row['nspname'],
                'oid' => (int) $row['oid'],
                'relkind' => $row['relkind'],
                'unlogged' => $row['relpersistence'] === 'u',
                'partition' => self::pgBool($row['relispartition']),
                'partbound' => $row['partbound'],
                'partkey' => $row['partkey'],
                'options' => (string) $row['options'],
                'parents' => $row['parents'] === null || $row['parents'] === '' ? [] : array_map('intval', explode(',', $row['parents'])),
                'qualified' => self::pgIdent($row['nspname']) . '.' . self::pgIdent($row['relname']),
            ];
        }

        return $objects;
    }

    private static function pgTables($db, array $options)
    {
        $schemas = self::pgSchemas($db, $options);
        $list = [];

        $rows = self::pgRows(
            $db,
            "SELECT n.nspname, c.relname, c.relkind, c.reltuples::bigint AS rows,
                    pg_catalog.pg_total_relation_size(c.oid) AS size
             FROM pg_catalog.pg_class c
             JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relkind IN ('r', 'p', 'v', 'm') AND c.relnamespace IN (" . self::pgOids(array_keys($schemas)) . ")
             ORDER BY n.nspname, c.relname"
        );

        foreach ($rows as $row) {
            $list[] = [
                'nome' => $row['nspname'] === 'public' ? $row['relname'] : $row['nspname'] . '.' . $row['relname'],
                'tipo' => in_array($row['relkind'], ['v', 'm'], true) ? 'view' : 'tabela',
                'engine' => null,
                'linhas_aprox' => (int) $row['rows'] < 0 || in_array($row['relkind'], ['v', 'p'], true) ? null : (int) $row['rows'],
                'tamanho_bytes' => (int) $row['size'],
            ];
        }

        return $list;
    }

    private static function pgDump(array $plan, array &$writer)
    {
        $db = $plan['db'];
        $stats = ['tabelas' => 0, 'views' => 0, 'triggers' => 0, 'linhas' => 0, 'snapshot' => false];

        self::pgBegin($db, $plan['consistent']);
        $stats['snapshot'] = (bool) $plan['consistent'];

        try {
            $version = (int) self::pgValue($db, "SELECT pg_catalog.current_setting('server_version_num')");
            $schemaOids = self::pgOids(array_keys($plan['schemas']));
            $full = $plan['mode'] === self::FULL;
            $tag = '$bkp_' . bin2hex(random_bytes(4)) . '$';

            $byOid = [];

            foreach ($plan['objects'] as $key => $object) {
                $byOid[$object['oid']] = $key;
            }

            $tables = self::pgOrderTables($plan, $byOid);
            $tableOids = [];

            foreach ($tables as $key) {
                $tableOids[$plan['objects'][$key]['oid']] = true;
            }

            self::write($writer, self::pgHeader($plan, $db));

            // Schemas e extensões
            $schemaNames = array_values($plan['schemas']);
            $extensions = self::pgRows(
                $db,
                "SELECT e.extname, n.nspname FROM pg_catalog.pg_extension e
                 JOIN pg_catalog.pg_namespace n ON n.oid = e.extnamespace
                 WHERE e.extname <> 'plpgsql' ORDER BY e.oid"
            );

            foreach ($extensions as $extension) {
                $schemaNames[] = $extension['nspname'];
            }

            foreach (array_unique($schemaNames) as $schema) {
                self::pgStatement($writer, 'CREATE SCHEMA IF NOT EXISTS ' . self::pgIdent($schema) . ';', $tag);
            }

            self::write($writer, "\n");

            foreach ($extensions as $extension) {
                self::pgStatement(
                    $writer,
                    'CREATE EXTENSION IF NOT EXISTS ' . self::pgIdent($extension['extname']) .
                    ' WITH SCHEMA ' . self::pgIdent($extension['nspname']) . ';',
                    $tag
                );
            }

            // Tipos (enum, domínio e composto) e funções
            foreach (self::pgTypes($db, $schemaOids) as $type) {
                self::pgStatement($writer, self::pgGuarded($type, $tag), $tag);
            }

            $kindFilter = $version >= 110000 ? "p.prokind IN ('f', 'p')" : 'NOT p.proisagg AND NOT p.proiswindow';

            $functions = self::pgRows(
                $db,
                "SELECT p.oid, pg_catalog.pg_get_functiondef(p.oid) AS def
                 FROM pg_catalog.pg_proc p
                 WHERE p.pronamespace IN (" . $schemaOids . ") AND " . $kindFilter . "
                   AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend d
                                   WHERE d.classid = 'pg_catalog.pg_proc'::pg_catalog.regclass
                                     AND d.objid = p.oid AND d.deptype = 'e')
                 ORDER BY p.oid"
            );

            foreach ($functions as $function) {
                // Sem OR REPLACE: função que já existe no destino é mantida.
                $def = preg_replace('/^CREATE OR REPLACE (FUNCTION|PROCEDURE)/', 'CREATE $1', trim($function['def']));
                self::pgStatement($writer, self::pgGuarded($def, $tag), $tag);
            }

            // Sequências avulsas (as de colunas IDENTITY nascem com a tabela)
            $sequences = self::pgRows(
                $db,
                "SELECT c.oid, n.nspname, c.relname, pg_catalog.format_type(s.seqtypid, NULL) AS type,
                        s.seqstart, s.seqincrement, s.seqmin, s.seqmax, s.seqcache, s.seqcycle
                 FROM pg_catalog.pg_class c
                 JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
                 JOIN pg_catalog.pg_sequence s ON s.seqrelid = c.oid
                 WHERE c.relkind = 'S' AND c.relnamespace IN (" . $schemaOids . ")
                   AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend d
                                   WHERE d.classid = 'pg_catalog.pg_class'::pg_catalog.regclass
                                     AND d.objid = c.oid AND d.deptype IN ('i', 'e'))
                 ORDER BY c.oid"
            );

            if ($sequences) {
                self::write($writer, "\n--\n-- Sequências\n--\n\n");
            }

            foreach ($sequences as $sequence) {
                self::pgStatement(
                    $writer,
                    'CREATE SEQUENCE IF NOT EXISTS ' . self::pgIdent($sequence['nspname']) . '.' . self::pgIdent($sequence['relname']) .
                    ' AS ' . $sequence['type'] . self::pgSequenceOptions($sequence) . ';',
                    $tag
                );
            }

            // Tabelas
            $columns = [];

            foreach ($tables as $key) {
                $object = $plan['objects'][$key];
                $columns[$key] = self::pgColumns($db, $object['oid'], $version);

                self::write($writer, "\n--\n-- Tabela " . $key . "\n--\n\n");
                self::pgStatement($writer, self::pgCreateTable($db, $plan['objects'], $byOid, $key, $columns[$key], $version), $tag);
                $stats['tabelas']++;
            }

            // Dados
            if ($full) {
                foreach ($tables as $key) {
                    if ($plan['objects'][$key]['relkind'] === 'r' && !in_array($key, $plan['exclude_data'], true)) {
                        $stats['linhas'] += self::pgDumpRows($db, $plan['objects'][$key], $columns[$key], $writer);
                    }
                }

                // Sequências continuam de onde pararam, sem nunca voltar.
                $setvals = '';

                foreach ($sequences as $sequence) {
                    $setvals .= self::pgSetval(
                        $db,
                        self::pgIdent($sequence['nspname']) . '.' . self::pgIdent($sequence['relname']),
                        self::pgLiteral(self::pgIdent($sequence['nspname']) . '.' . self::pgIdent($sequence['relname'])) . '::pg_catalog.regclass',
                        (int) $sequence['seqincrement']
                    );
                }

                foreach ($tables as $key) {
                    foreach ($columns[$key] as $column) {
                        if ($column['identity_sequence'] !== null) {
                            $setvals .= self::pgSetval(
                                $db,
                                $column['identity_sequence'],
                                'pg_catalog.pg_get_serial_sequence(' . self::pgLiteral($plan['objects'][$key]['qualified']) .
                                    ', ' . self::pgLiteral($column['name']) . ')::pg_catalog.regclass',
                                (int) $column['identity']['seqincrement']
                            );
                        }
                    }
                }

                if ($setvals !== '') {
                    self::write($writer, "--\n-- Valores das sequências\n--\n\n" . $setvals . "\n");
                }
            }

            // Índices e chaves estrangeiras
            self::write($writer, "--\n-- Índices\n--\n\n");

            foreach ($tables as $key) {
                foreach (self::pgIndexes($db, $plan['objects'][$key]['oid'], $version) as $index) {
                    self::pgStatement($writer, $index, $tag);
                }
            }

            self::write($writer, "\n--\n-- Chaves estrangeiras\n--\n\n");

            foreach ($tables as $key) {
                $object = $plan['objects'][$key];
                $foreign = self::pgRows(
                    $db,
                    "SELECT c.conname, c.confrelid, pg_catalog.pg_get_constraintdef(c.oid) AS def
                     FROM pg_catalog.pg_constraint c
                     WHERE c.conrelid = " . $object['oid'] . " AND c.contype = 'f' AND c.conislocal" .
                     ($version >= 110000 ? ' AND c.conparentid = 0' : '') . "
                     ORDER BY c.conname"
                );

                foreach ($foreign as $constraint) {
                    if (!isset($tableOids[(int) $constraint['confrelid']])) {
                        self::write($writer, '-- Chave ' . $constraint['conname'] . ' de ' . $key . " não incluída: a tabela referenciada está fora do backup.\n");
                        continue;
                    }

                    self::pgStatement(
                        $writer,
                        self::pgGuarded(
                            'ALTER TABLE ' . $object['qualified'] . ' ADD CONSTRAINT ' . self::pgIdent($constraint['conname']) . ' ' . $constraint['def'],
                            $tag
                        ),
                        $tag
                    );
                }
            }

            // Triggers (depois dos dados: não disparam na restauração)
            if ($plan['triggers']) {
                foreach ($tables as $key) {
                    $stats['triggers'] += self::pgTriggers($db, $plan['objects'][$key]['oid'], $version, $writer, $tag);
                }
            }

            // Views, na ordem de dependência
            foreach (self::pgOrderViews($db, $plan) as $key) {
                $object = $plan['objects'][$key];
                $def = rtrim(trim((string) self::pgValue($db, 'SELECT pg_catalog.pg_get_viewdef(' . $object['oid'] . ', true)')), ';');
                $with = $object['options'] !== '' ? ' WITH (' . $object['options'] . ')' : '';

                self::write($writer, "\n--\n-- View " . $key . "\n--\n\n");

                if ($object['relkind'] === 'm') {
                    self::pgStatement(
                        $writer,
                        'CREATE MATERIALIZED VIEW IF NOT EXISTS ' . $object['qualified'] . $with . " AS\n" . $def .
                        ($full ? "\nWITH DATA;" : "\nWITH NO DATA;"),
                        $tag
                    );

                    foreach (self::pgIndexes($db, $object['oid'], $version) as $index) {
                        self::pgStatement($writer, $index, $tag);
                    }
                } else {
                    self::pgStatement($writer, self::pgGuarded('CREATE VIEW ' . $object['qualified'] . $with . " AS\n" . $def, $tag), $tag);
                }

                if ($plan['triggers']) {
                    $stats['triggers'] += self::pgTriggers($db, $object['oid'], $version, $writer, $tag);
                }

                $stats['views']++;
            }

            self::write($writer, "\n-- Fim do backup\n");
        } finally {
            self::pgSession($db, 'ROLLBACK');
        }

        return $stats;
    }

    /*
     * Abre a transação de leitura. Se a conexão já estiver dentro de uma
     * transação, recusa: não daria para garantir o modo somente leitura,
     * e encerrar a transação de outra rotina não é papel do backup.
     */
    private static function pgBegin($db, $consistent)
    {
        $connection = self::pgNative($db);

        $busy = $connection instanceof PDO
            ? $connection->inTransaction()
            : pg_transaction_status($connection) !== PGSQL_TRANSACTION_IDLE;

        if ($busy) {
            throw new RuntimeException('Há uma transação aberta nesta conexão. Chame o backup fora de transações.');
        }

        self::pgSession($db, 'BEGIN TRANSACTION ISOLATION LEVEL ' . ($consistent ? 'REPEATABLE READ' : 'READ COMMITTED') . ', READ ONLY');

        // SET LOCAL vale só até o ROLLBACK: a sessão volta ao normal sozinha.
        $settings = [
            'search_path' => 'pg_catalog',
            'TimeZone' => 'UTC',
            'DateStyle' => 'ISO, YMD',
            'IntervalStyle' => 'postgres',
            'extra_float_digits' => '3',
            'bytea_output' => 'hex',
            'standard_conforming_strings' => 'on',
            'client_encoding' => 'UTF8',
        ];

        try {
            if ((string) self::pgValue($db, "SELECT pg_catalog.current_setting('transaction_read_only')") !== 'on') {
                throw new RuntimeException('Não foi possível abrir a transação somente leitura.');
            }

            foreach ($settings as $name => $value) {
                self::pgSession($db, 'SET LOCAL ' . $name . " = '" . $value . "'");
            }
        } catch (Throwable $error) {
            self::pgSession($db, 'ROLLBACK');

            throw $error;
        }
    }

    private static function pgHeader(array $plan, $db)
    {
        return "-- Backup gerado por BackupDatabaseHelper\n" .
            '-- Banco: ' . $plan['database'] . " (PostgreSQL)\n" .
            '-- Servidor: ' . self::pgValue($db, "SELECT pg_catalog.current_setting('server_version')") . "\n" .
            '-- Schemas: ' . implode(', ', $plan['schemas']) . "\n" .
            '-- Modo: ' . ($plan['mode'] === self::FULL ? 'estrutura + dados' : 'somente estrutura (tabelas vazias)') . "\n" .
            '-- Gerado em: ' . gmdate('Y-m-d H:i:s') . " UTC\n" .
            "--\n" .
            "-- Este arquivo NÃO apaga nada ao ser restaurado: não há DROP, DELETE,\n" .
            "-- TRUNCATE nem UPDATE, e nada usa OR REPLACE.\n" .
            "--   - tabelas, índices e sequências usam IF NOT EXISTS;\n" .
            "--   - tipos, funções, views, triggers e chaves estrangeiras ficam em blocos\n" .
            "--     DO que ignoram o objeto se ele já existir;\n" .
            "--   - os dados usam ON CONFLICT DO NOTHING (registros existentes ficam);\n" .
            "--   - o único ALTER é ADD CONSTRAINT, que só acrescenta chave estrangeira;\n" .
            "--   - sequências só avançam (setval nunca volta o contador).\n" .
            "-- Atenção: tabelas sem chave primária/única recebem as linhas de novo\n" .
            "-- se o arquivo for restaurado duas vezes no mesmo banco.\n" .
            "--\n" .
            "-- Restaurar: psql -d banco_destino -f arquivo.sql\n\n" .
            "SET client_encoding = 'UTF8';\n" .
            "SET standard_conforming_strings = on;\n" .
            "SET check_function_bodies = false;\n" .
            "SET client_min_messages = warning;\n" .
            "SELECT pg_catalog.set_config('search_path', '', false);\n\n";
    }

    /*
     * Pais antes dos filhos (partições e herança).
     */
    private static function pgOrderTables(array $plan, array $byOid)
    {
        $pending = $plan['tables'];
        $selected = array_flip($pending);
        $ordered = [];

        while ($pending) {
            $progress = false;

            foreach ($pending as $i => $key) {
                $ready = true;

                foreach ($plan['objects'][$key]['parents'] as $parent) {
                    if (isset($byOid[$parent]) && isset($selected[$byOid[$parent]]) && !in_array($byOid[$parent], $ordered, true)) {
                        $ready = false;
                    }
                }

                if ($ready) {
                    $ordered[] = $key;
                    unset($pending[$i]);
                    $progress = true;
                }
            }

            if (!$progress) {
                $ordered = array_merge($ordered, array_values($pending));
                break;
            }
        }

        return $ordered;
    }

    /*
     * Views que dependem de outras views vêm depois delas.
     */
    private static function pgOrderViews($db, array $plan)
    {
        if (!$plan['views']) {
            return [];
        }

        $oidToKey = [];

        foreach ($plan['views'] as $key) {
            $oidToKey[$plan['objects'][$key]['oid']] = $key;
        }

        $deps = [];

        $rows = self::pgRows(
            $db,
            "SELECT DISTINCT r.ev_class AS view, d.refobjid AS dep
             FROM pg_catalog.pg_rewrite r
             JOIN pg_catalog.pg_depend d ON d.objid = r.oid
                  AND d.classid = 'pg_catalog.pg_rewrite'::pg_catalog.regclass
                  AND d.refclassid = 'pg_catalog.pg_class'::pg_catalog.regclass
             WHERE d.refobjid <> r.ev_class AND r.ev_class IN (" . self::pgOids(array_keys($oidToKey)) . ")"
        );

        foreach ($rows as $row) {
            if (isset($oidToKey[(int) $row['dep']])) {
                $deps[$oidToKey[(int) $row['view']]][] = $oidToKey[(int) $row['dep']];
            }
        }

        $pending = $plan['views'];
        $ordered = [];

        while ($pending) {
            $progress = false;

            foreach ($pending as $i => $key) {
                if (!isset($deps[$key]) || !array_diff($deps[$key], $ordered)) {
                    $ordered[] = $key;
                    unset($pending[$i]);
                    $progress = true;
                }
            }

            if (!$progress) {
                $ordered = array_merge($ordered, array_values($pending));
                break;
            }
        }

        return $ordered;
    }

    private static function pgTypes($db, $schemaOids)
    {
        $rows = self::pgRows(
            $db,
            "SELECT t.oid, t.typtype, t.typrelid, t.typnotnull,
                    pg_catalog.format_type(t.oid, NULL) AS name,
                    pg_catalog.format_type(t.typbasetype, t.typtypmod) AS base,
                    t.typdefault AS def
             FROM pg_catalog.pg_type t
             WHERE t.typnamespace IN (" . $schemaOids . ")
               AND (t.typtype IN ('e', 'd')
                    OR (t.typtype = 'c' AND (SELECT c.relkind FROM pg_catalog.pg_class c WHERE c.oid = t.typrelid) = 'c'))
               AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_depend d
                               WHERE d.classid = 'pg_catalog.pg_type'::pg_catalog.regclass
                                 AND d.objid = t.oid AND d.deptype = 'e')
             ORDER BY t.oid"
        );

        $types = [];

        foreach ($rows as $row) {
            $oid = (int) $row['oid'];

            if ($row['typtype'] === 'e') {
                $labels = self::pgValue(
                    $db,
                    'SELECT pg_catalog.string_agg(pg_catalog.quote_literal(enumlabel), \', \' ORDER BY enumsortorder)
                     FROM pg_catalog.pg_enum WHERE enumtypid = ' . $oid
                );

                $types[] = 'CREATE TYPE ' . $row['name'] . ' AS ENUM (' . $labels . ')';
            } elseif ($row['typtype'] === 'd') {
                $sql = 'CREATE DOMAIN ' . $row['name'] . ' AS ' . $row['base'];

                if ($row['def'] !== null) {
                    $sql .= ' DEFAULT ' . $row['def'];
                }

                if (self::pgBool($row['typnotnull'])) {
                    $sql .= ' NOT NULL';
                }

                $constraints = self::pgRows(
                    $db,
                    "SELECT conname, pg_catalog.pg_get_constraintdef(oid) AS def
                     FROM pg_catalog.pg_constraint WHERE contypid = " . $oid . " AND contype = 'c' ORDER BY conname"
                );

                foreach ($constraints as $constraint) {
                    $sql .= ' CONSTRAINT ' . self::pgIdent($constraint['conname']) . ' ' . $constraint['def'];
                }

                $types[] = $sql;
            } else {
                $attributes = self::pgRows(
                    $db,
                    'SELECT attname, pg_catalog.format_type(atttypid, atttypmod) AS type
                     FROM pg_catalog.pg_attribute
                     WHERE attrelid = ' . (int) $row['typrelid'] . ' AND attnum > 0 AND NOT attisdropped
                     ORDER BY attnum'
                );

                $fields = [];

                foreach ($attributes as $attribute) {
                    $fields[] = self::pgIdent($attribute['attname']) . ' ' . $attribute['type'];
                }

                $types[] = 'CREATE TYPE ' . $row['name'] . ' AS (' . implode(', ', $fields) . ')';
            }
        }

        return $types;
    }

    private static function pgColumns($db, $oid, $version)
    {
        $rows = self::pgRows(
            $db,
            "SELECT a.attnum, a.attname, pg_catalog.format_type(a.atttypid, a.atttypmod) AS type,
                    a.attnotnull, a.attislocal, a.attidentity,
                    " . ($version >= 120000 ? 'a.attgenerated' : "''") . " AS attgenerated,
                    pg_catalog.pg_get_expr(ad.adbin, ad.adrelid) AS def,
                    CASE WHEN a.attcollation <> 0 AND a.attcollation <> t.typcollation
                         THEN pg_catalog.quote_ident(cn.nspname) || '.' || pg_catalog.quote_ident(co.collname) END AS collation
             FROM pg_catalog.pg_attribute a
             JOIN pg_catalog.pg_type t ON t.oid = a.atttypid
             LEFT JOIN pg_catalog.pg_attrdef ad ON ad.adrelid = a.attrelid AND ad.adnum = a.attnum
             LEFT JOIN pg_catalog.pg_collation co ON co.oid = a.attcollation
             LEFT JOIN pg_catalog.pg_namespace cn ON cn.oid = co.collnamespace
             WHERE a.attrelid = " . (int) $oid . " AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum"
        );

        $identities = [];

        foreach (self::pgRows(
            $db,
            "SELECT d.refobjsubid AS attnum, n.nspname, c.relname,
                    s.seqstart, s.seqincrement, s.seqmin, s.seqmax, s.seqcache, s.seqcycle
             FROM pg_catalog.pg_depend d
             JOIN pg_catalog.pg_sequence s ON s.seqrelid = d.objid
             JOIN pg_catalog.pg_class c ON c.oid = s.seqrelid
             JOIN pg_catalog.pg_namespace n ON n.oid = c.relnamespace
             WHERE d.classid = 'pg_catalog.pg_class'::pg_catalog.regclass
               AND d.refclassid = 'pg_catalog.pg_class'::pg_catalog.regclass
               AND d.refobjid = " . (int) $oid . " AND d.deptype = 'i'"
        ) as $row) {
            $identities[(int) $row['attnum']] = $row;
        }

        $columns = [];

        foreach ($rows as $row) {
            $identity = isset($identities[(int) $row['attnum']]) && in_array($row['attidentity'], ['a', 'd'], true)
                ? $identities[(int) $row['attnum']]
                : null;

            $columns[] = [
                'name' => $row['attname'],
                'type' => $row['type'],
                'not_null' => self::pgBool($row['attnotnull']),
                'local' => self::pgBool($row['attislocal']),
                'default' => $row['def'],
                'generated' => (string) $row['attgenerated'] === 's',
                'identity_mode' => $identity !== null ? $row['attidentity'] : '',
                'identity' => $identity,
                'identity_sequence' => $identity !== null ? self::pgIdent($identity['nspname']) . '.' . self::pgIdent($identity['relname']) : null,
                'collation' => $row['collation'],
            ];
        }

        return $columns;
    }

    private static function pgCreateTable($db, array $objects, array $byOid, $key, array $columns, $version)
    {
        $object = $objects[$key];
        $lines = [];
        $inherited = $object['parents'] && !$object['partition'];

        if (!$object['partition']) {
            foreach ($columns as $column) {
                // Na herança, colunas herdadas vêm do pai.
                if ($inherited && !$column['local']) {
                    continue;
                }

                $line = self::pgIdent($column['name']) . ' ' . $column['type'];

                if ($column['collation'] !== null) {
                    $line .= ' COLLATE ' . $column['collation'];
                }

                if ($column['generated']) {
                    $line .= ' GENERATED ALWAYS AS (' . $column['default'] . ') STORED';
                } elseif ($column['identity'] !== null) {
                    $line .= ' GENERATED ' . ($column['identity_mode'] === 'a' ? 'ALWAYS' : 'BY DEFAULT') .
                        ' AS IDENTITY (' . trim(self::pgSequenceOptions($column['identity'])) . ')';
                } elseif ($column['default'] !== null) {
                    $line .= ' DEFAULT ' . $column['default'];
                }

                if ($column['not_null']) {
                    $line .= ' NOT NULL';
                }

                $lines[] = $line;
            }
        }

        $constraints = self::pgRows(
            $db,
            "SELECT conname, pg_catalog.pg_get_constraintdef(oid) AS def
             FROM pg_catalog.pg_constraint
             WHERE conrelid = " . $object['oid'] . " AND contype IN ('p', 'u', 'c', 'x') AND conislocal" .
             ($version >= 110000 ? ' AND conparentid = 0' : '') . "
             ORDER BY CASE contype WHEN 'p' THEN 0 WHEN 'u' THEN 1 WHEN 'c' THEN 2 ELSE 3 END, conname"
        );

        foreach ($constraints as $constraint) {
            $lines[] = 'CONSTRAINT ' . self::pgIdent($constraint['conname']) . ' ' . $constraint['def'];
        }

        $sql = 'CREATE ' . ($object['unlogged'] ? 'UNLOGGED ' : '') . 'TABLE IF NOT EXISTS ' . $object['qualified'];

        if ($object['partition']) {
            $parent = isset($byOid[$object['parents'][0]]) ? $objects[$byOid[$object['parents'][0]]]['qualified'] : null;

            if ($parent === null) {
                throw new RuntimeException('Partição ' . $key . ' sem a tabela principal no backup.');
            }

            $sql .= ' PARTITION OF ' . $parent . ($lines ? " (\n    " . implode(",\n    ", $lines) . "\n)" : '') . ' ' . $object['partbound'];
        } else {
            $sql .= " (\n    " . implode(",\n    ", $lines) . "\n)";

            if ($inherited) {
                $parents = [];

                foreach ($object['parents'] as $parentOid) {
                    if (!isset($byOid[$parentOid])) {
                        throw new RuntimeException('Tabela ' . $key . ' herda de uma tabela fora do backup.');
                    }

                    $parents[] = $objects[$byOid[$parentOid]]['qualified'];
                }

                $sql .= ' INHERITS (' . implode(', ', $parents) . ')';
            }
        }

        if ($object['relkind'] === 'p') {
            $sql .= ' PARTITION BY ' . $object['partkey'];
        }

        if ($object['options'] !== '') {
            $sql .= ' WITH (' . $object['options'] . ')';
        }

        return $sql . ';';
    }

    private static function pgSequenceOptions(array $sequence)
    {
        foreach (['seqstart', 'seqincrement', 'seqmin', 'seqmax', 'seqcache'] as $field) {
            if (!preg_match('/^-?\d+$/', (string) $sequence[$field])) {
                throw new RuntimeException('Valor de sequência inesperado no catálogo.');
            }
        }

        return ' INCREMENT BY ' . $sequence['seqincrement'] .
            ' MINVALUE ' . $sequence['seqmin'] .
            ' MAXVALUE ' . $sequence['seqmax'] .
            ' START WITH ' . $sequence['seqstart'] .
            ' CACHE ' . $sequence['seqcache'] .
            (self::pgBool($sequence['seqcycle']) ? ' CYCLE' : ' NO CYCLE');
    }

    /*
     * setval só quando o contador do destino está atrás do backup.
     */
    private static function pgSetval($db, $sequenceName, $target, $increment)
    {
        $row = self::pgRow($db, 'SELECT last_value::text AS last_value, is_called FROM ' . $sequenceName);

        if (!$row || !self::pgBool($row['is_called']) || $increment <= 0 || !preg_match('/^-?\d+$/', (string) $row['last_value'])) {
            return '';
        }

        $value = $row['last_value'];

        return 'SELECT pg_catalog.setval(s, ' . $value . ', true) FROM (SELECT ' . $target . ' AS s) AS seq' .
            ' WHERE COALESCE(pg_catalog.pg_sequence_last_value(s), 0) < ' . $value . ";\n";
    }

    private static function pgIndexes($db, $oid, $version)
    {
        $rows = self::pgRows(
            $db,
            "SELECT pg_catalog.pg_get_indexdef(i.indexrelid) AS def
             FROM pg_catalog.pg_index i
             JOIN pg_catalog.pg_class ic ON ic.oid = i.indexrelid
             WHERE i.indrelid = " . (int) $oid . "
               AND NOT EXISTS (SELECT 1 FROM pg_catalog.pg_constraint c
                               WHERE c.conindid = i.indexrelid AND c.contype IN ('p', 'u', 'x'))" .
               ($version >= 110000 ? ' AND NOT ic.relispartition' : '') . "
             ORDER BY ic.relname"
        );

        $indexes = [];

        foreach ($rows as $row) {
            $def = preg_replace('/^CREATE (UNIQUE )?INDEX /', 'CREATE $1INDEX IF NOT EXISTS ', trim($row['def']), 1);

            // Em tabela particionada, cria o índice também nas partições.
            $indexes[] = preg_replace('/ ON ONLY /', ' ON ', $def, 1) . ';';
        }

        return $indexes;
    }

    private static function pgTriggers($db, $oid, $version, array &$writer, $tag)
    {
        $rows = self::pgRows(
            $db,
            "SELECT t.tgname, pg_catalog.pg_get_triggerdef(t.oid) AS def
             FROM pg_catalog.pg_trigger t
             WHERE t.tgrelid = " . (int) $oid . " AND NOT t.tgisinternal" .
             ($version >= 130000 ? ' AND t.tgparentid = 0' : '') . "
             ORDER BY t.tgname"
        );

        foreach ($rows as $row) {
            self::write($writer, '-- Trigger ' . $row['tgname'] . "\n");
            self::pgStatement($writer, self::pgGuarded(trim($row['def']), $tag), $tag);
        }

        return count($rows);
    }

    /*
     * Lê a tabela com um cursor (uma passada só, sem ORDER BY nem OFFSET)
     * e escreve INSERT ... ON CONFLICT DO NOTHING. Todo valor vem como
     * texto, que o PostgreSQL converte de volta para o tipo da coluna.
     */
    private static function pgDumpRows($db, array $object, array $columns, array &$writer)
    {
        $names = [];
        $expressions = [];
        $overriding = false;

        foreach ($columns as $column) {
            // Colunas geradas são calculadas pelo banco e não aceitam INSERT.
            if ($column['generated']) {
                continue;
            }

            if ($column['identity_mode'] === 'a') {
                $overriding = true;
            }

            $names[] = self::pgIdent($column['name']);
            $expressions[] = self::pgIdent($column['name']) . '::text';
        }

        if (!$names) {
            return 0;
        }

        $insert = 'INSERT INTO ' . $object['qualified'] . ' (' . implode(', ', $names) . ')' .
            ($overriding ? ' OVERRIDING SYSTEM VALUE' : '') . ' VALUES';

        $cursor = 'bkp_' . bin2hex(random_bytes(6));
        $total = 0;
        $values = [];
        $size = 0;

        self::write($writer, "\n--\n-- Dados de " . $object['qualified'] . "\n--\n\n");
        self::pgSession($db, 'DECLARE ' . $cursor . ' NO SCROLL CURSOR FOR SELECT ' . implode(', ', $expressions) . ' FROM ONLY ' . $object['qualified']);

        try {
            while (true) {
                $rows = self::pgRows($db, 'FETCH FORWARD ' . (int) self::$chunkSize . ' FROM ' . $cursor, false);

                foreach ($rows as $row) {
                    $encoded = [];

                    foreach ($row as $value) {
                        $encoded[] = $value === null ? 'NULL' : self::pgLiteral((string) $value);
                    }

                    $tuple = '(' . implode(',', $encoded) . ')';
                    $values[] = $tuple;
                    $size += strlen($tuple);

                    if (count($values) >= self::$rowsPerInsert || $size >= self::$maxInsertBytes) {
                        self::write($writer, $insert . "\n" . implode(",\n", $values) . "\nON CONFLICT DO NOTHING;\n");
                        $values = [];
                        $size = 0;
                    }
                }

                $total += count($rows);

                if (count($rows) < self::$chunkSize) {
                    break;
                }
            }
        } finally {
            self::pgSession($db, 'CLOSE ' . $cursor);
        }

        if ($values) {
            self::write($writer, $insert . "\n" . implode(",\n", $values) . "\nON CONFLICT DO NOTHING;\n");
        }

        return $total;
    }

    /*
     * Envolve o comando num bloco que ignora "já existe": o objeto do
     * destino é mantido como está, e qualquer outro erro continua erro.
     */
    private static function pgGuarded($sql, $tag)
    {
        if (strpos($sql, $tag) !== false) {
            throw new RuntimeException('Conflito de delimitador ao gerar o backup.');
        }

        return 'DO ' . $tag . "\nBEGIN\n" . rtrim(trim($sql), ';') . ";\n" .
            "EXCEPTION WHEN duplicate_object OR duplicate_table OR duplicate_function THEN NULL;\nEND\n" . $tag . ';';
    }

    /*
     * Última barreira do PostgreSQL: só sai para o arquivo comando que cria
     * algo sem substituir nem remover o que já existe.
     */
    private static function pgStatement(array &$writer, $sql, $tag)
    {
        $sql = trim($sql);
        $allowed = '/^(CREATE SCHEMA IF NOT EXISTS |CREATE EXTENSION IF NOT EXISTS |CREATE SEQUENCE IF NOT EXISTS |' .
            'CREATE (UNLOGGED )?TABLE IF NOT EXISTS |CREATE (UNIQUE )?INDEX IF NOT EXISTS |CREATE MATERIALIZED VIEW IF NOT EXISTS |' .
            'DO ' . preg_quote($tag, '/') . '\nBEGIN\n(CREATE (TYPE|DOMAIN|VIEW|FUNCTION|PROCEDURE|TRIGGER|CONSTRAINT TRIGGER) |ALTER TABLE \S.* ADD CONSTRAINT ))/';

        if (!preg_match($allowed, $sql)) {
            throw new LogicException('Comando inesperado no backup: ' . substr($sql, 0, 60));
        }

        self::write($writer, $sql . "\n");
    }

    private static function pgIdent($name)
    {
        return '"' . str_replace('"', '""', (string) $name) . '"';
    }

    private static function pgLiteral($value)
    {
        return "'" . str_replace("'", "''", (string) $value) . "'";
    }

    private static function pgOids(array $oids)
    {
        return implode(', ', array_map('intval', $oids));
    }

    private static function pgBool($value)
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1' || $value === 'true' || $value === 'on';
    }

    // ------------------------------------------------------------------
    // PostgreSQL: acesso ao banco, somente leitura e pela conexão nativa
    // ------------------------------------------------------------------

    private static function pgRows($db, $sql, $assoc = true)
    {
        if (!preg_match('/^\s*(SELECT|FETCH FORWARD \d+ FROM bkp_[0-9a-f]+$)/i', $sql)) {
            throw new LogicException('BackupDatabaseHelper só executa SELECT no PostgreSQL.');
        }

        return self::pgRun($db, $sql, $assoc ? 'assoc' : 'num');
    }

    private static function pgRow($db, $sql)
    {
        $rows = self::pgRows($db, $sql);

        return $rows ? $rows[0] : null;
    }

    private static function pgValue($db, $sql)
    {
        $rows = self::pgRows($db, $sql, false);

        return $rows ? $rows[0][0] : null;
    }

    /*
     * Comandos permitidos além de SELECT: abrir e desfazer a transação de
     * leitura, configurar a própria transação (SET LOCAL) e o cursor.
     */
    private static function pgSession($db, $sql)
    {
        $allowed = '/^(BEGIN TRANSACTION ISOLATION LEVEL (REPEATABLE READ|READ COMMITTED), READ ONLY|ROLLBACK|' .
            'SET LOCAL \w+ = \'[\w ,]+\'|' .
            'DECLARE bkp_[0-9a-f]+ NO SCROLL CURSOR FOR SELECT .+|CLOSE bkp_[0-9a-f]+)$/s';

        if (!preg_match($allowed, $sql)) {
            throw new LogicException('Comando de sessão não permitido no backup.');
        }

        self::pgRun($db, $sql, 'none');
    }

    private static function pgRun($db, $sql, $fetch)
    {
        $connection = self::pgNative($db);

        if ($connection instanceof PDO) {
            $statement = $connection->query($sql);

            if ($statement === false) {
                $info = $connection->errorInfo();

                throw new RuntimeException(isset($info[2]) ? (string) $info[2] : 'Erro no PostgreSQL.');
            }

            if ($fetch === 'none') {
                $statement->closeCursor();

                return [];
            }

            return $statement->fetchAll($fetch === 'assoc' ? PDO::FETCH_ASSOC : PDO::FETCH_NUM);
        }

        $result = @pg_query($connection, $sql);

        if ($result === false) {
            throw new RuntimeException(pg_last_error($connection));
        }

        if ($fetch === 'none') {
            return [];
        }

        $rows = pg_fetch_all($result, $fetch === 'assoc' ? PGSQL_ASSOC : PGSQL_NUM);

        return $rows ?: [];
    }

    /*
     * PDO no Joomla 4+ (driver pgsql) ou a conexão pg_* do Joomla 3.
     */
    private static function pgNative($db)
    {
        if (method_exists($db, 'connect')) {
            $db->connect();
        }

        $connection = method_exists($db, 'getConnection') ? $db->getConnection() : null;

        if ($connection instanceof PDO
            || (class_exists('PgSql\\Connection', false) && $connection instanceof PgSql\Connection)
            || is_resource($connection)) {
            return $connection;
        }

        throw new RuntimeException('Não foi possível acessar a conexão nativa do PostgreSQL.');
    }

    // ------------------------------------------------------------------
    // Acesso ao banco: somente leitura
    // ------------------------------------------------------------------

    /*
     * Toda leitura passa por aqui, e só SELECT e SHOW são aceitos.
     */
    private static function query($db, $sql, $method)
    {
        if (!preg_match('/^\s*(SELECT|SHOW)\s/i', $sql)) {
            throw new LogicException('BackupDatabaseHelper só executa SELECT e SHOW.');
        }

        $db->setQuery($sql);
        $result = $db->$method();

        return $result === null && ($method === 'loadAssocList' || $method === 'loadRowList') ? [] : $result;
    }

    /*
     * Comandos de sessão permitidos: fuso horário da conexão e a transação
     * de leitura do snapshot. Nada além disso.
     */
    private static function restoreSession($db, $session)
    {
        $value = function ($key, $pattern, $default) use ($session) {
            $text = is_array($session) && isset($session[$key]) ? (string) $session[$key] : '';

            return preg_match($pattern, $text) ? $text : $default;
        };

        $charset = $value('charset', '/^\w+$/', 'utf8mb4');
        $collation = $value('collation', '/^\w+$/', '');

        self::session($db, "SET NAMES '" . $charset . "'" . ($collation !== '' ? " COLLATE '" . $collation . "'" : ''));
        self::session($db, "SET SESSION time_zone = '" . $value('time_zone', '/^[\w\/:+-]+$/', 'SYSTEM') . "'");
    }

    private static function session($db, $sql)
    {
        $allowed = '/^(SET SESSION time_zone = \'[\w\/:+-]+\'|SET NAMES \'\w+\'( COLLATE \'\w+\')?|SET TRANSACTION ISOLATION LEVEL REPEATABLE READ|START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY|ROLLBACK)$/';

        if (!preg_match($allowed, $sql)) {
            throw new LogicException('Comando de sessão não permitido no backup.');
        }

        // O driver do Joomla usa prepared statements, e o MySQL não aceita
        // START TRANSACTION nesse modo; por isso vai pela conexão nativa.
        if (method_exists($db, 'connect')) {
            $db->connect();
        }

        $connection = method_exists($db, 'getConnection') ? $db->getConnection() : null;

        if ($connection instanceof mysqli) {
            // O mysqli só escapa no charset certo se souber da troca.
            if (preg_match('/^SET NAMES \'(\w+)\'/', $sql, $match)) {
                try {
                    $connection->set_charset($match[1]);
                } catch (Throwable $e) {
                }
            }

            if ($connection->query($sql) === false) {
                throw new RuntimeException($connection->error);
            }

            return;
        }

        if ($connection instanceof PDO) {
            $connection->exec($sql);

            return;
        }

        $db->setQuery($sql);
        $db->execute();
    }

    /*
     * START TRANSACTION confirmaria uma transação já aberta na mesma
     * conexão. Por isso o snapshot só é usado quando não há nenhuma.
     */
    private static function canUseSnapshot($db)
    {
        if (class_exists('DbTransactionHelper') && DbTransactionHelper::isActive($db)) {
            return false;
        }

        try {
            $property = new ReflectionProperty($db, 'transactionDepth');

            if ((int) $property->getValue($db) > 0) {
                return false;
            }
        } catch (Throwable $e) {
        }

        // MariaDB informa se a sessão está dentro de uma transação.
        try {
            if ((int) self::query($db, 'SELECT @@in_transaction', 'loadResult') > 0) {
                return false;
            }
        } catch (Throwable $e) {
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Saída (arquivo e/ou navegador), com gzip opcional
    // ------------------------------------------------------------------

    private static function openWriter(array $handles, $gzip)
    {
        $deflate = null;

        if ($gzip) {
            if (!function_exists('deflate_init')) {
                throw new RuntimeException('A extensão zlib é necessária para gerar .sql.gz.');
            }

            $deflate = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
        }

        return ['handles' => $handles, 'deflate' => $deflate, 'buffer' => ''];
    }

    private static function write(array &$writer, $text)
    {
        $writer['buffer'] .= $text;

        if (strlen($writer['buffer']) >= 65536) {
            self::flush($writer, false);
        }
    }

    private static function flush(array &$writer, $finish)
    {
        $data = $writer['buffer'];
        $writer['buffer'] = '';

        if ($writer['deflate'] !== null) {
            $data = deflate_add($writer['deflate'], $data, $finish ? ZLIB_FINISH : ZLIB_NO_FLUSH);
        }

        if ($data === '' || $data === false) {
            return;
        }

        foreach ($writer['handles'] as $handle) {
            $length = strlen($data);
            $written = 0;

            while ($written < $length) {
                $bytes = fwrite($handle, $written === 0 ? $data : substr($data, $written));

                if ($bytes === false || $bytes === 0) {
                    throw new RuntimeException('Falha ao gravar o backup (disco cheio ou conexão encerrada?).');
                }

                $written += $bytes;
            }
        }

        if (!$finish) {
            foreach ($writer['handles'] as $handle) {
                fflush($handle);
            }
        }
    }

    private static function closeWriter(array &$writer)
    {
        self::flush($writer, true);

        foreach ($writer['handles'] as $handle) {
            fclose($handle);
        }
    }

    private static function markIncomplete(array &$writer, Throwable $error)
    {
        try {
            $writer['buffer'] .= "\n\n-- ATENÇÃO: BACKUP INCOMPLETO. Não use este arquivo para restaurar.\n" .
                '-- Erro: ' . str_replace(["\r", "\n"], ' ', $error->getMessage()) . "\n";
            self::closeWriter($writer);
        } catch (Throwable $e) {
        }
    }

    // ------------------------------------------------------------------
    // Arquivos: nunca sobrescreve nem remove
    // ------------------------------------------------------------------

    private static function createFile($path)
    {
        // Modo 'x': falha se o arquivo já existir.
        $handle = @fopen($path, 'xb');

        if ($handle === false) {
            throw new RuntimeException('Não foi possível criar o arquivo de backup: ' . basename($path));
        }

        @chmod($path, self::$filePermission);

        return $handle;
    }

    private static function fileName(array $plan, array $options)
    {
        $extension = !empty($options['gzip']) ? '.sql.gz' : '.sql';

        if (!empty($options['file_name'])) {
            $name = preg_replace('/(\.sql)?(\.gz)?$/i', '', basename(str_replace('\\', '/', (string) $options['file_name'])));
            $name = PlatformHelper::makeSafe($name);

            if ($name !== '') {
                return $name . $extension;
            }
        }

        $database = PlatformHelper::makeSafe($plan['database']);

        return ($database !== '' ? $database : 'banco') . '_' .
            ($plan['mode'] === self::FULL ? 'completo' : 'estrutura') . '_' .
            date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . $extension;
    }

    private static function uniquePath($directory, $fileName)
    {
        $path = $directory . '/' . $fileName;

        while (file_exists($path) || file_exists($path . '.parcial')) {
            $base = preg_replace('/(\.sql(\.gz)?)$/i', '', $fileName);
            $extension = substr($fileName, strlen($base));
            $path = $directory . '/' . $base . '_' . bin2hex(random_bytes(3)) . $extension;
        }

        return $path;
    }

    private static function targetDirectory(array $options)
    {
        $base = self::getBaseDirectory();

        if (empty($options['directory'])) {
            return $base;
        }

        $parts = [];

        foreach (explode('/', trim(str_replace('\\', '/', (string) $options['directory']), '/')) as $part) {
            $part = trim($part);

            if ($part === '' || $part === '.' || $part === '..' || strpos($part, "\0") !== false) {
                throw new InvalidArgumentException('Subpasta de backup inválida.');
            }

            $parts[] = PlatformHelper::makeSafe($part);
        }

        return self::prepareDirectory($base . '/' . implode('/', $parts));
    }

    private static function absolutePath($directory)
    {
        $directory = trim(str_replace('\\', '/', (string) $directory));

        if ($directory === '' || strpos($directory, "\0") !== false || preg_match('#(^|/)\.\.(/|$)#', $directory)) {
            throw new InvalidArgumentException('Diretório de backup inválido.');
        }

        if ($directory[0] !== '/' && !preg_match('#^[A-Za-z]:/#', $directory)) {
            $directory = self::normalizePath(PlatformHelper::rootPath()) . '/' . $directory;
        }

        return self::normalizePath($directory);
    }

    /*
     * Cria a pasta se preciso e bloqueia o acesso pela web. Os arquivos de
     * proteção só são criados quando não existem.
     */
    private static function prepareDirectory($directory)
    {
        if (!is_dir($directory) && !@mkdir($directory, self::$directoryPermission, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta de backup: ' . $directory);
        }

        $real = realpath($directory);

        if ($real === false || !is_writable($real)) {
            throw new RuntimeException('Sem permissão de escrita na pasta de backup: ' . $directory);
        }

        $real = self::normalizePath($real);

        $protection = [
            '.htaccess' => "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n",
            'index.html' => "<!DOCTYPE html><title></title>\n",
        ];

        foreach ($protection as $name => $content) {
            if (!file_exists($real . '/' . $name)) {
                $handle = @fopen($real . '/' . $name, 'xb');

                if ($handle !== false) {
                    fwrite($handle, $content);
                    fclose($handle);
                }
            }
        }

        return $real;
    }

    private static function normalizePath($path)
    {
        return rtrim(preg_replace('#/+#', '/', str_replace('\\', '/', (string) $path)), '/');
    }

    private static function clearOutputBuffers()
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
    }

    // ------------------------------------------------------------------
    // Resultado e log
    // ------------------------------------------------------------------

    private static function summary(array $plan, array $stats, $started)
    {
        return [
            'modo' => $plan['mode'],
            'banco' => $plan['database'],
            'tabelas' => $stats['tabelas'],
            'views' => $stats['views'],
            'triggers' => $stats['triggers'],
            'linhas' => $stats['linhas'],
            'snapshot' => $stats['snapshot'],
            'segundos' => round(microtime(true) - $started, 2),
        ];
    }

    private static function result($success, $message, array $data = [], array $errors = [])
    {
        return [
            'success' => (bool) $success,
            'status' => $success ? 'sucesso' : 'erro',
            'mensagem' => (string) $message,
            'data' => $data,
            'errors' => $errors,
        ];
    }

    private static function log($level, $message, array $context = [])
    {
        try {
            $levels = [
                'info' => PlatformHelper::LOG_INFO,
                'warning' => PlatformHelper::LOG_WARNING,
                'error' => PlatformHelper::LOG_ERROR,
            ];

            try {
                $context['usuario'] = PlatformHelper::userId();
            } catch (Throwable $e) {
            }

            $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            PlatformHelper::log(
                $message . ($json ? ' | Contexto: ' . $json : ''),
                isset($levels[$level]) ? $levels[$level] : PlatformHelper::LOG_INFO,
                self::$logCategory
            );
        } catch (Throwable $e) {
        }
    }
}
