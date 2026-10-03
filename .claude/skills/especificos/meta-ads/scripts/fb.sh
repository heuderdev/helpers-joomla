#!/usr/bin/env bash
# Helper único para a Marketing API do Meta.
# O token NUNCA é impresso. Sempre lido de ~/.meta-ads-token.
#
# Uso:
#   fb.sh get   <path> [param=valor ...]
#   fb.sh post  <path> [campo=valor ...]     # cria/edita — exige CONFIRM=1
#   fb.sh file  <path> <campo>=@/arquivo [campo=valor ...]  # upload — exige CONFIRM=1
#
# Exemplos:
#   fb.sh get me/adaccounts fields=id,name
#   fb.sh get act_123/campaigns fields=id,name,effective_status
#   CONFIRM=1 fb.sh post act_123/campaigns name=Teste objective=OUTCOME_SALES status=PAUSED

set -euo pipefail

API_VERSION="${FB_API_VERSION:-v26.0}"
BASE="https://graph.facebook.com/${API_VERSION}"
TOKEN_FILE="${META_ADS_TOKEN_FILE:-$HOME/.meta-ads-token}"

if [[ ! -f "$TOKEN_FILE" ]]; then
  echo "ERRO: token não encontrado em $TOKEN_FILE" >&2
  echo "Gere um System User token no Business Manager e salve nesse arquivo (chmod 600)." >&2
  exit 1
fi

TK="$(tr -d '\n\r' < "$TOKEN_FILE")"
if [[ -z "$TK" ]]; then
  echo "ERRO: arquivo de token está vazio: $TOKEN_FILE" >&2
  exit 1
fi

pretty() { python3 -m json.tool 2>/dev/null || cat; }

# Falha alto se a resposta contiver um erro da Graph API.
check_error() {
  local body="$1"
  if printf '%s' "$body" | grep -q '"error"'; then
    echo "$body" | pretty >&2
    echo "" >&2
    echo ">>> A Graph API retornou erro. Nada foi alterado." >&2
    return 1
  fi
  return 0
}

CMD="${1:-}"; shift || true
PATH_ARG="${1:-}"; shift || true

if [[ -z "$CMD" || -z "$PATH_ARG" ]]; then
  sed -n '2,14p' "$0"
  exit 1
fi

case "$CMD" in
  get)
    ARGS=(-s -G "${BASE}/${PATH_ARG}")
    for kv in "$@"; do ARGS+=(--data-urlencode "$kv"); done
    ARGS+=(--data-urlencode "access_token=${TK}")
    BODY="$(curl "${ARGS[@]}")"
    check_error "$BODY" || exit 1
    printf '%s' "$BODY" | pretty
    ;;

  post|file)
    # Guarda de escrita: nada é criado/alterado sem CONFIRM=1 explícito.
    if [[ "${CONFIRM:-}" != "1" ]]; then
      echo "BLOQUEADO: operação de escrita exige CONFIRM=1." >&2
      echo "Isso existe para impedir criação/alteração acidental de anúncios." >&2
      echo "Repita com: CONFIRM=1 $0 $CMD $PATH_ARG ..." >&2
      exit 2
    fi
    ARGS=(-s -X POST "${BASE}/${PATH_ARG}")
    for kv in "$@"; do ARGS+=(-F "$kv"); done
    ARGS+=(-F "access_token=${TK}")
    BODY="$(curl "${ARGS[@]}")"
    check_error "$BODY" || exit 1
    printf '%s' "$BODY" | pretty
    ;;

  *)
    echo "Comando inválido: $CMD (use get, post ou file)" >&2
    exit 1
    ;;
esac
