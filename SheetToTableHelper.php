<?php

// Bloqueia só o acesso direto pela URL; funciona no Joomla 3 a 6, no Laravel e em PHP puro.
if (!defined('_JEXEC') && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    die('Acesso restrito.');
}

if (!class_exists('DbConnectionHelper')) {
    require_once __DIR__ . '/DbConnectionHelper.php';
}

if (!class_exists('DbTransactionHelper')) {
    require_once __DIR__ . '/DbTransactionHelper.php';
}

if (!class_exists('OrmTables')) {
    require_once __DIR__ . '/OrmTables.php';
}

/*
 * Erro com mensagem segura para mostrar ao usuário ("Arquivo vazio",
 * "Formato não suportado"). Erros do banco ou do PHP vão só para o log.
 */
class SheetToTableException extends RuntimeException
{
}

/*
 * Uso interno: desfaz a transação levando o resumo com os erros.
 */
class SheetToTableRollback extends Exception
{
    public $summary;

    public function __construct(array $summary)
    {
        parent::__construct('Importação desfeita: há linhas com erro.');
        $this->summary = $summary;
    }
}

/*
 * Importa CSV, XLSX, XLS ou ODS para uma tabela casando o CABEÇALHO do
 * arquivo com os CAMPOS da tabela, pelo nome, em qualquer ordem.
 *
 *   tabela:  ID | NUM_CENTRAL | PRODUTO | DATA_BASE | PRODUCAO
 *   arquivo: PRODUCAO;DATA_BASE;NUM_CENTRAL;PRODUTO      -> importa
 *   arquivo: PRODUCAO;NUM_CENTRAL;PRODUTO                -> bloqueia:
 *            "Importação cancelada. Falta a seguinte coluna no arquivo: DATA_BASE."
 *
 * Feito para arquivos grandes (1 milhão de linhas ou mais): CSV e XLSX
 * são lidos linha a linha, sem carregar o arquivo na memória, e a
 * gravação é em lotes (1000 linhas por INSERT, configurável).
 *
 * Cada valor é conferido contra o tipo da coluna no banco (inteiro e
 * seus limites, decimal(p,s), data, data e hora, texto e tamanho, enum,
 * json...). Qualquer linha fora do padrão volta com a LINHA e o CAMPO
 * exatos, e por padrão a importação inteira é desfeita (rollback).
 *
 * ---- Uso -----------------------------------------------------------------
 *
 *   $res = SheetToTableHelper::import($arquivo, 'pt18_cnv_desempenhos4', [
 *       'mode'       => 'replace',  // ver MODOS abaixo
 *       'batch_size' => 1000,       // linhas por INSERT
 *       'on_error'   => 'rollback', // rollback | skip
 *   ]);
 *
 *   if (!$res['valido']) {
 *       // $res['mensagem'] e $res['erros'] = [['linha' => 37, 'campo' => 'DATA_BASE',
 *       //                                     'mensagem' => 'Data inválida: "31/02/2026".'], ...]
 *   }
 *
 * ---- MODOS ('mode') ----------------------------------------------------
 *
 *   'append'      (padrão) insere junto com o que já existe na tabela
 *   'replace'     apaga TUDO da tabela e insere o arquivo
 *                 + 'replace_where' => ['DATA_BASE' => '2026-11-17']  apaga só o que bate
 *                 + 'replace_by'    => ['DATA_BASE']   apaga só as DATA_BASE que vieram no arquivo
 *   'upsert'      insere; se a chave única já existir, atualiza a linha
 *                 (PostgreSQL: informe 'unique_by' => ['COL', ...])
 *   'insert_new'  insere só o que não existe; duplicados pela chave única são ignorados
 *
 * ---- Erros ('on_error') -------------------------------------------------
 *
 *   'rollback' (padrão) qualquer erro (de tipo ou do banco) desfaz TUDO,
 *              inclusive o DELETE do modo replace. Os erros de todo o
 *              arquivo são listados (até 'max_errors') para o usuário corrigir.
 *   'skip'     grava as linhas boas e devolve as ruins na lista de erros.
 *
 * Outras opções: 'atomic' => true (uma transação só; false = uma por
 * lote, mais leve, mas sem rollback do que já foi gravado),
 * 'validate_first' => true (lê o arquivo inteiro sem tocar no banco
 * antes de gravar), 'dry_run' => true (só valida), 'on_progress' =>
 * callable, e as de validate() (ignore, only, optional, fixed, aliases...).
 *
 * Banco: DbConnectionHelper (Joomla 3 a 6 sem configurar nada; Laravel
 * e PHP puro com DbConnectionHelper::register() + 'connection' => 'nome').
 * Para gravar por outro meio (Eloquent, API) use process().
 */
class SheetToTableHelper
{
    const MODE_APPEND = 'append';

    const MODE_REPLACE = 'replace';

    const MODE_UPSERT = 'upsert';

    const MODE_INSERT_NEW = 'insert_new';

    const ON_ERROR_ROLLBACK = 'rollback';

    const ON_ERROR_SKIP = 'skip';

    private static $maxFileSize = 2147483648;

    private static $batchSize = 1000;

    private static $maxBatchSize = 20000;

    // Tamanho máximo de um INSERT (o MySQL recusa acima do max_allowed_packet).
    private static $maxSqlBytes = 4194304;

    private static $maxErrors = 1000;

    private static $logCategory = 'sheet_to_table';

    private static $csvExtensions = ['csv', 'txt', 'tsv'];

    private static $excelExtensions = ['xlsx', 'xlsm', 'xls', 'ods'];

    private static $delimiterCandidates = [';', ',', "\t", '|'];

    private static $modeAliases = [
        'append' => self::MODE_APPEND,
        'insert' => self::MODE_APPEND,
        'replace' => self::MODE_REPLACE,
        'truncate_insert' => self::MODE_REPLACE,
        'upsert' => self::MODE_UPSERT,
        'insert_new' => self::MODE_INSERT_NEW,
        'insert_ignore' => self::MODE_INSERT_NEW,
    ];

    private static $columnsCache = [];

    // Marca "use o DEFAULT do banco" num valor do INSERT.
    private static $defaultMarker = null;

    /* =================================================================
     * Configuração global (cada chamada também aceita as opções)
     * ================================================================= */

    public static function setBatchSize($size)
    {
        self::$batchSize = max(1, min(self::$maxBatchSize, (int) $size));
    }

    public static function setMaxSqlBytes($bytes)
    {
        self::$maxSqlBytes = max(65536, (int) $bytes);
    }

    public static function setMaxFileSize($bytes)
    {
        self::$maxFileSize = max(1024, (int) $bytes);
    }

    public static function setMaxErrors($max)
    {
        self::$maxErrors = max(1, (int) $max);
    }

    public static function setLogCategory($category)
    {
        self::$logCategory = (string) $category;
    }

    // XLS e ODS (e gerar modelo .xlsx) dependem da phpoffice/phpspreadsheet.
    public static function supportsPhpSpreadsheet()
    {
        return class_exists('PhpOffice\\PhpSpreadsheet\\IOFactory');
    }

    public static function supportedExtensions()
    {
        $extensions = array_merge(self::$csvExtensions, ['xlsx', 'xlsm']);

        return self::supportsPhpSpreadsheet() ? array_merge($extensions, ['xls', 'ods']) : $extensions;
    }

    public static function clearCache()
    {
        self::$columnsCache = [];
    }

    /* =================================================================
     * Estrutura da tabela
     * ================================================================= */

    /*
     * Campos da tabela, já interpretados:
     * [NOME => ['name', 'type', 'base', 'length', 'bytes', 'unsigned',
     *           'precision', 'scale', 'enum', 'nullable', 'default',
     *           'has_default', 'auto', 'generated', 'required_value']]
     */
    public static function tableColumns($table, array $options = [])
    {
        if (!empty($options['columns'])) {
            return self::columnsFromOption($options['columns']);
        }

        $table = self::validTable($table);
        $db = self::orm($table, $options)->db();
        $cacheKey = spl_object_hash($db) . '|' . $table;

        if (isset(self::$columnsCache[$cacheKey])) {
            return self::applyAutoOption(self::$columnsCache[$cacheKey], $options);
        }

        try {
            $raw = $db->getTableColumns($table, false);
        } catch (Throwable $error) {
            self::log('Falha ao ler a estrutura da tabela.', ['tabela' => $table, 'erro' => $error->getMessage()], 'error');

            throw new SheetToTableException('Não foi possível ler a estrutura da tabela ' . $table . '.', 500, $error);
        }

        if (empty($raw)) {
            throw new SheetToTableException('A tabela ' . $table . ' não existe ou não tem colunas.');
        }

        $columns = [];

        foreach ($raw as $key => $info) {
            $info = is_object($info) ? get_object_vars($info) : (array) $info;
            $column = self::describeColumn($key, $info);
            $columns[$column['name']] = $column;
        }

        self::$columnsCache[$cacheKey] = $columns;

        return self::applyAutoOption($columns, $options);
    }

    /*
     * Campos que o arquivo precisa ter, conforme as opções:
     *   'require'  => 'all' (padrão) todo campo importável;
     *                 'not_null' só os NOT NULL sem valor padrão
     *   'ignore'   => campos que nunca entram
     *   'only'     => restringe aos campos listados (lista permitida)
     *   'optional' => podem faltar no arquivo (o banco aplica o padrão)
     *   'fixed'    => valores fixos; não precisam estar no arquivo
     *   'auto'     => campos gerados pelo banco não detectados (identity do PostgreSQL)
     */
    public static function expectedColumns($table, array $options = [])
    {
        $plan = self::plan(self::tableColumns($table, $options), $options);

        return $plan['required'];
    }

    /* =================================================================
     * Arquivo
     * ================================================================= */

    // Nomes das abas (CSV tem uma só: 'CSV').
    public static function sheets($file, array $options = [])
    {
        $source = self::source($file, $options);

        if ($source['type'] === 'csv') {
            return ['CSV'];
        }

        if ($source['type'] === 'xlsx') {
            $zip = self::openZip($source['path']);

            try {
                return array_keys(self::xlsxSheets($zip));
            } finally {
                $zip->close();
            }
        }

        return self::spreadsheetReader($source)->listWorksheetNames($source['path']);
    }

    /*
     * Cabeçalho: ['cabecalho' => [...], 'linha' => 1, 'aba' => '...', 'formato' => 'csv'].
     */
    public static function headers($file, array $options = [])
    {
        $source = self::source($file, $options);
        $reader = self::openRows($source, $options);

        try {
            $header = self::readHeader($reader['rows'], $options);
        } finally {
            self::closeRows($reader);
        }

        return [
            'cabecalho' => $header['values'],
            'linha' => $header['line'],
            'aba' => $reader['sheet'],
            'formato' => $source['extension'],
        ];
    }

    /* =================================================================
     * Casamento cabeçalho x campos
     * ================================================================= */

