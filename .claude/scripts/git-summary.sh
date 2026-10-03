#!/usr/bin/env bash
# git-summary.sh — Resumo enxuto do estado de um repo git (com visão Laravel)
# Uso: git-summary.sh [diretório] [--full]
#
# Sem flags: branch + remote + ahead/behind + status + últimos 5 commits
#            + alertas Laravel (.env versionado, migrations novas/alteradas)
# --full:    inclui diff stat (unstaged/staged) e alterações agrupadas por
#            camada Laravel (app/Models, app/Http, routes, database, views...)
#
# Read-only: nunca faz commit, checkout, reset ou qualquer escrita.

set -euo pipefail

DIR="${1:-.}"
FULL=0
[[ "${2:-}" == "--full" ]] && FULL=1

cd "$DIR"

if ! git rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  echo "ERRO: '$DIR' não é um repositório git" >&2
  exit 1
fi

BRANCH=$(git branch --show-current 2>/dev/null || echo "(detached)")
[[ -z "$BRANCH" ]] && BRANCH="(detached)"
REMOTE=$(git remote get-url origin 2>/dev/null || echo "(sem remote)")
AHEAD=$(git rev-list --count "@{u}..HEAD" 2>/dev/null || echo "?")
BEHIND=$(git rev-list --count "HEAD..@{u}" 2>/dev/null || echo "?")

echo "=== Repositório ==="
echo "Branch:   $BRANCH"
echo "Remote:   $REMOTE"
echo "Ahead:    $AHEAD"
echo "Behind:   $BEHIND"
ROOT=$(git rev-parse --show-toplevel)
if [[ -f "$ROOT/artisan" ]]; then
  echo "Projeto:  Laravel (artisan na raiz)"
fi
echo ""

echo "=== Status ==="
STATUS=$(git status --short 2>/dev/null)
if [[ -z "$STATUS" ]]; then
  echo "(working tree limpo)"
else
  echo "$STATUS"
fi
echo ""

echo "=== Últimos 5 commits ==="
git log --oneline -5 --decorate 2>/dev/null || echo "(sem histórico)"

# --- Alertas específicos de Laravel ------------------------------------------
ALERTS=()
for f in .env .env.production .env.local; do
  if git ls-files --error-unmatch "$f" >/dev/null 2>&1; then
    ALERTS+=("🔴 $f está VERSIONADO no git — contém segredos (APP_KEY, DB_PASSWORD...)")
  fi
done
if git ls-files --error-unmatch vendor >/dev/null 2>&1 || [[ -n "$(git ls-files vendor 2>/dev/null | head -1)" ]]; then
  ALERTS+=("🟡 vendor/ está versionado — deveria estar no .gitignore")
fi
if [[ -n "$(git ls-files 'public/build' 2>/dev/null | head -1)" ]]; then
  ALERTS+=("🟡 public/build/ (assets do Vite) está versionado")
fi
MIGRATIONS=$(git status --short -- database/migrations 2>/dev/null || true)
if [[ -n "$MIGRATIONS" ]]; then
  while IFS= read -r line; do
    code="${line:0:2}"; file="${line:3}"
    if [[ "$code" == *"?"* || "$code" == *"A"* ]]; then
      ALERTS+=("🟢 migration nova: $file")
    else
      ALERTS+=("🟡 migration EXISTENTE alterada: $file (se já rodou em produção, crie uma nova)")
    fi
  done <<< "$MIGRATIONS"
fi
if git status --short -- composer.json 2>/dev/null | grep -q . && ! git status --short -- composer.lock 2>/dev/null | grep -q .; then
  ALERTS+=("🟡 composer.json alterado sem composer.lock correspondente")
fi

if [[ ${#ALERTS[@]} -gt 0 ]]; then
  echo ""
  echo "=== Alertas Laravel ==="
  printf '%s\n' "${ALERTS[@]}"
fi

if [[ "$FULL" -eq 1 ]]; then
  echo ""
  echo "=== Diff stat (não-committed) ==="
  git diff --stat 2>/dev/null || true
  echo ""
  echo "=== Diff stat (staged) ==="
  git diff --cached --stat 2>/dev/null || true

  if [[ -n "$STATUS" ]]; then
    echo ""
    echo "=== Alterações por camada Laravel ==="
    git status --short --untracked-files=all 2>/dev/null | cut -c4- | awk '
      {
        f = $0; sub(/.* -> /, "", f)
        if      (f ~ /^app\/Models\//)                 k = "Models (Eloquent)"
        else if (f ~ /^app\/Http\/Controllers\//)      k = "Controllers"
        else if (f ~ /^app\/Http\/Requests\//)         k = "Form Requests"
        else if (f ~ /^app\/Http\/Middleware\//)       k = "Middleware"
        else if (f ~ /^app\/Http\/Resources\//)        k = "API Resources"
        else if (f ~ /^app\/Policies\//)               k = "Policies"
        else if (f ~ /^app\/(Actions|Services)\//)     k = "Actions/Services"
        else if (f ~ /^app\/(Jobs|Events|Listeners|Notifications|Mail)\//) k = "Jobs/Events/Notifications"
        else if (f ~ /^app\/View\//)                   k = "View Components (classe)"
        else if (f ~ /^app\//)                         k = "app/ (outros)"
        else if (f ~ /^routes\//)                      k = "Rotas"
        else if (f ~ /^database\/migrations\//)        k = "Migrations"
        else if (f ~ /^database\/(factories|seeders)\//) k = "Factories/Seeders"
        else if (f ~ /^resources\/views\//)            k = "Views Blade"
        else if (f ~ /^resources\/(css|js)\//)         k = "Assets (Vite)"
        else if (f ~ /^config\//)                      k = "Config"
        else if (f ~ /^tests\//)                       k = "Testes"
        else if (f ~ /^(composer\.(json|lock)|package(-lock)?\.json)$/) k = "Dependências"
        else                                           k = "Outros"
        n[k]++; files[k] = files[k] "\n    " f
      }
      END { for (k in n) printf "%s (%d):%s\n", k, n[k], files[k] }
    '
  fi
fi
