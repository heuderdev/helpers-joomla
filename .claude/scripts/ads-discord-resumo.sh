export PATH="/usr/local/bin:/opt/homebrew/bin:/usr/bin:/bin"

TOKEN=$(cat "$HOME/.meta-ads-token")
API="https://graph.facebook.com/v26.0"
LOG="$HOME/.claude/logs/ads-discord.log"
mkdir -p "$(dirname "$LOG")"

WEBHOOK_EDUSITES="${DISCORD_WEBHOOK_ADS_EDUSITES:?defina DISCORD_WEBHOOK_ADS_EDUSITES}"
WEBHOOK_MF="${DISCORD_WEBHOOK_ADS_MF:?defina DISCORD_WEBHOOK_ADS_MF}"

resumir() {
  local ACT="$1" HOOK="$2" NOME="$3" COR="$4"

  local INS
  INS=$(curl -s -G "$API/act_$ACT/insights" \
    --data-urlencode "access_token=$TOKEN" \
    --data-urlencode "fields=spend,impressions,reach,frequency,clicks,ctr,cpm,cpc,actions,cost_per_action_type,action_values" \
    --data-urlencode "date_preset=today" \
    --data-urlencode "level=account")

  local CAMPS
  CAMPS=$(curl -s -G "$API/act_$ACT/insights" \
    --data-urlencode "access_token=$TOKEN" \
    --data-urlencode "fields=campaign_id,campaign_name,spend,impressions,clicks,ctr,actions,cost_per_action_type,action_values" \
    --data-urlencode "date_preset=today" \
    --data-urlencode "level=campaign" \
    --data-urlencode "limit=10")

  local ADSETS
  ADSETS=$(curl -s -G "$API/act_$ACT/insights" \
    --data-urlencode "access_token=$TOKEN" \
    --data-urlencode "fields=adset_name,campaign_name,spend,impressions,clicks,ctr,cpm,frequency,actions,cost_per_action_type,action_values" \
    --data-urlencode "date_preset=today" \
    --data-urlencode "level=adset" \
    --data-urlencode "limit=25")

  local ACUM
  ACUM=$(curl -s -G "$API/act_$ACT/insights" \
    --data-urlencode "access_token=$TOKEN" \
    --data-urlencode "fields=campaign_id,campaign_name,spend,actions,action_values" \
    --data-urlencode "date_preset=maximum" \
    --data-urlencode "level=campaign" \
    --data-urlencode "filtering=[{\"field\":\"spend\",\"operator\":\"GREATER_THAN\",\"value\":0}]" \
    --data-urlencode "limit=200")

  local REPROV
  REPROV=$(curl -s -G "$API/act_$ACT/ads" \
    --data-urlencode "access_token=$TOKEN" \
    --data-urlencode "fields=name,effective_status,campaign{effective_status}" \
    --data-urlencode "effective_status=[\"DISAPPROVED\",\"WITH_ISSUES\"]" \
    --data-urlencode "limit=50")

  PAYLOAD=$(INS="$INS" CAMPS="$CAMPS" ADSETS="$ADSETS" ACUM="$ACUM" REPROV="$REPROV" NOME="$NOME" COR="$COR" python3 "$HOME/.claude/scripts/ads-discord-payload.py")

  if [ -z "$PAYLOAD" ]; then
    echo "[$(date '+%F %T')] $NOME: falha ao montar payload" >> "$LOG"
    return 1
  fi

  local HTTP
  HTTP=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$HOOK?with_components=true" \
    -H "Content-Type: application/json" -d "$PAYLOAD")
  echo "[$(date '+%F %T')] $NOME: discord HTTP $HTTP" >> "$LOG"
}

resumir "375936614875629"  "$WEBHOOK_EDUSITES" "EduSites"                    3447003
resumir "1387009996684912" "$WEBHOOK_MF"       "MF Gestão · Delivery Futuro" 15105570