    /*
     * Núcleo puro (sem banco nem arquivo): casa campos com o cabeçalho.
     * Devolve ['valido', 'mensagem', 'faltantes', 'duplicados', 'extras',
     * 'sugestoes', 'mapeamento' => [CAMPO => posição]].
     *
     * 'match' => 'normal' (padrão: ignora maiúsculas, acentos e trata
     * espaço, hífen e _ como iguais: "Data Base" = DATA_BASE) ou 'strict'
     * (só maiúsculas e espaços nas pontas). 'aliases' => [CAMPO => [...]],
     * 'optional' => [...], 'extra_columns' => 'ignore' | 'error'.
     */
    public static function matchHeaders(array $fields, array $header, array $options = [])
    {
        $mode = isset($options['match']) ? $options['match'] : 'normal';
        $aliases = isset($options['aliases']) ? (array) $options['aliases'] : [];
        $optional = self::upperList(isset($options['optional']) ? (array) $options['optional'] : []);

        $positions = [];
        $repeated = [];
        $originalByKey = [];

        foreach ($header as $position => $name) {
            $key = self::normalizeName($name, $mode);

            if ($key === '') {
                continue;
            }

            if (array_key_exists($key, $positions)) {
                $repeated[$key] = true;

                continue;
            }

            $positions[$key] = $position;
            $originalByKey[$key] = trim(self::stripBom((string) $name));
        }

        $map = [];
        $missing = [];
        $duplicated = [];
        $used = [];

        foreach ($fields as $field) {
            $field = (string) $field;
            $candidates = array_merge([$field], isset($aliases[$field]) ? (array) $aliases[$field] : []);
            $found = null;

            foreach ($candidates as $candidate) {
                $key = self::normalizeName($candidate, $mode);

                if ($key !== '' && array_key_exists($key, $positions)) {
                    $found = $key;
                    break;
                }
            }

            if ($found === null) {
                if (!in_array(strtoupper($field), $optional, true)) {
                    $missing[] = $field;
                }

                continue;
            }

            if (isset($repeated[$found])) {
                $duplicated[] = $originalByKey[$found];
            }

            $map[$field] = $positions[$found];
            $used[$found] = true;
        }

        $extras = [];

        foreach ($originalByKey as $key => $original) {
            if (!isset($used[$key])) {
                $extras[] = $original;
            }
        }

        $suggestions = self::suggest($missing, array_diff_key($originalByKey, $used));
        $duplicated = array_values(array_unique($duplicated));
        $extraIsError = isset($options['extra_columns']) && $options['extra_columns'] === 'error';
        $valid = !$missing && !$duplicated && !($extraIsError && $extras);

        return [
            'valido' => $valid,
            'mensagem' => self::headerMessage($missing, $duplicated, $extraIsError ? $extras : [], $suggestions),
            'faltantes' => $missing,
            'duplicados' => $duplicated,
            'extras' => $extras,
            'sugestoes' => $suggestions,
            'mapeamento' => $valid ? $map : [],
        ];
    }

    /*
     * Confere só o cabeçalho do arquivo contra a tabela, sem gravar.
     */
    public static function validate($file, $table, array $options = [])
    {
        $columns = self::tableColumns($table, $options);
        $plan = self::plan($columns, $options);
        $header = self::headers($file, $options);

        return array_merge(self::matchHeaders($plan['candidates'], $header['cabecalho'], array_merge($options, [
            'optional' => $plan['optional'],
        ])), [
            'cabecalho' => $header['cabecalho'],
            'linha_cabecalho' => $header['linha'],
            'aba' => $header['aba'],
            'campos_esperados' => $plan['required'],
            'campos_fixos' => array_keys($plan['fixed']),
            'campos_ignorados' => $plan['skipped'],
        ]);
    }

    // Texto pronto para alerta (vazio quando está tudo certo).
    public static function missingMessage(array $result)
    {
        return empty($result['valido']) ? (string) $result['mensagem'] : '';
    }

    /*
     * Erros agrupados para exibir: "Linha 37, campo DATA_BASE: Data inválida."
     */
    public static function errorLines(array $result, $limit = 100)
    {
        $lines = [];

        foreach (array_slice(isset($result['erros']) ? $result['erros'] : [], 0, max(1, (int) $limit)) as $error) {
            $lines[] = 'Linha ' . $error['linha'] . ($error['campo'] !== null ? ', campo ' . $error['campo'] : '') . ': ' . $error['mensagem'];
        }

        return $lines;
    }

    /* =================================================================
     * Prévia, processamento e importação
     * ================================================================= */

    /*
     * Primeiras linhas convertidas, com os erros de cada uma, sem gravar.
     */
    public static function preview($file, $table, $limit = 10, array $options = [])
    {
        $rows = [];

        $summary = self::run($file, $table, $options, function (array $batch) use (&$rows) {
            foreach ($batch as $item) {
                $rows[] = $item;
            }
        }, ['limit' => max(1, (int) $limit), 'keep_invalid' => true]);

        $summary['linhas'] = array_map(function ($item) {
            return ['linha' => $item['line'], 'dados' => SheetToTableHelper::exportRecord($item['data'], true), 'erros' => $item['errors']];
        }, $rows);

        return $summary;
    }

    /*
     * Lê, valida e converte; entrega lotes prontos para $callback($registros,
     * $numeroDoLote) sem gravar nada (Laravel: DB::table()->insert($registros)).
     * Linhas com erro não entram e aparecem em 'erros'. Com 'on_error' =>
     * 'rollback', para de entregar lotes no primeiro erro.
     */
    public static function process($file, $table, callable $callback, array $options = [])
    {
        $abort = self::onErrorMode($options) === self::ON_ERROR_ROLLBACK;

        return self::run($file, $table, $options, function (array $batch, $number) use ($callback) {
            $callback(array_map(function ($item) {
                return SheetToTableHelper::exportRecord($item['data']);
            }, $batch), $number);
        }, ['abort' => $abort]);
    }

