#!/usr/bin/env bash
# Imprime a pasta dos helpers. Nenhum caminho fixo: funciona em qualquer
# máquina, pasta de projeto ou worktree (Claude Code, Codex, Orca…).
#
#   _repo.sh           para CONSULTAR a API: prefere a cópia que o projeto atual usa
#   _repo.sh --fonte   para INSTALAR: só a versão oficial (nunca a cópia do projeto)
#
# Ordem:
#   1. $HELPERS_JOOMLA_DIR
#   2. (sem --fonte) helpers dentro do projeto atual (busca a partir da raiz do git ou da pasta atual)
#   3. o repositório onde esta skill está, se ela estiver dentro de um clone do helpers-joomla
#   4. clone em cache: ${XDG_CACHE_HOME:-~/.cache}/helpers-joomla (baixado do GitHub na 1ª vez)
set -euo pipefail

valido() { [ -n "$1" ] && [ -f "$1/OrmBase.php" ] && [ -f "$1/ApiResponseHelper.php" ]; }
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

if [ -n "${HELPERS_JOOMLA_DIR:-}" ]; then
    valido "$HELPERS_JOOMLA_DIR" && { echo "$HELPERS_JOOMLA_DIR"; exit 0; }
    echo "HELPERS_JOOMLA_DIR não contém os helpers: $HELPERS_JOOMLA_DIR" >&2; exit 1
fi

raiz="$(git rev-parse --show-toplevel 2>/dev/null || pwd)"
if [ "${1:-}" != "--fonte" ] && [ "$raiz" != "$HOME" ] && [ "$raiz" != "/" ]; then
    achado="$(find "$raiz" -maxdepth 6 -name OrmBase.php \
        -not -path '*/node_modules/*' -not -path '*/vendor/*' -not -path '*/.git/*' 2>/dev/null | head -1)"
    if [ -n "$achado" ] && valido "$(dirname "$achado")"; then
        dirname "$achado"; exit 0
    fi
fi

# Skill dentro de um clone do repositório: <repo>/skills/joomla-helpers/scripts
repo="$(cd "$AQUI/../../.." 2>/dev/null && pwd -P || true)"
valido "$repo" && { echo "$repo"; exit 0; }

CACHE="${XDG_CACHE_HOME:-$HOME/.cache}/helpers-joomla"
if valido "$CACHE"; then
    # Atualiza no máximo uma vez por dia; offline segue com o que tem.
    if [ -z "$(find "$CACHE/.git/FETCH_HEAD" -mmin -1440 2>/dev/null)" ]; then
        git -C "$CACHE" pull -q --ff-only >/dev/null 2>&1 || touch "$CACHE/.git/FETCH_HEAD" 2>/dev/null || true
    fi
    echo "$CACHE"; exit 0
fi

mkdir -p "$(dirname "$CACHE")"
if git clone -q --depth 1 https://github.com/heuderdev/helpers-joomla.git "$CACHE" >&2 && valido "$CACHE"; then
    echo "$CACHE"; exit 0
fi
echo "Helpers não encontrados. Defina HELPERS_JOOMLA_DIR=/caminho/do/helpers-joomla ou verifique o acesso ao GitHub." >&2
exit 1
