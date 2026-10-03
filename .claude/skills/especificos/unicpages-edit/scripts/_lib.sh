#!/usr/bin/env bash
# Helpers comuns. Source este arquivo nos demais scripts.

set -euo pipefail

API_BASE="${UNICPAGES_API_BASE:-https://api-app.unicpages.com}"
CACHE_DIR="${UNICPAGES_CACHE_DIR:-$HOME/.cache/unicpages}"
mkdir -p "$CACHE_DIR"

die() {
  echo "error: $*" >&2
  exit 1
}

require() {
  command -v "$1" >/dev/null 2>&1 || die "missing dependency: $1"
}

load_token() {
  if [[ -n "${UNICPAGES_TOKEN:-}" ]]; then
    echo "$UNICPAGES_TOKEN"
    return
  fi
  if [[ -f "$HOME/.unicpages/token" ]]; then
    cat "$HOME/.unicpages/token"
    return
  fi
  die "no token: set UNICPAGES_TOKEN env or write JWT to ~/.unicpages/token"
}

cache_path() {
  local pageId="$1"
  echo "$CACHE_DIR/${pageId}.json"
}

cache_baseline() {
  local pageId="$1"
  echo "$CACHE_DIR/${pageId}.baseline.json"
}

require_cache() {
  local pageId="$1"
  local path
  path=$(cache_path "$pageId")
  if [[ ! -f "$path" ]]; then
    die "cache miss for $pageId — run pull.sh first"
  fi
  echo "$path"
}

api_get_draft() {
  local pageId="$1"
  local token
  token=$(load_token)
  curl -sS -H "x-access-token: $token" "$API_BASE/drafts/page/$pageId"
}

api_put_draft() {
  local pageId="$1"
  local content_json="$2"
  local token
  token=$(load_token)
  curl -sS -X PUT \
    -H "x-access-token: $token" \
    -H "Content-Type: application/json" \
    -d "{\"content\":$(jq -c -n --arg c "$content_json" '$c')}" \
    "$API_BASE/drafts/page/$pageId"
}

# Extrai o body.content (que vem como string JSON do api-app) e devolve JSON parseado
unwrap_draft_response() {
  jq -r '
    if .body == null then "{}"
    elif (.body | type) == "string" then .body
    elif (.body.content | type) == "string" then .body.content
    elif (.body.content | type) == "object" then (.body.content | tojson)
    elif (.body | type) == "object" then (.body | tojson)
    else "{}"
    end
  '
}

# Normaliza pro formato { desktop: { sections }, mobile: { sections } } esperado pelo schema
normalize_draft() {
  jq '
    if . == null or . == "" then { desktop: { sections: [] }, mobile: { sections: [] } }
    elif (type == "object") then
      {
        desktop: (
          if .desktop and (.desktop | type) == "object" then
            { sections: (.desktop.sections // []) }
          elif .desktopData then
            (if (.desktopData | type) == "array" then { sections: .desktopData } else { sections: (.desktopData.sections // []) } end)
          else { sections: [] }
          end
        ),
        mobile: (
          if .mobile and (.mobile | type) == "object" then
            { sections: (.mobile.sections // []) }
          elif .mobileData then
            (if (.mobileData | type) == "array" then { sections: .mobileData } else { sections: (.mobileData.sections // []) } end)
          else { sections: [] }
          end
        )
      }
    else { desktop: { sections: [] }, mobile: { sections: [] } }
    end
  '
}