    /*
     * Importa para a tabela. Veja os MODOS e opções no topo da classe.
     *
     * Retorno: ['valido', 'mensagem', 'total_linhas', 'gravadas',
     * 'com_erro', 'apagadas', 'ignoradas_duplicadas', 'lotes',
     * 'erros' => [['linha', 'campo', 'mensagem', 'valor']],
     * 'erros_truncados', 'rollback', 'modo', 'tempo_segundos', ...].
     */
    public static function import($file, $table, array $options = [])
    {
        $start = microtime(true);
        $mode = self::modeOption($options);
        $onError = self::onErrorMode($options);
        $atomic = !array_key_exists('atomic', $options) || $options['atomic'];
        $dryRun = !empty($options['dry_run']);

        self::prepareRuntime($options);

        $columns = self::tableColumns($table, $options);

        if (!empty($options['columns'])) {
            throw new SheetToTableException('import() lê a estrutura da tabela real; com "columns" manual use process().');
        }

        $tableName = self::validTable($table);
        $orm = self::orm($tableName, $options);
        $db = $orm->db();
        $writeOptions = self::writeOptions($db, $columns, $mode, $atomic, $options);

        $finish = function (array $summary, $message = null) use ($start, $mode, $dryRun) {
            $summary['modo'] = $mode;
            $summary['dry_run'] = $dryRun;
            $summary['tempo_segundos'] = round(microtime(true) - $start, 3);
            $summary['memoria_pico_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);

            if ($message !== null) {
                $summary['mensagem'] = $message;
            }

            return $summary;
        };

        // 1) Cabeçalho: se faltar coluna, devolve o alerta sem abrir transação.
        $header = self::validate($file, $table, $options);

        if (!$header['valido']) {
            return $finish(array_merge(self::emptySummary(), $header));
        }

        // 2) Simulação, ou validação completa antes de tocar no banco.
        if ($dryRun || !empty($options['validate_first'])) {
            $check = self::run($file, $table, $options, function () {
            }, ['abort' => $onError === self::ON_ERROR_ROLLBACK]);

            if ($dryRun) {
                return $finish($check, $check['com_erro'] > 0
                    ? 'Simulação: ' . $check['com_erro'] . ' linha(s) com erro. Nada foi gravado.'
                    : ($check['valido'] ? 'Simulação concluída: ' . $check['total_linhas'] . ' linha(s) prontas para importar. Nada foi gravado.' : $check['mensagem']));
            }

            if (!$check['valido'] || ($check['com_erro'] > 0 && $onError === self::ON_ERROR_ROLLBACK)) {
                $check['valido'] = false;

                return $finish($check, $check['com_erro'] > 0
                    ? 'Importação cancelada: ' . $check['com_erro'] . ' linha(s) com erro. Nada foi gravado.'
                    : $check['mensagem']);
            }
        }

        // 3) Gravação.
        $state = [
            'prepared' => false,
            'gravadas' => 0,
            'afetadas' => 0,
            'apagadas' => 0,
            'deleted_keys' => [],
        ];

        // TRUNCATE no MySQL não pode ficar dentro de transação (confirma na hora): roda antes.
        if ($writeOptions['truncate_upfront']) {
            $state['apagadas'] = self::query($tableName, $writeOptions)->count();
            self::query($tableName, $writeOptions)->truncate(true);
            $state['prepared'] = true;
        }

        $writer = function (array $batch) use ($orm, $tableName, $columns, $writeOptions, &$state) {
            return SheetToTableHelper::writeBatchFor($orm, $tableName, $columns, $batch, $writeOptions, $state);
        };

        if (!$atomic) {
            // Uma transação por lote: um lote nunca fica pela metade.
            $writer = function (array $batch) use ($writer, $orm) {
                return $orm->transaction(function () use ($writer, $batch) {
                    return $writer($batch);
                });
            };
        }

        $runInternal = ['abort' => $onError === self::ON_ERROR_ROLLBACK];
        $summary = null;

        try {
            if ($atomic) {
                $orm->transaction(function () use ($file, $table, $options, $writer, $runInternal, $onError, &$summary) {
                    $summary = SheetToTableHelper::runFor($file, $table, $options, $writer, $runInternal);

                    if (!$summary['valido'] || ($summary['com_erro'] > 0 && $onError === SheetToTableHelper::ON_ERROR_ROLLBACK)) {
                        throw new SheetToTableRollback($summary);
                    }
                });
            } else {
                $summary = self::run($file, $table, $options, $writer, $runInternal);
            }
        } catch (Throwable $caught) {
            $error = $caught;
        }

        // O ORM embrulha a exceção do callback: procura a original na cadeia.
        $rollback = isset($error) ? self::findInChain($error, 'SheetToTableRollback') : null;

        if ($rollback !== null) {
            $summary = $rollback->summary;
            $summary['valido'] = false;
            $summary['rollback'] = true;
            $summary['gravadas'] = 0;
            $summary['apagadas'] = 0;
            $summary['ignoradas_duplicadas'] = 0;

            return $finish($summary, $summary['com_erro'] > 0
                ? 'Importação cancelada: ' . $summary['com_erro'] . ' linha(s) com erro. Nada foi gravado (rollback). Corrija as linhas indicadas e envie de novo.'
                : $summary['mensagem'] . ' Nada foi gravado (rollback).');
        }

        if (isset($error)) {
            $userError = self::findInChain($error, 'SheetToTableException');

            if ($userError !== null) {
                throw $userError;
            }

            self::log('Falha ao gravar a importação.', ['tabela' => $tableName, 'erro' => $error->getMessage()], 'error');

            throw new SheetToTableException(
                $atomic
                    ? 'Falha ao gravar no banco. Nada foi gravado (rollback).'
                    : 'Falha ao gravar no banco. ' . $state['gravadas'] . ' linha(s) já tinham sido gravadas antes da falha.',
                500,
                $error
            );
        }

        $summary['rollback'] = false;
        $summary['gravadas'] = $state['gravadas'];
        $summary['apagadas'] = $state['apagadas'];
        $summary['ignoradas_duplicadas'] = $mode === self::MODE_INSERT_NEW ? max(0, $state['gravadas'] - $state['afetadas']) : 0;

        if ($mode === self::MODE_INSERT_NEW) {
            $summary['gravadas'] = $state['afetadas'];
        }

        if (!$atomic && $summary['com_erro'] > 0 && $onError === self::ON_ERROR_ROLLBACK) {
            $summary['valido'] = false;
            $message = 'Importação interrompida na primeira linha com erro: ' . $summary['gravadas'] . ' linha(s) já tinham sido gravadas (atomic = false, sem rollback).';
        } elseif ($summary['com_erro'] > 0) {
            $message = 'Importação concluída: ' . $summary['gravadas'] . ' linha(s) gravadas e ' . $summary['com_erro'] . ' com erro (puladas).';
        } else {
            $message = 'Importação concluída: ' . $summary['gravadas'] . ' linha(s) gravadas.';
        }

        if ($summary['apagadas'] > 0) {
            $message .= ' ' . $summary['apagadas'] . ' linha(s) antigas apagadas.';
        }

        if ($summary['ignoradas_duplicadas'] > 0) {
            $message .= ' ' . $summary['ignoradas_duplicadas'] . ' já existiam e foram ignoradas.';
        }

        self::log('Importação concluída.', [
            'tabela' => $tableName,
            'modo' => $mode,
            'gravadas' => $summary['gravadas'],
            'apagadas' => $summary['apagadas'],
            'com_erro' => $summary['com_erro'],
        ], 'info');

        return $finish($summary, $message);
    }

    /*
     * Arquivo modelo só com o cabeçalho que a tabela espera. CSV (com ; e
     * BOM para o Excel abrir com acentos) ou XLSX/ODS (phpspreadsheet).
     */
    public static function template($table, $destination, array $options = [])
    {
        $plan = self::plan(self::tableColumns($table, $options), $options);
        $fields = $plan['candidates'];
        $extension = strtolower(pathinfo((string) $destination, PATHINFO_EXTENSION));
        $directory = dirname((string) $destination);

        if (!is_dir($directory) || !is_writable($directory)) {
            throw new SheetToTableException('A pasta de destino não existe ou não tem permissão de escrita.');
        }

        if (in_array($extension, ['xlsx', 'xls', 'ods'], true)) {
            if (!self::supportsPhpSpreadsheet()) {
                throw new SheetToTableException('Para gerar Excel instale: composer require phpoffice/phpspreadsheet');
            }

            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();

            foreach (array_values($fields) as $index => $field) {
                $sheet->setCellValue(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($index + 1) . '1', $field);
            }

            $writers = ['xlsx' => 'Xlsx', 'xls' => 'Xls', 'ods' => 'Ods'];
            \PhpOffice\PhpSpreadsheet\IOFactory::createWriter($spreadsheet, $writers[$extension])->save($destination);
            $spreadsheet->disconnectWorksheets();

            return $destination;
        }

        $handle = fopen($destination, 'wb');

        if ($handle === false) {
            throw new SheetToTableException('Não foi possível criar o arquivo modelo.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($fields), isset($options['delimiter']) ? (string) $options['delimiter'] : ';', '"', '');
        fclose($handle);

        return $destination;
    }

    /* =================================================================
     * Ganchos usados dentro de closures (não chame direto)
     * ================================================================= */

    public static function runFor($file, $table, array $options, callable $onBatch, array $internal)
    {
        return self::run($file, $table, $options, $onBatch, $internal);
    }

    public static function writeBatchFor($orm, $table, array $columns, array $batch, array $options, array &$state)
    {
        return self::writeBatch($orm, $table, $columns, $batch, $options, $state);
    }

    // Registro para fora: "padrão do banco" sai do array (ou vira null para exibir).
    public static function exportRecord(array $record, $asNull = false)
    {
        foreach ($record as $field => $value) {
            if ($value === self::defaultMarker()) {
                if ($asNull) {
                    $record[$field] = null;
                } else {
                    unset($record[$field]);
                }
            }
        }

        return $record;
    }

    /* =================================================================
     * Motor: lê, casa, converte e entrega em lotes
     * ================================================================= */

    /*
     * $onBatch($itens, $numero) recebe [['line', 'data', 'errors'], ...]
     * e pode devolver erros do banco [['linha', 'campo', 'mensagem'], ...].
     * Internos: 'limit', 'keep_invalid', 'abort' (após o 1º erro, para de
     * entregar lotes mas segue lendo para listar todos os erros).
     */
    private static function run($file, $table, array $options, callable $onBatch, array $internal = [])
    {
        $columns = self::tableColumns($table, $options);
        $plan = self::plan($columns, $options);
        $source = self::source($file, $options);
        $batchSize = isset($options['batch_size']) ? max(1, min(self::$maxBatchSize, (int) $options['batch_size'])) : self::$batchSize;
        $maxErrors = isset($options['max_errors']) ? max(1, (int) $options['max_errors']) : self::$maxErrors;
        $limit = isset($internal['limit']) ? (int) $internal['limit'] : 0;
        $keepInvalid = !empty($internal['keep_invalid']);
        $abort = !empty($internal['abort']);
        $progress = isset($options['on_progress']) && is_callable($options['on_progress']) ? $options['on_progress'] : null;
        $reader = self::openRows($source, $options);

        $summary = self::emptySummary();
        $errorLines = [];
        $stopWriting = false;

        $addErrors = function (array $errors) use (&$summary, &$errorLines, $maxErrors) {
            foreach ($errors as $error) {
                if (!isset($errorLines[$error['linha']])) {
                    $errorLines[$error['linha']] = true;
                    $summary['com_erro']++;
                }

                if (count($summary['erros']) < $maxErrors) {
                    $summary['erros'][] = $error;
                } else {
                    $summary['erros_truncados'] = true;
                }
            }
        };

        try {
            $header = self::readHeader($reader['rows'], $options);
            $match = self::matchHeaders($plan['candidates'], $header['values'], array_merge($options, ['optional' => $plan['optional']]));

            $summary = array_merge($summary, $match, [
                'cabecalho' => $header['values'],
                'linha_cabecalho' => $header['line'],
                'aba' => $reader['sheet'],
                'campos_esperados' => $plan['required'],
                'campos_fixos' => array_keys($plan['fixed']),
                'campos_ignorados' => $plan['skipped'],
            ]);

            if (!$match['valido']) {
                return $summary;
            }

            $map = $match['mapeamento'];
            $batch = [];
            $batchNumber = 0;
            $isExcel = $source['type'] !== 'csv';

            $flush = function () use (&$batch, &$batchNumber, &$stopWriting, &$summary, $onBatch, $abort, $addErrors, $progress) {
                if (!$batch) {
                    return;
                }

                if (!$stopWriting) {
                    $dbErrors = $onBatch($batch, ++$batchNumber);

                    if (is_array($dbErrors) && $dbErrors) {
                        $addErrors($dbErrors);
                        $summary['validas'] -= count(array_unique(array_column($dbErrors, 'linha')));

                        if ($abort) {
                            $stopWriting = true;
                        }
                    }

                    $summary['lotes'] = $batchNumber;
                }

                $batch = [];

                if ($progress) {
                    call_user_func($progress, [
                        'lote' => $batchNumber,
                        'linhas_lidas' => $summary['total_linhas'],
                        'com_erro' => $summary['com_erro'],
                        'memoria_mb' => round(memory_get_usage(true) / 1048576, 1),
                    ]);
                }
            };

            // while, não foreach: o generator já avançou no cabeçalho e não pode voltar.
            $rows = $reader['rows'];

            for (; $rows->valid(); $rows->next()) {
                list($line, $values) = $rows->current();

                if (self::isEmptyRow($values)) {
                    continue;
                }

                $summary['total_linhas']++;
                $converted = self::convertRow($values, $map, $plan, $columns, $options, $line, $isExcel);

                if ($converted['errors']) {
                    $addErrors($converted['errors']);

                    if ($abort) {
                        $stopWriting = true;
                        $batch = [];
                    }

                    // Erros suficientes para o usuário corrigir: não precisa ler o resto.
                    if ($summary['erros_truncados'] && $abort) {
                        $summary['leitura_interrompida'] = true;
                        break;
                    }

                    if (!$keepInvalid) {
                        continue;
                    }
                } else {
                    $summary['validas']++;
                }

                if ($converted['data'] === false || ($stopWriting && !$keepInvalid)) {
                    continue;
                }

                $batch[] = ['line' => $line, 'data' => $converted['data'], 'errors' => $converted['errors']];

                if (count($batch) >= $batchSize) {
                    $flush();
                }

                if ($limit > 0 && $summary['total_linhas'] >= $limit) {
                    break;
                }
            }

            $flush();
        } finally {
            self::closeRows($reader);
        }

        if ($summary['total_linhas'] === 0) {
            $summary['valido'] = false;
            $summary['mensagem'] = 'O arquivo não tem linhas de dados abaixo do cabeçalho.';
        } elseif ($summary['com_erro'] > 0) {
            $summary['mensagem'] = $summary['com_erro'] . ' de ' . $summary['total_linhas'] . ' linha(s) com erro.';
        } else {
            $summary['mensagem'] = $summary['total_linhas'] . ' linha(s) válidas.';
        }

        return $summary;
    }

    private static function emptySummary()
    {
        return [
            'valido' => true,
            'mensagem' => '',
            'total_linhas' => 0,
            'validas' => 0,
            'com_erro' => 0,
            'gravadas' => 0,
            'apagadas' => 0,
            'ignoradas_duplicadas' => 0,
            'lotes' => 0,
            'erros' => [],
            'erros_truncados' => false,
            'leitura_interrompida' => false,
            'rollback' => false,
        ];
    }

    /*
     * Separa os campos da tabela em: candidatos (procurados no arquivo),
     * obrigatórios no arquivo, opcionais, fixos e ignorados.
     */
    private static function plan(array $columns, array $options)
    {
        $ignore = self::upperList(isset($options['ignore']) ? (array) $options['ignore'] : []);
        $only = self::upperList(isset($options['only']) ? (array) $options['only'] : []);
        $optional = self::upperList(isset($options['optional']) ? (array) $options['optional'] : []);
        $fixedInput = isset($options['fixed']) ? (array) $options['fixed'] : [];
        $requirePolicy = isset($options['require']) ? $options['require'] : 'all';
        $byUpper = [];

        foreach ($columns as $name => $column) {
            $byUpper[strtoupper($name)] = $name;
        }

        $fixed = [];

        foreach ($fixedInput as $field => $value) {
            if (!isset($byUpper[strtoupper($field)])) {
                throw new SheetToTableException('Campo fixo inexistente na tabela: ' . $field . '.');
            }

            $fixed[$byUpper[strtoupper($field)]] = $value;
        }

        $candidates = [];
        $required = [];
        $optionalFields = [];
        $skipped = [];

        foreach ($columns as $name => $column) {
            $upper = strtoupper($name);

            if ($column['auto'] || $column['generated'] || in_array($upper, $ignore, true) || ($only && !in_array($upper, $only, true))) {
                $skipped[] = $name;
                continue;
            }

            if (array_key_exists($name, $fixed)) {
                continue;
            }

            $candidates[] = $name;

            if (in_array($upper, $optional, true) || ($requirePolicy === 'not_null' && !$column['required_value'])) {
                $optionalFields[] = $name;
            } else {
                $required[] = $name;
            }
        }

        return [
            'candidates' => $candidates,
            'required' => $required,
            'optional' => $optionalFields,
            'fixed' => $fixed,
            'skipped' => $skipped,
        ];
    }

    private static function convertRow(array $values, array $map, array $plan, array $columns, array $options, $line, $isExcel)
    {
        $data = [];
        $errors = [];
        $transforms = isset($options['transform']) ? (array) $options['transform'] : [];
        $convert = !array_key_exists('convert', $options) || $options['convert'];
        $trim = !array_key_exists('trim', $options) || $options['trim'];

        foreach ($map as $field => $position) {
            $value = array_key_exists($position, $values) ? $values[$position] : null;

            if ($trim && is_string($value)) {
                $value = trim($value);
            }

            if ($value === '') {
                $value = null;
            }

            if (isset($transforms[$field]) && is_callable($transforms[$field])) {
                try {
                    $value = call_user_func($transforms[$field], $value, $values, $line);
                } catch (Throwable $error) {
                    $errors[] = self::rowError($line, $field, $error->getMessage(), $value);
                    continue;
                }
            }

            $column = $columns[$field];

            if ($value === null || $value === '') {
                if ($column['required_value']) {
                    $errors[] = self::rowError($line, $field, 'Campo obrigatório sem valor.', null);
                } elseif (!$column['nullable'] && $column['has_default']) {
                    $data[$field] = self::defaultMarker();
                } else {
                    $data[$field] = null;
                }

                continue;
            }

            if (!$convert) {
                $data[$field] = $value;
                continue;
            }

            $result = self::convertValue($value, $column, $options, $isExcel);

            if ($result[1] !== null) {
                $errors[] = self::rowError($line, $field, $result[1], $value);
                continue;
            }

            $data[$field] = $result[0];
        }

        foreach ($plan['fixed'] as $field => $value) {
            $data[$field] = $value instanceof Closure ? $value($values, $line) : $value;
        }

        if (!$errors && isset($options['row_callback']) && is_callable($options['row_callback'])) {
            try {
                $result = call_user_func($options['row_callback'], $data, $line);

                if ($result === false) {
                    return ['data' => false, 'errors' => []];
                }

                if (is_array($result)) {
                    $data = $result;
                }
            } catch (Throwable $error) {
                $errors[] = self::rowError($line, null, $error->getMessage(), null);
            }
        }

        return ['data' => $data, 'errors' => $errors];
    }

    /* =================================================================
     * Validação e conversão pelo tipo da coluna
     * ================================================================= */

    /*
     * Devolve [valor convertido, null] ou [null, 'mensagem de erro'].
     */
    private static function convertValue($value, array $column, array $options, $isExcel)
    {
        $decimal = isset($options['decimal']) ? $options['decimal'] : 'auto';

        switch ($column['base']) {
            case 'bool':
                $bool = self::parseBool($value);

                return $bool === null
                    ? [null, 'Valor não é sim/não (use 1/0, sim/não, true/false): "' . self::show($value) . '".']
                    : [$bool ? '1' : '0', null];

            case 'int':
                if ($column['length'] === 1 && !is_numeric($value) && self::parseBool($value) !== null) {
                    return [self::parseBool($value) ? '1' : '0', null];
                }

                $number = self::parseNumber($value, $decimal);

                if ($number === null) {
                    return [null, 'Número inteiro inválido: "' . self::show($value) . '".'];
                }

                if (!preg_match('/^(-?\d+)(\.0+)?$/', $number, $matches)) {
                    return [null, 'Esperado número inteiro, veio decimal: "' . self::show($value) . '".'];
                }

                $integer = $matches[1] === '-0' ? '0' : $matches[1];
                $range = self::intRange($column);

                if ($range !== null && (self::compareInt($integer, $range[0]) < 0 || self::compareInt($integer, $range[1]) > 0)) {
                    return [null, 'Número fora do limite da coluna (' . $range[0] . ' a ' . $range[1] . '): "' . self::show($value) . '".'];
                }

                return [$integer, null];

            case 'decimal':
                $number = self::parseNumber($value, $decimal);

                if ($number === null) {
                    return [null, 'Número inválido: "' . self::show($value) . '".'];
                }

                if ($column['unsigned'] && strpos($number, '-') === 0) {
                    return [null, 'Número negativo não é permitido nesta coluna: "' . self::show($value) . '".'];
                }

                if ($column['precision'] > 0) {
                    $parts = explode('.', ltrim($number, '-'));
                    $intDigits = strlen(ltrim($parts[0], '0'));
                    $maxIntDigits = $column['precision'] - $column['scale'];

                    if ($intDigits > $maxIntDigits) {
                        return [null, 'Número grande demais para a coluna (até ' . $maxIntDigits . ' dígitos antes da vírgula): "' . self::show($value) . '".'];
                    }
                }

                return [$number, null];

            case 'date':
            case 'datetime':
            case 'timestamp':
            case 'time':
                $date = self::parseDate($value, $column['base'], $options, $isExcel);

                if ($date === null) {
                    return [null, ($column['base'] === 'time' ? 'Hora inválida' : 'Data inválida') . ': "' . self::show($value) . '".'];
                }

                $rangeError = self::dateRangeError($date, $column['base']);

                return $rangeError !== null ? [null, $rangeError] : [$date, null];

            case 'year':
                $number = self::parseNumber($value, $decimal);
                $year = $number !== null && preg_match('/^\d{4}$/', $number) ? (int) $number : null;

                return $year === null || ($year !== 0 && ($year < 1901 || $year > 2155))
                    ? [null, 'Ano inválido: "' . self::show($value) . '".']
                    : [(string) $year, null];

            case 'enum':
                $text = self::toText($value);

                foreach ($column['enum'] as $option) {
                    if (mb_strtolower($option) === mb_strtolower($text)) {
                        return [$option, null];
                    }
                }

                return [null, 'Valor não permitido: "' . self::show($value) . '". Use: ' . implode(', ', $column['enum']) . '.'];

            case 'json':
                $text = self::toText($value);
                json_decode($text);

                return json_last_error() === JSON_ERROR_NONE ? [$text, null] : [null, 'JSON inválido.'];

            case 'uuid':
                $text = self::toText($value);

                return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $text)
                    ? [$text, null]
                    : [null, 'UUID inválido: "' . self::show($value) . '".'];

            default:
                $text = self::toText($value);

                if (!mb_check_encoding($text, 'UTF-8')) {
                    return [null, 'Texto com caracteres inválidos (codificação).'];
                }

                if ($column['length'] > 0 && self::length($text) > $column['length']) {
                    return [null, 'Texto com ' . self::length($text) . ' caracteres; o máximo da coluna é ' . $column['length'] . '.'];
                }

                if ($column['bytes'] > 0 && strlen($text) > $column['bytes']) {
                    return [null, 'Texto maior que o permitido pela coluna (' . $column['bytes'] . ' bytes).'];
                }

                return [$text, null];
        }
    }

    private static function intRange(array $column)
    {
        $ranges = [
            'tinyint' => ['-128', '127', '255'],
            'smallint' => ['-32768', '32767', '65535'],
            'mediumint' => ['-8388608', '8388607', '16777215'],
            'int' => ['-2147483648', '2147483647', '4294967295'],
            'bigint' => ['-9223372036854775808', '9223372036854775807', '18446744073709551615'],
        ];

        if (!isset($ranges[$column['int_kind']])) {
            return null;
        }

        $range = $ranges[$column['int_kind']];

        return $column['unsigned'] ? ['0', $range[2]] : [$range[0], $range[1]];
    }

    // Compara inteiros em texto (sem perder precisão em BIGINT).
    private static function compareInt($a, $b)
    {
        $negA = strpos($a, '-') === 0;
        $negB = strpos($b, '-') === 0;

        if ($negA !== $negB) {
            return $negA ? -1 : 1;
        }

        $absA = ltrim(ltrim($a, '-'), '0');
        $absB = ltrim(ltrim($b, '-'), '0');
        $cmp = strlen($absA) === strlen($absB) ? strcmp($absA, $absB) : (strlen($absA) < strlen($absB) ? -1 : 1);
        $cmp = $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);

        return $negA ? -$cmp : $cmp;
    }

    private static function dateRangeError($value, $base)
    {
        if ($base === 'time') {
            return null;
        }

        $year = (int) substr($value, 0, 4);

        if ($base === 'timestamp' && ($value < '1970-01-01 00:00:01' || $value > '2038-01-19 03:14:07')) {
            return 'Data fora do limite de TIMESTAMP (1970 a 2038): "' . $value . '".';
        }

        if ($year < 1000 || $year > 9999) {
            return 'Ano fora do limite (1000 a 9999): "' . $value . '".';
        }

        return null;
    }

    /*
     * '1.234,56' / '1,234.56' / '1234.56' / 'R$ 10,00' / '15%' / '(10)' -> '1234.56'.
     * 'auto': com os dois separadores, o último é o decimal; só vírgula é
     * decimal; só ponto é decimal, salvo grupos de milhar (1.234.567).
     * Devolve texto numérico normalizado (sem perder precisão) ou null.
     */
    private static function parseNumber($value, $mode = 'auto')
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return is_finite($value) ? self::floatToString($value) : null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = preg_replace('/[\s\x{00A0}]|R\$|%/u', '', (string) $value);
        $negative = false;

        if (preg_match('/^\((.*)\)$/', $text, $matches)) {
            $text = $matches[1];
            $negative = true;
        }

        if ($text === '' || !preg_match('/^[+-]?[\d.,]+([eE][+-]?\d+)?$/', $text)) {
            return null;
        }

        $comma = strrpos($text, ',');
        $dot = strrpos($text, '.');

        if ($mode === 'br') {
            $text = str_replace(['.', ','], ['', '.'], $text);
        } elseif ($mode === 'us') {
            $text = str_replace(',', '', $text);
        } elseif ($comma !== false && $dot !== false) {
            $text = $comma > $dot ? str_replace(['.', ','], ['', '.'], $text) : str_replace(',', '', $text);
        } elseif ($comma !== false) {
            $text = substr_count($text, ',') > 1 ? str_replace(',', '', $text) : str_replace(',', '.', $text);
        } elseif ($dot !== false && substr_count($text, '.') > 1) {
            $text = str_replace('.', '', $text);
        }

        if (!is_numeric($text)) {
            return null;
        }

        if (stripos($text, 'e') !== false) {
            $text = self::floatToString((float) $text);
        }

        $text = ltrim($text, '+');

        if ($negative && strpos($text, '-') !== 0) {
            $text = '-' . $text;
        }

        // Normaliza: tira zeros à esquerda e o ponto final ("010." -> "10").
        if (preg_match('/^(-?)(\d*)(?:\.(\d*))?$/', $text, $parts)) {
            $intPart = ltrim($parts[2], '0');
            $intPart = $intPart === '' ? '0' : $intPart;
            $fraction = isset($parts[3]) ? $parts[3] : '';
            $text = $parts[1] . $intPart . ($fraction !== '' ? '.' . $fraction : '');
        }

        return $text === '-0' ? '0' : $text;
    }

