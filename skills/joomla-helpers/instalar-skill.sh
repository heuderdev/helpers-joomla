#!/usr/bin/env bash
# Instala esta skill sem depender de onde o repositório está.
# Prefira:  npx skills add heuderdev/helpers-joomla --skill joomla-helpers   (Claude Code, Codex, Orca…)
# Este script é a alternativa offline/manual, rodado de dentro de um clone:
#   ./instalar-skill.sh                 global: ~/.claude/skills, ~/.codex/skills e ~/.agents/skills
#   ./instalar-skill.sh --projeto DIR   num projeto: DIR/.claude/skills (Claude) e DIR/.agents/skills (Codex e padrão aberto)
#   ./instalar-skill.sh --destino DIR   numa pasta de skills qualquer
#   --copiar   copia em vez de criar link (para o projeto versionar a skill junto)
set -euo pipefail
ORIGEM="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
NOME="$(basename "$ORIGEM")"
MODO=link; DESTINOS=()

while [ $# -gt 0 ]; do
    case "$1" in
        --copiar) MODO=copia ;;
        --projeto) DESTINOS+=("$2/.claude/skills" "$2/.agents/skills"); shift ;;
        --destino) DESTINOS+=("$2"); shift ;;
        -h|--help) sed -n '2,9p' "$0"; exit 0 ;;
        *) echo "opção desconhecida: $1" >&2; exit 1 ;;
    esac
    shift
done
[ ${#DESTINOS[@]} -gt 0 ] || DESTINOS=("$HOME/.claude/skills" "${CODEX_HOME:-$HOME/.codex}/skills" "$HOME/.agents/skills")

for d in "${DESTINOS[@]}"; do
    mkdir -p "$d"; alvo="$d/$NOME"
    if [ -L "$alvo" ] && [ "$(readlink -f "$alvo")" = "$ORIGEM" ]; then echo "já instalada: $alvo"; continue; fi
    if [ -e "$alvo" ] || [ -L "$alvo" ]; then echo "já existe outra versão (não mexi): $alvo"; continue; fi
    if [ $MODO = link ]; then ln -s "$ORIGEM" "$alvo"; else cp -r "$ORIGEM" "$alvo"; fi
    echo "instalada ($MODO): $alvo"
done
chmod +x "$ORIGEM"/scripts/*.sh 2>/dev/null || true
