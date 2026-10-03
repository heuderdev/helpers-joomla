#!/usr/bin/env bash
# render.sh — renderiza uma comp de um .aep via aerender (sem abrir a interface).
#
# Duas armadilhas já confirmadas na maquina do Eduardo:
#   1. aerender resolve caminho relativo a partir da PASTA DO BINARIO, nao do
#      cwd -> convertemos tudo para absoluto.
#   2. -RStemplate "Best Settings" / -OMtemplate "Lossless" FALHAM: o AE esta
#      em portugues e os templates tem nome traduzido. Omitindo, usa o padrao
#      e sai um H.264 limpo.
set -euo pipefail

AERENDER="${AERENDER:-/Applications/Adobe After Effects 2026/aerender}"
PROJETO="${1:?uso: render.sh <projeto.aep> <comp> <saida.mp4>}"
COMP="${2:?falta o nome da comp}"
SAIDA="${3:?falta o arquivo de saida}"

[[ -x "$AERENDER" ]] || { echo "erro: aerender nao encontrado em $AERENDER" >&2; exit 1; }
[[ -f "$PROJETO" ]]  || { echo "erro: projeto nao encontrado: $PROJETO" >&2; exit 1; }

# Absolutiza (exigencia do aerender)
PROJETO="$(cd "$(dirname "$PROJETO")" && pwd)/$(basename "$PROJETO")"
mkdir -p "$(dirname "$SAIDA")"
SAIDA="$(cd "$(dirname "$SAIDA")" && pwd)/$(basename "$SAIDA")"

echo "==> renderizando '$COMP' -> $SAIDA" >&2
log="$(mktemp)"
"$AERENDER" -project "$PROJETO" -comp "$COMP" -output "$SAIDA" 2>&1 | tee "$log" \
  | grep -iE "PROGRESS: +[0-9]+%|ERROR|Tempo total|conclu" || true

if grep -qiE "aerender ERROR|aerender Error" "$log"; then
  echo "erro: falha no render. Log completo:" >&2
  grep -iE "error" "$log" >&2 | head -20
  rm -f "$log"; exit 2
fi
rm -f "$log"

# aerender pode trocar a extensao conforme o modulo de saida.
real="$SAIDA"
[[ -f "$real" ]] || real="$(ls "${SAIDA%.*}".* 2>/dev/null | head -1 || true)"
[[ -n "$real" && -f "$real" ]] || { echo "erro: arquivo de saida nao foi gerado" >&2; exit 3; }

echo "$real"