    private static function floatToString($number)
    {
        if (floor($number) == $number && abs($number) < 1e15) {
            return sprintf('%.0f', $number);
        }

        return rtrim(rtrim(sprintf('%.10F', $number), '0'), '.');
    }

    private static function toText($value)
    {
        if (is_float($value)) {
            return self::floatToString($value);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private static function parseBool($value)
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value == 1 ? true : ($value == 0 ? false : null);
        }

        $text = mb_strtolower(trim((string) $value));

        if (in_array($text, ['1', 'true', 'verdadeiro', 'sim', 's', 'yes', 'y', 'x', 'ativo', 't'], true)) {
            return true;
        }

        return in_array($text, ['0', 'false', 'falso', 'nao', 'não', 'n', 'no', 'inativo', 'f'], true) ? false : null;
    }

    /*
     * Datas em ISO, padrão brasileiro ou número serial do Excel.
     * Devolve 'Y-m-d', 'Y-m-d H:i:s' ou 'H:i:s' conforme a coluna.
     */
    private static function parseDate($value, $base, array $options, $isExcel)
    {
        $output = $base === 'date' ? 'Y-m-d' : ($base === 'time' ? 'H:i:s' : 'Y-m-d H:i:s');

        if ($value instanceof DateTimeInterface) {
            return $value->format($output);
        }

        // Serial do Excel (dias desde 1899-12-30). Em CSV não: lá "45000" não é data.
        if ($isExcel && (is_int($value) || is_float($value) || is_numeric($value)) && (float) $value >= 0 && (float) $value < 2958466) {
            $serial = (float) $value;

            if ($base === 'time') {
                return gmdate('H:i:s', (int) round(fmod($serial, 1) * 86400));
            }

            return gmdate($output, (int) round(($serial - 25569) * 86400));
        }

        $text = trim((string) $value);
        $formats = [];

        if (!empty($options['date_format'])) {
            $formats = array_merge($formats, (array) $options['date_format']);
        }

        $formats = array_merge($formats, [
            'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\TH:i:s', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.uP', 'Y-m-d H:i:s.u', 'Y-m-d',
            'd/m/Y H:i:s', 'd/m/Y H:i', 'd/m/Y',
            'd-m-Y H:i:s', 'd-m-Y', 'd.m.Y', 'Y/m/d', 'Ymd', 'd/m/y',
            'H:i:s', 'H:i',
        ]);

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat('!' . $format, $text);

            if ($date === false) {
                continue;
            }

            $errors = DateTime::getLastErrors();

            // getLastErrors() devolve false quando não há erro (PHP 8.2+).
            if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                continue;
            }

