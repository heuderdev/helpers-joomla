#!/usr/bin/env bash
# grep-code.sh — Busca conteúdo em código Laravel (preferindo ripgrep)
# Uso: grep-code.sh <padrão> [diretório] [--ext extensão] [--type tipo] [--count]
#
# Tipos (--type): php, blade, js, css, migration, route, config, test
#   php        → *.php (exceto views Blade)
#   blade      → *.blade.php
#   migration  → database/migrations/*.php
#   route      → routes/*.php
#   config     → config/*.php
#   test       → tests/**/*.php
#
# Exemplos:
#   grep-code.sh "class .*Controller" ./app --type php
#   grep-code.sh "x-data" ./resources/views --type blade
#   grep-code.sh "->hasMany\(" ./app/Models
#   grep-code.sh "TODO|FIXME" . --count
#   grep-code.sh "Route::(get|post)" . --type route
#
# Ignora vendor/, node_modules/, .git/, storage/, bootstrap/cache/, public/build/.

set -euo pipefail

PATTERN="${1:?Uso: grep-code.sh <padrão> [dir] [--ext ext] [--type tipo] [--count]}"
DIR="${2:-.}"
EXT=""
TYPE=""
COUNT=0

shift 2 2>/dev/null || shift 1 2>/dev/null || true

while [[ $# -gt 0 ]]; do
  case "$1" in
    --ext)   EXT="${2#.}"; shift 2 ;;
    --type)  TYPE="$2"; shift 2 ;;
    --count) COUNT=1; shift ;;
    *) shift ;;
  esac
done

EXCLUDES=(vendor node_modules .git storage bootstrap/cache public/build dist build)

# Globs de inclusão/exclusão por tipo lógico do Laravel
INCLUDE=()
EXCLUDE_FILES=()
case "$TYPE" in
  "")        ;;
  php)       INCLUDE=("*.php"); EXCLUDE_FILES=("*.blade.php") ;;
  blade)     INCLUDE=("*.blade.php") ;;
  js)        INCLUDE=("*.js" "*.mjs") ;;
  css)       INCLUDE=("*.css") ;;
  migration) INCLUDE=("**/database/migrations/*.php") ;;
  route)     INCLUDE=("**/routes/*.php") ;;
  config)    INCLUDE=("**/config/*.php") ;;
  test)      INCLUDE=("**/tests/**/*.php") ;;
  *)         INCLUDE=("*.${TYPE}") ;;
esac
[[ -n "$EXT" ]] && INCLUDE=("*.${EXT}")

if command -v rg >/dev/null 2>&1; then
  CMD=(rg --hidden --line-number --color never)
  for ex in "${EXCLUDES[@]}"; do CMD+=(--glob "!**/$ex/**"); done
  for g in "${INCLUDE[@]}"; do CMD+=(--glob "$g"); done
  for g in "${EXCLUDE_FILES[@]}"; do CMD+=(--glob "!$g"); done
  [[ "$COUNT" -eq 1 ]] && CMD+=(--count)
  CMD+=(-e "$PATTERN" "$DIR")
  "${CMD[@]}" || true
else
  GREP_ARGS=(-rnE)
  for ex in "${EXCLUDES[@]}"; do GREP_ARGS+=(--exclude-dir="${ex##*/}"); done
  for g in "${EXCLUDE_FILES[@]}"; do GREP_ARGS+=(--exclude="$g"); done
  [[ "$COUNT" -eq 1 ]] && GREP_ARGS+=(-c)

  # grep não entende "**/pasta/*.php": traduz para diretório + --include
  SEARCH_DIRS=("$DIR")
  case "$TYPE" in
    migration) SEARCH_DIRS=("$DIR/database/migrations"); INCLUDE=("*.php") ;;
    route)     SEARCH_DIRS=("$DIR/routes");              INCLUDE=("*.php") ;;
    config)    SEARCH_DIRS=("$DIR/config");              INCLUDE=("*.php") ;;
    test)      SEARCH_DIRS=("$DIR/tests");               INCLUDE=("*.php") ;;
  esac
  [[ -n "$EXT" ]] && INCLUDE=("*.${EXT}")
  for g in "${INCLUDE[@]}"; do GREP_ARGS+=(--include="$g"); done

  EXISTING=()
  for d in "${SEARCH_DIRS[@]}"; do [[ -e "$d" ]] && EXISTING+=("$d"); done
  [[ ${#EXISTING[@]} -eq 0 ]] && { echo "Nada para buscar: ${SEARCH_DIRS[*]} não existe" >&2; exit 0; }

  if [[ "$COUNT" -eq 1 ]]; then
    grep "${GREP_ARGS[@]}" -e "$PATTERN" "${EXISTING[@]}" 2>/dev/null | grep -v ':0$' || true
  else
    grep "${GREP_ARGS[@]}" -e "$PATTERN" "${EXISTING[@]}" 2>/dev/null || true
  fi
fi
