#!/usr/bin/env bash
#
# gitlab-api.sh — wrapper fino sobre a API REST do GitLab.
#
# Autenticação: extrai o Personal Access Token de ~/gitlab-login.sh pela conta
# (default: eduardo). O token NUNCA é impresso.
#
# Uso:
#   gitlab-api.sh whoami [conta]
#   gitlab-api.sh list-projects [conta] [--mine|--membership|--group <grupo>]
#   gitlab-api.sh list-groups [conta]
#   gitlab-api.sh info <project-path-ou-id> [conta]
#   gitlab-api.sh create-project <nome> [conta] [--group <grupo>] [--public] [--no-readme] [--description "..."]
#   gitlab-api.sh create-group <nome> [conta] [--parent <id-ou-path>] [--public] [--description "..."]
#   gitlab-api.sh delete-project <project-path-ou-id> [conta]   (pede confirmação via env CONFIRM=yes)
#
# A conta é sempre o primeiro argumento posicional após o subcomando, ou cai em 'eduardo'.
# Flags podem vir em qualquer ordem.

set -euo pipefail

GITLAB_HOST="https://gitlab.com"
API="$GITLAB_HOST/api/v4"
LOGIN_SCRIPT="$HOME/gitlab-login.sh"

# ---- extrai o token de uma conta do gitlab-login.sh ----
get_token() {
  local conta="$1"
  if [ ! -f "$LOGIN_SCRIPT" ]; then
    echo "ERRO: $LOGIN_SCRIPT não encontrado." >&2
    return 1
  fi
  # Lê o bloco "conta)" e pega a 2ª linha de echo (a senha/token)
  local token
  token=$(awk -v acct="$conta" '
    $0 ~ "^[[:space:]]*"acct"\\)" { found=1; n=0; next }
    found && /echo/ { n++; if (n==2) { gsub(/.*echo[[:space:]]+"?/,""); gsub(/".*/,""); print; exit } }
  ' "$LOGIN_SCRIPT")
  if [ -z "$token" ]; then
    echo "ERRO: conta '$conta' não encontrada em $LOGIN_SCRIPT." >&2
    return 1
  fi
  printf '%s' "$token"
}

# ---- urlencode de um path (ex: grupo/sub/repo -> grupo%2Fsub%2Frepo) ----
urlencode_path() {
  printf '%s' "$1" | sed 's|/|%2F|g'
}

# ---- chamada curl autenticada. $1=method $2=path $3=conta [data...] ----
api_call() {
  local method="$1" path="$2" conta="$3"; shift 3
  local token
  token=$(get_token "$conta") || return 1
  curl -sS --header "PRIVATE-TOKEN: $token" --request "$method" "$@" "$API$path"
}

# ---- pretty print JSON via python3 ----
pp() {
  python3 -c "import sys,json; data=json.load(sys.stdin); print(json.dumps(data, indent=2, ensure_ascii=False))"
}

cmd="${1:-help}"; shift || true

case "$cmd" in
  whoami)
    conta="${1:-eduardo}"
    api_call GET "/user" "$conta" | python3 -c "
import sys,json
u=json.load(sys.stdin)
if isinstance(u,dict) and u.get('message'): print('ERRO:',u['message']); sys.exit(1)
print(f\"Logado como: {u['username']} ({u.get('name','')}) — id={u['id']}\")
"
    ;;

  list-projects)
    conta="eduardo"; mode="membership"; grupo=""
    while [ $# -gt 0 ]; do
      case "$1" in
        --mine) mode="owned";;
        --membership) mode="membership";;
        --group) grupo="$2"; shift;;
        --*) ;;
        *) conta="$1";;
      esac; shift
    done
    if [ -n "$grupo" ]; then
      gp=$(urlencode_path "$grupo")
      api_call GET "/groups/$gp/projects?per_page=100&include_subgroups=true&order_by=last_activity_at" "$conta"
    else
      api_call GET "/projects?$mode=true&per_page=100&order_by=last_activity_at" "$conta"
    fi | python3 -c "
import sys,json
data=json.load(sys.stdin)
if isinstance(data,dict) and data.get('message'): print('ERRO:',data['message']); sys.exit(1)
if not data: print('(nenhum projeto)'); sys.exit(0)
for p in data:
    vis=p.get('visibility','?')
    print(f\"{p['path_with_namespace']}  [{vis}]  {p['web_url']}\")
"
    ;;

  list-groups)
    conta="${1:-eduardo}"
    api_call GET "/groups?per_page=100&order_by=name&sort=asc&min_access_level=10" "$conta" | python3 -c "
import sys,json
data=json.load(sys.stdin)
if isinstance(data,dict) and data.get('message'): print('ERRO:',data['message']); sys.exit(1)
if not data: print('(nenhum grupo)'); sys.exit(0)
for g in data:
    print(f\"{g['full_path']}  (id={g['id']})  {g['web_url']}\")
"
    ;;

  info)
    proj="${1:?uso: info <project-path-ou-id> [conta]}"; conta="${2:-eduardo}"
    pp_path=$(urlencode_path "$proj")
    api_call GET "/projects/$pp_path" "$conta" | python3 -c "