            return $date->format($output);
        }

        return null;
    }

    /* =================================================================
     * Gravação
     * ================================================================= */

    private static function writeOptions($db, array $columns, $mode, $atomic, array $options)
    {
        $postgres = self::isPostgres($db);
        $uniqueBy = isset($options['unique_by']) ? self::realFields($columns, (array) $options['unique_by'], 'unique_by') : [];
        $replaceBy = isset($options['replace_by']) ? self::realFields($columns, (array) $options['replace_by'], 'replace_by') : [];
        $replaceWhere = [];

        if (!empty($options['replace_where'])) {
            foreach ((array) $options['replace_where'] as $field => $value) {
                $real = self::realFields($columns, [$field], 'replace_where');
                $replaceWhere[$real[0]] = $value;
            }
        }

        if (($replaceBy || $replaceWhere) && $mode !== self::MODE_REPLACE) {
            throw new SheetToTableException('"replace_by" e "replace_where" só valem com mode = replace.');
        }

        if ($replaceBy && $replaceWhere) {
            throw new SheetToTableException('Use "replace_by" ou "replace_where", não os dois.');
        }

        if ($mode === self::MODE_UPSERT && $postgres && !$uniqueBy) {
            throw new SheetToTableException('No PostgreSQL o upsert precisa de "unique_by" (colunas da chave única).');
        }

        $strategy = isset($options['replace_strategy']) ? strtolower((string) $options['replace_strategy']) : 'delete';

        if (!in_array($strategy, ['delete', 'truncate'], true)) {
            throw new SheetToTableException('replace_strategy deve ser "delete" ou "truncate".');
        }

        // TRUNCATE no MySQL confirma a transação na hora: não teria rollback.
        if ($strategy === 'truncate' && $mode === self::MODE_REPLACE && !$postgres && $atomic) {
            throw new SheetToTableException('TRUNCATE no MySQL não pode ser desfeito. Use replace_strategy = delete (padrão) ou atomic = false com validate_first = true.');
        }

        return [
            'orm_options' => ['connection' => $db, 'timestamps' => false],
            'truncate_upfront' => $strategy === 'truncate' && $mode === self::MODE_REPLACE && !$replaceBy && !$replaceWhere,
            'mode' => $mode,
            'postgres' => $postgres,
            'unique_by' => $uniqueBy,
            'replace_by' => $replaceBy,
            'replace_where' => $replaceWhere,
            'replace_strategy' => $strategy,
            'max_sql_bytes' => isset($options['max_sql_bytes']) ? max(65536, (int) $options['max_sql_bytes']) : self::$maxSqlBytes,
            'show_db_errors' => !array_key_exists('show_db_errors', $options) || $options['show_db_errors'],
        ];
    }

    /*
     * Grava um lote. Devolve [] ou a lista de erros do banco por linha.
     */
    private static function writeBatch($orm, $table, array $columns, array $batch, array $options, array &$state)
    {
        if (!$state['prepared']) {
            $state['prepared'] = true;

            if ($options['mode'] === self::MODE_REPLACE && !$options['replace_by']) {
                $state['apagadas'] += self::deleteRows($table, $options);
            }
        }

        if ($options['replace_by']) {
            $state['apagadas'] += self::deleteByFileKeys($table, $batch, $options, $state);
        }

        $db = $orm->db();
        $fields = [];

        foreach ($batch as $item) {
            foreach (array_keys($item['data']) as $field) {
                $fields[$field] = true;
            }
        }

        $fields = array_keys($fields);

        foreach ($fields as $field) {
            if (!isset($columns[$field])) {
                throw new SheetToTableException('Campo inexistente na tabela: ' . $field . '.');
            }
        }

        $prefix = 'INSERT INTO ' . $db->quoteName($table) . ' (' . implode(', ', array_map([$db, 'quoteName'], $fields)) . ') VALUES ';
        $suffix = self::conflictClause($db, $fields, $options);
        $errors = [];
        $chunk = [];
        $baseBytes = strlen($prefix) + strlen($suffix);
        $chunkBytes = $baseBytes;

        foreach ($batch as $item) {
            $tuple = self::tuple($fields, $item['data']);

            // Divide o lote se o INSERT passar do tamanho máximo (max_allowed_packet).
            if ($chunk && $chunkBytes + $tuple['bytes'] > $options['max_sql_bytes']) {
                $errors = array_merge($errors, self::executeChunk($orm, $prefix, $suffix, $chunk, $options, $state));
                $chunk = [];
                $chunkBytes = $baseBytes;
            }

            $tuple['line'] = $item['line'];
            $chunk[] = $tuple;
            $chunkBytes += $tuple['bytes'];
        }

        if ($chunk) {
            $errors = array_merge($errors, self::executeChunk($orm, $prefix, $suffix, $chunk, $options, $state));
        }

        return $errors;
    }

    /*
     * Um INSERT com várias linhas (OrmBase::rawExecute com bindings)
     * dentro de um savepoint. Se o banco recusar, volta ao savepoint e
     * grava linha a linha para achar exatamente a linha que ele rejeitou.
     */
    private static function executeChunk($orm, $prefix, $suffix, array $chunk, array $options, array &$state)
    {
        $db = $orm->db();
        $bindings = [];

        foreach ($chunk as $tuple) {
            foreach ($tuple['bindings'] as $value) {
                $bindings[] = $value;
            }
        }

        self::savepoint($db, 'begin');

        try {
            $affected = $orm->rawExecute($prefix . implode(', ', array_column($chunk, 'sql')) . $suffix, $bindings);
            self::savepoint($db, 'commit');
            $state['gravadas'] += count($chunk);
            $state['afetadas'] += $affected;

            return [];
        } catch (Throwable $error) {
            self::savepoint($db, 'rollback');
        }

        $errors = [];

        foreach ($chunk as $tuple) {
            self::savepoint($db, 'begin');

            try {
                $affected = $orm->rawExecute($prefix . $tuple['sql'] . $suffix, $tuple['bindings']);
                self::savepoint($db, 'commit');
                $state['gravadas']++;
                $state['afetadas'] += $affected;
            } catch (Throwable $error) {
                self::savepoint($db, 'rollback');

                $info = self::describeDbError($error);
                $errors[] = [
                    'linha' => (int) $tuple['line'],
                    'campo' => $info['campo'],
                    'mensagem' => $info['mensagem'] . ($options['show_db_errors'] && $info['detalhe'] !== '' ? ' (' . $info['detalhe'] . ')' : ''),
                    'valor' => null,
                ];
            }
        }

        return $errors;
    }

    /*
     * "(?, ?, DEFAULT, ?)" + bindings de uma linha. Os valores vão como
     * texto entre aspas: MySQL e PostgreSQL convertem para o tipo da coluna.
     */
    private static function tuple(array $fields, array $record)
    {
        $parts = [];
        $bindings = [];
        $bytes = 4;

        foreach ($fields as $field) {
            $value = array_key_exists($field, $record) ? $record[$field] : self::defaultMarker();

            if ($value === self::defaultMarker()) {
                $parts[] = 'DEFAULT';
                $bytes += 9;
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value !== null && !($value instanceof DateTimeInterface)) {
                $value = (string) $value;
            }

            $parts[] = '?';
            $bindings[] = $value;
            $bytes += is_string($value) ? strlen($value) * 2 + 4 : 24;
        }

        return ['sql' => '(' . implode(', ', $parts) . ')', 'bindings' => $bindings, 'bytes' => $bytes];
    }

    private static function conflictClause($db, array $fields, array $options)
    {
        $mode = $options['mode'];
        $updatable = array_values(array_diff($fields, $options['unique_by']));

        if ($mode === self::MODE_UPSERT) {
            if ($options['postgres']) {
                return ' ON CONFLICT (' . implode(', ', array_map([$db, 'quoteName'], $options['unique_by'])) . ')'
                    . ($updatable ? ' DO UPDATE SET ' . implode(', ', array_map(function ($field) use ($db) {
                        return $db->quoteName($field) . ' = EXCLUDED.' . $db->quoteName($field);
                    }, $updatable)) : ' DO NOTHING');
            }

            return $updatable ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(function ($field) use ($db) {
                return $db->quoteName($field) . ' = VALUES(' . $db->quoteName($field) . ')';
            }, $updatable)) : '';
        }

        if ($mode === self::MODE_INSERT_NEW) {
            if ($options['postgres']) {
                return $options['unique_by']
                    ? ' ON CONFLICT (' . implode(', ', array_map([$db, 'quoteName'], $options['unique_by'])) . ') DO NOTHING'
                    : ' ON CONFLICT DO NOTHING';
            }

            // Não usa INSERT IGNORE: ele também engoliria erros de tipo/tamanho.
            $first = $db->quoteName($fields[0]);

            return ' ON DUPLICATE KEY UPDATE ' . $first . ' = ' . $first;
        }

        return '';
    }

    // Consulta nova do ORM na mesma conexão da importação.
    private static function query($table, array $options)
    {
        return OrmTables::table($table, $options['orm_options']);
    }

    private static function deleteRows($table, array $options)
    {
        $query = self::query($table, $options);

        if ($options['replace_where']) {
            foreach ($options['replace_where'] as $field => $value) {
                if ($value === null) {
                    $query->whereNull($field);
                } elseif (is_array($value)) {
                    if (!$value) {
                        return 0;
                    }

                    $query->whereIn($field, array_values($value));
                } else {
                    $query->where($field, $value);
                }
            }

            return $query->deleteWhere();
        }

        return $query->allowMassOperation()->deleteWhere();
    }

    /*
     * replace_by: antes de gravar cada lote, apaga do banco as chaves do
     * lote que ainda não foram apagadas (cada chave é apagada uma vez só,
     * para não apagar o que o próprio arquivo acabou de inserir).
     */
    private static function deleteByFileKeys($table, array $batch, array $options, array &$state)
    {
        $keys = [];

        foreach ($batch as $item) {
            $tuple = [];

            foreach ($options['replace_by'] as $field) {
                $value = array_key_exists($field, $item['data']) ? $item['data'][$field] : null;
                $tuple[$field] = $value === self::defaultMarker() ? null : $value;
            }

            $hash = md5(serialize(array_values($tuple)));

            if (!isset($state['deleted_keys'][$hash])) {
                $state['deleted_keys'][$hash] = true;
                $keys[] = $tuple;
            }
        }

        $deleted = 0;

        foreach (array_chunk($keys, 500) as $chunk) {
            $query = self::query($table, $options);

            if (count($options['replace_by']) === 1) {
                $field = $options['replace_by'][0];
                $values = array_column($chunk, $field);
                $notNull = array_values(array_filter($values, function ($value) {
                    return $value !== null;
                }));
                $hasNull = count($notNull) < count($values);

                $query->whereGroup(function ($group) use ($field, $notNull, $hasNull) {
                    if ($notNull) {
                        $group->whereIn($field, $notNull);
                    }

                    if ($hasNull) {
                        $group->orWhereNull($field);
                    }
                });
            } else {
                $query->whereGroup(function ($group) use ($chunk) {
                    foreach ($chunk as $tuple) {
                        $group->orWhereGroup(function ($and) use ($tuple) {
                            foreach ($tuple as $field => $value) {
                                if ($value === null) {
                                    $and->whereNull($field);
                                } else {
                                    $and->where($field, $value);
                                }
                            }
                        });
                    }
                });
            }

            $deleted += $query->deleteWhere();
        }

        return $deleted;
    }

    /*
     * SAVEPOINT pelo próprio driver (transactionStart(true) dentro de uma
     * transação). SQL "SAVEPOINT x" direto falha no mysqli do Joomla 4+,
     * que executa tudo como prepared statement.
     */
    private static function savepoint($db, $action)
    {
        $ok = $action === 'begin'
            ? DbTransactionHelper::begin($db)
            : ($action === 'commit' ? DbTransactionHelper::commit($db) : DbTransactionHelper::rollback($db));

        if (!$ok) {
            throw new SheetToTableException('Falha ao controlar o savepoint da importação (' . $action . ').');
        }
    }

    // Procura uma exceção de uma classe na cadeia (o ORM embrulha as exceções).
    private static function findInChain(Throwable $error, $class)
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof $class) {
                return $current;
            }
        }

        return null;
    }

    /*
     * Erro do banco em texto para o usuário, com o campo quando dá para saber.
     */
    private static function describeDbError(Throwable $error)
    {
        $raw = '';
        $codes = [];

        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $codes[] = (string) $current->getCode();

            if ($raw === '' && trim($current->getMessage()) !== '') {
                $raw = $current->getMessage();
            }
        }

        // Joomla 3 anexa o SQL inteiro na mensagem: corta.
        $raw = trim(preg_replace('/\s*SQL\s*=.*$/s', '', $raw));
        $raw = preg_replace('/^(Erro ao executar SQL raw:\s*)+/i', '', $raw);
        $raw = mb_substr(preg_replace('/\s+/', ' ', $raw), 0, 300);

        $field = null;

        foreach (['/FOREIGN KEY \(`?"?([^`")]+)`?"?\)/i', "/column '([^']+)'/i", '/column "([^"]+)"/i', '/Key \(([^)]+)\)=/i'] as $pattern) {
            if (preg_match($pattern, $raw, $matches)) {
                $field = $matches[1];
                break;
            }
        }

        $has = function ($list) use ($codes) {
            return (bool) array_intersect($codes, $list);
        };

        if ($has(['1062', '23505']) || stripos($raw, 'duplicate') !== false) {
            $message = 'Registro duplicado: já existe uma linha com essa chave única';

            if (preg_match("/Duplicate entry '(.*)' for key '?([^']+)'?/i", $raw, $matches)) {
                $message .= ' (valor "' . $matches[1] . '", chave ' . $matches[2] . ')';
            }

            return ['campo' => $field, 'mensagem' => $message . '.', 'detalhe' => ''];
        }

        if ($has(['1452', '1451', '23503']) || stripos($raw, 'foreign key') !== false) {
            return ['campo' => $field, 'mensagem' => 'Valor não encontrado na tabela relacionada.', 'detalhe' => $raw];
        }

        if ($has(['1048', '1364', '23502']) || stripos($raw, 'cannot be null') !== false || stripos($raw, 'null value') !== false) {
            return ['campo' => $field, 'mensagem' => 'Campo obrigatório sem valor.', 'detalhe' => ''];
        }

        if ($has(['1406', '22001']) || stripos($raw, 'too long') !== false) {
            return ['campo' => $field, 'mensagem' => 'Texto maior que o permitido pela coluna.', 'detalhe' => ''];
        }

        if ($has(['1264', '22003']) || stripos($raw, 'out of range') !== false) {
            return ['campo' => $field, 'mensagem' => 'Número fora do limite da coluna.', 'detalhe' => ''];
        }

        if ($has(['1366', '1292', '1265', '22P02', '22007', '22008']) || stripos($raw, 'incorrect') !== false || stripos($raw, 'invalid input') !== false) {
            return ['campo' => $field, 'mensagem' => 'Valor em formato inválido para a coluna.', 'detalhe' => $raw];
        }

        return ['campo' => $field, 'mensagem' => 'O banco recusou a linha.', 'detalhe' => $raw];
    }

    private static function defaultMarker()
    {
        if (self::$defaultMarker === null) {
            self::$defaultMarker = new stdClass();
        }

        return self::$defaultMarker;
    }

    /* =================================================================
     * Leitura de arquivos
     * ================================================================= */

    /*
     * Aceita caminho ou a entrada do $_FILES (['tmp_name', 'name', 'error']).
     */
    private static function source($file, array $options = [])
    {
        $name = null;

        if (is_array($file)) {
            if (isset($file['error']) && (int) $file['error'] !== 0) {
                throw new SheetToTableException('O envio do arquivo falhou (código ' . (int) $file['error'] . ').');
            }

            $name = isset($file['name']) ? (string) $file['name'] : null;
            $file = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
        }

        $path = (string) $file;

        if ($path === '' || !is_file($path) || !is_readable($path)) {
            throw new SheetToTableException('Arquivo não encontrado ou sem permissão de leitura.');
        }

        $size = filesize($path);

        if ($size === 0) {
            throw new SheetToTableException('O arquivo está vazio.');
        }

        if ($size > self::$maxFileSize) {
            throw new SheetToTableException('O arquivo passa do limite de ' . round(self::$maxFileSize / 1048576) . ' MB.');
        }

        $extension = isset($options['extension'])
            ? strtolower((string) $options['extension'])
            : strtolower(pathinfo($name !== null ? $name : $path, PATHINFO_EXTENSION));

        if (in_array($extension, self::$csvExtensions, true)) {
            return ['path' => $path, 'extension' => $extension, 'type' => 'csv'];
        }

        if ($extension === 'xlsx' || $extension === 'xlsm') {
            if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
                throw new SheetToTableException('Para importar XLSX o PHP precisa das extensões zip e xmlreader.');
            }

            return ['path' => $path, 'extension' => $extension, 'type' => 'xlsx'];
        }

        if (in_array($extension, self::$excelExtensions, true)) {
            if (!self::supportsPhpSpreadsheet()) {
                throw new SheetToTableException('Para importar ' . strtoupper($extension) . ' instale: composer require phpoffice/phpspreadsheet');
            }

            return ['path' => $path, 'extension' => $extension, 'type' => 'spreadsheet'];
        }

        throw new SheetToTableException('Formato não suportado: .' . $extension . '. Use ' . implode(', ', self::supportedExtensions()) . '.');
    }

    private static function openRows(array $source, array $options)
    {
        if ($source['type'] === 'csv') {
            return self::openCsv($source['path'], $options);
        }

        if ($source['type'] === 'xlsx') {
            return self::openXlsx($source['path'], $options);
        }

        return self::openSpreadsheet($source, $options);
    }

    private static function closeRows(array $reader)
    {
        if (isset($reader['close']) && is_callable($reader['close'])) {
            call_user_func($reader['close']);
        }
    }

    /*
     * Primeira linha não vazia (ou 'header_row', contando a partir de 1).
     */
    private static function readHeader(Generator $rows, array $options)
    {
        $wanted = isset($options['header_row']) ? max(1, (int) $options['header_row']) : 0;

        while ($rows->valid()) {
            list($line, $values) = $rows->current();
            $rows->next();

            if ($wanted > 0) {
                if ($line < $wanted) {
                    continue;
                }

                if ($line > $wanted) {
                    break;
                }
            } elseif (self::isEmptyRow($values)) {
                continue;
            }

            $values = array_map(function ($value) {
                return $value === null ? '' : trim(self::stripBom(self::toText($value)));
            }, $values);

            // Remove colunas vazias no fim (célula formatada sem conteúdo).
            while ($values && end($values) === '') {
                array_pop($values);
            }

            if (!$values) {
                break;
            }

            return ['line' => $line, 'values' => $values];
        }

        throw new SheetToTableException($wanted > 0
            ? 'A linha ' . $wanted . ' do arquivo está vazia ou não existe.'
            : 'O arquivo não tem cabeçalho (está vazio).');
    }

    /* ---- CSV (linha a linha) --------------------------------------- */

    private static function openCsv($path, array $options)
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new SheetToTableException('Não foi possível abrir o arquivo.');
        }

        $sample = (string) fread($handle, 65536);
        $encoding = isset($options['encoding']) ? strtoupper((string) $options['encoding']) : self::detectEncoding($sample);
        $tempFile = null;

        // UTF-16 não é lido pelo fgetcsv: converte em blocos para um temporário UTF-8.
        if (strpos($encoding, 'UTF-16') === 0) {
            $tempFile = self::convertUtf16($handle, $encoding);
            fclose($handle);
            $handle = fopen($tempFile, 'rb');
            $encoding = 'UTF-8';
            $sample = (string) fread($handle, 65536);
        }

        rewind($handle);

        if (strncmp($sample, "\xEF\xBB\xBF", 3) === 0) {
            fseek($handle, 3);
            $sample = substr($sample, 3);
        }

        $delimiter = isset($options['delimiter']) && $options['delimiter'] !== '' ? (string) $options['delimiter'] : null;
        $skipSepLine = false;

        // Linha "sep=;" que o Excel grava/entende.
        if (preg_match('/^sep=(.)\r?\n/i', $sample, $matches)) {
            $delimiter = $delimiter !== null ? $delimiter : $matches[1];
            $skipSepLine = true;
        }

        if ($delimiter === null) {
            $delimiter = self::detectDelimiter($sample);
        }

        $convert = $encoding !== 'UTF-8';
        $enclosure = isset($options['enclosure']) ? (string) $options['enclosure'] : '"';

        $rows = (function () use ($handle, $delimiter, $enclosure, $convert, $encoding, $skipSepLine) {
            $line = 0;

            if ($skipSepLine) {
                fgets($handle);
                $line++;
            }

            while (($values = fgetcsv($handle, 0, $delimiter, $enclosure, '')) !== false) {
                $line++;

                if ($values === [null]) {
                    yield [$line, []];
                    continue;
                }

                if ($convert) {
                    foreach ($values as $index => $value) {
                        if ($value !== null) {
                            $values[$index] = mb_convert_encoding($value, 'UTF-8', $encoding);
                        }
                    }
                }

                yield [$line, $values];
            }
        })();

        return [
            'rows' => $rows,
            'sheet' => 'CSV',
            'close' => function () use ($handle, $tempFile) {
                if (is_resource($handle)) {
                    fclose($handle);
                }

                if ($tempFile !== null && is_file($tempFile)) {
                    @unlink($tempFile);
                }
            },
        ];
    }

    private static function convertUtf16($handle, $encoding)
    {
        $temp = tempnam(sys_get_temp_dir(), 'stt');
        $out = fopen($temp, 'wb');
        rewind($handle);
        fread($handle, 2);
        $carry = '';

        while (!feof($handle)) {
            $chunk = $carry . fread($handle, 1048576);
            // Mantém número par de bytes e não corta um par substituto ao meio.
            $cut = strlen($chunk) - (strlen($chunk) % 2);
            $carry = substr($chunk, $cut);
            $chunk = substr($chunk, 0, $cut);

            if ($chunk !== '') {
                $last = $encoding === 'UTF-16BE' ? ord($chunk[strlen($chunk) - 2]) : ord($chunk[strlen($chunk) - 1]);

                if ($last >= 0xD8 && $last <= 0xDB && !feof($handle)) {
                    $carry = substr($chunk, -2) . $carry;
                    $chunk = substr($chunk, 0, -2);
                }

                fwrite($out, mb_convert_encoding($chunk, 'UTF-8', $encoding));
            }
        }

        fclose($out);

        return $temp;
    }

    private static function detectEncoding($sample)
    {
        if (strncmp($sample, "\xFF\xFE", 2) === 0) {
            return 'UTF-16LE';
        }

        if (strncmp($sample, "\xFE\xFF", 2) === 0) {
            return 'UTF-16BE';
        }

        // Corta um possível caractere multibyte pela metade no fim da amostra.
        $check = strlen($sample) >= 65536 ? substr($sample, 0, -4) : $sample;

        return mb_check_encoding($check, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
    }

    /*
     * Delimitador que divide as primeiras linhas no mesmo número de
     * colunas (e no maior número delas).
     */
    private static function detectDelimiter($sample)
    {
        $lines = array_slice(array_values(array_filter(preg_split('/\r\n|\n|\r/', $sample), function ($line) {
            return trim($line) !== '';
        })), 0, 10);

        if (count($lines) > 1 && strlen($sample) >= 65536) {
            array_pop($lines);
        }

        $best = ';';
        $bestScore = -1;

        foreach (self::$delimiterCandidates as $delimiter) {
            $counts = array_map(function ($line) use ($delimiter) {
                return count(str_getcsv($line, $delimiter, '"', ''));
            }, $lines);

            if (!$counts || max($counts) < 2) {
                continue;
            }

            $score = (count(array_unique($counts)) === 1 ? 1000 : 0) + max($counts);

            if ($score > $bestScore) {
                $best = $delimiter;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /* ---- XLSX (leitor próprio, linha a linha) ----------------------- */

    /*
     * Lê o XML da planilha em fluxo (XMLReader), sem montar a planilha
     * na memória: 1 milhão de linhas usa poucos MB. Só os textos
     * compartilhados (sharedStrings) ficam em memória.
     */
    private static function openXlsx($path, array $options)
    {
        $zip = self::openZip($path);
        $temps = [];

        try {
            $sheets = self::xlsxSheets($zip);
            $sheetName = self::pickSheet(array_keys($sheets), isset($options['sheet']) ? $options['sheet'] : 0);
            $strings = self::xlsxSharedStrings($zip, $temps);
            $sheetFile = self::extractEntry($zip, $sheets[$sheetName], $temps);
        } catch (Throwable $error) {
            $zip->close();
            self::removeTemps($temps);

            throw $error instanceof SheetToTableException ? $error : new SheetToTableException('Não foi possível ler a planilha. Verifique se o arquivo não está corrompido ou protegido por senha.', 0, $error);
        }

        $zip->close();
        $xml = new XMLReader();

        if (!$xml->open($sheetFile, null, LIBXML_NONET | LIBXML_COMPACT)) {
            self::removeTemps($temps);

            throw new SheetToTableException('Não foi possível ler a aba ' . $sheetName . '.');
        }

        $rows = (function () use ($xml, $strings) {
            $lastRow = 0;
            $values = null;
            $column = 0;
            $type = '';
            $cellValue = null;
            $inlineText = null;

            while ($xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT) {
                    switch ($xml->localName) {
                        case 'row':
                            $number = $xml->getAttribute('r');
                            $lastRow = $number !== null ? (int) $number : $lastRow + 1;
                            $values = [];
                            $column = 0;

                            if ($xml->isEmptyElement) {
                                yield [$lastRow, []];
                                $values = null;
                            }

                            break;

                        case 'c':
                            $ref = $xml->getAttribute('r');
                            $column = $ref !== null ? self::columnIndex($ref) : $column + 1;
                            $type = (string) $xml->getAttribute('t');
                            $cellValue = null;
                            $inlineText = null;

                            if ($xml->isEmptyElement && $values !== null) {
                                $values[$column - 1] = null;
                            }

                            break;

                        case 'v':
                            $cellValue = $xml->readString();
                            break;

                        case 't':
                            if ($type === 'inlineStr') {
                                $inlineText = ($inlineText === null ? '' : $inlineText) . $xml->readString();
                            }

                            break;
                    }

                    continue;
                }

                if ($xml->nodeType !== XMLReader::END_ELEMENT) {
                    continue;
                }

                if ($xml->localName === 'c' && $values !== null) {
                    $values[$column - 1] = self::xlsxValue($type, $cellValue, $inlineText, $strings);
                } elseif ($xml->localName === 'row' && $values !== null) {
                    if ($values) {
                        $max = max(array_keys($values));
                        $filled = array_fill(0, $max + 1, null);

                        foreach ($values as $index => $value) {
                            $filled[$index] = $value;
                        }

                        $values = $filled;
                    }

                    yield [$lastRow, $values];
                    $values = null;
                }
            }
        })();

        return [
            'rows' => $rows,
            'sheet' => $sheetName,
            'close' => function () use ($xml, $temps) {
                $xml->close();
                self::removeTemps($temps);
            },
        ];
    }

    private static function xlsxValue($type, $value, $inlineText, array $strings)
    {
        switch ($type) {
            case 's':
                return $value !== null && isset($strings[(int) $value]) ? $strings[(int) $value] : null;

            case 'inlineStr':
                return $inlineText;

            case 'str':
                return $value;

            case 'b':
                return $value === null ? null : $value === '1';

            case 'e':
                // Erro de fórmula (#N/A, #DIV/0!): vira texto para a validação apontar a célula.
                return $value;

            case 'd':
                return $value;

            default:
                if ($value === null || $value === '') {
                    return null;
                }

                if (preg_match('/^-?\d+$/', $value) && strlen(ltrim($value, '-')) < 16) {
                    return (int) $value;
                }

                return is_numeric($value) ? (float) $value : $value;
        }
    }

    private static function openZip($path)
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new SheetToTableException('O arquivo não é um XLSX válido.');
        }

        return $zip;
    }

    // [nome da aba => caminho do XML dentro do zip], na ordem do arquivo.
    private static function xlsxSheets(ZipArchive $zip)
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            throw new SheetToTableException('O arquivo não é um XLSX válido.');
        }

        $targets = [];
        $relsXml = self::loadXml($rels);

        foreach ($relsXml->children() as $relation) {
            $target = (string) $relation['Target'];
            $target = strpos($target, '/') === 0 ? ltrim($target, '/') : 'xl/' . $target;
            $targets[(string) $relation['Id']] = self::normalizeZipPath($target);
        }

        $sheets = [];
        $bookXml = self::loadXml($workbook);
        $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

        foreach ($bookXml->children()->sheets->children() as $sheet) {
            $attributes = $sheet->attributes($relNs);
            $id = (string) $attributes['id'];

            if (isset($targets[$id])) {
                $sheets[(string) $sheet['name']] = $targets[$id];
            }
        }

        if (!$sheets) {
            throw new SheetToTableException('A planilha não tem abas.');
        }

        return $sheets;
    }

    private static function xlsxSharedStrings(ZipArchive $zip, array &$temps)
    {
        if ($zip->locateName('xl/sharedStrings.xml') === false) {
            return [];
        }

        $file = self::extractEntry($zip, 'xl/sharedStrings.xml', $temps);
        $xml = new XMLReader();
        $xml->open($file, null, LIBXML_NONET | LIBXML_COMPACT);
        $strings = [];
        $current = null;

        while ($xml->read()) {
            if ($xml->nodeType === XMLReader::ELEMENT) {
                if ($xml->localName === 'si') {
                    if ($xml->isEmptyElement) {
                        $strings[] = '';
                        continue;
                    }

                    $current = '';
                } elseif ($xml->localName === 'rPh') {
                    // Guia fonético (japonês): não faz parte do texto.
                    $xml->next();
                } elseif ($xml->localName === 't' && $current !== null) {
                    $current .= $xml->readString();
                }
            } elseif ($xml->nodeType === XMLReader::END_ELEMENT && $xml->localName === 'si') {
                $strings[] = $current;
                $current = null;
            }
        }

        $xml->close();

        return $strings;
    }

    // Copia um item do zip para um temporário em fluxo (não carrega na memória).
    private static function extractEntry(ZipArchive $zip, $name, array &$temps)
    {
        $stream = $zip->getStream($name);

        if ($stream === false) {
            throw new SheetToTableException('O arquivo não é um XLSX válido (falta ' . $name . ').');
        }

        $temp = tempnam(sys_get_temp_dir(), 'stt');
        $out = fopen($temp, 'wb');
        stream_copy_to_stream($stream, $out);
        fclose($out);
        fclose($stream);
        $temps[] = $temp;

        return $temp;
    }

    private static function removeTemps(array $temps)
    {
        foreach ($temps as $temp) {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    private static function loadXml($content)
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            throw new SheetToTableException('O arquivo não é um XLSX válido.');
        }

        return $xml;
    }

    private static function normalizeZipPath($path)
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }

    // 'B12' -> 2; 'AA3' -> 27.
    private static function columnIndex($reference)
    {
        $index = 0;
        $length = strlen($reference);

        for ($i = 0; $i < $length; $i++) {
            $char = ord($reference[$i]);

            if ($char < 65 || $char > 90) {
                break;
            }

            $index = $index * 26 + ($char - 64);
        }

        return $index;
    }

    /* ---- XLS / ODS (phpspreadsheet) --------------------------------- */

    /*
     * XLS tem no máximo 65.536 linhas, então carregar na memória é
     * aceitável. Para ODS muito grandes, prefira salvar como XLSX ou CSV.
     */
    private static function openSpreadsheet(array $source, array $options)
    {
        $reader = self::spreadsheetReader($source);
        $reader->setReadDataOnly(true);

        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $sheetName = self::pickSheet($reader->listWorksheetNames($source['path']), isset($options['sheet']) ? $options['sheet'] : 0);

        if (method_exists($reader, 'setLoadSheetsOnly')) {
            $reader->setLoadSheetsOnly([$sheetName]);
        }

        try {
            $spreadsheet = $reader->load($source['path']);
        } catch (Throwable $error) {
            self::log('Falha ao abrir planilha.', ['erro' => $error->getMessage()], 'error');

            throw new SheetToTableException('Não foi possível ler a planilha. Verifique se o arquivo não está corrompido ou protegido por senha.', 0, $error);
        }

        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            $sheet = $spreadsheet->getActiveSheet();
        }

        $rows = (function () use ($sheet) {
            $lastRow = (int) $sheet->getHighestDataRow();
            $lastColumn = $sheet->getHighestDataColumn();

            for ($start = 1; $start <= $lastRow; $start += 1000) {
                $end = min($lastRow, $start + 999);
                $line = $start;

                foreach ($sheet->rangeToArray('A' . $start . ':' . $lastColumn . $end, null, true, false, false) as $values) {
                    yield [$line++, array_values($values)];
                }
            }
        })();

        return [
            'rows' => $rows,
            'sheet' => $sheetName,
            'close' => function () use ($spreadsheet) {
                $spreadsheet->disconnectWorksheets();
            },
        ];
    }

    private static function spreadsheetReader(array $source)
    {
        $types = ['xlsx' => 'Xlsx', 'xlsm' => 'Xlsx', 'xls' => 'Xls', 'ods' => 'Ods'];

        try {
            $type = \PhpOffice\PhpSpreadsheet\IOFactory::identify($source['path']);
        } catch (Throwable $error) {
            $type = isset($types[$source['extension']]) ? $types[$source['extension']] : 'Xlsx';
        }

        if ($type === 'Csv' || $type === 'Html') {
            throw new SheetToTableException('O arquivo tem extensão .' . $source['extension'] . ' mas não é uma planilha.');
        }

        return \PhpOffice\PhpSpreadsheet\IOFactory::createReader($type);
    }

    private static function pickSheet(array $names, $sheet)
    {
        if (!$names) {
            throw new SheetToTableException('A planilha não tem abas.');
        }

        if (is_int($sheet) || ctype_digit((string) $sheet)) {
            $index = (int) $sheet;

            if (!isset($names[$index])) {
                throw new SheetToTableException('A planilha não tem a aba ' . ($index + 1) . '.');
            }

            return $names[$index];
        }

        foreach ($names as $name) {
            if (mb_strtolower(trim($name)) === mb_strtolower(trim((string) $sheet))) {
                return $name;
            }
        }

        throw new SheetToTableException('Aba não encontrada: ' . $sheet . '. Abas: ' . implode(', ', $names) . '.');
    }

    /* =================================================================
     * Estrutura: interpretação das colunas do driver
     * ================================================================= */

    private static function describeColumn($key, array $info)
    {
        $lower = array_change_key_case($info, CASE_LOWER);
        $name = isset($lower['field']) ? $lower['field'] : (isset($lower['column_name']) ? $lower['column_name'] : (string) $key);
        $type = (string) (isset($lower['type']) ? $lower['type'] : (isset($lower['data_type']) ? $lower['data_type'] : ''));
        $null = strtoupper((string) (isset($lower['null']) ? $lower['null'] : (isset($lower['is_nullable']) ? $lower['is_nullable'] : 'YES')));
        $default = array_key_exists('default', $lower) ? $lower['default'] : (isset($lower['column_default']) ? $lower['column_default'] : null);
        $extra = strtolower((string) (isset($lower['extra']) ? $lower['extra'] : ''));

        $nullable = in_array($null, ['YES', 'Y', '1', 'TRUE'], true);
        $auto = strpos($extra, 'auto_increment') !== false
            || (is_string($default) && stripos($default, 'nextval(') === 0)
            || stripos($type, 'serial') !== false
            || (isset($lower['is_identity']) && strtoupper((string) $lower['is_identity']) === 'YES');
        $generated = strpos($extra, 'virtual generated') !== false || strpos($extra, 'stored generated') !== false;
        $hasDefault = $default !== null || strpos($extra, 'default_generated') !== false;

        return array_merge(self::parseType($type), [
            'name' => (string) $name,
            'nullable' => $nullable,
            'default' => $default,
            'has_default' => $hasDefault,
            'auto' => $auto,
            'generated' => $generated,
            'required_value' => !$nullable && !$hasDefault && !$auto && !$generated,
        ]);
    }

    /*
     * 'columns' manual: ['NOME', 'CPF'] ou ['NOME' => 'varchar(150)', 'NASC' => 'date'].
     */
    private static function columnsFromOption($columns)
    {
        $result = [];

        foreach ((array) $columns as $key => $value) {
            $name = is_int($key) ? (string) $value : (string) $key;

            $result[$name] = array_merge(self::parseType(is_int($key) ? 'text' : (string) $value), [
                'name' => $name,
                'nullable' => true,
                'default' => null,
                'has_default' => false,
                'auto' => false,
                'generated' => false,
                'required_value' => false,
            ]);
        }

        return $result;
    }

    private static function applyAutoOption(array $columns, array $options)
    {
        if (empty($options['auto'])) {
            return $columns;
        }

        $auto = self::upperList((array) $options['auto']);

        foreach ($columns as $name => $column) {
            if (in_array(strtoupper($name), $auto, true)) {
                $columns[$name]['auto'] = true;
                $columns[$name]['required_value'] = false;
            }
        }

        return $columns;
    }

    /*
     * Interpreta o tipo da coluna (MySQL/MariaDB e PostgreSQL).
     */
    private static function parseType($type)
    {
        $original = trim((string) $type);
        $type = strtolower($original);
        $info = [
            'type' => $type,
            'base' => 'text',
            'int_kind' => null,
            'length' => 0,
            'bytes' => 0,
            'unsigned' => strpos($type, 'unsigned') !== false,
            'precision' => 0,
            'scale' => 0,
            'enum' => [],
        ];

        if (preg_match('/^enum\s*\((.*)\)/is', $original, $matches)) {
            $info['base'] = 'enum';
            preg_match_all("/'((?:[^']|'')*)'/", $matches[1], $values);
            $info['enum'] = array_map(function ($value) {
                return str_replace("''", "'", $value);
            }, $values[1]);

            return $info;
        }

        if ($type === 'tinyint(1)' || preg_match('/^tinyint\(1\)\s/', $type)) {
            $info['base'] = 'int';
            $info['int_kind'] = 'tinyint';
            $info['length'] = 1;

            return $info;
        }

        if (in_array($type, ['boolean', 'bool', 'bit(1)', 'bit'], true)) {
            $info['base'] = 'bool';

            return $info;
        }

        if (preg_match('/^(tinyint|smallint|mediumint|int|integer|bigint|smallserial|serial|bigserial|int2|int4|int8)\b/', $type, $matches)) {
            $kinds = [
                'integer' => 'int', 'serial' => 'int', 'int4' => 'int',
                'smallserial' => 'smallint', 'int2' => 'smallint',
                'bigserial' => 'bigint', 'int8' => 'bigint',
            ];
            $info['base'] = 'int';
            $info['int_kind'] = isset($kinds[$matches[1]]) ? $kinds[$matches[1]] : $matches[1];

            return $info;
        }

        if (preg_match('/^year\b/', $type)) {
            $info['base'] = 'year';

            return $info;
        }

        if (preg_match('/^(decimal|numeric|dec|fixed)\s*(?:\((\d+)\s*(?:,\s*(\d+))?\))?/', $type, $matches)) {
            $info['base'] = 'decimal';
            $info['precision'] = isset($matches[2]) ? (int) $matches[2] : ($matches[1] === 'numeric' ? 0 : 10);
            $info['scale'] = isset($matches[3]) ? (int) $matches[3] : 0;

            return $info;
        }

        if (preg_match('/^(float|double|real|money|float4|float8)\b/', $type)) {
            $info['base'] = 'decimal';

            return $info;
        }

        if (preg_match('/^timestamp\b/', $type)) {
            // TIMESTAMP do MySQL vai de 1970 a 2038. No PostgreSQL o tipo vem como
            // "timestamp without/with time zone" e aceita qualquer data comum.
            $info['base'] = preg_match('/^timestamp(\(\d+\))?$/', $type) ? 'timestamp' : 'datetime';

            return $info;
        }

        if (preg_match('/^datetime\b/', $type)) {
            $info['base'] = 'datetime';

            return $info;
        }

        if (preg_match('/^date\b/', $type)) {
            $info['base'] = 'date';

            return $info;
        }

        if (preg_match('/^time\b/', $type)) {
            $info['base'] = 'time';

            return $info;
        }

        if (preg_match('/^jsonb?\b/', $type)) {
            $info['base'] = 'json';

            return $info;
        }

        if ($type === 'uuid') {
            $info['base'] = 'uuid';

            return $info;
        }

        if (preg_match('/^(?:var)?char(?:acter)?(?: varying)?\s*\((\d+)\)/', $type, $matches)) {
            $info['length'] = (int) $matches[1];

            return $info;
        }

        $textBytes = ['tinytext' => 255, 'text' => 65535, 'mediumtext' => 16777215, 'tinyblob' => 255, 'blob' => 65535, 'mediumblob' => 16777215];

        if (isset($textBytes[$type])) {
            $info['bytes'] = $textBytes[$type];
        }

        return $info;
    }

    /* =================================================================
     * Utilitários
     * ================================================================= */

    /*
     * 'normal': "Data Base", "data-base", " DATA_BASE " e "Dáta Base" viram DATA_BASE.
     * 'strict': só tira BOM/espaços das pontas e ignora maiúsculas.
     */
    private static function normalizeName($name, $mode = 'normal')
    {
        $name = trim(self::stripBom((string) $name));

        if ($mode === 'strict') {
            return mb_strtoupper($name, 'UTF-8');
        }

        $name = strtoupper(self::removeAccents($name));
        $name = preg_replace('/[^A-Z0-9]+/', '_', $name);

        return trim($name, '_');
    }

    private static function removeAccents($text)
    {
        return strtr($text, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N', 'º' => 'o', 'ª' => 'a',
        ]);
    }

    private static function stripBom($text)
    {
        return strncmp($text, "\xEF\xBB\xBF", 3) === 0 ? substr($text, 3) : $text;
    }

    /*
     * Para cada campo faltante, o cabeçalho sobrando mais parecido
     * (só sugere; nunca casa sozinho).
     */
    private static function suggest(array $missing, array $leftover)
    {
        $suggestions = [];

        foreach ($missing as $field) {
            $target = self::normalizeName($field, 'normal');
            $best = null;
            $bestScore = 0;

            foreach ($leftover as $original) {
                similar_text($target, self::normalizeName($original, 'normal'), $percent);

                if ($percent > $bestScore) {
                    $bestScore = $percent;
                    $best = $original;
                }
            }

            if ($best !== null && $bestScore >= 75) {
                $suggestions[$field] = $best;
            }
        }

        return $suggestions;
    }

    private static function headerMessage(array $missing, array $duplicated, array $extras, array $suggestions)
    {
        if (!$missing && !$duplicated && !$extras) {
            return 'Cabeçalho validado. Arquivo pronto para importação.';
        }

        $parts = [];

        if ($missing) {
            $labels = array_map(function ($field) use ($suggestions) {
                return isset($suggestions[$field]) ? $field . ' (no arquivo há "' . $suggestions[$field] . '")' : $field;
            }, $missing);

            $parts[] = (count($missing) === 1 ? 'Falta a seguinte coluna no arquivo: ' : 'Faltam as seguintes colunas no arquivo: ') . implode(', ', $labels) . '.';
        }

        if ($duplicated) {
            $parts[] = 'Colunas repetidas no arquivo: ' . implode(', ', $duplicated) . '.';
        }

        if ($extras) {
            $parts[] = 'Colunas que não existem na tabela: ' . implode(', ', $extras) . '.';
        }

        return 'Importação cancelada. ' . implode(' ', $parts);
    }

    private static function rowError($line, $field, $message, $value)
    {
        return [
            'linha' => (int) $line,
            'campo' => $field,
            'mensagem' => (string) $message,
            'valor' => $value === null ? null : self::show($value),
        ];
    }

    private static function show($value)
    {
        $text = self::toText($value);

        return self::length($text) > 60 ? mb_substr($text, 0, 57) . '...' : $text;
    }

    private static function isEmptyRow(array $values)
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '' && (!is_string($value) || trim($value) !== '')) {
                return false;
            }
        }

        return true;
    }

    private static function upperList(array $values)
    {
        return array_values(array_map(function ($value) {
            return strtoupper(trim((string) $value));
        }, $values));
    }

    // Nomes reais (com a caixa do banco) de campos informados nas opções.
    private static function realFields(array $columns, array $fields, $option)
    {
        $byUpper = [];

        foreach ($columns as $name => $column) {
            $byUpper[strtoupper($name)] = $name;
        }

        $real = [];

        foreach ($fields as $field) {
            if (!isset($byUpper[strtoupper((string) $field)])) {
                throw new SheetToTableException('Campo inexistente na tabela em "' . $option . '": ' . $field . '.');
            }

            $real[] = $byUpper[strtoupper((string) $field)];
        }

        return $real;
    }

    private static function modeOption(array $options)
    {
        $mode = isset($options['mode']) ? strtolower(trim((string) $options['mode'])) : self::MODE_APPEND;

        if (!isset(self::$modeAliases[$mode])) {
            throw new SheetToTableException('Modo de importação inválido: "' . $mode . '". Use append, replace, upsert ou insert_new.');
        }

        return self::$modeAliases[$mode];
    }

    private static function onErrorMode(array $options)
    {
        $value = isset($options['on_error']) ? strtolower(trim((string) $options['on_error'])) : self::ON_ERROR_ROLLBACK;

        if ($value === 'abort') {
            $value = self::ON_ERROR_ROLLBACK;
        }

        if (!in_array($value, [self::ON_ERROR_ROLLBACK, self::ON_ERROR_SKIP], true)) {
            throw new SheetToTableException('on_error deve ser "rollback" ou "skip".');
        }

        return $value;
    }

    /*
     * Arquivos grandes: sem limite de tempo por padrão ('time_limit' => 0)
     * e a importação continua mesmo se o navegador fechar.
     */
    private static function prepareRuntime(array $options)
    {
        $limit = array_key_exists('time_limit', $options) ? (int) $options['time_limit'] : 0;

        if ($limit >= 0 && function_exists('set_time_limit')) {
            @set_time_limit($limit);
        }

        if ((!array_key_exists('ignore_user_abort', $options) || $options['ignore_user_abort']) && function_exists('ignore_user_abort')) {
            @ignore_user_abort(true);
        }
    }

    private static function length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
    }

    private static function validTable($table)
    {
        $table = trim((string) $table);

        if ($table === '' || !preg_match('/^[A-Za-z0-9_#$.]+$/', $table)) {
            throw new SheetToTableException('Nome de tabela inválido.');
        }

        return $table;
    }

    /*
     * Acesso ao banco pelo ORM do pacote: 'connection' aceita null (Joomla),
     * nome registrado no DbConnectionHelper ou um driver pronto.
     */
    private static function orm($table, array $options)
    {
        return OrmTables::table($table, [
            'connection' => isset($options['connection']) ? $options['connection'] : null,
            'timestamps' => false,
        ]);
    }

    private static function isPostgres($db)
    {
        return DbConnectionHelper::serverType($db) === DbConnectionHelper::POSTGRESQL;
    }

    private static function log($message, array $context = [], $level = 'warning')
    {
        try {
            if (class_exists('LogHelper') && method_exists('LogHelper', $level)) {
                LogHelper::$level($message, self::$logCategory, $context);
            }
        } catch (Throwable $error) {
        }
    }
}
