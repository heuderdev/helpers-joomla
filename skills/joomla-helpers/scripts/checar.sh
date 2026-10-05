#!/usr/bin/env bash
# Confere código PHP gerado/refatorado: sintaxe, PHP 7.0 e padrões que os helpers substituem.
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

# padrão§mensagem  (PHP 7.1+ não roda no PHP 7.0 dos servidores Joomla 3)
PHP71=(
    '\?(int|string|bool|float|array|self|callable|iterable|object|[A-Z][A-Za-z_]+) \$§tipo anulável (?tipo) exige PHP 7.1'
    'function\b[^;{]*\)\s*:\s*\??[A-Za-z_\\]+§tipo de retorno declarado: evite (void e ?tipo exigem 7.1; os helpers não usam)'
    '\bfn\s*\(§arrow function exige PHP 7.4'
    '\?\?=§??= exige PHP 7.4'
    '\?->§nullsafe ?-> exige PHP 8'
    '\bmatch\s*\(§match exige PHP 8'
    '^\s*\[[^]=]*\]\s*=[^=>]§desestruturação [$a, $b] = exige PHP 7.1 (use list())'
    'catch\s*\([^)]*\|§catch múltiplo (A | B) exige PHP 7.1'
    '(private|protected|public)\s+const\b§visibilidade em constante exige PHP 7.1'
    '(private|protected|public)\s+(static\s+)?(int|string|bool|float|array|\?[a-z]+)\s+\$§propriedade tipada exige PHP 7.4'
    'str_contains|str_starts_with|str_ends_with|array_key_first|array_key_last§função de PHP 7.3+/8'
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
    'catch\s*\(\s*Exception\b§capture Throwable (pega também Error do PHP 7)'
)

for f in "${ARQS[@]}"; do
    [ -f "$f" ] || { echo "não encontrado: $f"; continue; }
    if ! saida=$(php -l "$f" 2>&1); then
        erro "$f" 0 "$(echo "$saida" | grep -m1 -i error)"; continue
    fi
    for regra in "${PHP71[@]}"; do
        while IFS=: read -r n _; do [ -n "$n" ] && erro "$f" "$n" "${regra#*§}"; done \
            < <(grep -nE -- "${regra%%§*}" "$f" | grep -vE '^\s*[0-9]+:\s*(\*|//|#)')
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
