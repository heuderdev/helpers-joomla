#!/usr/bin/env bash
# Descobre a pasta dos helpers e imprime o caminho.
# Ordem: $HELPERS_JOOMLA_DIR → /developer/helpersJoomla → cache clonado do GitHub.
set -euo pipefail

valido() { [ -f "$1/OrmBase.php" ] && [ -f "$1/QueueHelper.php" ]; }

if [ -n "${HELPERS_JOOMLA_DIR:-}" ] && valido "$HELPERS_JOOMLA_DIR"; then
    echo "$HELPERS_JOOMLA_DIR"; exit 0
fi

if valido /developer/helpersJoomla; then
    echo /developer/helpersJoomla; exit 0
fi

CACHE="${XDG_CACHE_HOME:-$HOME/.cache}/helpers-joomla"

if valido "$CACHE"; then
    # Atualiza no máximo uma vez por dia, sem travar se estiver offline.
    if [ -z "$(find "$CACHE/.git/FETCH_HEAD" -mmin -1440 2>/dev/null)" ]; then
        git -C "$CACHE" pull -q --ff-only >/dev/null 2>&1 || true
    fi
    echo "$CACHE"; exit 0
fi

git clone -q --depth 1 https://github.com/heuderdev/helpers-joomla.git "$CACHE" >&2 \
    || { echo "Não encontrei os helpers. Defina HELPERS_JOOMLA_DIR=/caminho/helpers-joomla" >&2; exit 1; }
echo "$CACHE"
