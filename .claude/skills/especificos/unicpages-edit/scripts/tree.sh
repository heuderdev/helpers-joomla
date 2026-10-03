#!/usr/bin/env bash
# tree.sh <pageId> [section_id_or_index] [desktop|mobile]
# Tree achatada de uma section (ou da página inteira se omitir target).

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SCRIPT_DIR/_lib.sh"

require jq

[[ $# -ge 1 ]] || die "usage: tree.sh <pageId> [section_id_or_index] [desktop|mobile]"
PAGE="$1"
TARGET="${2:-}"
DEVICE="${3:-desktop}"

[[ "$DEVICE" == "desktop" || "$DEVICE" == "mobile" ]] || die "device must be desktop or mobile"

local_path=$(require_cache "$PAGE")

jq --arg device "$DEVICE" --arg target "$TARGET" '
  def text_of(n):
    if n.content == null then ""
    elif (n.content.text | type) == "string" then n.content.text
    elif (n.content.button.text | type) == "string" then n.content.button.text
    else "" end;

  def preview: text_of(.) | gsub("\\s+"; " ") | .[:60];

  def walk(depth; parent):
    {
      id: .id,
      tag: .tag,
      name: (.name // ""),
      depth: depth,
      parentId: parent,
      preview: preview,
      children: (.children // [] | length)
    },
    ( (.children // [])[] | walk(depth + 1; .id) );

  ( ([.[$device].sections[]?] as $secs |
     if $target == "" then $secs
     elif ($target | test("^[0-9]+$")) then [$secs[($target | tonumber)]]
     else [$secs[] | select(.id == $target)]
     end) | .[] | walk(0; null) )
' "$local_path" | jq -s '.' | jq -r '
  .[] | "\(.depth | . * 2 | " " * .)\(.id) [\(.tag)]\(if .name != "" then " — \(.name)" else "" end)\(if .preview != "" then "  «\(.preview)»" else "" end)\(if .children > 0 then " (\(.children)c)" else "" end)"
'
