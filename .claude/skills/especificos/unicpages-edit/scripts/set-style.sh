#!/usr/bin/env bash
# set-style.sh <pageId> <elementId> <jq_path_dentro_de_style> <json_value> [desktop|mobile|both]
# Exemplos:
#   set-style.sh p_x el_btn '.background.solid.color' '"#FF6600"'
#   set-style.sh p_x el_h1  '.fontSize'               '{"value":56,"unit":"px"}'
#   set-style.sh p_x el_h1  '.color'                  'null'   # remove a chave

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SCRIPT_DIR/_lib.sh"
# shellcheck source=_patch_helpers.sh
source "$SCRIPT_DIR/_patch_helpers.sh"

require jq

[[ $# -ge 4 ]] || die "usage: set-style.sh <pageId> <elementId> <jq_path> <json_value> [desktop|mobile|both]"
PAGE="$1"
ELEMENT="$2"
JQ_PATH="$3"
VALUE="$4"
DEVICE="${5:-both}"

# Constrói um patch de style usando o caminho. {} | setpath e o jq path string-form ".a.b.c" precisa virar array.
# Convertendo ".a.b.c" → ["a","b","c"]
style_patch=$(jq -cn --arg path "$JQ_PATH" --argjson v "$VALUE" '
  ($path | ltrimstr(".") | split(".")) as $segs
  | reduce ($segs | reverse[]) as $k ($v; {($k): .})
')

patch=$(jq -cn --argjson style "$style_patch" '{ style: $style }')
apply_patch "$PAGE" "$ELEMENT" "$patch" "$DEVICE"

echo "set-style $PAGE $ELEMENT ($DEVICE) → style$JQ_PATH = $VALUE"
