#!/usr/bin/env bash
# find.sh <pageId> <query> [desktop|mobile|both]
# Procura por substring (case-insensitive) em id, name e content.text. Retorna lista compacta.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SCRIPT_DIR/_lib.sh"

require jq

[[ $# -ge 2 ]] || die "usage: find.sh <pageId> <query> [desktop|mobile|both]"
PAGE="$1"
QUERY="$2"
DEVICE="${3:-both}"

local_path=$(require_cache "$PAGE")

jq -r --arg q "$(printf '%s' "$QUERY" | tr '[:upper:]' '[:lower:]')" --arg device "$DEVICE" '
  def text_of(n):
    if n.content == null then ""
    elif (n.content.text | type) == "string" then n.content.text
    elif (n.content.button.text | type) == "string" then n.content.button.text
    else "" end;

  def lower(s): s | ascii_downcase;

  def walk_section(section; dev):
    def go(node; path):
      ( ( (node.id // "") + " " + (node.name // "") + " " + text_of(node) ) | ascii_downcase ) as $hay
      | (
          if ($hay | contains($q)) then
            { device: dev, sectionId: section.id, elementId: node.id, tag: node.tag, name: (node.name // ""), text: text_of(node), path: path }
          else empty end
        ),
        ( (node.children // [])[] | go(.; path + " > " + (.id // "?")) );
    go(section; section.id);

  def scan(dev):
    ( .[dev].sections // [] )[] | walk_section(.; dev);

  [
    (if $device == "desktop" or $device == "both" then scan("desktop") else empty end),
    (if $device == "mobile"  or $device == "both" then scan("mobile")  else empty end)
  ]
  | unique_by(.device + .elementId)
  | .[]
  | "[\(.device)] \(.elementId) <\(.tag)>\(if .name != "" then " — \(.name)" else "" end)\(if .text != "" then "  «\(.text[:80])»" else "" end)  @ \(.path)"
' "$local_path"
