<?php

defined('_JEXEC') or die;

jimport('joomla.filesystem.file');
jimport('joomla.filesystem.folder');

/*
 * Erro com mensagem segura para mostrar ao usuário ("O CSV está vazio",
 * "Coluna obrigatória não encontrada: cpf"). Outros erros (do PHP, do
 * banco) têm a mensagem registrada só no log.
 */
class CsvHelperException extends RuntimeException
{
}

/*
 * Leitura e importação de qualquer CSV.
 *
 * O helper descobre sozinho, em cada arquivo:
 * - a codificação (UTF-8, UTF-8 com BOM, UTF-16, Windows-1252/Excel);
 * - o delimitador (; , tab |), inclusive a linha "sep=;" do Excel;
 * - em que linha está o cabeçalho, ignorando títulos e linhas em branco
 *   acima dele, ou que o arquivo não tem cabeçalho;
 * - nomes de coluna prontos para o banco ("Descrição" → descricao);
 * - o tipo de cada coluna (inteiro, decimal, data, data e hora,
 *   booleano, texto), com o formato de cada uma ("1.234,56" ou
 *   "1,234.56"; DD/MM ou MM/DD).
 *
 * Fluxo típico:
 *   CsvHelper::analyze()  → mostrar ao usuário o que foi entendido
 *   CsvHelper::preview()  → primeiras linhas já convertidas
 *   CsvHelper::import()   → gravar numa tabela (ou process() para
 *                           tratar os lotes no seu próprio código)
 */
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

    // Linhas lidas para descobrir delimitador e cabeçalho.
    private static $sniffRecords = 40;

    // Linhas de dados usadas para descobrir o tipo de cada coluna.
    private static $sampleRows = 300;

    private static $delimiterCandidates = array(
        ';',
        ',',
        "\t",
        '|'
    );

    private static $allowedExtensions = array(
        'csv',
        'txt',
        'tsv'
    );

    private static $allowedMimes = array(
        'text/plain',
        'text/csv',
        'application/csv',
        'application/vnd.ms-excel',
        'application/octet-stream'
    );

    private static $trueWords = array('1', 'sim', 's', 'true', 'yes', 'y', 'verdadeiro', 'v', 'x');

    private static $falseWords = array('0', 'nao', 'não', 'n', 'false', 'no', 'falso', 'f');

    private static $boolWords = array('sim', 'não', 'nao', 'true', 'false', 'yes', 'no', 's', 'n', 'verdadeiro', 'falso');

    /* =================================================================
     * Log e resultado
     * ================================================================= */

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

    /*
     * Mensagem de falha para o usuário: o motivo, quando ele é seguro
     * (CsvHelperException e erros de parâmetro); senão, só o texto
     * genérico. O detalhe sempre vai para o log.
     */
    private static function failure($message, Throwable $error, array $data = array(), array $errors = array())
    {
        if ($error instanceof CsvHelperException || $error instanceof InvalidArgumentException) {
            $message .= ' ' . $error->getMessage();
        }

        return self::result(false, $message, $data, $errors);
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

    /* =================================================================
     * Caminhos (o arquivo precisa estar dentro do diretório base)
     * ================================================================= */

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

    /*
     * O arquivo temporário de um upload do PHP ($_FILES[...]['tmp_name'])
     * fica fora do site, mas pode ser lido direto: is_uploaded_file()
     * garante que veio de um upload desta requisição.
     */
    private static function isUploadedTempFile($path)
    {
        return is_string($path)
            && $path !== ''
            && function_exists('is_uploaded_file')
            && is_uploaded_file($path);
    }

    private static function resolveFilePath($path, $baseDirectory = null, $mustExist = true)
    {
        if ($mustExist && self::isUploadedTempFile($path)) {
            return self::normalizePath($path);
        }

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
            throw new CsvHelperException(
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
            throw new CsvHelperException(
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
            throw new CsvHelperException(
                'Arquivo CSV fora da base autorizada.'
            );
        }

        return $realPath;
    }

    private static function relativePath($filePath, $baseDirectory = null)
    {
        $baseDirectory = self::resolveBaseDirectory($baseDirectory);
        $filePath = self::normalizePath($filePath);

        if (!self::pathInsideBase($filePath, $baseDirectory)) {
            return basename($filePath);
        }

        return ltrim(
            substr($filePath, strlen($baseDirectory)),
            '/'
        );
    }

    /* =================================================================
     * Validação do arquivo
     * ================================================================= */

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

        // Desde o PHP 8.1 o finfo é um objeto liberado sozinho.
        if (PHP_VERSION_ID < 80100) {
            finfo_close($finfo);
        }

        if ($mime === false || trim((string) $mime) === '') {
            return 'application/octet-stream';
        }

        return strtolower(trim((string) $mime));
    }

    private static function validateCsvFile($filePath, array $options = array())
    {
        if (!is_file($filePath)) {
            throw new CsvHelperException(
                'Arquivo CSV não encontrado.'
            );
        }

        $size = filesize($filePath);

        if ($size === false || $size <= 0) {
            throw new CsvHelperException(
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
            throw new CsvHelperException(
                'O arquivo CSV excede o tamanho máximo permitido.'
            );
        }

        // Upload temporário não tem extensão: vale o nome original informado.
        $originalName = isset($options['original_name'])
            ? (string) $options['original_name']
            : basename($filePath);

        $extension = strtolower(
            pathinfo($originalName, PATHINFO_EXTENSION)
        );

        $allowedExtensions = array_map(
            function ($value) {
                return strtolower(trim((string) $value));
            },
            isset($options['allowed_extensions'])
                ? (array) $options['allowed_extensions']
                : self::$allowedExtensions
        );

        if (
            !self::isUploadedTempFile($filePath) || isset($options['original_name'])
        ) {
            if (!in_array($extension, $allowedExtensions, true)) {
                throw new CsvHelperException(
                    'A extensão do arquivo não é permitida.'
                );
            }
        }

        $mime = self::getMimeType($filePath);

        $allowedMimes = array_map(
            function ($value) {
                return strtolower(trim((string) $value));
            },
            isset($options['allowed_mimes'])
                ? (array) $options['allowed_mimes']
                : self::$allowedMimes
        );

        /*
         * O finfo classifica CSVs de formas variadas (text/x-csv,
         * text/x-Algol68...). Com a lista padrão, qualquer text/* é
         * aceito; arquivos binários (imagens, zip, executáveis) não.
         */
        $mimeAllowed = in_array($mime, $allowedMimes, true)
            || (!isset($options['allowed_mimes']) && strpos($mime, 'text/') === 0);

        if (!$mimeAllowed) {
            throw new CsvHelperException(
                'O tipo do arquivo não é permitido.'
            );
        }

        // Bytes nulos só aparecem em texto UTF-16; fora dele, é binário.
        $sample = (string) @file_get_contents($filePath, false, null, 0, 8192);

        if (strpos($sample, "\0") !== false && !self::isUtf16(self::detectFileEncoding($filePath))) {
            throw new CsvHelperException(
                'O arquivo não parece ser um CSV de texto.'
            );
        }

        return array(
            'path' => $filePath,
            'file_name' => $originalName,
            'extension' => $extension,
            'mime' => $mime,
            'size' => (int) $size
        );
    }

    /* =================================================================
     * Codificação
     * ================================================================= */

    private static function removeUtf8Bom($value)
    {
        if (substr($value, 0, 3) === "\xEF\xBB\xBF") {
            return substr($value, 3);
        }

        return $value;
    }

    /*
     * BOM → UTF-8 / UTF-16. Sem BOM: muitos bytes nulos alternados
     * indicam UTF-16; texto que é UTF-8 válido é UTF-8; o resto é
     * tratado como Windows-1252, o padrão do Excel no Brasil (e que
     * inclui o ISO-8859-1).
     */
    private static function detectFileEncoding($filePath)
    {
        $sample = @file_get_contents($filePath, false, null, 0, 65536);

        if ($sample === false || $sample === '') {
            return 'UTF-8';
        }

        if (strncmp($sample, "\xEF\xBB\xBF", 3) === 0) {
            return 'UTF-8';
        }

        if (strncmp($sample, "\xFF\xFE", 2) === 0) {
            return 'UTF-16LE';
        }

        if (strncmp($sample, "\xFE\xFF", 2) === 0) {
            return 'UTF-16BE';
        }

        $length = min(strlen($sample), 4000);
        $zerosEven = 0;
        $zerosOdd = 0;

        for ($index = 0; $index < $length; $index++) {
            if ($sample[$index] === "\0") {
                if ($index % 2) {
                    $zerosOdd++;
                } else {
                    $zerosEven++;
                }
            }
        }

        if ($zerosOdd > $length * 0.3) {
            return 'UTF-16LE';
        }

        if ($zerosEven > $length * 0.3) {
            return 'UTF-16BE';
        }

        // A amostra pode cortar um caractere de vários bytes no fim.
        if (strlen($sample) === 65536) {
            $lastNewLine = strrpos($sample, "\n");

            if ($lastNewLine !== false) {
                $sample = substr($sample, 0, $lastNewLine);
            }
        }

        if (preg_match('//u', $sample)) {
            return 'UTF-8';
        }

        return 'Windows-1252';
    }

    private static function normalizeEncodingName($encoding)
    {
        $key = strtoupper(str_replace(array('_', ' '), '-', trim((string) $encoding)));

        $aliases = array(
            'UTF8' => 'UTF-8',
            'UTF-8-SIG' => 'UTF-8',
            'UTF-16' => 'UTF-16LE',
            'UCS-2' => 'UTF-16LE',
            'UCS-2LE' => 'UTF-16LE',
            'CP1252' => 'Windows-1252',
            'WINDOWS-1252' => 'Windows-1252',
            'ANSI' => 'Windows-1252',
            'LATIN1' => 'ISO-8859-1',
            'LATIN-1' => 'ISO-8859-1'
        );

        return isset($aliases[$key]) ? $aliases[$key] : ($key === '' ? 'UTF-8' : $key);
    }

    private static function isUtf16($encoding)
    {
        return $encoding === 'UTF-16LE' || $encoding === 'UTF-16BE';
    }

    private static function convertToUtf8($value, $sourceEncoding)
    {
        $value = (string) $value;

        if (
            $value === '' ||
            $sourceEncoding === 'UTF-8' ||
            self::isUtf16($sourceEncoding)
        ) {
            return $value;
        }

        // Só bytes ASCII: nada a converter.
        if (!preg_match('/[\x80-\xFF]/', $value)) {
            return $value;
        }

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $sourceEncoding);

            if ($converted !== false && $converted !== null) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($sourceEncoding, 'UTF-8//IGNORE', $value);

            if ($converted !== false) {
                return $converted;
            }
        }

        return $value;
    }

    /*
     * Caminho de leitura do arquivo já em UTF-8 no nível dos bytes.
     * UTF-16 precisa ser convertido antes do parser de CSV, que só
     * entende delimitadores de um byte: com o filtro convert.iconv a
     * conversão é feita durante a leitura; sem ele, numa cópia
     * temporária.
     */
    private static function openSource($filePath, $encoding)
    {
        if (!self::isUtf16($encoding)) {
            return array('path' => $filePath, 'temp' => null);
        }

        if (in_array('convert.iconv.*', stream_get_filters(), true)) {
            return array(
                'path' => 'php://filter/read=convert.iconv.' . $encoding . '.UTF-8/resource=' . $filePath,
                'temp' => null
            );
        }

        return self::convertedCopy($filePath, $encoding);
    }

    private static function convertedCopy($filePath, $encoding)
    {
        $content = file_get_contents($filePath);

        if ($content === false || !function_exists('mb_convert_encoding')) {
            throw new CsvHelperException(
                'Não foi possível converter o arquivo ' . $encoding . ' para UTF-8.'
            );
        }

        $temp = tempnam(sys_get_temp_dir(), 'csv');

        if ($temp === false || file_put_contents($temp, mb_convert_encoding($content, 'UTF-8', $encoding)) === false) {
            throw new RuntimeException(
                'Não foi possível criar a cópia temporária do CSV.'
            );
        }

        return array('path' => $temp, 'temp' => $temp);
    }

    /* =================================================================
     * Texto: nomes de coluna
     * ================================================================= */

    private static function removeAccents($value)
    {
        $value = (string) $value;

        // Tabela própria primeiro: o resultado não depende do sistema.
        $map = array(
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o', 'ø' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe',
            'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Ø' => 'O',
            'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y', 'Æ' => 'AE', 'Œ' => 'OE',
            'º' => 'o', 'ª' => 'a', '°' => 'o'
        );

        $value = strtr($value, $map);

        if (preg_match('/[^\x00-\x7F]/', $value) && class_exists('Normalizer')) {
            $normalized = Normalizer::normalize($value, Normalizer::FORM_D);

            if ($normalized !== false && $normalized !== null) {
                $value = preg_replace('/\p{Mn}+/u', '', $normalized);
            }
        }

        return $value;
    }

    /*
     * "Descrição do Produto" → "descricao_do_produto"
     */
    private static function normalizeHeader($header)
    {
        $header = self::removeUtf8Bom(
            trim((string) $header)
        );

        $header = self::removeAccents($header);

        $header = function_exists('mb_strtolower')
            ? mb_strtolower($header, 'UTF-8')
            : strtolower($header);

        $header = preg_replace('/[^a-z0-9]+/', '_', $header);

        return trim($header, '_');
    }

    /*
     * Nome válido de coluna de banco: só [a-z0-9_], começando por
     * letra, com até 60 caracteres (o limite do MySQL é 64 e o do
     * PostgreSQL, 63).
     */
    private static function columnName($header, $position)
    {
        $name = self::normalizeHeader($header);

        if ($name === '') {
            return 'coluna_' . ($position + 1);
        }

        if (ctype_digit($name[0])) {
            $name = 'c_' . $name;
        }

        return rtrim(substr($name, 0, 60), '_');
    }

    private static function uniqueNames(array $names)
    {
        $used = array();
        $result = array();

        foreach ($names as $name) {
            $candidate = $name;
            $suffix = 2;

            while (isset($used[$candidate])) {
                $candidate = substr($name, 0, 56) . '_' . $suffix;
                $suffix++;
            }

            $used[$candidate] = true;
            $result[] = $candidate;
        }

        return $result;
    }

    /* =================================================================
     * Células
     * ================================================================= */

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

    /*
     * Converte a célula para UTF-8 e limpa: espaços (inclusive o
     * espaço não separável), BOM e o formato ="00123" que o Excel usa
     * para preservar zeros à esquerda.
     */
    private static function cleanCell($value, $encoding, $removeBom = false)
    {
        if ($value === null || is_array($value)) {
            return '';
        }

        $value = self::convertToUtf8((string) $value, $encoding);

        if ($removeBom) {
            $value = self::removeUtf8Bom($value);
        }

        $trimmed = preg_replace('/^[\s\x{00A0}\x{FEFF}]+|[\s\x{00A0}]+$/u', '', $value);
        $value = $trimmed === null ? trim($value) : $trimmed;

        if (strlen($value) > 3 && $value[0] === '=' && $value[1] === '"' && substr($value, -1) === '"') {
            $value = str_replace('""', '"', substr($value, 2, -1));
        }

        return $value;
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

    /* =================================================================
     * Dialeto: delimitador, aspas, escape
     * ================================================================= */

    private static function validateDelimiter($delimiter)
    {
        $delimiter = (string) $delimiter;

        if ($delimiter === '\t' || strtolower($delimiter) === 'tab') {
            $delimiter = "\t";
        }

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

        if ($escape === '' && PHP_VERSION_ID < 70400) {
            return '\\';
        }

        return $escape;
    }

    /*
     * Escape vazio = padrão RFC 4180 (aspas dobradas ""). O PHP só o
     * aceita a partir da 7.4; antes, usa a barra invertida.
     */
    private static function defaultEscape()
    {
        return PHP_VERSION_ID >= 70400 ? '' : '\\';
    }

    /*
     * Primeiras linhas físicas não vazias, já em UTF-8 no nível dos
     * bytes (o delimitador é ASCII, então Windows-1252 não atrapalha).
     */
    private static function readRawLines($filePath, $encoding, $limit)
    {
        $source = self::openSource($filePath, $encoding);
        $lines = array();

        try {
            $handle = fopen($source['path'], 'rb');

            if ($handle === false) {
                throw new RuntimeException('Não foi possível abrir o CSV.');
            }

            $first = true;

            while (count($lines) < $limit && ($line = fgets($handle)) !== false) {
                if ($first) {
                    $line = self::removeUtf8Bom($line);
                    $first = false;
                }

                $line = rtrim($line, "\r\n");

                if (trim($line) !== '') {
                    $lines[] = $line;
                }
            }

            fclose($handle);
        } finally {
            if ($source['temp'] !== null) {
                @unlink($source['temp']);
            }
        }

        return $lines;
    }

    /*
     * Linha "sep=;" que o Excel grava para indicar o delimitador.
     */
    private static function sepDirective(array $lines)
    {
        if (!empty($lines) && preg_match('/^\s*sep=(.)\s*$/i', $lines[0], $matches)) {
            return $matches[1];
        }

        return null;
    }

    /*
     * O melhor delimitador é o que divide a maioria das linhas no mesmo
     * número de colunas (maior que 1). Títulos e totais com outra
     * quantidade de colunas não atrapalham, e um ";" que separa colunas
     * vence a vírgula decimal de "10,5".
     */
    private static function sniffDelimiter(array $lines, array $candidates, $enclosure, $escape)
    {
        $best = null;
        $bestScore = -1;

        foreach ($candidates as $candidate) {
            $candidate = self::validateDelimiter($candidate);
            $frequency = array();

            foreach ($lines as $line) {
                $count = count(str_getcsv($line, $candidate, $enclosure, $escape));

                if ($count > 1) {
                    $frequency[$count] = isset($frequency[$count]) ? $frequency[$count] + 1 : 1;
                }
            }

            if (empty($frequency)) {
                continue;
            }

            arsort($frequency);
            $modeCount = (int) key($frequency);
            $score = reset($frequency) * 1000 + min($modeCount, 999);

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    /*
     * Lê os registros do CSV (um registro pode ocupar várias linhas
     * físicas, quando um campo entre aspas tem quebra de linha) e devolve
     * array('line' => linha física onde o registro começa, 'cells' => ...).
     * Linhas em branco são puladas, mas contadas.
     */
    private static function records($filePath, array $dialect)
    {
        $source = self::openSource($filePath, $dialect['encoding']);

        try {
            $csv = new SplFileObject($source['path'], 'r');
            $csv->setCsvControl($dialect['delimiter'], $dialect['enclosure'], $dialect['escape']);

            $line = 1;
            $first = true;

            while (!$csv->eof()) {
                $row = $csv->fgetcsv($dialect['delimiter'], $dialect['enclosure'], $dialect['escape']);

                if ($row === false || $row === null) {
                    break;
                }

                $startLine = $line;
                $physicalLines = 1;

                foreach ($row as $cell) {
                    if (is_string($cell)) {
                        $physicalLines += substr_count($cell, "\n");
                    }
                }

                $line += $physicalLines;

                if ($row === array(null)) {
                    continue;
                }

                $cells = array();

                foreach ($row as $index => $cell) {
                    $cells[] = self::cleanCell($cell, $dialect['encoding'], $first && $index === 0);
                }

                $first = false;

                if (!empty($dialect['skip_sep_line']) && $startLine === 1) {
                    continue;
                }

                if (self::isEmptyRow($cells)) {
                    continue;
                }

                yield array(
                    'line' => $startLine,
                    'cells' => $cells
                );
            }
        } finally {
            if ($source['temp'] !== null) {
                @unlink($source['temp']);
            }
        }
    }

    /* =================================================================
     * Tipos de valor
     * ================================================================= */

    private static function lower($value)
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    private static function stripCurrency($value)
    {
        $stripped = preg_replace('/^\s*(R\$|US\$|\$|€)\s*|\s+/u', '', $value);

        return $stripped === null ? $value : $stripped;
    }

    private static function isBrazilianNumber($value)
    {
        return (bool) preg_match('/^[+-]?(\d{1,3}(\.\d{3})+|\d+)(,\d+)?$/', $value);
    }

    private static function isEnglishNumber($value)
    {
        return (bool) preg_match('/^[+-]?(\d{1,3}(,\d{3})+|\d+)(\.\d+)?$/', $value);
    }

    /*
     * Partes de uma data (sem validar): array(dia/mês ambíguos, ano,
     * hora) ou null.
     */
    private static function dateParts($value)
    {
        $value = trim($value);

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?(?:\.\d+)?(?:Z|[+-]\d{2}:?\d{2})?)?$/', $value, $m)) {
            return array(
                'order' => 'ymd',
                'a' => (int) $m[2],
                'b' => (int) $m[3],
                'year' => (int) $m[1],
                'separator' => '-',
                'time' => isset($m[4]) && $m[4] !== '' ? array((int) $m[4], (int) $m[5], isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0) : null
            );
        }

        if (preg_match('#^(\d{1,2})([/.-])(\d{1,2})\2(\d{4}|\d{2})(?:[ T](\d{1,2}):(\d{2})(?::(\d{2}))?)?$#', $value, $m)) {
            $year = (int) $m[4];

            if (strlen($m[4]) === 2) {
                $year += $year < 70 ? 2000 : 1900;
            }

            return array(
                'order' => 'xxy',
                'a' => (int) $m[1],
                'b' => (int) $m[3],
                'year' => $year,
                'separator' => $m[2],
                'time' => isset($m[5]) && $m[5] !== '' ? array((int) $m[5], (int) $m[6], isset($m[7]) && $m[7] !== '' ? (int) $m[7] : 0) : null
            );
        }

        return null;
    }

    private static function validTime($time)
    {
        return $time === null || ($time[0] <= 23 && $time[1] <= 59 && $time[2] <= 59);
    }

    /*
     * Classe de um valor: empty, bool, int, code (dígitos com zero à
     * esquerda ou máscara: CPF, CEP, telefone), number, date, datetime
     * ou text.
     */
    private static function classifyValue($value)
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 'empty';
        }

        if (in_array(self::lower($value), self::$boolWords, true)) {
            return 'bool';
        }

        if (preg_match('/^[+-]?\d+$/', $value)) {
            $digits = ltrim($value, '+-');

            return strlen($digits) > 1 && $digits[0] === '0' ? 'code' : 'int';
        }

        $number = self::stripCurrency($value);

        if (self::isBrazilianNumber($number) || self::isEnglishNumber($number)) {
            return 'number';
        }

        $date = self::dateParts($value);

        if ($date !== null) {
            $validDayMonth = $date['order'] === 'ymd'
                ? checkdate($date['a'], $date['b'], $date['year'])
                : (checkdate($date['b'], $date['a'], $date['year']) || checkdate($date['a'], $date['b'], $date['year']));

            if ($validDayMonth && self::validTime($date['time'])) {
                return $date['time'] === null ? 'date' : 'datetime';
            }
        }

        if (preg_match('/^[\d.\-\/() ]+$/', $value) && preg_match_all('/\d/', $value) >= 8) {
            return 'code';
        }

        return 'text';
    }

    /*
     * Parece um nome de coluna: texto curto, com letras, sem cara de
     * e-mail, URL ou número.
     */
    private static function looksLikeLabel($value)
    {
        if (self::classifyValue($value) !== 'text') {
            return false;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);

        if ($length > 60 || strpos($value, '@') !== false || preg_match('#^https?://#i', $value)) {
            return false;
        }

        if (!preg_match('/\p{L}/u', $value)) {
            return false;
        }

        return preg_match_all('/\d/', $value) <= $length / 2;
    }

    /* =================================================================
     * Cabeçalho
     * ================================================================= */

    private static function rowWidth(array $cells)
    {
        for ($index = count($cells) - 1; $index >= 0; $index--) {
            if ($cells[$index] !== '') {
                return $index + 1;
            }
        }

        return 0;
    }

    private static function filledCount(array $cells)
    {
        $count = 0;

        foreach ($cells as $cell) {
            if ($cell !== '') {
                $count++;
            }
        }

        return $count;
    }

    /*
     * Largura típica da tabela: a quantidade de colunas mais comum entre
     * os registros com mais de uma coluna preenchida.
     */
    private static function tableWidth(array $records)
    {
        $frequency = array();

        foreach ($records as $record) {
            $width = self::rowWidth($record['cells']);

            if ($width > 1) {
                $frequency[$width] = isset($frequency[$width]) ? $frequency[$width] + 1 : 1;
            }
        }

        if (empty($frequency)) {
            return 1;
        }

        arsort($frequency);
        $top = reset($frequency);
        $widths = array();

        foreach ($frequency as $width => $count) {
            if ($count === $top) {
                $widths[] = $width;
            }
        }

        return max($widths);
    }

    /*
     * Índice do registro que é o cabeçalho, ou -1 se não houver.
     *
     * Cada um dos primeiros registros recebe pontos por:
     * - preencher a largura da tabela (títulos têm 1 ou 2 células);
     * - ter células com cara de rótulo (texto curto, sem números);
     * - não repetir valores;
     * - ter registros abaixo com a mesma largura;
     * - contrastar com os dados abaixo: rótulo em cima, número ou data
     *   embaixo.
     * O maior placar vence (o primeiro, em caso de empate), desde que a
     * maioria das células pareça rótulo.
     */
    private static function detectHeaderIndex(array $records, $width)
    {
        $bestIndex = -1;
        $bestScore = 0.0;
        $limit = min(count($records), 15);

        for ($index = 0; $index < $limit; $index++) {
            $cells = array_slice($records[$index]['cells'], 0, max($width, 1));
            $filled = self::filledCount($cells);

            if ($filled === 0 || $filled < max(1, (int) ceil($width * 0.6))) {
                continue;
            }

            $labels = 0;
            $values = array();
            $tooLong = false;

            foreach ($cells as $cell) {
                if ($cell === '') {
                    continue;
                }

                if (self::looksLikeLabel($cell)) {
                    $labels++;
                }

                if (strlen($cell) > 80) {
                    $tooLong = true;
                }

                $values[self::normalizeHeader($cell)] = true;
            }

            $labelRatio = $labels / $filled;

            if ($labelRatio < 0.6) {
                continue;
            }

            $following = array_slice($records, $index + 1, 10);

            if (empty($following)) {
                continue;
            }

            $consistent = 0;
            $contrast = 0;

            foreach ($following as $record) {
                if (self::filledCount($record['cells']) >= max(1, (int) ceil($width * 0.5))) {
                    $consistent++;
                }
            }

            foreach ($cells as $column => $cell) {
                if ($cell === '' || !self::looksLikeLabel($cell)) {
                    continue;
                }

                $typed = 0;
                $present = 0;

                foreach ($following as $record) {
                    $below = isset($record['cells'][$column]) ? $record['cells'][$column] : '';

                    if ($below === '') {
                        continue;
                    }

                    $present++;

                    if (self::classifyValue($below) !== 'text') {
                        $typed++;
                    }
                }

                if ($present > 0 && $typed / $present >= 0.5) {
                    $contrast++;
                }
            }

            $score = 2 * min(1, $filled / max($width, 1))
                + 3 * $labelRatio
                + count($values) / $filled
                + 2 * ($consistent / count($following))
                + 2 * ($contrast / $filled)
                - ($tooLong ? 1 : 0);

            if ($score > $bestScore + 0.0001) {
                $bestScore = $score;
                $bestIndex = $index;
            }
        }

        return $bestScore >= 5.0 ? $bestIndex : -1;
    }

    /* =================================================================
     * Tipo de cada coluna
     * ================================================================= */

    /*
     * Separador decimal de uma coluna inteira. Valores que só cabem em
     * um formato decidem ("1.234,56", "10,5" → vírgula; "1,234.56",
     * "10.25" → ponto). Se todos forem ambíguos ("1.500"), vale o padrão
     * do arquivo: ";" indica Excel brasileiro (vírgula decimal).
     */
    private static function decimalSeparator(array $values, $delimiter)
    {
        $brazilian = 0;
        $english = 0;

        foreach ($values as $value) {
            $value = self::stripCurrency($value);
            $isBr = self::isBrazilianNumber($value);
            $isEn = self::isEnglishNumber($value);

            if ($isBr && !$isEn) {
                $brazilian++;
            } elseif ($isEn && !$isBr) {
                $english++;
            }
        }

        if ($brazilian > $english) {
            return ',';
        }

        if ($english > $brazilian) {
            return '.';
        }

        return $delimiter === ';' ? ',' : '.';
    }

    /*
     * Ordem de dia e mês de uma coluna de datas com barra/ponto/hífen:
     * um valor com primeira parte > 12 prova DD/MM; com segunda parte > 12
     * prova MM/DD. Sem prova, DD/MM (padrão brasileiro).
     */
    private static function dateOrder(array $values)
    {
        $dayFirst = false;
        $monthFirst = false;

        foreach ($values as $value) {
            $parts = self::dateParts($value);

            if ($parts === null || $parts['order'] === 'ymd') {
                continue;
            }

            if ($parts['a'] > 12) {
                $dayFirst = true;
            }

            if ($parts['b'] > 12) {
                $monthFirst = true;
            }
        }

        if ($monthFirst && !$dayFirst) {
            return 'mdy';
        }

        return 'dmy';
    }

    private static function inferColumn(array $values, $delimiter)
    {
        $filled = array();
        $classes = array();

        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }

            $filled[] = $value;
            $class = self::classifyValue($value);
            $classes[$class] = true;
        }

        $spec = array(
            'type' => 'string',
            'decimal_separator' => null,
            'date_order' => null,
            'filled' => count($filled),
            'empty' => count($values) - count($filled),
            'suggested_type' => null,
            'outliers' => array()
        );

        if (empty($filled)) {
            return $spec;
        }

        $strict = self::inferStrict($filled, array_keys($classes), $delimiter, $spec);

        if ($strict['type'] !== 'string' || count($filled) < 5) {
            return $strict;
        }

        /*
         * Coluna de texto em que 80% ou mais dos valores têm um tipo:
         * provavelmente é desse tipo, com alguns valores errados. Fica
         * como sugestão, com exemplos dos valores fora do padrão.
         */
        $families = array(
            'int' => array('int'),
            'decimal' => array('int', 'number'),
            'date' => array('date'),
            'datetime' => array('date', 'datetime'),
            'bool' => array('bool')
        );

        $classified = array();

        foreach ($filled as $value) {
            $classified[] = array($value, self::classifyValue($value));
        }

        $best = null;
        $bestCoverage = 0.0;

        foreach ($families as $family => $allowed) {
            $inFamily = 0;

            foreach ($classified as $item) {
                if (in_array($item[1], $allowed, true)) {
                    $inFamily++;
                }
            }

            $coverage = $inFamily / count($classified);

            if ($coverage >= 0.8 && $coverage > $bestCoverage + 0.0001) {
                $best = $family;
                $bestCoverage = $coverage;
            }
        }

        if ($best !== null) {
            $strict['suggested_type'] = $best;

            foreach ($classified as $item) {
                if (!in_array($item[1], $families[$best], true) && count($strict['outliers']) < 3) {
                    $strict['outliers'][] = self::limitText($item[0], 60);
                }
            }
        }

        return $strict;
    }

    private static function inferStrict(array $filled, array $kinds, $delimiter, array $spec)
    {
        $only = function (array $allowed) use ($kinds) {
            return count(array_diff($kinds, $allowed)) === 0;
        };

        if ($only(array('int'))) {
            $tooLong = false;

            foreach ($filled as $value) {
                if (strlen(ltrim($value, '+-')) > 18) {
                    $tooLong = true;
                }
            }

            $spec['type'] = $tooLong ? 'string' : 'int';

            return $spec;
        }

        if ($only(array('int', 'number'))) {
            $separator = self::decimalSeparator($filled, $delimiter);
            $integral = true;
            $valid = true;

            foreach ($filled as $value) {
                $number = self::parseNumber($value, $separator);

                if ($number === null) {
                    $valid = false;
                    break;
                }

                if (strpos($number, '.') !== false) {
                    $integral = false;
                }
            }

            if ($valid) {
                $spec['type'] = $integral ? 'int' : 'decimal';
                $spec['decimal_separator'] = $separator;
            }

            return $spec;
        }

        if ($only(array('date'))) {
            $spec['type'] = 'date';
            $spec['date_order'] = self::dateOrder($filled);

            return $spec;
        }

        if ($only(array('date', 'datetime'))) {
            $spec['type'] = 'datetime';
            $spec['date_order'] = self::dateOrder($filled);

            return $spec;
        }

        if ($only(array('bool'))) {
            $spec['type'] = 'bool';
        }

        return $spec;
    }

    /* =================================================================
     * Conversão de valores para o banco
     * ================================================================= */

    /*
     * Número como texto normalizado ("1234.56", "-3", "1500") ou null.
     * Texto, e não float, para não perder precisão em valores
     * monetários; o banco converte para a coluna DECIMAL.
     */
    private static function parseNumber($value, $separator)
    {
        $value = self::stripCurrency(trim((string) $value));

        if ($value === '') {
            return null;
        }

        $isBr = self::isBrazilianNumber($value);
        $isEn = self::isEnglishNumber($value);

        if (!$isBr && !$isEn) {
            return null;
        }

        // Ambíguo ("1.500", "10,5" em coluna americana?): vale o separador da coluna.
        $useBr = $isBr && (!$isEn || $separator === ',');

        $value = $useBr
            ? str_replace(array('.', ','), array('', '.'), $value)
            : str_replace(',', '', $value);

        $negative = $value[0] === '-';
        $value = ltrim($value, '+-');

        if (strpos($value, '.') !== false) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        $value = ltrim($value, '0');

        if ($value === '' || $value[0] === '.') {
            $value = '0' . $value;
        }

        return ($negative && $value !== '0' ? '-' : '') . $value;
    }

    private static function parseDate($value, $order, $withTime)
    {
        $parts = self::dateParts($value);

        if ($parts === null) {
            return null;
        }

        if ($parts['order'] === 'ymd') {
            $month = $parts['a'];
            $day = $parts['b'];
        } elseif ($order === 'mdy') {
            $month = $parts['a'];
            $day = $parts['b'];
        } else {
            $day = $parts['a'];
            $month = $parts['b'];
        }

        if (!checkdate($month, $day, $parts['year']) || !self::validTime($parts['time'])) {
            return null;
        }

        $date = sprintf('%04d-%02d-%02d', $parts['year'], $month, $day);

        if (!$withTime) {
            return $parts['time'] === null ? $date : null;
        }

        $time = $parts['time'] === null ? array(0, 0, 0) : $parts['time'];

        return $date . sprintf(' %02d:%02d:%02d', $time[0], $time[1], $time[2]);
    }

    private static function parseBool($value)
    {
        $value = self::lower(trim((string) $value));

        if (in_array($value, self::$trueWords, true)) {
            return 1;
        }

        if (in_array($value, self::$falseWords, true)) {
            return 0;
        }

        return null;
    }

    private static function typeLabel($type)
    {
        $labels = array(
            'int' => 'número inteiro',
            'decimal' => 'número',
            'date' => 'data',
            'datetime' => 'data e hora',
            'bool' => 'sim/não',
            'intbool' => 'número ou sim/não',
            'digits' => 'número'
        );

        return isset($labels[$type]) ? $labels[$type] : $type;
    }

    /*
     * Converte um valor conforme o tipo da coluna. Devolve
     * array(true, valor) ou array(false, mensagem de erro).
     */
    private static function convertValue($value, array $spec, $emptyAsNull, array $row)
    {
        $type = $spec['type'];

        if ($type === 'raw') {
            return array(true, $value);
        }

        if ($value === '') {
            return array(true, $emptyAsNull ? null : '');
        }

        if (is_callable($spec['callable'])) {
            return array(true, call_user_func($spec['callable'], $value, $row));
        }

        switch ($type) {
            case 'int':
                $number = self::parseNumber($value, $spec['decimal_separator']);

                if ($number === null || strpos($number, '.') !== false) {
                    break;
                }

                return array(true, strlen($number) > 18 ? $number : (int) $number);

            case 'decimal':
                $number = self::parseNumber($value, $spec['decimal_separator']);

                if ($number === null) {
                    break;
                }

                return array(true, $number);

            case 'date':
            case 'datetime':
                $date = self::parseDate($value, $spec['date_order'], $type === 'datetime');

                if ($date === null) {
                    break;
                }

                return array(true, $date);

            case 'bool':
                $bool = self::parseBool($value);

                if ($bool === null) {
                    break;
                }

                return array(true, $bool);

            case 'intbool':
                if (in_array(self::lower($value), self::$boolWords, true)) {
                    return array(true, self::parseBool($value));
                }

                $number = self::parseNumber($value, $spec['decimal_separator']);

                if ($number === null || strpos($number, '.') !== false) {
                    break;
                }

                return array(true, (int) $number);

            case 'digits':
                $digits = preg_replace('/\D+/', '', $value);

                if ($digits === '') {
                    break;
                }

                return array(true, $digits);

            default:
                return array(true, $value);
        }

        return array(false, 'Valor inválido para ' . self::typeLabel($type) . ': "' . self::limitText($value, 60) . '".');
    }

    /* =================================================================
     * Análise do arquivo
     * ================================================================= */

    private static function dialectFromOptions($filePath, array $options)
    {
        $encoding = isset($options['encoding']) && trim((string) $options['encoding']) !== ''
            ? self::normalizeEncodingName($options['encoding'])
            : self::detectFileEncoding($filePath);

        $enclosure = isset($options['enclosure'])
            ? self::validateEnclosure($options['enclosure'])
            : self::$defaultEnclosure;

        $escape = isset($options['escape'])
            ? self::validateEscape($options['escape'])
            : self::defaultEscape();

        $lines = self::readRawLines($filePath, $encoding, self::$sniffRecords);

        if (empty($lines)) {
            throw new CsvHelperException('O arquivo CSV não possui linhas.');
        }

        $sep = self::sepDirective($lines);

        if (isset($options['delimiter'])) {
            $delimiter = self::validateDelimiter($options['delimiter']);
        } elseif ($sep !== null) {
            $delimiter = $sep;
        } elseif (self::$defaultDelimiter !== null) {
            $delimiter = self::validateDelimiter(self::$defaultDelimiter);
        } else {
            $candidates = isset($options['candidates'])
                ? (array) $options['candidates']
                : self::$delimiterCandidates;

            $delimiter = self::sniffDelimiter(
                $sep !== null ? array_slice($lines, 1) : $lines,
                $candidates,
                $enclosure,
                $escape
            );

            // Uma coluna só: o delimitador não importa.
            if ($delimiter === null) {
                $delimiter = $candidates ? self::validateDelimiter(reset($candidates)) : ';';
            }
        }

        return array(
            'encoding' => $encoding,
            'delimiter' => $delimiter,
            'enclosure' => $enclosure,
            'escape' => $escape,
            'skip_sep_line' => $sep !== null
        );
    }

    /*
     * Descobre o dialeto, o cabeçalho e os tipos das colunas. É a base
     * de headers(), preview(), process(), analyze() e import().
     */
    private static function analyzeFile($filePath, array $options)
    {
        $dialect = self::dialectFromOptions($filePath, $options);
        $warnings = array();

        $headerOption = 'auto';

        if (array_key_exists('header_row', $options)) {
            $headerOption = $options['header_row'];
        } elseif (array_key_exists('has_header', $options) && !$options['has_header']) {
            $headerOption = 0;
        }

        $sniff = array();
        $generator = self::records($filePath, $dialect);

        foreach ($generator as $record) {
            $sniff[] = $record;

            if (count($sniff) >= self::$sniffRecords) {
                break;
            }
        }

        $generator = null;

        if (empty($sniff)) {
            throw new CsvHelperException('O arquivo CSV não possui linhas com dados.');
        }

        $width = self::tableWidth($sniff);
        $headerIndex = -1;

        if ($headerOption === 'auto' || $headerOption === null || $headerOption === true) {
            $headerIndex = self::detectHeaderIndex($sniff, $width);
        } elseif ((int) $headerOption > 0) {
            foreach ($sniff as $index => $record) {
                if ($record['line'] >= (int) $headerOption) {
                    $headerIndex = $index;
                    break;
                }
            }

            if ($headerIndex === -1) {
                throw new CsvHelperException(
                    'A linha de cabeçalho ' . (int) $headerOption . ' não foi encontrada.'
                );
            }
        }

        $headerCells = $headerIndex >= 0 ? $sniff[$headerIndex]['cells'] : array();

        if ($headerIndex > 0) {
            $warnings[] = 'O cabeçalho está na linha ' . $sniff[$headerIndex]['line']
                . '; as linhas acima dele foram ignoradas.';
        }

        if ($headerIndex === -1 && $headerOption === 'auto') {
            $warnings[] = 'Nenhum cabeçalho foi reconhecido: as colunas foram chamadas de coluna_1, coluna_2...';
        }

        // rowWidth ignora células vazias no fim: um delimitador sobrando
        // no fim das linhas não cria uma coluna sem nome.
        $columns = max($width, self::rowWidth($headerCells));

        $original = array();
        $names = array();

        for ($index = 0; $index < $columns; $index++) {
            $label = isset($headerCells[$index]) ? $headerCells[$index] : '';
            $original[] = $label;
            $names[] = self::columnName($label, $index);
        }

        $unique = self::uniqueNames($names);

        foreach ($unique as $index => $name) {
            if ($name !== $names[$index]) {
                $warnings[] = 'A coluna "' . $original[$index] . '" se repete e foi chamada de ' . $name . '.';
            }

            if ($headerIndex >= 0 && $original[$index] === '') {
                $warnings[] = 'A coluna ' . ($index + 1) . ' não tem nome no cabeçalho e foi chamada de ' . $name . '.';
            }
        }

        // Amostra de dados para os tipos: registros depois do cabeçalho.
        $samples = array_fill(0, $columns, array());
        $sampleRows = array();
        $dataStartLine = $headerIndex >= 0 ? $sniff[$headerIndex]['line'] + 1 : $sniff[0]['line'];
        $count = 0;
        $extraColumns = 0;

        foreach (self::records($filePath, $dialect) as $record) {
            if ($record['line'] < $dataStartLine) {
                continue;
            }

            if ($headerIndex >= 0 && $record['line'] === $sniff[$headerIndex]['line']) {
                continue;
            }

            if (self::rowWidth($record['cells']) > $columns) {
                $extraColumns++;
            }

            for ($index = 0; $index < $columns; $index++) {
                $samples[$index][] = isset($record['cells'][$index]) ? $record['cells'][$index] : '';
            }

            if (count($sampleRows) < 5) {
                $sampleRows[] = $record;
            }

            $count++;

            if ($count >= self::$sampleRows) {
                break;
            }
        }

        if ($extraColumns > 0) {
            $warnings[] = $extraColumns . ' linha(s) da amostra têm mais colunas que o cabeçalho; as colunas extras são ignoradas.';
        }

        $columnInfo = array();

        foreach ($unique as $index => $name) {
            $spec = self::inferColumn($samples[$index], $dialect['delimiter']);
            $examples = array();

            foreach ($samples[$index] as $value) {
                if ($value !== '' && !in_array($value, $examples, true)) {
                    $examples[] = self::limitText($value, 60);
                }

                if (count($examples) >= 3) {
                    break;
                }
            }

            $columnInfo[] = array(
                'index' => $index,
                'original' => $original[$index],
                'name' => $name,
                'type' => $spec['type'],
                'decimal_separator' => $spec['decimal_separator'],
                'date_order' => $spec['date_order'],
                'filled' => $spec['filled'],
                'empty' => $spec['empty'],
                'suggested_type' => $spec['suggested_type'],
                'outliers' => $spec['outliers'],
                'examples' => $examples
            );
        }

        return array(
            'dialect' => $dialect,
            'has_header' => $headerIndex >= 0,
            'header_line' => $headerIndex >= 0 ? $sniff[$headerIndex]['line'] : null,
            'data_start_line' => $dataStartLine,
            'columns' => $columnInfo,
            'names' => $unique,
            'original' => $original,
            'sample_rows' => $sampleRows,
            'sampled_rows' => $count,
            'warnings' => $warnings
        );
    }

    /* =================================================================
     * Casamento de colunas e plano de leitura
     * ================================================================= */

    private static function tokens($name)
    {
        return array_values(array_filter(explode('_', $name), 'strlen'));
    }

    /*
     * Pontuação de 0 a 1 entre um nome de coluna do CSV e um nome
     * esperado, com o método usado.
     */
    private static function similarity($header, $alias)
    {
        if ($header === $alias) {
            return array(1.0, 'nome');
        }

        $headerTokens = self::tokens($header);
        $aliasTokens = self::tokens($alias);

        if (!empty($aliasTokens) && count(array_diff($aliasTokens, $headerTokens)) === 0) {
            return array(0.85, 'contem');
        }

        if (!empty($headerTokens) && count(array_diff($headerTokens, $aliasTokens)) === 0) {
            return array(0.8, 'contido');
        }

        $length = max(strlen($header), strlen($alias));

        if ($length === 0 || $length > 255) {
            return array(0.0, '');
        }

        $ratio = 1 - levenshtein($header, $alias) / $length;

        return array($ratio * 0.95, 'aproximado');
    }

    /*
     * 'columns' => array(
     *     'nome' => array('nome', 'nome completo', 'cliente'),  // destino => apelidos
     *     'cpf' => 'documento',
     *     'email',                                              // destino = apelido
     * )
     *
     * Cada destino recebe a coluna do CSV com maior pontuação (cada
     * coluna é usada uma vez). Devolve os casamentos e os destinos sem
     * coluna.
     */
    private static function matchColumns(array $names, array $original, array $columnsOption, $threshold)
    {
        $wanted = array();

        foreach ($columnsOption as $destination => $aliases) {
            if (is_int($destination)) {
                $destination = (string) $aliases;
                $aliases = array();
            }

            $destination = trim((string) $destination);

            if ($destination === '') {
                continue;
            }

            $list = array(self::normalizeHeader($destination));

            foreach ((array) $aliases as $alias) {
                $normalized = self::normalizeHeader($alias);

                if ($normalized !== '') {
                    $list[] = $normalized;
                }
            }

            $wanted[$destination] = array_values(array_unique($list));
        }

        $candidates = array();

        foreach ($wanted as $destination => $aliases) {
            foreach ($names as $index => $name) {
                $best = array(0.0, '');
                $normalizedOriginal = self::normalizeHeader($original[$index]);

                foreach ($aliases as $position => $alias) {
                    foreach (array_unique(array($name, $normalizedOriginal)) as $candidateName) {
                        if ($candidateName === '') {
                            continue;
                        }

                        $score = self::similarity($candidateName, $alias);

                        if ($score[1] === 'nome' && $position > 0) {
                            $score[1] = 'apelido';
                        }

                        if ($score[0] > $best[0]) {
                            $best = $score;
                        }
                    }
                }

                if ($best[0] >= $threshold) {
                    $candidates[] = array($best[0], $destination, $index, $best[1]);
                }
            }
        }

        usort($candidates, function ($a, $b) {
            if ($a[0] === $b[0]) {
                return $a[2] - $b[2];
            }

            return $a[0] < $b[0] ? 1 : -1;
        });

        $matches = array();
        $usedIndexes = array();

        foreach ($candidates as $candidate) {
            list($score, $destination, $index, $method) = $candidate;

            if (isset($matches[$destination]) || isset($usedIndexes[$index])) {
                continue;
            }

            $matches[$destination] = array(
                'index' => $index,
                'header' => $original[$index] !== '' ? $original[$index] : $names[$index],
                'name' => $names[$index],
                'method' => $method,
                'score' => round($score, 2)
            );

            $usedIndexes[$index] = true;
        }

        $ordered = array();
        $missing = array();

        foreach (array_keys($wanted) as $destination) {
            if (isset($matches[$destination])) {
                $ordered[$destination] = $matches[$destination];
            } else {
                $missing[] = $destination;
            }
        }

        $unused = array();

        foreach ($names as $index => $name) {
            if (!isset($usedIndexes[$index])) {
                $unused[] = $name;
            }
        }

        return array(
            'matches' => $ordered,
            'missing' => $missing,
            'unused' => $unused
        );
    }

    /*
     * Monta a lista de campos de saída (nome de destino + índice da
     * coluna + regra de conversão) a partir da análise e das opções.
     */
    private static function buildPlan(array $analysis, array $options)
    {
        $names = $analysis['names'];
        $matching = null;
        $fields = array();

        if (!empty($options['columns'])) {
            $matching = self::matchColumns(
                $names,
                $analysis['original'],
                (array) $options['columns'],
                isset($options['match_threshold']) ? (float) $options['match_threshold'] : 0.8
            );

            foreach ($matching['matches'] as $destination => $match) {
                $fields[] = array('name' => $destination, 'index' => $match['index']);
            }

            if (!empty($options['keep_unmatched'])) {
                $usedIndexes = array();

                foreach ($fields as $field) {
                    $usedIndexes[$field['index']] = true;
                }

                foreach ($names as $index => $name) {
                    if (!isset($usedIndexes[$index])) {
                        $fields[] = array('name' => $name, 'index' => $index);
                    }
                }
            }
        } else {
            $mapping = array();

            foreach (isset($options['mapping']) ? (array) $options['mapping'] : array() as $source => $destination) {
                $source = self::normalizeHeader($source);
                $destination = trim((string) $destination);

                if ($source !== '' && $destination !== '') {
                    $mapping[$source] = $destination;
                }
            }

            foreach ($names as $index => $name) {
                $normalizedOriginal = self::normalizeHeader($analysis['original'][$index]);

                if (isset($mapping[$name])) {
                    $destination = $mapping[$name];
                } elseif ($normalizedOriginal !== '' && isset($mapping[$normalizedOriginal])) {
                    $destination = $mapping[$normalizedOriginal];
                } else {
                    $destination = $name;
                }

                $fields[] = array('name' => $destination, 'index' => $index);
            }
        }

        $types = isset($options['types']) ? (array) $options['types'] : array();
        $convertAll = !empty($options['convert']);

        foreach ($fields as $position => $field) {
            $inferred = $analysis['columns'][$field['index']];
            $declared = isset($types[$field['name']]) ? $types[$field['name']] : null;
            $callable = null;

            if (is_callable($declared) && !is_string($declared)) {
                $callable = $declared;
                $type = 'callable';
            } elseif ($declared !== null && $declared !== 'auto') {
                $type = strtolower(trim((string) $declared));
                $aliases = array(
                    'integer' => 'int',
                    'float' => 'decimal',
                    'number' => 'decimal',
                    'numeric' => 'decimal',
                    'money' => 'decimal',
                    'boolean' => 'bool',
                    'text' => 'string',
                    'timestamp' => 'datetime'
                );

                if (isset($aliases[$type])) {
                    $type = $aliases[$type];
                }
            } elseif ($declared === 'auto' || $convertAll) {
                $type = $inferred['type'];
            } else {
                $type = null;
            }

            $separator = $inferred['decimal_separator'];

            if ($separator === null && in_array($type, array('int', 'decimal'), true)) {
                $values = array();

                foreach ($analysis['sample_rows'] as $record) {
                    if (isset($record['cells'][$field['index']])) {
                        $values[] = $record['cells'][$field['index']];
                    }
                }

                $separator = self::decimalSeparator($values, $analysis['dialect']['delimiter']);
            }

            $fields[$position]['spec'] = $type === null ? null : array(
                'type' => $type,
                'callable' => $callable,
                'decimal_separator' => $separator,
                'date_order' => $inferred['date_order'] !== null ? $inferred['date_order'] : 'dmy'
            );
        }

        return array(
            'fields' => $fields,
            'matching' => $matching,
            'empty_as_null' => array_key_exists('empty_as_null', $options)
                ? (bool) $options['empty_as_null']
                : true
        );
    }

    /*
     * Linha de saída: sem conversão, os textos como estão (compatível
     * com as versões anteriores); com conversão, os valores tipados.
     * Devolve array(dados, erros de conversão).
     */
    private static function buildRow(array $cells, array $plan)
    {
        $row = array();

        foreach ($plan['fields'] as $field) {
            $row[$field['name']] = isset($cells[$field['index']]) ? $cells[$field['index']] : '';
        }

        $converted = array();
        $errors = array();

        foreach ($plan['fields'] as $field) {
            $value = $row[$field['name']];

            if ($field['spec'] === null) {
                $converted[$field['name']] = $value;
                continue;
            }

            $result = self::convertValue($value, $field['spec'], $plan['empty_as_null'], $row);

            if ($result[0]) {
                $converted[$field['name']] = $result[1];
            } else {
                $converted[$field['name']] = $value;
                $errors[$field['name']] = array($result[1]);
            }
        }

        return array($converted, $errors);
    }

    private static function validateRequired(array $analysis, array $plan, array $options)
    {
        $missing = array();

        foreach (isset($options['required_headers']) ? (array) $options['required_headers'] : array() as $header) {
            $normalized = self::normalizeHeader($header);
            $found = in_array($normalized, $analysis['names'], true);

            foreach ($analysis['original'] as $original) {
                if (self::normalizeHeader($original) === $normalized) {
                    $found = true;
                }
            }

            if (!$found) {
                $missing[] = $header;
            }
        }

        if ($plan['matching'] !== null) {
            foreach (isset($options['required_columns']) ? (array) $options['required_columns'] : array() as $column) {
                if (in_array($column, $plan['matching']['missing'], true)) {
                    $missing[] = $column;
                }
            }
        }

        if (!empty($missing)) {
            throw new CsvHelperException(
                'Colunas obrigatórias não encontradas no arquivo: ' . implode(', ', array_unique($missing)) . '.'
            );
        }
    }

    private static function prepare($path, $baseDirectory, array $options)
    {
        $filePath = self::resolveFilePath($path, $baseDirectory, true);
        $fileData = self::validateCsvFile($filePath, $options);
        $analysis = self::analyzeFile($filePath, $options);
        $plan = self::buildPlan($analysis, $options);

        $fileData['relative_path'] = self::isUploadedTempFile($filePath)
            ? $fileData['file_name']
            : self::relativePath($filePath, $baseDirectory);

        return array($filePath, $fileData, $analysis, $plan);
    }

    private static function describe(array $fileData, array $analysis, array $plan)
    {
        $headersOriginal = array();
        $headerIndexes = array();

        foreach ($analysis['columns'] as $column) {
            $headersOriginal[] = $column['original'];
            $headerIndexes[$column['name']] = $column['index'];
        }

        $data = array_merge(
            $fileData,
            array(
                'encoding' => $analysis['dialect']['encoding'],
                'delimiter' => $analysis['dialect']['delimiter'],
                'enclosure' => $analysis['dialect']['enclosure'],
                'escape' => $analysis['dialect']['escape'],
                'has_header' => $analysis['has_header'],
                'header_line' => $analysis['header_line'],
                'data_start_line' => $analysis['data_start_line'],
                'headers_original' => $headersOriginal,
                'headers_normalized' => $analysis['names'],
                'header_indexes' => $headerIndexes,
                'columns' => $analysis['columns'],
                'warnings' => $analysis['warnings']
            )
        );

        if ($plan['matching'] !== null) {
            $data['matches'] = $plan['matching']['matches'];
            $data['missing_columns'] = $plan['matching']['missing'];
            $data['unused_columns'] = $plan['matching']['unused'];
        }

        return $data;
    }

    /* =================================================================
     * Lotes e relatório
     * ================================================================= */

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

    private static function loadHelper($class)
    {
        if (!class_exists($class) && is_file(__DIR__ . '/' . $class . '.php')) {
            require_once __DIR__ . '/' . $class . '.php';
        }

        return class_exists($class);
    }

    /* =================================================================
     * Configuração
     * ================================================================= */

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

    /*
     * Delimitador fixo para todos os arquivos (null = detectar).
     */
    public static function setDefaultDelimiter($delimiter)
    {
        self::$defaultDelimiter = $delimiter === null || $delimiter === ''
            ? null
            : self::validateDelimiter($delimiter);
    }

    /* =================================================================
     * API pública: detecção
     * ================================================================= */

    /*
     * 'UTF-8', 'UTF-16LE', 'UTF-16BE' ou 'Windows-1252'.
     */
    public static function detectEncoding($path, $baseDirectory = null)
    {
        try {
            return self::detectFileEncoding(
                self::resolveFilePath($path, $baseDirectory, true)
            );
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
            $filePath = self::resolveFilePath($path, $baseDirectory, true);
            $dialect = self::dialectFromOptions($filePath, $options);

            return $dialect['delimiter'];
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

    /*
     * Tudo o que foi entendido do arquivo, para mostrar ao usuário antes
     * de importar: codificação, delimitador, linha do cabeçalho, colunas
     * (nome original, nome para o banco, tipo, exemplos), avisos e, com
     * 'columns', o casamento com as colunas esperadas.
     */
    public static function analyze($path, $baseDirectory = null, array $options = array())
    {
        try {
            list($filePath, $fileData, $analysis, $plan) = self::prepare($path, $baseDirectory, $options);

            $data = self::describe($fileData, $analysis, $plan);
            $data['sampled_rows'] = $analysis['sampled_rows'];

            return self::result(
                true,
                'CSV analisado com sucesso.',
                $data
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao analisar CSV.',
                array(
                    'arquivo' => is_string($path) ? $path : '',
                    'erro' => $error->getMessage()
                )
            );

            return self::failure('Não foi possível analisar o CSV.', $error);
        }
    }

    public static function headers($path, $baseDirectory = null, array $options = array())
    {
        try {
            list($filePath, $fileData, $analysis, $plan) = self::prepare($path, $baseDirectory, $options);

            return self::result(
                true,
                'Cabeçalhos CSV carregados com sucesso.',
                self::describe($fileData, $analysis, $plan)
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao ler cabeçalhos CSV.',
                array(
                    'arquivo' => is_string($path) ? $path : '',
                    'erro' => $error->getMessage()
                )
            );

            return self::failure('Não foi possível carregar os cabeçalhos do CSV.', $error);
        }
    }

    /*
     * Primeiras linhas de dados. Cada linha traz 'raw' (células como
     * estão), 'mapped' (por nome de coluna, como texto) e 'converted'
     * (valores já convertidos pelos tipos detectados ou declarados),
     * com 'errors' quando algum valor não pôde ser convertido.
     */
    public static function preview($path, $baseDirectory = null, $limit = 10, array $options = array())
    {
        try {
            $limit = max(1, min(100, (int) $limit));

            list($filePath, $fileData, $analysis, $plan) = self::prepare($path, $baseDirectory, $options);

            $textPlan = $plan;

            foreach ($textPlan['fields'] as $position => $field) {
                $textPlan['fields'][$position]['spec'] = null;
            }

            $typedOptions = $options;
            $typedOptions['convert'] = true;
            $typedPlan = self::buildPlan($analysis, $typedOptions);

            $rows = array();

            foreach (self::records($filePath, $analysis['dialect']) as $record) {
                if ($record['line'] < $analysis['data_start_line'] || $record['line'] === $analysis['header_line']) {
                    continue;
                }

                list($mapped) = self::buildRow($record['cells'], $textPlan);
                list($converted, $errors) = self::buildRow($record['cells'], $typedPlan);

                $rows[] = array(
                    'line' => $record['line'],
                    'raw' => $record['cells'],
                    'mapped' => $mapped,
                    'converted' => $converted,
                    'errors' => $errors
                );

                if (count($rows) >= $limit) {
                    break;
                }
            }

            $description = self::describe($fileData, $analysis, $plan);

            return self::result(
                true,
                'Prévia CSV carregada com sucesso.',
                array(
                    'headers' => $analysis['names'],
                    'headers_original' => $description['headers_original'],
                    'delimiter' => $analysis['dialect']['delimiter'],
                    'encoding' => $analysis['dialect']['encoding'],
                    'has_header' => $analysis['has_header'],
                    'header_line' => $analysis['header_line'],
                    'columns' => $analysis['columns'],
                    'warnings' => $analysis['warnings'],
                    'matches' => isset($description['matches']) ? $description['matches'] : null,
                    'missing_columns' => isset($description['missing_columns']) ? $description['missing_columns'] : array(),
                    'rows' => $rows
                )
            );
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao gerar prévia CSV.',
                array(
                    'arquivo' => is_string($path) ? $path : '',
                    'erro' => $error->getMessage()
                )
            );

            return self::failure('Não foi possível gerar a prévia do CSV.', $error);
        }
    }

    /* =================================================================
     * API pública: processamento
     * ================================================================= */

    /*
     * Lê o CSV inteiro em lotes e entrega cada lote ao $callback:
     *
     *   function (array $lote, array $relatorio) { ... }
     *
     * $lote = array(array('line' => 12, 'data' => array(...), 'raw' => array(...)), ...)
     *
     * Opções principais: header_row, columns, mapping, types, convert,
     * empty_as_null, transform, rules, validate_row, required_headers,
     * required_columns, chunk_size, stop_on_error, max_errors.
     */
    public static function process($path, $callback, $baseDirectory = null, array $options = array())
    {
        $report = array(
            'file' => null,
            'delimiter' => null,
            'encoding' => null,
            'headers' => array(),
            'header_line' => null,
            'columns' => array(),
            'warnings' => array(),
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

            list($filePath, $fileData, $analysis, $plan) = self::prepare($path, $baseDirectory, $options);

            self::validateRequired($analysis, $plan, $options);

            $description = self::describe($fileData, $analysis, $plan);

            $report['file'] = $description;
            $report['delimiter'] = $analysis['dialect']['delimiter'];
            $report['encoding'] = $analysis['dialect']['encoding'];
            $report['headers'] = $analysis['names'];
            $report['header_line'] = $analysis['header_line'];
            $report['columns'] = $analysis['columns'];
            $report['warnings'] = $analysis['warnings'];

            if (isset($description['matches'])) {
                $report['matches'] = $description['matches'];
                $report['missing_columns'] = $description['missing_columns'];
            }

            $chunkSize = self::normalizeChunkSize(
                isset($options['chunk_size'])
                    ? $options['chunk_size']
                    : self::$defaultChunkSize
            );

            $transform = isset($options['transform']) && is_callable($options['transform'])
                ? $options['transform']
                : null;

            $validateRow = isset($options['validate_row']) && is_callable($options['validate_row'])
                ? $options['validate_row']
                : null;

            $rules = isset($options['rules']) && is_array($options['rules']) && !empty($options['rules'])
                ? $options['rules']
                : null;

            if ($rules !== null && !self::loadHelper('ValidationHelper')) {
                throw new RuntimeException('A opção "rules" exige o ValidationHelper.');
            }

            $onChunk = isset($options['on_chunk']) ? $options['on_chunk'] : null;
            $onProgress = isset($options['on_progress']) ? $options['on_progress'] : null;
            $stopOnError = !empty($options['stop_on_error']);
            $enforceColumnCount = !empty($options['enforce_column_count']);
            $expectedColumns = count($analysis['names']);

            $reject = function ($line, $message, array $data) use (&$report, $stopOnError) {
                self::reportError($report, $line, $message, $data);

                $report['skipped_rows']++;
                $report['processed_rows']++;

                if ($stopOnError) {
                    throw new CsvHelperException('Processamento interrompido na linha ' . $line . ': ' . $message);
                }
            };

            $dispatch = function (array $chunk) use ($callback, &$report, $onChunk, $onProgress) {
                $chunkResult = call_user_func($callback, $chunk, $report);

                self::applyChunkResult($report, $chunk, $chunkResult);

                if (is_callable($onChunk)) {
                    call_user_func($onChunk, $chunk, $chunkResult, $report);
                }

                if (is_callable($onProgress)) {
                    call_user_func($onProgress, $report);
                }
            };

            $chunk = array();

            foreach (self::records($filePath, $analysis['dialect']) as $record) {
                $line = $record['line'];

                if ($line < $analysis['data_start_line'] || $line === $analysis['header_line']) {
                    continue;
                }

                $row = $record['cells'];
                $report['total_rows']++;

                if ($enforceColumnCount && self::rowWidth($row) !== $expectedColumns && count($row) !== $expectedColumns) {
                    $reject($line, 'Quantidade de colunas diferente do cabeçalho.', array(
                        'expected_columns' => $expectedColumns,
                        'received_columns' => count($row),
                        'row' => $row
                    ));

                    continue;
                }

                list($data, $conversionErrors) = self::buildRow($row, $plan);

                if (!empty($conversionErrors)) {
                    $first = reset($conversionErrors);

                    $reject($line, key($conversionErrors) . ': ' . $first[0], array(
                        'errors' => $conversionErrors,
                        'row' => $data
                    ));

                    continue;
                }

                if ($transform !== null) {
                    $transformed = call_user_func($transform, $data, $line, $row);

                    // false: linha descartada de propósito (totais, rodapés...).
                    if ($transformed === false) {
                        $report['skipped_rows']++;
                        $report['processed_rows']++;

                        continue;
                    }

                    if (is_array($transformed)) {
                        $data = $transformed;
                    }
                }

                if ($rules !== null) {
                    $validation = ValidationHelper::validate(
                        $data,
                        $rules,
                        isset($options['messages']) ? (array) $options['messages'] : array(),
                        isset($options['labels']) ? (array) $options['labels'] : array()
                    );

                    if (!$validation['valid']) {
                        $reject($line, $validation['first_error'], array(
                            'errors' => $validation['errors'],
                            'row' => $data
                        ));

                        continue;
                    }
                }

                if ($validateRow !== null) {
                    $validation = call_user_func($validateRow, $data, $line, $row);

                    if (is_array($validation)) {
                        if (empty($validation['valid'])) {
                            $reject(
                                $line,
                                isset($validation['first_error']) ? $validation['first_error'] : 'Dados inválidos na linha CSV.',
                                array(
                                    'errors' => isset($validation['errors']) ? $validation['errors'] : array(),
                                    'row' => $data
                                )
                            );

                            continue;
                        }
                    } elseif ($validation !== true) {
                        $reject(
                            $line,
                            is_string($validation) && $validation !== '' ? $validation : 'Dados inválidos na linha CSV.',
                            array('row' => $data)
                        );

                        continue;
                    }
                }

                $chunk[] = array(
                    'line' => $line,
                    'data' => $data,
                    'raw' => $row
                );

                if (count($chunk) >= $chunkSize) {
                    $dispatch($chunk);
                    $chunk = array();
                }
            }

            if (!empty($chunk)) {
                $dispatch($chunk);
            }

            $report['finished_at'] = JFactory::getDate()->toSql();

            self::log(
                'info',
                'Processamento CSV concluído.',
                array(
                    'arquivo' => $fileData['relative_path'],
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
                    'arquivo' => is_string($path) ? $path : '',
                    'erro' => $error->getMessage(),
                    'total_rows' => $report['total_rows'],
                    'success_rows' => $report['success_rows'],
                    'errors_count' => $report['errors_count']
                )
            );

            return self::failure(
                'Não foi possível processar o arquivo CSV.',
                $error,
                $report,
                $report['errors']
            );
        }
    }

    /*
     * Mensagem de erro de gravação segura para o relatório: duplicidade
     * é identificada; o resto fica genérico (o detalhe vai para o log).
     */
    private static function databaseErrorMessage(Throwable $error)
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            $message = strtolower($current->getMessage());

            if (strpos($message, 'duplicate') !== false || strpos($message, '23505') !== false) {
                return 'Registro duplicado: já existe um registro com estes dados.';
            }

            if (strpos($message, 'cannot be null') !== false || strpos($message, 'not-null') !== false || strpos($message, '23502') !== false) {
                return 'Um campo obrigatório da tabela está vazio.';
            }

            if (strpos($message, 'too long') !== false || strpos($message, 'too large') !== false || strpos($message, '22001') !== false) {
                return 'Um valor é maior do que a coluna da tabela permite.';
            }

            if (strpos($message, 'foreign key') !== false || strpos($message, '23503') !== false) {
                return 'Um valor faz referência a um registro que não existe.';
            }
        }

        return 'Não foi possível gravar a linha no banco de dados.';
    }

    /*
     * Tipo de conversão a partir do tipo da coluna no banco (MySQL ou
     * PostgreSQL). TINYINT aceita inteiros e também sim/não, porque é o
     * tipo usado para booleanos no MySQL.
     */
    private static function typeFromDatabase($databaseType)
    {
        $type = strtolower(trim((string) $databaseType));
        $type = preg_replace('/\(.*$|\s+unsigned.*$/', '', $type);

        if ($type === 'tinyint') {
            return 'intbool';
        }

        if (in_array($type, array('int', 'integer', 'bigint', 'smallint', 'mediumint', 'serial', 'bigserial', 'smallserial', 'year'), true)) {
            return 'int';
        }

        if (in_array($type, array('decimal', 'numeric', 'float', 'double', 'real', 'double precision', 'money'), true)) {
            return 'decimal';
        }

        if ($type === 'date') {
            return 'date';
        }

        if ($type === 'datetime' || strpos($type, 'timestamp') === 0) {
            return 'datetime';
        }

        if ($type === 'boolean' || $type === 'bool') {
            return 'bool';
        }

        return 'string';
    }

    /*
     * Importa o CSV para uma tabela, usando OrmTables e
     * DbTransactionHelper.
     *
     * Opções (além das de process()):
     * - connection:  conexão do DbConnectionHelper (padrão: Joomla);
     * - mode:        'insert' (padrão) ou 'upsert' (atualiza se existir);
     * - unique_by:   coluna(s) que identificam o registro no upsert;
     * - defaults:    valores fixos para todas as linhas
     *                (ex.: array('importacao_id' => 15));
     * - dry_run:     true = só lê, converte e valida, sem gravar;
     * - atomic:      true = tudo ou nada (qualquer erro desfaz tudo);
     * - orm:         opções do OrmTables (casts, primaryKey...).
     *
     * Os valores são convertidos pelos tipos detectados (convert = true
     * por padrão). Cada linha é gravada num savepoint: uma linha com
     * erro é registrada no relatório sem desfazer as outras do lote.
     */
    public static function import($path, $table, $baseDirectory = null, array $options = array())
    {
        try {
            $table = trim((string) $table);

            if ($table === '') {
                throw new InvalidArgumentException('Informe a tabela de destino.');
            }

            if (!self::loadHelper('OrmTables') || !self::loadHelper('DbTransactionHelper')) {
                throw new RuntimeException('import() exige OrmTables e DbTransactionHelper.');
            }

            $mode = isset($options['mode']) ? strtolower((string) $options['mode']) : 'insert';

            if (!in_array($mode, array('insert', 'upsert'), true)) {
                throw new InvalidArgumentException('Modo de importação inválido: use insert ou upsert.');
            }

            $uniqueBy = isset($options['unique_by']) ? array_values((array) $options['unique_by']) : array();

            if ($mode === 'upsert' && empty($uniqueBy)) {
                throw new InvalidArgumentException('O modo upsert exige a opção unique_by.');
            }

            $defaults = isset($options['defaults']) ? (array) $options['defaults'] : array();
            $dryRun = !empty($options['dry_run']);
            $stopOnError = !empty($options['stop_on_error']);

            $ormOptions = isset($options['orm']) ? (array) $options['orm'] : array();

            if (isset($options['connection'])) {
                $ormOptions['connection'] = $options['connection'];
            }

            $orm = OrmTables::table($table, $ormOptions);
            $db = $orm->db();

            $tableTypes = array();

            try {
                $tableTypes = (array) $db->getTableColumns($table, true);
            } catch (Throwable $error) {
                throw new CsvHelperException('A tabela ' . $table . ' não foi encontrada.');
            }

            $tableColumns = array_keys($tableTypes);

            if (empty($tableColumns)) {
                throw new CsvHelperException('A tabela ' . $table . ' não foi encontrada.');
            }

            // Datas automáticas só se a tabela tiver as colunas.
            if (!array_key_exists('timestamps', $ormOptions)) {
                $ormOptions['timestamps'] = in_array('created_at', $tableColumns, true)
                    && in_array('updated_at', $tableColumns, true);

                $orm = OrmTables::table($table, $ormOptions);
            }

            if (!array_key_exists('convert', $options)) {
                $options['convert'] = true;
            }

            /*
             * O tipo de cada coluna vem da própria tabela: "abc" numa coluna
             * INT vira erro daquela linha, e uma coluna VARCHAR recebe o texto
             * como está, mesmo que pareça número. Tipos declarados em 'types'
             * têm prioridade.
             */
            if (!isset($options['use_table_types']) || $options['use_table_types']) {
                $declared = isset($options['types']) ? (array) $options['types'] : array();

                foreach ($tableTypes as $column => $databaseType) {
                    if (!array_key_exists($column, $declared)) {
                        $declared[$column] = self::typeFromDatabase($databaseType);
                    }
                }

                $options['types'] = $declared;
            }

            $totals = array('inserted' => 0, 'updated' => 0);

            $callback = function (array $chunk) use ($orm, $db, $mode, $uniqueBy, $defaults, $dryRun, $stopOnError, &$totals) {
                if ($dryRun) {
                    return array('success_rows' => count($chunk));
                }

                $outcome = null;

                DbTransactionHelper::run(function () use ($chunk, $orm, $db, $mode, $uniqueBy, $defaults, $stopOnError, &$outcome) {
                    // Zerado aqui: em deadlock, o lote inteiro é executado de novo.
                    $outcome = array('success_rows' => 0, 'skipped_rows' => 0, 'errors' => array(), 'inserted' => 0, 'updated' => 0);

                    foreach ($chunk as $item) {
                        $row = array_merge($defaults, $item['data']);

                        try {
                            $action = DbTransactionHelper::run(function () use ($orm, $row, $mode, $uniqueBy) {
                                if ($mode === 'upsert') {
                                    $conditions = array();

                                    foreach ($uniqueBy as $column) {
                                        if (!isset($row[$column]) || $row[$column] === '') {
                                            throw new CsvHelperException('A coluna ' . $column . ', usada para identificar o registro, está vazia.');
                                        }

                                        $conditions[$column] = $row[$column];
                                    }

                                    $finder = clone $orm;

                                    foreach ($conditions as $column => $value) {
                                        $finder->where($column, $value);
                                    }

                                    if ($finder->exists()) {
                                        $updater = clone $orm;

                                        foreach ($conditions as $column => $value) {
                                            $updater->where($column, $value);
                                        }

                                        $updater->updateWhere($row);

                                        return 'updated';
                                    }
                                }

                                // create() pode devolver null numa tabela sem chave
                                // autoincremento: a linha foi gravada, só não foi relida.
                                $orm->create($row);

                                return 'inserted';
                            }, array('connection' => $db, 'retries' => 0));

                            $outcome['success_rows']++;
                            $outcome[$action]++;
                        } catch (Throwable $error) {
                            $message = $error instanceof CsvHelperException
                                ? $error->getMessage()
                                : self::databaseErrorMessage($error);

                            if (!($error instanceof CsvHelperException)) {
                                self::log('warning', 'Falha ao gravar linha do CSV.', array(
                                    'linha' => $item['line'],
                                    'erro' => $error->getMessage(),
                                    'anterior' => $error->getPrevious() ? $error->getPrevious()->getMessage() : null
                                ));
                            }

                            $outcome['skipped_rows']++;
                            $outcome['errors'][] = array(
                                'line' => $item['line'],
                                'message' => $message,
                                'data' => array('row' => $row)
                            );

                            if ($stopOnError) {
                                throw new CsvHelperException('Importação interrompida na linha ' . $item['line'] . ': ' . $message);
                            }
                        }
                    }
                }, array('connection' => $db, 'retries' => 2));

                $totals['inserted'] += $outcome['inserted'];
                $totals['updated'] += $outcome['updated'];

                return $outcome;
            };

            $run = function () use ($path, $callback, $baseDirectory, $options) {
                return self::process($path, $callback, $baseDirectory, $options);
            };

            $rolledBack = false;

            if (!empty($options['atomic']) && !$dryRun) {
                $result = null;

                try {
                    DbTransactionHelper::run(function () use ($run, &$result) {
                        $result = $run();

                        if (!$result['success']) {
                            throw new CsvHelperException('importação desfeita');
                        }
                    }, array('connection' => $db, 'retries' => 0));
                } catch (CsvHelperException $error) {
                    $rolledBack = true;
                }
            } else {
                $result = $run();
            }

            $result['data']['table'] = $table;
            $result['data']['mode'] = $mode;
            $result['data']['dry_run'] = $dryRun;
            $result['data']['inserted_rows'] = $rolledBack ? 0 : $totals['inserted'];
            $result['data']['updated_rows'] = $rolledBack ? 0 : $totals['updated'];
            $result['data']['rolled_back'] = $rolledBack;

            // Colunas do CSV que não existem na tabela (o ORM as descarta).
            $ignored = array();
            $headers = isset($result['data']['matches'])
                ? array_keys($result['data']['matches'])
                : (isset($result['data']['headers']) ? $result['data']['headers'] : array());

            if (empty($options['columns']) && !empty($options['mapping'])) {
                $headers = array();

                foreach ($result['data']['headers'] as $header) {
                    $mapped = false;

                    foreach ((array) $options['mapping'] as $source => $destination) {
                        if (self::normalizeHeader($source) === $header) {
                            $headers[] = $destination;
                            $mapped = true;
                        }
                    }

                    if (!$mapped) {
                        $headers[] = $header;
                    }
                }
            }

            foreach ($headers as $header) {
                if (!in_array($header, $tableColumns, true)) {
                    $ignored[] = $header;
                }
            }

            $result['data']['ignored_columns'] = $ignored;

            if (!empty($ignored)) {
                $result['data']['warnings'][] = 'Colunas sem correspondência na tabela (não gravadas): ' . implode(', ', $ignored) . '.';
            }

            if ($rolledBack) {
                $result['success'] = false;
                $result['status'] = 'erro';
                $result['mensagem'] = 'Nenhuma linha foi gravada: o arquivo tem erros e a importação foi desfeita.';
            } elseif ($dryRun && $result['success']) {
                $result['mensagem'] = 'Simulação concluída: nenhuma linha foi gravada.';
            }

            return $result;
        } catch (Throwable $error) {
            self::log(
                'error',
                'Falha ao importar CSV.',
                array(
                    'arquivo' => is_string($path) ? $path : '',
                    'tabela' => is_string($table) ? $table : '',
                    'erro' => $error->getMessage()
                )
            );

            return self::failure('Não foi possível importar o arquivo CSV.', $error);
        }
    }

    /*
     * Texto que o Excel interpretaria como fórmula (=, +, -, @) ganha um
     * apóstrofo na frente: evita "CSV injection" ao abrir o arquivo.
     */
    private static function safeCsvValue($value)
    {
        $value = is_scalar($value) || $value === null ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($value !== '' && in_array($value[0], array('=', '+', '-', '@', "\t", "\r"), true) && !is_numeric($value)) {
            return "'" . $value;
        }

        return $value;
    }

    /*
     * Grava um CSV com as linhas rejeitadas. Com 'reimportable' => true,
     * cada coluna da linha original vira uma coluna do arquivo (mais
     * "linha" e "erro"), para o usuário corrigir e importar de novo.
     */
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

            $escape = self::defaultEscape();

            $handle = fopen($filePath, 'wb');

            if ($handle === false) {
                throw new RuntimeException(
                    'Não foi possível criar o CSV de rejeitados.'
                );
            }

            if (!array_key_exists('utf8_bom', $options) || !empty($options['utf8_bom'])) {
                // BOM: o Excel só reconhece os acentos de um CSV UTF-8 com ele.
                fwrite($handle, "\xEF\xBB\xBF");
            }

            $columns = array();

            if (!empty($options['reimportable'])) {
                foreach ($errors as $error) {
                    if (isset($error['data']['row']) && is_array($error['data']['row'])) {
                        foreach (array_keys($error['data']['row']) as $column) {
                            $columns[$column] = true;
                        }
                    }
                }

                $columns = array_keys($columns);
            }

            fputcsv(
                $handle,
                empty($columns) ? array('linha', 'erro', 'dados') : array_merge($columns, array('linha', 'erro')),
                $delimiter,
                $enclosure,
                $escape
            );

            foreach ($errors as $error) {
                $line = isset($error['line'])
                    ? (int) $error['line']
                    : 0;

                $message = isset($error['message'])
                    ? (string) $error['message']
                    : 'Erro não identificado.';

                if (!empty($columns)) {
                    $row = isset($error['data']['row']) && is_array($error['data']['row']) ? $error['data']['row'] : array();
                    $values = array();

                    foreach ($columns as $column) {
                        $values[] = self::safeCsvValue(isset($row[$column]) ? $row[$column] : '');
                    }

                    $values[] = $line;
                    $values[] = self::safeCsvValue($message);

                    fputcsv($handle, $values, $delimiter, $enclosure, $escape);

                    continue;
                }

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
                        self::safeCsvValue($message),
                        self::safeCsvValue($data)
                    ),
                    $delimiter,
                    $enclosure,
                    $escape
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

            return self::failure('Não foi possível criar o CSV de rejeitados.', $error);
        }
    }
}
