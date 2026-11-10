#!/usr/bin/env bash
# Confere código PHP gerado/refatorado: sintaxe, PHP 8.2+ e padrões que os helpers substituem.
#   checar.sh arquivo.php [pasta ...]
# Saída: ERRO (precisa corrigir) e AVISO (revise). Código de saída 1 se houver ERRO.
set -uo pipefail
[ $# -gt 0 ] || { echo "uso: checar.sh <arquivo|pasta>..." >&2; exit 2; }

mapfile -t ARQS < <(for a in "$@"; do
    if [ -d "$a" ]; then find "$a" -name '*.php' -not -path '*/vendor/*'; else echo "$a"; fi
done)

erros=0; avisos=0
erro()  { echo "ERRO  $1:$2  $3"; erros=$((erros + 1)); }
aviso() { echo "AVISO $1:$2  $3"; avisos=$((avisos + 1)); }

# padrão§mensagem  (recursos do PHP 8.3+ não rodam no mínimo do projeto, PHP 8.2)
PHP83=(
    '\bconst\s+(int|string|bool|float|array|mixed|object|iterable|\?[a-z]+|[A-Z][A-Za-z_\\]+)\s+[A-Za-z_][A-Za-z0-9_]*\s*=§constante de classe tipada exige PHP 8.3'
    '#\[\\?Override\b§atributo #[Override] exige PHP 8.3'
    '::\{\$§acesso dinâmico a constante (Classe::{$x}) exige PHP 8.3'
    '\b(json_validate|mb_str_pad|str_increment|str_decrement)\s*\(§função de PHP 8.3'
    '(public|protected)\s+(private|protected)\(set\)§visibilidade assimétrica exige PHP 8.4'
    '\bnew\s+[A-Za-z_\\]+\([^()]*\)\s*->§new Classe()->metodo() sem parênteses em volta exige PHP 8.4: use (new Classe())->metodo()'
    '\b(array_find|array_find_key|array_any|array_all|mb_trim|mb_ltrim|mb_rtrim|mb_ucfirst|mb_lcfirst|bcround|bcfloor|bcceil|request_parse_body)\s*\(§função de PHP 8.4'
    '#\[\\?Deprecated\b§atributo #[Deprecated] exige PHP 8.4'
    '\|>§operador pipe |> exige PHP 8.5'
    '\b(array_first|array_last)\s*\(§função de PHP 8.5'
    '#\[\\?NoDiscard\b§atributo #[NoDiscard] exige PHP 8.5'
)

# padrão§mensagem  (obsoletos no PHP 8.2+: funcionam, mas geram aviso)
OBSOLETOS=(
    '\b(utf8_encode|utf8_decode)\s*\(§utf8_encode/utf8_decode obsoletos no PHP 8.2: use mb_convert_encoding($v, "UTF-8", "ISO-8859-1")'
    '"[^"]*\$\{[A-Za-z_]§interpolação "${var}" obsoleta no PHP 8.2: use "{$var}"'
    '[(,]\s*[A-Za-z_\\][A-Za-z0-9_\\]*\s+&?\$[A-Za-z_][A-Za-z0-9_]*\s*=\s*null\b§parâmetro tipado com = null sem ? é obsoleto no PHP 8.4: use ?Tipo $x = null'
)

# padrão§mensagem  (oportunidades de usar os helpers / riscos)
HELPERS=(
    '\$_(GET|POST|REQUEST)\b§leia a entrada com InputHelper (tipo e filtro corretos)'
    'getInput\(\)->get|->input->get§considere InputHelper::string/int/... (tipo explícito)'
    'echo\s+json_encode§responda com ApiResponseHelper (status HTTP, cabeçalhos, encerramento)'
    'move_uploaded_file§use UploadMaster::upload (confere o tipo real e o tamanho)'
    'fputcsv§para exportar, ExportHelper; para gerar arquivo, FileHelper'
    'fgetcsv§para importar CSV, CsvHelper (codificação, delimitador, tipos, validação)'
    'transactionStart\(\)§use DbTransactionHelper::run (savepoints, rollback e retry de deadlock)'
    'LogHelper::write§LogHelper::write é privado: use LogHelper::info/error/exception'
    'set_time_limit\(0\)§tarefa longa: considere a fila (QueueHelper)'
    "date\\(\\s*['\"]Y-m-d H:i(:s)?['\"]\\s*\\)§date() usa o fuso do servidor: para gravar no banco use DateHelper::nowSql() (UTC); para mostrar, DateHelper::toUser()"
    'catch\s*\(\s*Exception\b§capture Throwable (pega também Error: TypeError, ValueError...)'
)

for f in "${ARQS[@]}"; do
    [ -f "$f" ] || { echo "não encontrado: $f"; continue; }
    if ! saida=$(php -l "$f" 2>&1); then
        erro "$f" 0 "$(echo "$saida" | grep -m1 -i error)"; continue
    fi
    for regra in "${PHP83[@]}"; do
        while IFS=: read -r n _; do [ -n "$n" ] && erro "$f" "$n" "${regra#*§}"; done \
            < <(grep -nE -- "${regra%%§*}" "$f" | grep -vE '^[0-9]+:\s*(\*|//|# )')
    done
    for regra in "${OBSOLETOS[@]}"; do
        while IFS=: read -r n _; do [ -n "$n" ] && aviso "$f" "$n" "${regra#*§}"; done \
            < <(grep -nE -- "${regra%%§*}" "$f" | grep -vE '^[0-9]+:\s*(\*|//|# )')
    done
    for regra in "${HELPERS[@]}"; do
        while IFS=: read -r n _; do [ -n "$n" ] && aviso "$f" "$n" "${regra#*§}"; done \
            < <(grep -nE -- "${regra%%§*}" "$f" | grep -vE '^\s*[0-9]+:\s*(\*|//|#)')
    done
    # SQL montado com variável concatenada
    while IFS=: read -r n _; do [ -n "$n" ] && aviso "$f" "$n" "SQL com variável concatenada: use where()/quote() do ORM"; done \
        < <(grep -nE "(setQuery|whereRaw|where)\s*\(\s*['\"][^'\"]*(SELECT|UPDATE|DELETE|INSERT|=|LIKE)[^'\"]*['\"]\s*\.\s*\\\$" "$f")
    # task de escrita sem checagem de token
    if grep -qE "extends +(JControllerLegacy|JControllerForm|JControllerAdmin|BaseController|FormController|AdminController)" "$f" \
        && grep -qE "public function (salvar|save|excluir|delete|remover|importar|apply|publish)\b" "$f" \
        && ! grep -qE "checkToken|Session::checkToken|requireToken" "$f"; then
        aviso "$f" 0 "há tarefa de escrita sem JSession::checkToken() ou PermissionHelper::requireToken()"
    fi
done

echo "---- ${#ARQS[@]} arquivo(s): $erros erro(s), $avisos aviso(s)"
[ $erros -eq 0 ]
