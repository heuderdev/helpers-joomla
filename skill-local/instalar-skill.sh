#!/usr/bin/env bash
# Instala esta skill nos agentes.
#   ./instalar-skill.sh                 Claude Code e Codex (global, no seu usuário)
#   ./instalar-skill.sh --projeto DIR   só no projeto DIR (.claude/skills, .codex/skills e .agents/skills)
#   ./instalar-skill.sh --destino DIR   numa pasta de skills qualquer (ex.: a do Orca)
# Usa link simbólico: atualizar este repositório atualiza a skill. --copiar faz cópia.
set -euo pipefail
ORIGEM="$(cd "$(dirname "$0")" && pwd)"
NOME=joomla-helpers
MODO=link; DESTINOS=()

while [ $# -gt 0 ]; do
    case "$1" in
        --copiar) MODO=copia ;;
        --projeto) DESTINOS+=("$2/.claude/skills" "$2/.codex/skills" "$2/.agents/skills"); shift ;;
        --destino) DESTINOS+=("$2"); shift ;;
        *) echo "opção desconhecida: $1" >&2; exit 1 ;;
    esac
    shift
done
[ ${#DESTINOS[@]} -gt 0 ] || DESTINOS=("$HOME/.claude/skills" "${CODEX_HOME:-$HOME/.codex}/skills")

for d in "${DESTINOS[@]}"; do
    mkdir -p "$d"; alvo="$d/$NOME"
    if [ -e "$alvo" ] || [ -L "$alvo" ]; then
        if [ -L "$alvo" ] && [ "$(readlink "$alvo")" = "$ORIGEM" ]; then echo "já instalada: $alvo"; continue; fi
        echo "existe e não é esta skill (não mexi): $alvo"; continue
    fi
    if [ $MODO = link ]; then ln -s "$ORIGEM" "$alvo"; else cp -r "$ORIGEM" "$alvo"; fi
    echo "instalada ($MODO): $alvo"
done
chmod +x "$ORIGEM"/scripts/*.sh