import sys,json
p=json.load(sys.stdin)
if isinstance(p,dict) and p.get('message'): print('ERRO:',p['message']); sys.exit(1)
print(f\"Nome:        {p['name']}\")
print(f\"Path:        {p['path_with_namespace']}\")
print(f\"Visibilidade:{p['visibility']}\")
print(f\"Default br:  {p.get('default_branch','-')}\")
print(f\"Web:         {p['web_url']}\")
print(f\"Clone HTTP:  {p['http_url_to_repo']}\")
print(f\"Clone SSH:   {p['ssh_url_to_repo']}\")
print(f\"Vazio:       {p.get('empty_repo','?')}\")
print(f\"Descrição:   {p.get('description') or '-'}\")
"
    ;;

  create-project)
    nome="${1:?uso: create-project <nome> [conta] [--group <grupo>] [--public] [--no-readme] [--description ...]}"; shift
    conta="eduardo"; grupo=""; visibility="private"; readme="true"; descricao=""
    while [ $# -gt 0 ]; do
      case "$1" in
        --group) grupo="$2"; shift;;
        --public) visibility="public";;
        --internal) visibility="internal";;
        --no-readme) readme="false";;
        --description) descricao="$2"; shift;;
        --*) ;;
        *) conta="$1";;
      esac; shift
    done

    # monta o JSON
    args=(--data-urlencode "name=$nome" \
          --data-urlencode "path=$nome" \
          --data-urlencode "visibility=$visibility" \
          --data-urlencode "initialize_with_readme=$readme" \
          --data-urlencode "default_branch=main")
    [ -n "$descricao" ] && args+=(--data-urlencode "description=$descricao")
    if [ -n "$grupo" ]; then
      # resolve namespace_id a partir do path do grupo
      gp=$(urlencode_path "$grupo")
      nsid=$(api_call GET "/groups/$gp" "$conta" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('id','') if not d.get('message') else '')")
      if [ -z "$nsid" ]; then
        echo "ERRO: grupo '$grupo' não encontrado." >&2; exit 1
      fi
      args+=(--data-urlencode "namespace_id=$nsid")
    fi

    api_call POST "/projects" "$conta" "${args[@]}" | python3 -c "
import sys,json
p=json.load(sys.stdin)
if isinstance(p,dict) and p.get('message'): print('ERRO:',json.dumps(p['message'],ensure_ascii=False)); sys.exit(1)
print('CRIADO')
print(f\"Path:       {p['path_with_namespace']}\")
print(f\"Web:        {p['web_url']}\")
print(f\"Clone HTTP: {p['http_url_to_repo']}\")
"
    ;;

  create-group)
    nome="${1:?uso: create-group <nome> [conta] [--parent <id-ou-path>] [--public] [--description ...]}"; shift
    conta="eduardo"; parent=""; visibility="private"; descricao=""
    while [ $# -gt 0 ]; do
      case "$1" in
        --parent) parent="$2"; shift;;
        --public) visibility="public";;
        --internal) visibility="internal";;
        --description) descricao="$2"; shift;;
        --*) ;;
        *) conta="$1";;
      esac; shift
    done

    args=(--data-urlencode "name=$nome" \
          --data-urlencode "path=$nome" \
          --data-urlencode "visibility=$visibility")
    [ -n "$descricao" ] && args+=(--data-urlencode "description=$descricao")
    if [ -n "$parent" ]; then
      if [[ "$parent" =~ ^[0-9]+$ ]]; then
        pid="$parent"
      else
        gp=$(urlencode_path "$parent")
        pid=$(api_call GET "/groups/$gp" "$conta" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('id','') if not d.get('message') else '')")
        [ -z "$pid" ] && { echo "ERRO: grupo pai '$parent' não encontrado." >&2; exit 1; }
      fi
      args+=(--data-urlencode "parent_id=$pid")
    fi

    api_call POST "/groups" "$conta" "${args[@]}" | python3 -c "
import sys,json
g=json.load(sys.stdin)
if isinstance(g,dict) and g.get('message'): print('ERRO:',json.dumps(g['message'],ensure_ascii=False)); sys.exit(1)
print('CRIADO')
print(f\"Path: {g['full_path']}  (id={g['id']})\")
print(f\"Web:  {g['web_url']}\")
"
    ;;

  delete-project)
    proj="${1:?uso: delete-project <project-path-ou-id> [conta]}"; conta="${2:-eduardo}"
    if [ "${CONFIRM:-no}" != "yes" ]; then
      echo "ERRO: deleção exige CONFIRM=yes no ambiente. Abortado." >&2
      exit 1
    fi
    pp_path=$(urlencode_path "$proj")
    api_call DELETE "/projects/$pp_path" "$conta" | python3 -c "
import sys,json
try:
    d=json.load(sys.stdin)
    print(d.get('message','OK'))
except Exception:
    print('OK (sem corpo)')
"
    ;;

  help|*)
    sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'
    ;;
esac
