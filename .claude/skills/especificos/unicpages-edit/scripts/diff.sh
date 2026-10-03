#!/usr/bin/env bash
# diff.sh <pageId>
# Mostra diferenças entre cache atual e baseline (último pull/push).

set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=_lib.sh
source "$SCRIPT_DIR/_lib.sh"

require jq

[[ $# -ge 1 ]] || die "usage: diff.sh <pageId>"
PAGE="$1"

local_path=$(require_cache "$PAGE")
baseline=$(cache_baseline "$PAGE")
[[ -f "$baseline" ]] || die "no baseline yet — run pull.sh first"

# Pretty print ambos e roda diff
tmp_a=$(mktemp)
tmp_b=$(mktemp)
jq -S '.' "$baseline" > "$tmp_a"
jq -S '.' "$local_path" > "$tmp_b"

if diff -u "$tmp_a" "$tmp_b" > /tmp/unicpages_diff_out 2>&1; then
  echo "no changes vs baseline"
else
  cat /tmp/unicpages_diff_out
fi

rm -f "$tmp_a" "$tmp_b" /tmp/unicpages_diff_out
