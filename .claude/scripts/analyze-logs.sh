#!/usr/bin/env bash
# analyze-logs.sh — Análise rápida de logs, com suporte nativo ao laravel.log
# Uso: analyze-logs.sh <arquivo|diretório> [--errors] [--top N] [--since "1 hour ago"]
#
# Exemplos:
#   analyze-logs.sh storage/logs/laravel.log
#   analyze-logs.sh storage/logs --errors            # inclui laravel-YYYY-MM-DD.log (canal daily)
#   analyze-logs.sh storage/logs/laravel.log --top 20
#   analyze-logs.sh storage/logs/laravel.log --since "30 minutes ago"
#
# Formato Laravel (Monolog) reconhecido:
#   [2026-09-29 14:03:11] production.ERROR: Mensagem {"exception":"[object] (Classe(code: 0): msg at /app/X.php:42)
#   [stacktrace]
#   #0 /var/www/app/Http/Controllers/OrderController.php(31): ...
#   "}
#
# Para logs Laravel: contagem por nível e ambiente, top exceptions, top mensagens
# normalizadas e top pontos de origem no código da aplicação (app/, routes/,
# resources/views, database/ — ignorando vendor/). Linhas de stacktrace não
# contam como entradas. Outros formatos caem na análise genérica por linha.
# --since só funciona em logs Laravel (filtra pelo timestamp da entrada).

set -euo pipefail

TARGET="${1:?Uso: analyze-logs.sh <arquivo|dir> [--errors] [--top N] [--since \"1 hour ago\"]}"
ERRORS_ONLY=0
TOP=10
SINCE=""

shift
while [[ $# -gt 0 ]]; do
  case "$1" in
    --errors) ERRORS_ONLY=1; shift ;;
    --top)    TOP="$2"; shift 2 ;;
    --since)  SINCE="$2"; shift 2 ;;
    *) shift ;;
  esac
done

collect_lines() {
  if [[ -d "$TARGET" ]]; then
    # Ordena por nome: laravel-2026-09-28.log antes de laravel-2026-09-29.log
    find "$TARGET" -type f \( -name "*.log" -o -name "*.txt" \) -print0 | sort -z | xargs -0 cat
  elif [[ -f "$TARGET" ]]; then
    cat "$TARGET"
  else
    echo "ERRO: '$TARGET' não encontrado" >&2
    exit 1
  fi
}

TMP=$(mktemp)
trap 'rm -f "$TMP"' EXIT
collect_lines > "$TMP"

LARAVEL_HEADER='^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}[^]]*\] [A-Za-z0-9_-]+\.[A-Z]+: '

