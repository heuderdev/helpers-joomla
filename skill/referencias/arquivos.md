# Arquivos: UploadMaster, FileHelper, CsvHelper, ChunkHelper, ExportHelper

Todos devolvem `['success', 'status', 'mensagem', 'data', 'errors']`, não lançam exceção e só operam dentro de um **diretório base** (padrão `JPATH_SITE`). Caminhos são relativos à base; `../` é recusado.

## UploadMaster (receber e entregar arquivos)

```php
$arquivo = UploadMaster::getFileFromInput('documento');            // <input type="file" name="documento">
$r = UploadMaster::upload($arquivo, 'contratos/' . date('Y'), null, [
    'allowed_extensions' => ['pdf', 'jpg', 'png'],
    'max_size' => 5 * 1024 * 1024,
    'base_directory' => 'media/com_x/privado',                     // opcional
]);
if (!$r['success']) return ApiResponseHelper::error($r['mensagem']);   // mensagem já traz o motivo
$r['data'];   // path (absoluto, não exponha), relative_path (grave no banco), url, file_name, extension, mime, size, size_formatted
```
- Confere o **tipo real** do conteúdo, gera nome único, recodifica imagens (remove EXIF).
- Opções: `allowed_extensions`, `allowed_mimes`, `max_size`, `base_directory`, `file_name`, `keep_original_name`, `prefix`, `overwrite`, `reencode_images`.
- `uploadMany($arquivos, $pasta, $op)` · `info($rel)` · `exists($rel)` (bool) · `copy/move($origem, $pasta, $nome, $op)` · `delete($rel)` · `removeEmptyDirectories($pasta)`.
- `thumbnail($rel, $pasta, $largura, $altura, ['crop' => true, 'format' => 'webp', 'quality' => 85, 'upscale' => false])` (precisa de GD).
- `stream($rel, $nomeDownload, ['inline' => true, 'max_age' => 0])`: download/exibição com suporte a Range (vídeo); encerra a aplicação. **Confira a permissão antes.**
- `publicData($r['data'])`: só as chaves seguras para devolver ao navegador.
- Globais: `setBaseDirectory`, `setAllowedExtensions`, `setMaxFileSize`, `setMimeMap`, `setAllowedMimes`, `setCreateIndexFiles`, `setThrowExceptions`, `maxUploadSize()`, `formatBytes()`.
- Arquivo privado: pasta fora da web ou com `.htaccess` `Require all denied`; entregue por `stream()`.

## FileHelper (sistema de arquivos)

`exists` · `directoryExists` · `ensureDirectory` · `info` · `read($rel, $base, $maxBytes)` · `readJson` · `write($rel, $conteudo, $base, ['overwrite' => true, 'append' => false])` · `writeJson` · `append` · `copy/move($origem, $destino, $baseOrigem, $baseDestino, ['overwrite'])` · `delete` · `deleteDirectory($pasta, $base, ['recursive' => true])` · `listFiles($pasta, $base, ['recursive', 'filter' => '\.csv$', 'exclude', 'flat'])` · `listDirectories` · `cleanOlderThan($pasta, $dias)` · `createZip($zip, [$arquivos], $base)` · `stream($rel, $nome, $base, ['inline', 'mime'])` · `formatBytes` · `randomFileName('relatorio', 'pdf')`. Conteúdo lido fica em `$r['data']`.

## CsvHelper (qualquer CSV → banco)

Descobre sozinho a codificação (UTF-8, BOM, UTF-16, Windows-1252), o delimitador (`;` `,` tab `|`), a linha do cabeçalho, os nomes de coluna para o banco ("Descrição" → `descricao`) e o tipo de cada coluna ("1.234,56", datas BR).

