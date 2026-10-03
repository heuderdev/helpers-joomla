#!/usr/bin/env bash
# pull.sh <pageId>
# Baixa o draft pra ~/.cache/unicpages/<pageId>.json (mais um baseline congelado pro diff/push)

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SCRIPT_DIR/_lib.sh"

require jq
require curl

[[ $# -ge 1 ]] || die "usage: pull.sh <pageId>"
PAGE="$1"

raw=$(api_get_draft "$PAGE")
# Detecta erro do API (status != 2xx vem como JSON com status field)
err_msg=$(printf '%s' "$raw" | jq -r 'if (.status // 200) >= 400 then (.body.message // .message // "API error") else empty end' 2>/dev/null || echo "")
if [[ -n "$err_msg" ]]; then
  die "api: $err_msg"
fi

content=$(printf '%s' "$raw" | unwrap_draft_response)
if [[ -z "$content" || "$content" == "null" ]]; then
  content='{"desktop":{"sections":[]},"mobile":{"sections":[]}}'
fi

normalized=$(printf '%s' "$content" | jq '. // {}' | normalize_draft)

printf '%s' "$normalized" > "$(cache_path "$PAGE")"
printf '%s' "$normalized" > "$(cache_baseline "$PAGE")"

desktop_count=$(printf '%s' "$normalized" | jq '.desktop.sections | length')
mobile_count=$(printf '%s' "$normalized" | jq '.mobile.sections | length')

echo "pulled $PAGE → $(cache_path "$PAGE")"
echo "  desktop sections: $desktop_count"
echo "  mobile sections:  $mobile_count"
