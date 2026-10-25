# Scripts utilitários (stack Laravel)

Scripts bash usados pelos agents Haiku/Sonnet para tarefas determinísticas em projetos **Laravel 12 + Blade + Alpine + Tailwind**.
Cada script tem header explicando uso. Todos são read-only por padrão (exceto `batch-rename.sh` com `--apply`).

## Lista

| Script | Função | Usado por |
|--------|--------|-----------|
| `find-files.sh` | Localiza arquivos por nome/glob/ext (entende `--ext blade.php`) | `file-finder` |
| `grep-code.sh` | Busca conteúdo com tipos Laravel (`--type php\|blade\|migration\|route\|config\|test`) | `code-searcher` |
| `batch-rename.sh` | Renomeio em lote (dry-run obrigatório, aviso PSR-4 para classes PHP) | `batch-renamer` |
| `analyze-logs.sh` | Análise do `storage/logs/laravel.log` (níveis, exceptions, origem no código) + logs genéricos | `log-analyzer` |
| `convert-format.sh` | Case conversion, convenções Laravel (Model ↔ tabela ↔ rota ↔ componente) + JSON/YAML/CSV | `format-converter` |
| `git-summary.sh` | Estado read-only do repo + alertas Laravel (`.env` versionado, migrations alteradas) | `git-inspector` |
| `dep-check.sh` | Composer (`require`, `composer outdated --direct`, `composer audit`) + package.json do Vite | `dependency-checker` |

Scripts específicos de produto (`ads-discord-payload.py`, `ads-discord-resumo.sh`, `elevenlabs-key.mjs`) não dependem da stack e seguem como no original.

## Exemplos rápidos

```bash
~/.claude/scripts/find-files.sh "*Policy.php" ./app
~/.claude/scripts/grep-code.sh "->hasMany\(" ./app/Models --type php
~/.claude/scripts/grep-code.sh "Route::resource" . --type route
~/.claude/scripts/analyze-logs.sh storage/logs --errors --since "1 hour ago"
~/.claude/scripts/convert-format.sh to-table BlogPost        # blog_posts
~/.claude/scripts/dep-check.sh . --audit
~/.claude/scripts/git-summary.sh . --full
```

## Dependências

Obrigatórias: `bash`, `python3`, `awk`, `git`.
Para o `dep-check.sh`: `composer` no PATH (e `npm`/`pnpm` se quiser checar o front).

Opcionais (melhoram performance):
- `fd` / `fdfind` — alternativa rápida ao `find` (find-files.sh)
- `rg` (ripgrep) — alternativa rápida ao `grep` (grep-code.sh)
- `yq` — JSON↔YAML mais rápido (convert-format.sh)
- `php` + `vendor/` no diretório atual — `convert-format.sh` usa `Illuminate\Support\Str` para plural/singular idêntico ao do framework

Sem essas, os scripts usam fallback nativo (find/grep/python3).

## Convenções

- Todos os scripts usam `set -euo pipefail`
- Erros vão para stderr, output útil para stdout
- Ações destrutivas exigem `--apply` explícito (default: dry-run)
- Sempre ignoram `vendor/`, `node_modules/`, `.git/`, `storage/`, `bootstrap/cache/`, `public/build/`, `dist/`, `build/`
- Nenhum script roda `composer install/update`, `php artisan migrate` ou qualquer comando que altere o projeto