if grep -qE "$LARAVEL_HEADER" "$TMP"; then
  SINCE_TS=""
  if [[ -n "$SINCE" ]]; then
    SINCE_TS=$(date -d "$SINCE" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || true)
    if [[ -z "$SINCE_TS" ]]; then
      # macOS/BSD: aceita só formatos do tipo "-30M", "-1H", "-2d"
      SINCE_TS=$(date -v"$SINCE" '+%Y-%m-%d %H:%M:%S' 2>/dev/null || true)
    fi
    [[ -z "$SINCE_TS" ]] && echo "AVISO: não entendi --since '$SINCE' — analisando tudo" >&2
  fi

  awk -v top="$TOP" -v errors_only="$ERRORS_ONLY" -v since="$SINCE_TS" '
    function norm(s) {
      gsub(/[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}/, "UUID", s)
      gsub(/"[^"]*"/, "\"S\"", s)
      gsub(/'"'"'[^'"'"']*'"'"'/, "'"'"'S'"'"'", s)
      gsub(/[0-9]+/, "N", s)
      return s
    }
    function is_error(lv) {
      return lv == "ERROR" || lv == "CRITICAL" || lv == "ALERT" || lv == "EMERGENCY"
    }
    function flush() {
      if (!cur_level) return
      if (app_frame != "") origin[app_frame]++
      else if (throw_at != "") origin[throw_at " (vendor)"]++
      cur_level = ""
    }
    function print_top(title, arr,    k, n, i, j, keys, tmp) {
      n = 0
      for (k in arr) { n++; keys[n] = k }
      # ordenação simples por contagem (insertion sort — N é pequeno)
      for (i = 2; i <= n; i++) {
        tmp = keys[i]; j = i - 1
        while (j > 0 && arr[keys[j]] < arr[tmp]) { keys[j+1] = keys[j]; j-- }
        keys[j+1] = tmp
      }
      print "=== " title " ==="
      if (n == 0) { print "(nenhum)"; print ""; return }
      for (i = 1; i <= n && i <= top; i++) printf "%6d  %s\n", arr[keys[i]], keys[i]
      print ""
    }
    BEGIN { total = 0; kept = 0; stack_lines = 0 }
    {
      if (match($0, /^\[[0-9]{4}-[0-9]{2}-[0-9]{2}[ T][0-9]{2}:[0-9]{2}:[0-9]{2}[^]]*\] [A-Za-z0-9_-]+\.[A-Z]+: /)) {
        flush()
        total++
        ts = substr($0, 2, 19); sub(/T/, " ", ts)
        if (since != "" && ts < since) { skipping = 1; next }
        skipping = 0
        header = substr($0, 1, RLENGTH)
        rest = substr($0, RLENGTH + 1)
        envlv = header; sub(/^\[[^]]*\] /, "", envlv); sub(/: $/, "", envlv)
        dot = index(envlv, ".")
        env = substr(envlv, 1, dot - 1); lv = substr(envlv, dot + 1)

        kept++
        levels[lv]++
        envs[env]++
        if (first_ts == "" || ts < first_ts) first_ts = ts
        if (ts > last_ts) last_ts = ts

        msg = rest
        p = index(msg, " {\"exception\"")
        if (p == 0) p = index(msg, " {\"")
        if (p == 0) p = index(msg, " [] []")
        if (p > 0) msg = substr(msg, 1, p - 1)
        if (length(msg) > 160) msg = substr(msg, 1, 160) "…"

        exc = ""
        if (match(rest, /\[object\] \([A-Za-z0-9_\\]+\(code: /)) {
          exc = substr(rest, RSTART + 10, RLENGTH - 17)
          gsub(/\\\\/, "\\", exc)
        }
        throw_at = ""
        if (match(rest, / at [^ ]+\.php:[0-9]+\)/)) {
          throw_at = substr(rest, RSTART + 4, RLENGTH - 5)
          if (match(throw_at, /\/(vendor|app|routes|resources|database|config|bootstrap)\//)) throw_at = substr(throw_at, RSTART + 1)
        }
        app_frame = ""
        if (throw_at != "" && throw_at !~ /(^|\/)vendor\//) app_frame = throw_at

        cur_level = lv
        if (is_error(lv)) {
          err_total++
          err_msgs[norm(msg)]++
          if (exc != "") excs[exc]++
          if (errors_only && sample_n < 100) {
            sample_n++
            line = $0
            if (length(line) > 300) line = substr(line, 1, 300) "…"
            sample[sample_n] = "[linha " NR "] " line
          }
        } else if (lv == "WARNING" || lv == "NOTICE") {
          warn_msgs[norm(msg)]++
        }
        all_msgs[lv ": " norm(msg)]++
        next
      }
      if (skipping) next
      if ($0 ~ /^#[0-9]+ /) {
        stack_lines++
        if (cur_level != "" && is_error(cur_level) && app_frame == "" && $0 !~ /\/vendor\// && match($0, /\/(app|routes|resources\/views|database|config)\/[^ (]+\.php\([0-9]+\)/)) {
          app_frame = substr($0, RSTART + 1, RLENGTH - 1)
          sub(/\(/, ":", app_frame); sub(/\)$/, "", app_frame)
        }
      }
    }
    END {
      flush()
      print "=== Resumo (formato Laravel detectado) ==="
      printf "Entradas de log:   %d", total
      if (since != "") printf " (%d desde %s)", kept, since
      printf "\n"
      printf "Linhas de stack:   %d\n", stack_lines
      if (first_ts != "") printf "Período:           %s → %s\n", first_ts, last_ts
      printf "Nível ERROR+:      %d\n", err_total
      print ""
      print "=== Por nível ==="
      split("EMERGENCY ALERT CRITICAL ERROR WARNING NOTICE INFO DEBUG", order, " ")
      for (i = 1; i <= 8; i++) if (levels[order[i]]) printf "%-10s %d\n", order[i], levels[order[i]]
      print ""
      print "=== Por ambiente ==="
      for (e in envs) printf "%-12s %d\n", e, envs[e]
      print ""
      print_top("Top " top " exceptions (ERROR+)", excs)
      print_top("Top " top " mensagens de erro normalizadas", err_msgs)
      print_top("Top " top " origens no código da app (ERROR+)", origin)
      if (errors_only) {
        print_top("Top " top " warnings normalizados", warn_msgs)
        print "=== Amostra de entradas ERROR+ (máx. 100, sem stacktrace) ==="
        for (i = 1; i <= sample_n; i++) print sample[i]
      } else {
        print_top("Top " top " mensagens (todos os níveis)", all_msgs)
      }
    }
  ' "$TMP"
  exit 0
fi

# ---------------------------------------------------------------------------
# Formato genérico (uma linha = uma entrada)
# ---------------------------------------------------------------------------
[[ -n "$SINCE" ]] && echo "AVISO: --since só é suportado em logs Laravel — ignorado" >&2

normalize() {
  sed -E 's/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/UUID/g; s/[0-9]+/N/g'
}

if [[ "$ERRORS_ONLY" -eq 1 ]]; then
  echo "=== Erros/Warnings ==="
  grep -iE "error|exception|fail|fatal|warn" "$TMP" | head -100 || true
  echo ""
  echo "=== Top $TOP padrões de erro ==="
  { grep -iE "error|exception|fail" "$TMP" || true; } | normalize | sort | uniq -c | sort -rn | head -"$TOP"
else
  TOTAL=$(wc -l < "$TMP" | tr -d ' ')
  ERR=$(grep -ciE "error|exception|fail|fatal" "$TMP" || true)
  WARN=$(grep -ci "warn" "$TMP" || true)
  INFO=$(grep -ci "info" "$TMP" || true)

  echo "=== Resumo ==="
  echo "Total de linhas: $TOTAL"
  echo "Errors:   $ERR"
  echo "Warnings: $WARN"
  echo "Info:     $INFO"
  echo ""
  echo "=== Top $TOP linhas mais frequentes ==="
  normalize < "$TMP" | sort | uniq -c | sort -rn | head -"$TOP"
fi
