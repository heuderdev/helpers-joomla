# Claude — agents, skills, scripts e rules (stack Laravel)

Versão **Laravel** do seu `~/.claude`, com a mesma organização: **genéricos** (servem em qualquer projeto) e **específicos** (amarrados a um produto seu).

**Stack alvo:** Laravel 12 · PHP 8.3+ · Blade + Alpine.js 3 + Tailwind CSS (Vite) · Eloquent (MySQL/PostgreSQL) · Sanctum · Pest · Pint · Larastan · Composer.

Nenhuma credencial foi copiada: `.env`, tokens OAuth, `client_secret` e `token.json` foram removidos. Reconfigure antes de usar as skills que dependem de API.

```
agente-laravel/
├── agents/
│   ├── genericos/
│   └── especificos/
├── skills/
│   ├── genericos/
│   └── especificos/
├── scripts/
├── rules/
└── RTK.md
```

## O que mudou em relação à versão Node/Nuxt

| Antes | Agora |
|---|---|
| `especialista-node` (Express + Mongoose) | `especialista-laravel` (rotas, FormRequest, Actions, Eloquent, Policies, Resources, Pest) |
| `especialista-nuxt` (Vue, Pinia, composables) | `especialista-blade` (layouts, componentes Blade, Alpine, formulários) |
| `especialista-js` (composables Vue) | `especialista-js` (Alpine.js + ES modules via Vite) |
| `especialista-css` (SASS indentado) | `especialista-css` (Tailwind + tokens CSS) |
| `dep-check.sh` (npm/pnpm) | `dep-check.sh` (Composer primeiro; npm só para o Vite) |
| Templates GitLab `base-nuxtjs` / `base-node` | `base-laravel` / `base-laravel-api` |
| `api-upload` em Node | Endpoint Laravel com `Storage::disk('s3')` (DigitalOcean Spaces) |
| MCP com SDK TypeScript | MCP com `laravel/mcp` |

Skills de vídeo (Remotion, After Effects), OBS, YouTube, Instagram e Meta Ads **não dependem de stack**: foram copiadas como estão, só com as referências de backend ajustadas. Remotion e os utilitários de puppeteer continuam em Node porque são ferramentas, não a aplicação. Os agents da UnicPages continuam descrevendo o produto real (feito em Nuxt).

## Agents

**Genéricos (16)** — `especialista-laravel`, `especialista-blade`, `especialista-css`, `especialista-js`, `especialista-html`, `especialista-ui`, `especialista-seo`, `especialista-seguranca`, `especialista-desempenho`, `code-searcher`, `file-finder`, `git-inspector`, `log-analyzer`, `dependency-checker`, `batch-renamer`, `format-converter`

**Específicos (6)** — `especialista-unicpages`, `arquiteto-templates-unicpages` (+ REFERENCE), `criador-carrossel-social`, `especialista-trafego`, `revisor-apple`

## Skills

**Genéricas (13)** — `gitlab`, `docker-deploy`, `mcp-builder`, `api-upload-files`, `figma-export`, `site-mockup`, `revisor-saas`, `publishing-updates`, `postiz-publish`, `magnific-image`, `openai-image-edit`, `transcribe-audio`, `remove-silence`

**Específicas (16)** — `appstore-publish`, `unicpages-edit`, `meta-ads`, `gestaodev-video`, `gestaodev-instagram`, `pagzero-video`, `dominnus-video`, `workshop-video`, `ddf-reels-aula`, `mentoria-edusites`, `editor-stories`, `portfolio-post`, `youtube-live`, `youtube-thumbnail`, `obs-cenas`, `after-effects`

## Scripts

Utilitários bash que os agents Haiku invocam em vez de "pensar" — custo zero de inferência na parte mecânica: `find-files.sh`, `grep-code.sh`, `batch-rename.sh`, `analyze-logs.sh`, `convert-format.sh`, `dep-check.sh`, `git-summary.sh`. Todos ignoram `vendor/`, `node_modules/`, `storage/` e `bootstrap/cache/`.

## Rules

| Arquivo | Conteúdo |
|---|---|
| `performance.md` | Estratégia de modelo (Opus pensa, Sonnet faz, Haiku busca) e quando delegar |
| `agents.md` | Orquestração, uso imediato de `especialista-laravel`/`especialista-blade` e execução paralela |
| `security.md` | Checklist de segurança pré-commit para Laravel (FormRequest, Policies, CSRF, mass assignment, `config()` vs `env()`) |
| `dev-servers.md` | Mapa de portas fixas por projeto (`artisan serve` + Vite) |

`agents.md` e `dev-servers.md` citam seus projetos reais (UnicPages, PagZero, Edu Sites…) — foram mantidos como referência. Ajuste ao adotar num projeto novo.

## Como usar

Copie o que interessa para o `.claude/` do projeto Laravel, ou para `~/.claude/`:

```bash
mkdir -p meu-projeto/.claude
cp -r agente-laravel/agents/genericos/*  meu-projeto/.claude/agents/
cp -r agente-laravel/skills/genericos/*  meu-projeto/.claude/skills/
cp -r agente-laravel/scripts             meu-projeto/.claude/
cp -r agente-laravel/rules               meu-projeto/.claude/
```
