#!/usr/bin/env bash
# find-files.sh — Busca rápida de arquivos por nome/padrão em projetos Laravel
# Uso: find-files.sh <padrão> [diretório] [--type f|d] [--ext extensão]
#
# Exemplos:
#   find-files.sh "*Controller.php" ./app
#   find-files.sh "invoice" ./resources/views --ext blade.php
#   find-files.sh "create_orders" ./database/migrations
#   find-files.sh "Policy" . --type f
#
# Saída: lista de paths ordenados. Ignora vendor/, node_modules/, .git/,
# storage/, bootstrap/cache/, public/build/, public/hot, dist/ e build/.

set -euo pipefail

PATTERN="${1:?Uso: find-files.sh <padrão> [dir] [--type f|d] [--ext ext]}"
DIR="${2:-.}"
TYPE=""
EXT=""

shift 2 2>/dev/null || shift 1 2>/dev/null || true

while [[ $# -gt 0 ]]; do
  case "$1" in
    --type) TYPE="$2"; shift 2 ;;
    --ext)  EXT="${2#.}"; shift 2 ;;
    *) shift ;;
  esac
done

# Pastas geradas/terceiros que nunca interessam numa busca de código Laravel
EXCLUDES=(vendor node_modules .git storage bootstrap/cache public/build dist build)

IS_GLOB=0
[[ "$PATTERN" == *"*"* || "$PATTERN" == *"?"* ]] && IS_GLOB=1

FD_BIN=""
command -v fd >/dev/null 2>&1 && FD_BIN="fd"
[[ -z "$FD_BIN" ]] && command -v fdfind >/dev/null 2>&1 && FD_BIN="fdfind"

if [[ -n "$FD_BIN" ]]; then
  CMD=("$FD_BIN" --hidden --no-ignore-vcs)
  for ex in "${EXCLUDES[@]}"; do CMD+=(--exclude "$ex"); done
  [[ -n "$TYPE" ]] && CMD+=(--type "$TYPE")
  # --extension do fd não entende "blade.php"; usamos glob no nome
  if [[ -n "$EXT" && "$IS_GLOB" -eq 0 ]]; then
    CMD+=(--glob "*${PATTERN}*.${EXT}" "$DIR")
  elif [[ -n "$EXT" ]]; then
    CMD+=(--glob "$PATTERN" "$DIR")
  elif [[ "$IS_GLOB" -eq 1 ]]; then
    CMD+=(--glob "$PATTERN" "$DIR")
  else
    CMD+=(--ignore-case "$PATTERN" "$DIR")
  fi
  "${CMD[@]}" 2>/dev/null | sort || true
else
  FIND_ARGS=("$DIR")
  for ex in "${EXCLUDES[@]}"; do FIND_ARGS+=(-not -path "*/$ex/*" -not -path "*/$ex"); done
  [[ -n "$TYPE" ]] && FIND_ARGS+=(-type "$TYPE")
  if [[ "$IS_GLOB" -eq 1 ]]; then
    FIND_ARGS+=(-name "$PATTERN")
  else
    FIND_ARGS+=(-iname "*${PATTERN}*")
  fi
  [[ -n "$EXT" ]] && FIND_ARGS+=(-name "*.${EXT}")
  find "${FIND_ARGS[@]}" 2>/dev/null | sort
fi
