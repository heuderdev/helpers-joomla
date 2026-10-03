#!/usr/bin/env bash
# dep-check.sh — Inspeção rápida de dependências de um projeto Laravel
# Uso: dep-check.sh [diretório] [--outdated] [--audit]
#
# Composer é o gerenciador principal (composer.json / composer.lock).
# O package.json, quando existe, é tratado só como toolchain de front (Vite,
# Tailwind, Alpine) e usa o gerenciador JS detectado pelo lockfile.
# Projetos sem composer.json caem no modo legado (npm/pnpm/yarn/bun/pip/cargo).
#
# Exemplos:
#   dep-check.sh .
#   dep-check.sh ./meu-app --outdated
#   dep-check.sh ./meu-app --audit
#
# Read-only: nunca instala, atualiza nem altera composer.json/lockfiles.

set -euo pipefail

DIR="${1:-.}"
OUTDATED=0
AUDIT=0

shift 1 2>/dev/null || true
while [[ $# -gt 0 ]]; do
  case "$1" in
    --outdated) OUTDATED=1; shift ;;
    --audit)    AUDIT=1; shift ;;
    *) shift ;;
  esac
done

cd "$DIR"

detect_js_manager() {
  if [[ -f "bun.lockb" || -f "bun.lock" ]]; then echo "bun"
  elif [[ -f "pnpm-lock.yaml" ]]; then echo "pnpm"
  elif [[ -f "yarn.lock" ]]; then echo "yarn"
  elif [[ -f "package.json" ]]; then echo "npm"
  else echo ""
  fi
}

have() { command -v "$1" >/dev/null 2>&1; }

composer_summary() {
  echo "=== Resumo composer.json ==="
  python3 - <<'PY'
import json, os, re

c = json.load(open('composer.json'))
lock = None
if os.path.exists('composer.lock'):
    lock = json.load(open('composer.lock'))

installed = {}
if lock:
    for pkg in lock.get('packages', []) + lock.get('packages-dev', []):
        installed[pkg['name']] = pkg.get('version', '?')

def is_platform(name):
    return name == 'php' or name.startswith('ext-') or name.startswith('lib-')

req = c.get('require', {}) or {}
dev = c.get('require-dev', {}) or {}

print(f"Nome:      {c.get('name', '?')}")
print(f"Tipo:      {c.get('type', '?')}")
print(f"PHP:       {req.get('php', '(não declarado)')}")

fw_constraint = req.get('laravel/framework')
if fw_constraint:
    fw_installed = installed.get('laravel/framework', '(sem composer.lock)' if not lock else '(não está no lock)')
    print(f"Laravel:   laravel/framework {fw_constraint} → instalado {fw_installed}")
else:
    print("Laravel:   laravel/framework não está em require (não é app Laravel?)")

pkgs_req = [k for k in req if not is_platform(k)]
pkgs_dev = [k for k in dev if not is_platform(k)]
print(f"Deps:      {len(pkgs_req)} require, {len(pkgs_dev)} require-dev (diretas)")
if lock:
    print(f"Lock:      {len(lock.get('packages', []))} pacotes prod + {len(lock.get('packages-dev', []))} dev resolvidos")
else:
    print("Lock:      composer.lock ausente — versões instaladas desconhecidas (rode composer install)")
print(f"Vendor:    {'presente' if os.path.isdir('vendor') else 'AUSENTE (composer install não foi rodado)'}")

platform = [k for k in req if is_platform(k) and k != 'php']
if platform:
    print(f"Extensões: {', '.join(platform)}")

def show(title, deps):
    rows = [(k, v) for k, v in deps.items() if not is_platform(k)]
    if not rows:
        return
    print()
    print(f"{title}:")
    width = max(len(k) for k, _ in rows)
    for k, v in rows:
        inst = installed.get(k, '-')
        print(f"  {k.ljust(width)}  {v:<18} instalado: {inst}")

show('require', req)
show('require-dev', dev)

scripts = c.get('scripts') or {}
if scripts:
    print()
    print('Scripts composer:')
    for k, v in scripts.items():
        if isinstance(v, list):
            v = ' && '.join(str(x) for x in v)
        print(f"  {k}: {str(v)[:90]}")

# Ferramentas de qualidade esperadas num projeto Laravel
expected = {
    'pestphp/pest': 'testes (Pest)',
    'laravel/pint': 'code style (Pint)',
    'larastan/larastan': 'análise estática (Larastan)',
}
missing = [f"{k} — {why}" for k, why in expected.items() if k not in dev and k not in req]
if missing and fw_constraint:
    print()
    print('Ferramentas de qualidade ausentes em require-dev:')
    for m in missing:
        print(f"  - {m}")
PY
}

