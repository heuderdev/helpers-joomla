#!/usr/bin/env bash
# Helpers internos pra editar o cache JSON in-place.

# apply_patch <pageId> <elementId> <patch_json> <device>
#   patch_json é um objeto com chaves opcionais: text, content, style, name, tag
#   device: desktop | mobile | both
apply_patch() {
  local pageId="$1"
  local elementId="$2"
  local patch_json="$3"
  local device="${4:-both}"

  local path
  path=$(cache_path "$pageId")
  [[ -f "$path" ]] || die "cache miss for $pageId — run pull.sh first"

  local tmp
  tmp=$(mktemp)

  jq --arg elementId "$elementId" \
     --arg device "$device" \
     --argjson patch "$patch_json" '
    def merge(a; b):
      if (a | type) == "object" and (b | type) == "object" then
        reduce (b | to_entries[]) as $kv (a;
          if $kv.value == null then del(.[$kv.key])
          elif (.[$kv.key] | type) == "object" and ($kv.value | type) == "object" then
            .[$kv.key] = merge(.[$kv.key]; $kv.value)
          else .[$kv.key] = $kv.value end)
      else b end;

    def is_text_tag(tag):
      tag == "h1" or tag == "h2" or tag == "h3" or tag == "h4" or tag == "h5" or tag == "h6"
      or tag == "p" or tag == "span" or tag == "a" or tag == "button";

    def apply_to(node):
      node
      | ( if ($patch.text | type) == "string" then
            .content = ( (.content // {}) | .text = $patch.text )
            | (if .tag == "button" then
                 .content.button = ( (.content.button // {}) + { text: $patch.text } )
               else . end)
          else . end )
      | ( if ($patch.content | type) == "object" then
            .content = merge((.content // {}); $patch.content)
          else . end )
      | ( if ($patch.style | type) == "object" then
            .style = merge((.style // {}); $patch.style)
          else . end )
      | ( if ($patch.name | type) == "string" then .name = $patch.name else . end )
      | ( if ($patch.tag  | type) == "string" then .tag  = $patch.tag  else . end );

    # walk recursivo modificando o nó com id correspondente; aceita id ou id_m no mobile
    def map_section(targetId; node):
      if node.id == targetId then apply_to(node)
      elif (node.children | type) == "array" then
        node | .children = (node.children | map(map_section(targetId; .)))
      else node
      end;

    def map_sections(arr; targetId):
      (arr // []) | map(map_section(targetId; .));

    .desktop.sections = (
      if $device == "desktop" or $device == "both"
      then map_sections(.desktop.sections; $elementId)
      else .desktop.sections end
    )
    | .mobile.sections = (
      if $device == "mobile" or $device == "both"
      then
        ( map_sections(.mobile.sections; $elementId) ) as $first
        | ( if ($elementId | endswith("_m")) then $first
            else (map_sections($first; ($elementId + "_m"))) end )
      else .mobile.sections end
    )
  ' "$path" > "$tmp"

  mv "$tmp" "$path"
}
