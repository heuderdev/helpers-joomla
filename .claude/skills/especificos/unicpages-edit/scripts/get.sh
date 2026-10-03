#!/usr/bin/env bash
# get.sh <pageId> <elementId> [desktop|mobile|both] [--summary]
# Imprime o JSON do elemento do cache local.

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SCRIPT_DIR/_lib.sh"

require jq

[[ $# -ge 2 ]] || die "usage: get.sh <pageId> <elementId> [desktop|mobile|both] [--summary]"
PAGE="$1"
ELEMENT="$2"
DEVICE="${3:-both}"
MODE="full"
for arg in "$@"; do
  [[ "$arg" == "--summary" ]] && MODE="summary"
done

local_path=$(require_cache "$PAGE")

jq --arg elementId "$ELEMENT" --arg device "$DEVICE" --arg mode "$MODE" '
  def find_in(node):
    if node.id == $elementId then node
    else (node.children // [])[] | find_in(.)
    end;

  def summary(n):
    {
      id: n.id,
      tag: n.tag,
      name: (n.name // ""),
      text: (if (n.content.text | type) == "string" then n.content.text
             elif (n.content.button.text | type) == "string" then n.content.button.text
             else null end),
      childrenCount: (n.children // [] | length),
      contentKeys: ((n.content // {}) | keys),
      styleKeys: ((n.style // {}) | keys)
    };

  def lookup(dev):
    [ ( .[dev].sections // [] )[] | (
        try (find_in(.)) catch empty
        // ( . as $s | [ $s | find_in(.) ] | first )
      ) ] | map(select(. != null)) | first;

  # tentativa direta + fallback _m no mobile
  def lookup_mobile:
    (lookup("mobile")) //
    ( if ($elementId | endswith("_m")) then null
      else
        ( ($elementId + "_m") as $alt |
          ( .mobile.sections // [] )[] |
          ( . as $s |
            ( [.. | objects | select(.id == $alt)] | first ) // empty
          )
        )
      end );

  def lookup_desktop:
    ( .desktop.sections // [] )[] |
    ( . as $s |
      ( [.. | objects | select(.id == $elementId)] | first ) // empty
    );

  {
    desktop: (if $device == "desktop" or $device == "both" then ([. | lookup_desktop] | map(select(. != null)) | first) else null end),
    mobile:  (if $device == "mobile"  or $device == "both" then ([. | lookup_mobile]  | map(select(. != null)) | first) else null end)
  }
  | if $mode == "summary" then
      { desktop: (.desktop | if . then summary(.) else null end),
        mobile:  (.mobile  | if . then summary(.) else null end) }
    else . end
' "$local_path"
