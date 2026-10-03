#!/usr/bin/env bash
# criar.sh — gera vídeo(s) a partir de roteiro(s) JSON usando o After Effects.
#
#   criar.sh exemplo            # roteiros/exemplo.json
#   criar.sh --todos            # todos os roteiros
#   criar.sh exemplo --so-aep   # só monta o .aep, sem renderizar
set -euo pipefail

SKILL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SAIDA="${AE_SAIDA:-$HOME/Downloads/ae-videos}"
ROTEIROS="$SKILL/roteiros"

extras=()
alvos=()
for arg in "$@"; do
  case "$arg" in
    --todos) ;;
    --*)     extras+=("$arg") ;;
    *)       alvos+=("$arg") ;;
  esac
done

if [[ " $* " == *" --todos "* ]]; then
  alvos=()
  for f in "$ROTEIROS"/*.json; do
    [[ -e "$f" ]] || { echo "nenhum roteiro em $ROTEIROS" >&2; exit 1; }
    alvos+=("$(basename "$f" .json)")
  done
fi

if [[ ${#alvos[@]} -eq 0 ]]; then
  echo "uso: criar.sh <id-do-roteiro> | --todos  [--so-aep]" >&2
  echo "roteiros disponiveis:" >&2
  ls "$ROTEIROS"/*.json 2>/dev/null | xargs -n1 basename 2>/dev/null | sed 's/\.json$/  /' >&2 || echo "  (nenhum)" >&2
  exit 1
fi

mkdir -p "$SAIDA"
for id in "${alvos[@]}"; do
  roteiro="$ROTEIROS/$id.json"
  [[ -f "$roteiro" ]] || { echo "roteiro nao encontrado: $roteiro" >&2; exit 1; }
  echo "=== $id ===" >&2
  node "$SKILL/lib/gerar.mjs" "$roteiro" --saida "$SAIDA" "${extras[@]+"${extras[@]}"}"
done

echo "==> saida em $SAIDA" >&2
