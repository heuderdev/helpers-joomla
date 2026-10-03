#!/usr/bin/env bash
# patch.sh <pageId> <elementId> <patch_json> [desktop|mobile|both]
# patch_json: objeto com text/content/style/name/tag. Use null pra deletar chaves.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SCRIPT_DIR/_lib.sh"
# shellcheck source=_patch_helpers.sh
source "$SCRIPT_DIR/_patch_helpers.sh"

require jq

[[ $# -ge 3 ]] || die "usage: patch.sh <pageId> <elementId> <patch_json> [desktop|mobile|both]"
PAGE="$1"
ELEMENT="$2"
PATCH="$3"
DEVICE="${4:-both}"

# valida que é JSON object
echo "$PATCH" | jq -e 'type == "object"' >/dev/null || die "patch_json must be a JSON object"

apply_patch "$PAGE" "$ELEMENT" "$PATCH" "$DEVICE"
echo "patched $PAGE $ELEMENT ($DEVICE)"
