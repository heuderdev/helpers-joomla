#!/usr/bin/env bash
# Gera/edita imagem tentando as chaves nomeadas em keys.env.
# Só troca de chave em erro TERMINAL de saldo/limite/auth (billing_hard_limit,
# insufficient_quota, invalid_api_key). Em erro transitório (rate_limit, 5xx,
# timeout) RETENTA a mesma chave até 3x — assim rodar em paralelo não derruba
# pra uma chave sem saldo.
# Uso: gen-fallback.sh <args do edit-image.py>
set -uo pipefail
SKILL="$HOME/.claude/skills/openai-image-edit"
source "$SKILL/keys.env"
ORDER=("DOMINNUS" "EDUSITES")   # dominnus tem saldo; edusites é fallback
TERMINAL="billing_hard_limit|insufficient_quota|invalid_api_key|billing_limit|account_deactivated"

for NAME in "${ORDER[@]}"; do
  VAR="OPENAI_KEY_$NAME"; KEY="${!VAR:-}"
  [ -z "$KEY" ] && continue
  for TRY in 1 2 3; do
    OUT=$(OPENAI_API_KEY="$KEY" python3 "$SKILL/edit-image.py" "$@" 2>&1)
    if [ $? -eq 0 ] && ! echo "$OUT" | grep -qiE "$TERMINAL|\"error\""; then
      echo "[key: $NAME] OK"; echo "$OUT" | tail -2; exit 0
    fi
    if echo "$OUT" | grep -qiE "$TERMINAL"; then
      echo "[key: $NAME] limite/saldo esgotado -> proxima chave" >&2
      echo "$OUT" | grep -iE "message|code" | head -1 >&2
      break   # erro terminal: pula pra proxima chave
    fi
    echo "[key: $NAME] erro transitorio (tentativa $TRY/3), retry..." >&2
    sleep $((TRY*3))
  done
done
echo "FALHOU em todas as chaves." >&2
exit 1