js_summary() {
  local mgr="$1"
  echo ""
  echo "=== Front-end (package.json — toolchain Vite, gerenciador: $mgr) ==="
  python3 - <<'PY'
import json
p = json.load(open('package.json'))
deps = p.get('dependencies', {}) or {}
dev = p.get('devDependencies', {}) or {}
print(f"Deps:    {len(deps)} produção, {len(dev)} dev")
interesting = ['vite', 'laravel-vite-plugin', 'tailwindcss', '@tailwindcss/vite', 'alpinejs', 'axios']
found = [(k, deps.get(k) or dev.get(k)) for k in interesting if k in deps or k in dev]
for k, v in found:
    print(f"  {k}: {v}")
scripts = p.get('scripts') or {}
if scripts:
    print('Scripts:')
    for k, v in scripts.items():
        print(f"  {k}: {v[:80]}")
PY
}

if [[ -f "composer.json" ]]; then
  echo "=== Gerenciador detectado: composer (PHP/Laravel) ==="
  [[ -f "artisan" ]] && echo "artisan:   presente (app Laravel completa)" || echo "artisan:   ausente (pacote ou app parcial)"
  echo ""
  composer_summary

  JS_MGR=$(detect_js_manager)
  [[ -n "$JS_MGR" ]] && js_summary "$JS_MGR"

  if [[ "$OUTDATED" -eq 1 ]]; then
    echo ""
    echo "=== Composer outdated (diretas) ==="
    if have composer; then
      composer outdated --direct --no-interaction 2>&1 || true
    else
      echo "composer não encontrado no PATH"
    fi
    if [[ -n "$JS_MGR" ]]; then
      echo ""
      echo "=== $JS_MGR outdated (front) ==="
      if [[ -d node_modules ]] && have "$JS_MGR"; then
        "$JS_MGR" outdated 2>/dev/null || true
      else
        echo "(node_modules ausente ou $JS_MGR indisponível — pulei)"
      fi
    fi
  fi

  if [[ "$AUDIT" -eq 1 ]]; then
    echo ""
    echo "=== Composer audit ==="
    if have composer; then
      if [[ -f composer.lock ]]; then
        composer audit --no-interaction 2>&1 || true
      else
        echo "composer.lock ausente — composer audit precisa do lock"
      fi
    else
      echo "composer não encontrado no PATH"
    fi
    if [[ -n "$JS_MGR" ]]; then
      echo ""
      echo "=== $JS_MGR audit (front) ==="
      if have "$JS_MGR" && [[ -f package-lock.json || -f pnpm-lock.yaml || -f yarn.lock || -f bun.lock || -f bun.lockb ]]; then
        "$JS_MGR" audit 2>/dev/null || true
      else
        echo "(sem lockfile JS ou $JS_MGR indisponível — pulei)"
      fi
    fi
  fi
  exit 0
fi

# ---------------------------------------------------------------------------
# Modo legado: projeto sem composer.json
# ---------------------------------------------------------------------------
detect_manager() {
  local js
  js=$(detect_js_manager)
  if [[ -n "$js" ]]; then echo "$js"
  elif [[ -f "requirements.txt" || -f "pyproject.toml" ]]; then echo "pip"
  elif [[ -f "Cargo.toml" ]]; then echo "cargo"
  else echo "unknown"
  fi
}

MGR=$(detect_manager)
echo "=== Gerenciador detectado: $MGR (sem composer.json — não é projeto Laravel) ==="
echo ""

case "$MGR" in
  npm|pnpm|yarn|bun)
    js_summary "$MGR"
    [[ "$OUTDATED" -eq 1 ]] && { echo ""; echo "=== Outdated ==="; "$MGR" outdated 2>/dev/null || true; }
    [[ "$AUDIT"    -eq 1 ]] && { echo ""; echo "=== Audit ===";    "$MGR" audit 2>/dev/null || true; }
    ;;
  pip)
    if [[ -f "pyproject.toml" ]]; then
      echo "=== pyproject.toml ==="
      grep -E "^(name|version|dependencies)" pyproject.toml | head -20
    elif [[ -f "requirements.txt" ]]; then
      echo "=== requirements.txt (top 30) ==="
      head -30 requirements.txt
      echo ""
      echo "Total: $(wc -l < requirements.txt) deps"
    fi
    [[ "$OUTDATED" -eq 1 ]] && { echo ""; pip list --outdated 2>/dev/null || true; }
    ;;
  cargo)
    echo "=== Cargo.toml ==="
    grep -E "^(name|version|\[dependencies\])" Cargo.toml | head -20
    [[ "$OUTDATED" -eq 1 ]] && { echo ""; cargo outdated 2>/dev/null || true; }
    ;;
  *)
    echo "Gerenciador não identificado em $DIR (esperado composer.json)"
    exit 1 ;;
esac
