#!/usr/bin/env bash
#
# gitlab-scaffold.sh — clona um template base e prepara um novo repo a partir dele.
#
# Fluxo:
#   1. clona o template (team-lecdt/base-*) numa pasta de destino
#   2. remove o .git do template e inicializa um repo limpo
#   3. (Laravel) ajusta composer.json "name", cria .env a partir do .env.example com
#      APP_NAME/APP_URL (porta do `php artisan serve`) e, com --install, roda
#      composer install + php artisan key:generate
#   4. (opcional) aponta o remote pro novo projeto e faz o primeiro push
#
# Uso:
#   gitlab-scaffold.sh <template> <pasta-destino> [conta] [--remote <path>] [--push] [--name <app-name>] [--port <porta>] [--install]
#
#   <template>      : laravel | api | shared | devops  (ou path completo team-lecdt/base-xxx)
#   <pasta-destino> : onde clonar (ex: ~/projetos/unic/frontend/app)
#   conta           : conta do gitlab-login.sh para auth no clone (default: eduardo)
#   --remote <path> : path do novo projeto no GitLab (ex: meu-projeto/frontend/app) — habilita push
#   --push          : faz o primeiro push (requer --remote)
#   --name <nome>   : nome amigável do app → APP_NAME no .env e "name" do composer.json (slug)
#   --port <porta>  : porta fixa do projeto → APP_URL=http://localhost:<porta> (use com `php artisan serve --port=<porta>`)
#   --install       : roda composer install + php artisan key:generate (precisa de php e composer no PATH)
#
# NÃO faz substituição agressiva de placeholders — isso fica a cargo do agente/usuário,
# que conhece o contexto. Apenas prepara o esqueleto clonável.

set -euo pipefail

LOGIN_SCRIPT="$HOME/gitlab-login.sh"
GITLAB="gitlab.com"
TEMPLATE_GROUP="team-lecdt"

get_token() {
  local conta="$1"
  awk -v acct="$conta" '
    $0 ~ "^[[:space:]]*"acct"\\)" { found=1; n=0; next }
    found && /echo/ { n++; if (n==2) { gsub(/.*echo[[:space:]]+"?/,""); gsub(/".*/,""); print; exit } }
  ' "$LOGIN_SCRIPT"
}

