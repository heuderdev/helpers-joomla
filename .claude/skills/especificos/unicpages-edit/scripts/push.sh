#!/usr/bin/env bash
# push.sh <pageId> [--force]
# Envia o cache local pro servidor. Aborta se o remoto mudou desde o pull (a menos que --force).

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SCRIPT_DIR/_lib.sh"

require jq
require curl

[[ $# -ge 1 ]] || die "usage: push.sh <pageId> [--force]"
PAGE="$1"
FORCE=0
[[ "${2:-}" == "--force" ]] && FORCE=1

local_path=$(require_cache "$PAGE")
baseline=$(cache_baseline "$PAGE")

content=$(cat "$local_path")

# Conflict detection: se temos baseline, compara com remoto atual
if [[ $FORCE -eq 0 && -f "$baseline" ]]; then
  remote_raw=$(api_get_draft "$PAGE")
  remote_content=$(printf '%s' "$remote_raw" | unwrap_draft_response | jq '. // {}' | normalize_draft)
  baseline_content=$(cat "$baseline" | jq '.')
  remote_hash=$(printf '%s' "$remote_content" | jq -S '.' | shasum | awk '{print $1}')
  baseline_hash=$(printf '%s' "$baseline_content" | jq -S '.' | shasum | awk '{print $1}')
  if [[ "$remote_hash" != "$baseline_hash" ]]; then
    die "remote draft changed since last pull. Run pull.sh again (you may lose local edits) or push --force to overwrite."
  fi
fi

response=$(api_put_draft "$PAGE" "$content")
status=$(printf '%s' "$response" | jq -r '.status // .statusCode // 200')

if [[ "$status" -ge 400 ]]; then
  msg=$(printf '%s' "$response" | jq -r '.body.message // .message // "API error"')
  die "push failed ($status): $msg"
fi

# Atualiza baseline
cp "$local_path" "$baseline"

desktop_count=$(printf '%s' "$content" | jq '.desktop.sections | length')
mobile_count=$(printf '%s' "$content" | jq '.mobile.sections | length')

echo "pushed $PAGE"
echo "  desktop sections: $desktop_count"
echo "  mobile sections:  $mobile_count"
