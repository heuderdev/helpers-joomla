#!/usr/bin/env bash
# Copia helpers (com as dependências) para a pasta de helpers de um componente.
#   instalar.sh <destino> OrmTables ApiResponseHelper ...
#   instalar.sh <destino> --todos
#   --forcar  sobrescreve arquivos que diferem da versão do repositório
# Ex.: instalar.sh components/com_loja/helpers OrmTables InputHelper QueueHelper
set -euo pipefail
ORIGEM="$(bash "$(dirname "$0")/_repo.sh" --fonte)"
DESTINO="${1:?uso: instalar.sh <destino> Helper... | --todos [--forcar]}"; shift

declare -A DEP=(
    [OrmTables]="OrmBase"
    [OrmBase]="DbConnectionHelper DbTransactionHelper DateHelper"
    [DbTransactionHelper]="DbConnectionHelper LogHelper"
    [AuditHelper]="OrmTables LogHelper"
    [CsvHelper]="OrmTables DbTransactionHelper ValidationHelper LogHelper"
    [ApiResponseHelper]="LogHelper"
    [PermissionHelper]="ApiResponseHelper LogHelper"
    [FileHelper]="LogHelper"
    [QueueHelper]="DateHelper"
    [HttpHelper]="LogHelper"
    [LockHelper]="LogHelper DbConnectionHelper"
    [CacheHelper]="LogHelper LockHelper"
    [MailHelper]="LogHelper"
    [RateLimitHelper]="LogHelper ApiResponseHelper DbConnectionHelper"
    [CryptoHelper]="LogHelper"
)
TODOS="LogHelper DateHelper InputHelper ValidationHelper DbConnectionHelper DbTransactionHelper OrmBase OrmTables ApiResponseHelper PermissionHelper FileHelper UploadMaster AuditHelper CsvHelper ChunkUploadHelper ExportHelper QueueHelper HttpHelper LockHelper CacheHelper MailHelper RateLimitHelper CryptoHelper FormatHelper IncludeHelper"

FORCAR=0; PEDIDOS=()
for a in "$@"; do
    case "$a" in
        --forcar) FORCAR=1 ;;
        --todos) PEDIDOS+=($TODOS) ;;
        ChunkHelper) PEDIDOS+=(ChunkUploadHelper) ;;
        *) PEDIDOS+=("$a") ;;
    esac
done
[ ${#PEDIDOS[@]} -gt 0 ] || { echo "Informe ao menos um helper (ou --todos)." >&2; exit 1; }

declare -A VISTO=(); ORDEM=()
resolver() {   # dependências antes de quem depende
    [ -n "${VISTO[$1]:-}" ] && return; VISTO[$1]=1
    [ -f "$ORIGEM/$1.php" ] || { echo "Helper desconhecido: $1" >&2; exit 1; }
    for d in ${DEP[$1]:-}; do resolver "$d"; done
    ORDEM+=("$1")
}
for h in "${PEDIDOS[@]}"; do resolver "$h"; done

ARQUIVOS=()
for h in "${ORDEM[@]}"; do
    ARQUIVOS+=("$h.php")
    [ "$h" = QueueHelper ] && ARQUIVOS+=(fila/AbstractJob.php fila/JobRegistry.php fila/QueueWorker.php fila/cli/queue-worker.php fila/table.sql fila/table.postgresql.sql)
    [ "$h" = AuditHelper ] && ARQUIVOS+=(tables/auditHelper.sql)
    [ "$h" = MailHelper ] && ARQUIVOS+=(fila/jobs/MailJob.php)
    [ "$h" = RateLimitHelper ] && ARQUIVOS+=(tables/rateLimitHelper.sql tables/rateLimitHelper.postgresql.sql)
done

mkdir -p "$DESTINO"
novos=0; iguais=0; mantidos=0
for f in "${ARQUIVOS[@]}"; do
    alvo="$DESTINO/$f"; mkdir -p "$(dirname "$alvo")"
    if [ -f "$alvo" ] && cmp -s "$ORIGEM/$f" "$alvo"; then
        iguais=$((iguais + 1)); continue
    fi
    if [ -f "$alvo" ] && [ $FORCAR -eq 0 ]; then
        echo "MANTIDO (difere do repositório; use --forcar para substituir): $alvo"
        mantidos=$((mantidos + 1)); continue
    fi
    cp "$ORIGEM/$f" "$alvo"; novos=$((novos + 1))
done

echo "Copiados: $novos · já atualizados: $iguais · mantidos: $mantidos · destino: $DESTINO"
echo "Carregar (só os que você usa diretamente; os helpers carregam as próprias dependências):"
for h in "${PEDIDOS[@]}"; do
    [ "$h" = ChunkUploadHelper ] && c="ChunkHelper" || c="$h"
    echo "  require_once JPATH_COMPONENT . '/helpers/$h.php';   // $c"
done