resolve_template() {
  case "$1" in
    laravel|blade|web|frontend)  echo "$TEMPLATE_GROUP/base-laravel";;
    api|laravel-api|backend)     echo "$TEMPLATE_GROUP/base-laravel-api";;
    upload|api-upload|s3)        echo "$TEMPLATE_GROUP/base-laravel-api";;  # upload é o módulo Storage da base-laravel-api
    shared)                      echo "$TEMPLATE_GROUP/base-laravel-shared";;
    devops|infra)                echo "$TEMPLATE_GROUP/base-devops";;
    */*)                       echo "$1";;  # path completo
    *)                         echo "";;
  esac
}

template_arg="${1:?uso: gitlab-scaffold.sh <template> <pasta-destino> [conta] [--remote path] [--push] [--name nome] [--port porta] [--install]}"
destino="${2:?falta a pasta de destino}"
shift 2

conta="eduardo"; remote_path=""; do_push="no"; app_name=""; app_port=""; do_install="no"
while [ $# -gt 0 ]; do
  case "$1" in
    --remote) remote_path="$2"; shift;;
    --push)   do_push="yes";;
    --name)   app_name="$2"; shift;;
    --port)   app_port="$2"; shift;;
    --install) do_install="yes";;
    --*) ;;
    *) conta="$1";;
  esac; shift
done

template=$(resolve_template "$template_arg")
[ -z "$template" ] && { echo "ERRO: template '$template_arg' inválido (use laravel|api|shared|devops)." >&2; exit 1; }

token=$(get_token "$conta")
[ -z "$token" ] && { echo "ERRO: conta '$conta' não encontrada em $LOGIN_SCRIPT." >&2; exit 1; }

if [ -e "$destino" ] && [ -n "$(ls -A "$destino" 2>/dev/null)" ]; then
  echo "ERRO: pasta de destino '$destino' já existe e não está vazia. Abortado." >&2
  exit 1
fi

echo "# Clonando template $template → $destino"
git clone --depth 1 "https://eduardolecdt:${token}@${GITLAB}/${template}.git" "$destino" 2>&1 | grep -vE "Cloning into|Receiving|Resolving|remote:" || true

echo "# Limpando histórico do template"
rm -rf "$destino/.git"

cd "$destino"
git init -q
git branch -m main 2>/dev/null || true

# Ajustes de app Laravel (só se o template for um app Laravel: tem artisan + composer.json)
if [ -f artisan ] && [ -f composer.json ]; then
  if [ -n "$app_name" ]; then
    slug=$(echo "$app_name" | iconv -f utf-8 -t ascii//TRANSLIT 2>/dev/null | tr '[:upper:]' '[:lower:]' | sed -E 's/[^a-z0-9]+/-/g; s/^-|-$//g')
    pkg="team-lecdt/${slug}-$(basename "$destino")"
    echo "# composer.json name → $pkg"
    php -r '$f="composer.json"; $j=json_decode(file_get_contents($f), true); $j["name"]=$argv[1]; file_put_contents($f, json_encode($j, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");' -- "$pkg"
  fi
  if [ ! -f .env ] && [ -f .env.example ]; then
    echo "# .env criado a partir do .env.example (não versionado)"
    cp .env.example .env
  fi
  if [ -f .env ]; then
    set_env() {  # set_env CHAVE valor — troca a linha se existir, senão adiciona
      if grep -qE "^$1=" .env; then
        php -r '$f=".env"; $c=file_get_contents($f); $c=preg_replace("/^".preg_quote($argv[1],"/")."=.*$/m", $argv[1]."=".$argv[2], $c); file_put_contents($f,$c);' -- "$1" "$2"
      else
        printf '%s=%s\n' "$1" "$2" >> .env
      fi
    }
    [ -n "$app_name" ] && set_env APP_NAME "\"$app_name\""
    [ -n "$app_port" ] && set_env APP_URL "http://localhost:$app_port"
  fi
  if [ "$do_install" = "yes" ]; then
    echo "# composer install + key:generate"
    composer install --no-interaction --quiet
    php artisan key:generate --no-interaction
  fi
  grep -qxF '.env' .gitignore 2>/dev/null || echo "AVISO: .gitignore não ignora .env — corrija antes do push." >&2
fi

if [ -n "$remote_path" ]; then
  echo "# Configurando remote → ${GITLAB}/${remote_path}"
  git remote add origin "https://eduardolecdt:${token}@${GITLAB}/${remote_path}.git"
fi

if [ "$do_push" = "yes" ]; then
  if [ -z "$remote_path" ]; then
    echo "ERRO: --push requer --remote <path>." >&2
    exit 1
  fi
  git add -A
  GIT_AUTHOR_NAME="Eduardo" GIT_AUTHOR_EMAIL="eduardolecdt@gmail.com" \
  GIT_COMMITTER_NAME="Eduardo" GIT_COMMITTER_EMAIL="eduardolecdt@gmail.com" \
    git commit -q -m "Setup inicial a partir do template ${template##*/}"
  # tenta push normal; se a main remota tiver README, faz merge unrelated mantendo o nosso
  git fetch origin main -q 2>/dev/null || true
  if git rev-parse --verify origin/main >/dev/null 2>&1; then
    GIT_AUTHOR_NAME="Eduardo" GIT_AUTHOR_EMAIL="eduardolecdt@gmail.com" \
    GIT_COMMITTER_NAME="Eduardo" GIT_COMMITTER_EMAIL="eduardolecdt@gmail.com" \
      git merge origin/main --allow-unrelated-histories -X ours -m "Merge inicial do remoto" -q 2>/dev/null || true
  fi
  git push -u origin main
  echo "# Push concluído → https://${GITLAB}/${remote_path}"
fi

echo ""
echo "PRONTO."
echo "  Template: $template"
echo "  Pasta:    $destino"
[ -n "$remote_path" ] && echo "  Remote:   https://${GITLAB}/${remote_path}"
[ -n "$app_name" ]    && echo "  App name: $app_name"
[ -n "$app_port" ]    && echo "  Rodar:    php artisan serve --port=$app_port  (+ npm run dev para o Vite)"
[ -f artisan ] && [ "$do_install" = "no" ] && echo "  Falta:    composer install && php artisan key:generate && php artisan migrate"