```php
$a = CsvHelper::analyze($rel, $base);                  // mostrar ao usuário: colunas, tipos, avisos
$p = CsvHelper::preview($rel, $base, 10);              // primeiras linhas já convertidas

$r = CsvHelper::import($rel, '#__clientes', $base, [
    'mapping' => ['CPF/CNPJ' => 'documento', 'Nome Completo' => 'nome'],   // cabeçalho → coluna
    'required_columns' => ['documento', 'nome'],
    'rules' => ['documento' => 'required|cpf_cnpj', 'email' => 'nullable|email'],   // ValidationHelper
    'transform' => function ($dados, $linha, $bruto) { $dados['nome'] = mb_strtoupper($dados['nome']); return $dados; },   // false = pula
    'defaults' => ['importacao_id' => $id],
    'mode' => 'upsert', 'unique_by' => ['documento'],   // padrão 'insert'
    'atomic' => false,                                  // true = tudo ou nada
    'dry_run' => false,                                 // true = só valida
    'chunk_size' => 500, 'max_errors' => 1000, 'connection' => null,
]);
// $r['data']: total_rows, processed_rows, success_rows, skipped_rows, inserted_rows, updated_rows, errors_count, errors[], rolled_back, columns, warnings
CsvHelper::writeRejectedCsv('rejeitados.csv', $r['data']['errors'], $base);   // devolver ao usuário as linhas com erro
```
- `process($rel, function ($lote, $relatorio) {...}, $base, $op)`: lotes de `['line', 'data', 'raw']` para gravar do seu jeito (mesmas opções de leitura/validação).
- Outras opções: `header_row`, `has_header`, `columns`, `types`, `convert`, `empty_as_null`, `validate_row` (fn → `true` ou `['valid', 'first_error']`), `required_headers`, `stop_on_error`, `delimiter`, `encoding`, `on_progress`.
- Também: `detectEncoding`, `detectDelimiter`, `headers`, `setBaseDirectory`, `setMaxFileSize` (padrão 100 MB), `setDefaultChunkSize`.
- Erros com texto seguro para o usuário são `CsvHelperException`.
- CSV grande (mais de alguns segundos): rode o `import` dentro de um job da fila (ficha `fila.md`).

## ChunkHelper (arquivo `ChunkUploadHelper.php`, classe `ChunkHelper`)

> A versão atual tem um erro de sintaxe perto da linha 2003 (`'\'` → `'\\'`). Corrija antes de usar.

```php
$r = ChunkHelper::readLines($rel, function (array $linhas, array $meta) {
    // processa o lote; return ['stop' => true] para parar
}, $base, ['rows_per_chunk' => 1000, 'skip_first_line' => true, 'max_chunks' => 20]);

ChunkHelper::resumeLines($rel, 'checkpoints/import.json', $callback, $base, $op);   // continua de onde parou
ChunkHelper::readBytes($rel, $callback, $base, ['read_size' => 8388608]);
ChunkHelper::splitFile($rel, 'partes', $base, ['split_by_lines' => 10000]);         // ou max_bytes_per_file
ChunkHelper::deleteCheckpoint($checkpoint, $base); ChunkHelper::info($rel); ChunkHelper::formatBytes($n);
```

## ExportHelper (gerar CSV)

```php
$colunas = [
    'id' => 'ID',                                                       // chave => rótulo
    'total' => ['label' => 'Total', 'callback' => function ($linha, $i) { $linha = (array) $linha; return number_format($linha['total'], 2, ',', '.'); }],
    'Cliente' => function ($linha) { $linha = (array) $linha; return $linha['nome']; },   // rótulo => callback
];
ExportHelper::download('pedidos.csv', $linhas, $colunas, ['delimiter' => ';']);       // envia e encerra
ExportHelper::csv('exportacoes/pedidos.csv', $linhas, $colunas, $base, $op);           // grava em arquivo

$q = JFactory::getDbo()->getQuery(true)->select('*')->from('#__loja_pedidos');
ExportHelper::downloadFromQuery('pedidos.csv', $q, $colunas);           // lê em lotes (milhões de linhas)
ExportHelper::fromQuery('exportacoes/p.csv', $q, $colunas, $base);
```
Opções: `delimiter`, `enclosure`, `headers` (true), `overwrite`, `max_rows`, `protect_csv_injection` (ativo: neutraliza `=`, `+`, `-`, `@` no início), `on_row`, `on_progress`, `progress_every`. `generatorFromQuery($q, 1000)` itera linhas; `temporaryFileName('exportacao')`.
