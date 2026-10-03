#!/usr/bin/env bash
# Upload de arquivos via endpoint de storage de uma API Laravel (DigitalOcean Spaces / S3).
# Sobe pro Spaces e retorna as URLs públicas no CDN.
#
# Uso:
#   upload.sh --app-dir <raiz-do-app-laravel> [--type imagem|arquivo] [--email user@dominio] [--max-width 1600] <file1> [file2 ...]
#
# Token: usa $UPLOAD_TOKEN se definido. Senão gera um token Sanctum temporário (15 min,
# ability "storage:upload") via `php artisan tinker` para o usuário de --email
# (ou UPLOAD_USER_EMAIL do .env). Nunca imprime o token.
#
# API: reusa APP_URL se estiver no ar; senão sobe `php artisan serve` numa porta livre
# e derruba no fim. Saída JSON: { "results": [ {input,nome,peso,url} ], "errors": [...] }

set -euo pipefail

APP_DIR=""; TYPE="imagem"; EMAIL=""; MAX_WIDTH=""; FILES=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    --app-dir) APP_DIR="$2"; shift 2 ;;
    --type) TYPE="$2"; shift 2 ;;
    --email) EMAIL="$2"; shift 2 ;;
    --max-width) MAX_WIDTH="$2"; shift 2 ;;
    *) FILES+=("$1"); shift ;;
  esac
done

fail() { php -r 'echo json_encode(["results"=>[],"errors"=>[["error"=>$argv[1]]]], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' -- "$1" >&2; exit 1; }

[[ -n "$APP_DIR" ]] || fail "--app-dir <raiz do app Laravel> é obrigatório"
[[ ${#FILES[@]} -gt 0 ]] || fail "informe ao menos 1 arquivo"
[[ "$TYPE" == "imagem" || "$TYPE" == "arquivo" ]] || fail "--type deve ser imagem ou arquivo"
APP_DIR="$(cd "$APP_DIR" && pwd)"
[[ -f "$APP_DIR/artisan" ]] || fail "artisan não encontrado em $APP_DIR (não é um app Laravel)"
command -v php >/dev/null || fail "php não encontrado no PATH"

env_get() { grep -E "^$1=" "$APP_DIR/.env" 2>/dev/null | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//' || true; }

[[ -d "$APP_DIR/vendor" ]] || { echo "[upload] vendor/ ausente, rodando composer install..." >&2; (cd "$APP_DIR" && composer install --no-interaction --quiet) || fail "composer install falhou"; }

probe() { curl -s -o /dev/null -m 3 -w '%{http_code}' "$1/up" 2>/dev/null | grep -qE '^[1-5][0-9][0-9]$'; }

BASE_URL="$(env_get APP_URL)"; BASE_URL="${BASE_URL%/}"
SERVER_PID=""
cleanup() { [[ -n "$SERVER_PID" ]] && kill "$SERVER_PID" 2>/dev/null || true; }
trap cleanup EXIT

if [[ -z "$BASE_URL" ]] || ! probe "$BASE_URL"; then
  PORT="$(php -r '$s=stream_socket_server("tcp://127.0.0.1:0"); $n=stream_socket_get_name($s,false); echo explode(":",$n)[1];')"
  (cd "$APP_DIR" && php artisan serve --host=127.0.0.1 --port="$PORT" >/dev/null 2>&1) &
  SERVER_PID=$!
  BASE_URL="http://127.0.0.1:$PORT"
  for _ in $(seq 1 30); do probe "$BASE_URL" && break; sleep 0.5; done
  probe "$BASE_URL" || fail "API Laravel não subiu na porta $PORT"
fi

TOKEN="${UPLOAD_TOKEN:-}"
if [[ -z "$TOKEN" ]]; then
  EMAIL="${EMAIL:-$(env_get UPLOAD_USER_EMAIL)}"
  [[ -n "$EMAIL" ]] || fail "defina UPLOAD_TOKEN, --email ou UPLOAD_USER_EMAIL no .env"
  TOKEN="$(cd "$APP_DIR" && UPLOAD_EMAIL="$EMAIL" php artisan tinker --execute='
    $u = \App\Models\User::where("email", getenv("UPLOAD_EMAIL"))->first();
    if (! $u) { fwrite(STDERR, "usuario nao encontrado"); exit(1); }
    echo $u->createToken("claude-cli", ["storage:upload"], now()->addMinutes(15))->plainTextToken;
  ' 2>/dev/null | tail -1)"
  [[ "$TOKEN" == *"|"* ]] || fail "não foi possível gerar o token Sanctum (usuário $EMAIL existe? model usa HasApiTokens?)"
fi

RESULTS=(); ERRORS=()
for f in "${FILES[@]}"; do
  if [[ ! -f "$f" ]]; then ERRORS+=("$(php -r 'echo json_encode(["input"=>$argv[1],"error"=>"arquivo não encontrado"], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);' -- "$f")"); continue; fi
  RESP="$(curl -s -m 600 -X POST "$BASE_URL/api/storage/$TYPE" \
    -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' \
    -F "file=@$f" ${MAX_WIDTH:+-F "maxWidth=$MAX_WIDTH"})" || RESP='{"status":"erro","message":"falha de conexão"}'
  LINE="$(php -r '
    $r = json_decode($argv[2], true);
    if (($r["status"] ?? null) === "ok") { echo "OK\t", json_encode(["input"=>$argv[1]] + $r["body"], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
    else { echo "ERR\t", json_encode(["input"=>$argv[1], "error"=>$r["message"] ?? $argv[2]], JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); }
  ' -- "$f" "$RESP")"
  if [[ "$LINE" == OK* ]]; then RESULTS+=("${LINE#OK	}"); else ERRORS+=("${LINE#ERR	}"); fi
done

join() { local IFS=,; echo "$*"; }
php -r 'echo json_encode(["results"=>json_decode($argv[1],true),"errors"=>json_decode($argv[2],true)], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE), "\n";' "[$(join "${RESULTS[@]+"${RESULTS[@]}"}")]" "[$(join "${ERRORS[@]+"${ERRORS[@]}"}")]"
[[ ${#RESULTS[@]} -eq 0 && ${#ERRORS[@]} -gt 0 ]] && exit 1 || exit 0
