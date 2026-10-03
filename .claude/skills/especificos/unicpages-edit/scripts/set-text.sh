#!/usr/bin/env bash
# set-text.sh <pageId> <elementId> <texto> [desktop|mobile|both]

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SCRIPT_DIR/_lib.sh"
# shellcheck source=_patch_helpers.sh
source "$SCRIPT_DIR/_patch_helpers.sh"

require jq

[[ $# -ge 3 ]] || die "usage: set-text.sh <pageId> <elementId> <texto> [desktop|mobile|both]"
PAGE="$1"
ELEMENT="$2"
TEXT="$3"
DEVICE="${4:-both}"

patch=$(jq -cn --arg t "$TEXT" '{ text: $t }')
apply_patch "$PAGE" "$ELEMENT" "$patch" "$DEVICE"

echo "set-text $PAGE $ELEMENT ($DEVICE) → \"$TEXT\""
