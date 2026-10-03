#!/usr/bin/env bash
# batch-rename.sh — Renomeia arquivos em lote por padrão
# Uso: batch-rename.sh <diretório> <padrão-from> <padrão-to> [--dry-run|--apply] [--ext extensão]
#
# Exemplos:
#   batch-rename.sh ./resources/views ".html" ".blade.php" --dry-run
#   batch-rename.sh ./resources/views/components "old-" "" --ext blade.php
#   batch-rename.sh ./public/images " " "-" --dry-run
#
# Por padrão executa em --dry-run. Use --apply para aplicar.
# Ignora vendor/, node_modules/, .git/, storage/, bootstrap/cache/, public/build/.
#
# ATENÇÃO (PSR-4): renomear .php dentro de app/, database/ ou tests/ exige
# renomear também a classe e o namespace, e depois rodar `composer dump-autoload`.
# O script avisa quando detecta esse caso, mas não altera o conteúdo dos arquivos.

set -euo pipefail

DIR="${1:?Uso: batch-rename.sh <dir> <from> <to> [--apply] [--ext ext]}"
FROM="${2:?Padrão 'from' obrigatório}"
TO="${3?Padrão 'to' obrigatório (use \"\" para remover)}"
APPLY=0
EXT=""

shift 3
while [[ $# -gt 0 ]]; do
  case "$1" in
    --apply)   APPLY=1; shift ;;
    --dry-run) APPLY=0; shift ;;
    --ext)     EXT="${2#.}"; shift 2 ;;
    *) shift ;;
  esac
done

if [[ ! -d "$DIR" ]]; then
  echo "ERRO: diretório '$DIR' não existe" >&2
  exit 1
fi

FIND_ARGS=("$DIR" -type f)
for ex in vendor node_modules .git storage bootstrap/cache public/build; do
  FIND_ARGS+=(-not -path "*/$ex/*")
done
[[ -n "$EXT" ]] && FIND_ARGS+=(-name "*.$EXT")

COUNT=0
RENAMED=0
PSR4_HITS=0

while IFS= read -r -d '' file; do
  base=$(basename "$file")
  dir=$(dirname "$file")
  newbase="${base//"$FROM"/"$TO"}"

  if [[ "$base" != "$newbase" ]]; then
    COUNT=$((COUNT + 1))
    target="$dir/$newbase"

    if [[ "$base" == *.php && "$base" != *.blade.php && "$file" =~ /(app|database|tests)/ ]]; then
      PSR4_HITS=$((PSR4_HITS + 1))
    fi

    if [[ "$APPLY" -eq 1 ]]; then
      if [[ -e "$target" ]]; then
        echo "SKIP (já existe): $target" >&2
      else
        mv "$file" "$target"
        echo "RENAMED: $file → $target"
        RENAMED=$((RENAMED + 1))
      fi
    else
      echo "DRY-RUN: $file → $target"
    fi
  fi
done < <(find "${FIND_ARGS[@]}" -print0 2>/dev/null)

echo "---"
if [[ "$APPLY" -eq 1 ]]; then
  echo "Total renomeados: $RENAMED de $COUNT candidatos"
else
  echo "Total candidatos: $COUNT (use --apply para aplicar)"
fi

if [[ "$PSR4_HITS" -gt 0 ]]; then
  echo "AVISO PSR-4: $PSR4_HITS arquivo(s) de classe PHP em app/, database/ ou tests/." >&2
  echo "  Renomeie também a classe/namespace dentro do arquivo e rode: composer dump-autoload" >&2
fi
