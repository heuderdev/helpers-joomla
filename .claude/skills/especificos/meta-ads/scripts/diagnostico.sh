#!/usr/bin/env bash
# Diagnóstico read-only de uma campanha: por que ela não está entregando.
# Uso: diagnostico.sh <CAMPAIGN_ID>
#
# Percorre a cadeia inteira (conta → campanha → conjuntos → anúncios) porque
# a entrega para no elo mais fraco: conta sem saldo, adset pausado, ad reprovado.

set -euo pipefail

FB="$(dirname "$0")/fb.sh"
CID="${1:-}"

if [[ -z "$CID" ]]; then
  echo "Uso: $0 <CAMPAIGN_ID>" >&2
  exit 1
fi

echo "=== CAMPANHA ==="
"$FB" get "$CID" fields=id,name,status,effective_status,objective,daily_budget,lifetime_budget,bid_strategy,issues_info

echo
echo "=== CONJUNTOS (status + motivo) ==="
"$FB" get "$CID/adsets" \
  fields=id,name,status,effective_status,issues_info,optimization_goal,billing_event,daily_budget,promoted_object,start_time,end_time \
  limit=100

echo
echo "=== ANÚNCIOS (status + reprovação) ==="
"$FB" get "$CID/ads" \
  fields=id,name,status,effective_status,issues_info,adset_id \
  limit=100

echo
echo "=== ENTREGA (últimos 7 dias) ==="
"$FB" get "$CID/insights" \
  fields=spend,impressions,clicks,ctr,cpc,actions \
  date_preset=last_7d

echo
echo "Leitura: effective_status revela o elo que trava."
echo "  ADSET_PAUSED      -> o conjunto está pausado (o anúncio em si está ok)"
echo "  CAMPAIGN_PAUSED   -> a campanha está pausada"
echo "  DISAPPROVED       -> criativo reprovado; ver issues_info"
echo "  PENDING_REVIEW    -> em análise, aguardar"
echo "  ACCOUNT_* / conta -> problema de saldo/pagamento; checar a conta"
