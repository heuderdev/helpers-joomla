#!/usr/bin/env bash
# API pública de um helper, sem ler o arquivo inteiro.
#   api.sh                      lista os helpers
#   api.sh OrmBase              assinaturas públicas (uma por linha)
#   api.sh OrmBase paginate     comentário + assinatura + início do corpo (--corpo: inteiro)
#   api.sh CsvHelper --busca upsert   linhas que mencionam o termo (com número)
set -euo pipefail
DIR="$(bash "$(dirname "$0")/_repo.sh")"

if [ $# -eq 0 ]; then
    (cd "$DIR" && ls *.php fila/*.php | sed 's/\.php$//')
    exit 0
fi

NOME="$1"
[ "$NOME" = ChunkHelper ] && NOME=ChunkUploadHelper
ARQ="$DIR/$NOME.php"
[ -f "$ARQ" ] || ARQ="$DIR/fila/$NOME.php"
[ -f "$ARQ" ] || { echo "Helper não encontrado: $1 (rode api.sh sem argumentos)" >&2; exit 1; }

if [ $# -eq 1 ]; then
    grep -nE '^[[:space:]]*public (static )?function ' "$ARQ" \
        | sed -E 's/^([0-9]+):\s*public (static )?function /\1: /; s/\)\s*$/)/'
    exit 0
fi

if [ "$2" = "--busca" ]; then
    grep -niE -- "${3:?informe o termo}" "$ARQ" | cut -c1-200 | head -40
    exit 0
fi

# Método: comentário logo acima + assinatura + início do corpo (--corpo: inteiro).
LIMITE=25
[ "${3:-}" = "--corpo" ] && LIMITE=400
awk -v m="$2" -v limite="$LIMITE" '
    /^[ \t]*\/\*/ { com = ""; dentro = 1 }
    dentro { com = com $0 "\n"; if ($0 ~ /\*\//) dentro = 0; next }
    !achou && $0 ~ ("function " m "\\(") && $0 ~ /public/ { achou = 1; printf "%s", com; nivel = 0 }
    achou {
        print
        a = gsub(/\{/, "{"); f = gsub(/\}/, "}"); nivel += a - f
        if (a + f > 0 && nivel <= 0) exit
        if (++linhas >= limite) { print "    // ... (corpo completo: api.sh " ARGV_NOME " " m " --corpo)"; exit }
        next
    }
    !/^[ \t]*$/ { com = "" }
' ARGV_NOME="$1" "$ARQ"
