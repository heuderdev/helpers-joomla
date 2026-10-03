#!/usr/bin/env bash
# rodar.sh — executa um .jsx no After Effects e devolve o que ele reportou.
#
# Existe porque chamar o AE por AppleScript tem três armadilhas que já custaram
# caro nos testes:
#   1. AppleEvent expira em 60s por padrão  -> "with timeout"
#   2. Qualquer diálogo modal trava o AE    -> detectamos em vez de pendurar
#   3. Erro de script não volta pelo stdout -> o .jsx grava um relatório
set -euo pipefail

AE_APP="${AE_APP:-Adobe After Effects 2026}"
JSX="${1:?uso: rodar.sh <script.jsx> [timeout_segundos]}"
TIMEOUT="${2:-900}"

[[ -f "$JSX" ]] || { echo "erro: script nao encontrado: $JSX" >&2; exit 1; }
JSX="$(cd "$(dirname "$JSX")" && pwd)/$(basename "$JSX")"

# O AE precisa estar aberto: abrir do zero leva ~1min e o AppleEvent expira.
if ! pgrep -qf "After Effects"; then
  echo "==> After Effects nao esta aberto. Abrindo..." >&2
  open -a "$AE_APP"
  for _ in $(seq 1 60); do
    sleep 2
    pgrep -qf "After Effects" && break
  done
  echo "==> aguardando carregar (25s)..." >&2
  sleep 25
fi

saida="$(osascript <<APPLESCRIPT 2>&1 || true
with timeout of ${TIMEOUT} seconds
  tell application "${AE_APP}"
    DoScriptFile "${JSX}"
  end tell
end timeout
APPLESCRIPT
)"

# -1712 = AppleEvent expirou. Quase sempre é diálogo modal aberto no AE.
if [[ "$saida" == *"-1712"* ]]; then
  cat >&2 <<'MSG'
erro: o After Effects nao respondeu no tempo limite.

Quase sempre e um dialogo modal aberto travando o AE (alerta de erro,
"salvar alteracoes?", etc). Enquanto ele estiver na tela, o AE ignora
qualquer script. Verifique a tela do AE e feche o dialogo.
MSG
  exit 2
fi

if [[ "$saida" == *"execution error"* ]]; then
  echo "erro do AppleScript: $saida" >&2
  exit 3
fi

echo "$saida"
