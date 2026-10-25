---
name: gitlab
description: Especialista em GitLab via API REST. Cria projetos, grupos e subgrupos, lista repositórios reais, mostra info de projeto, e faz scaffolding de novos repos a partir dos templates base Laravel (base-laravel, base-laravel-api, base-laravel-shared, base-devops do team-lecdt) com clone + ajuste + push. Autentica automaticamente via ~/gitlab-login.sh (token nunca exposto). Use quando o usuário pedir "criar projeto/grupo/repo no gitlab", "listar meus projetos", "novo sistema/app", "scaffolding", "começar projeto novo com a base", "criar a estrutura frontend/backend/devops", ou equivalentes.
---

# GitLab

Skill que dá ao Claude controle sobre o GitLab do usuário via API REST, mais scaffolding a partir dos templates base.

## Autenticação

Todas as ações usam o Personal Access Token extraído de `~/gitlab-login.sh`. As contas disponíveis são:
`gift4cars, mudeei, itaipu, gestaodev, boxcarclub, clubetruckbox, locafacil, oligoanalytics, aurea, eduardo`.

**Conta default: `eduardo`** (usuário `eduardolecdt`). Quando o usuário diz "meus projetos", "cria pra mim", "novo sistema" sem especificar conta, use `eduardo`.

O token **nunca** deve ser impresso no output. Os scripts extraem o token internamente e só o usam em chamadas curl / git.

## Convenção de estrutura do usuário

O usuário organiza **cada projeto/sistema** como um grupo no GitLab com 3 subgrupos:

```
<projeto>/                  (grupo raiz)
├── frontend/               (subgrupo)  → monólitos Laravel Blade + Alpine + Tailwind (app, admin, web, ...)
├── backend/                (subgrupo)  → APIs Laravel (api-app, api-admin) + pacote Composer compartilhado (shared)
└── devops/                 (subgrupo)  → repos de infra (server-general, server-database, docs, database-backup)
```

Exemplos reais: `luminiapp/{frontend,backend,devops}`, `mudeei/{frontend,backend,devops}`, `unic-pages/{...}`.

> **Laravel muda a divisão front/back:** um app Blade renderiza as telas **e** tem rotas, controllers, models e fila — ele mora em `frontend/` porque é o que o usuário acessa. Repos em `backend/` só existem quando há consumidores fora do navegador (app mobile, integrações, MCP) que precisam de uma API JSON com Sanctum. Sistema só-web = só `frontend/` + `devops/`.

E na máquina local, o usuário organiza como:
```
<pasta-base-do-projeto>/
├── frontend/   → clones dos repos frontend
├── backend/    → clones dos repos backend
└── devops/     → clones dos repos devops
```

## Templates base (em team-lecdt)

| Template | Repo GitLab | Vira que tipo de repo | Pasta local |
|----------|-------------|------------------------|-------------|
| `laravel` | `team-lecdt/base-laravel` | Monólito Laravel 12 + Blade + Alpine + Tailwind (Vite), auth + perfil prontos (Breeze Blade), Pest, Pint, Larastan — serve pra app, admin e web | `frontend/` |
| `api`     | `team-lecdt/base-laravel-api` | API Laravel 12 só JSON (Sanctum, API Resources, FormRequests, Policies), auth + perfil + módulo **Storage** (upload pro Spaces via `Storage::disk('s3')` + webp) | `backend/` |
| `shared`  | `team-lecdt/base-laravel-shared` | Pacote Composer privado compartilhado entre apps (models `Usuario`/`Administrador`, enums, traits, helpers de data), instalado via repositório VCS do GitLab | `backend/shared` |
| `devops`  | `team-lecdt/base-devops` | Infra (4 repos: server-general, server-database, docs, database-backup) | `devops/` |

> O antigo template `upload` (api-upload separada) deixou de existir: upload é o módulo Storage da `base-laravel-api` (ou das rotas `/api/storage/*` do próprio monólito). O alias `upload` do script aponta pra `base-laravel-api`. Ver a skill `api-upload-files`.

> O `base-devops` é um **mono-template** com 4 subpastas. Cada subpasta vira um repo separado no `devops/` do projeto. O README dele explica isso.

### Mapeamento típico de um projeto completo

Um sistema padrão do usuário (espelhando o `edu-sites`) tem:
- `frontend/app` ← `laravel` (área logada do cliente)
- `frontend/admin` ← `laravel` (painel administrativo)
- `frontend/web` ← `laravel` (site público / landing, SEO)
- `backend/api-app` ← `api` (só se houver app mobile/integrações)
- `backend/shared` ← `shared` (só se 2+ apps dividirem models)
- `devops/server-general` ← `devops` (subpasta server-general)
- `devops/server-database` ← `devops` (subpasta server-database)
- `devops/docs` ← `devops` (subpasta docs)
- `devops/database-backup` ← `devops` (subpasta de backup do MySQL/PostgreSQL)

## Scripts

Dois scripts em `scripts/`:

- **`gitlab-api.sh`** — operações na API (list, create, info, delete)
- **`gitlab-scaffold.sh`** — clona template + prepara repo novo + push opcional

### gitlab-api.sh

```bash
SK=~/.claude/skills/gitlab/scripts

# Identidade
$SK/gitlab-api.sh whoami [conta]

# Listar
$SK/gitlab-api.sh list-projects [conta] [--mine|--membership|--group <grupo>]
$SK/gitlab-api.sh list-groups [conta]

# Info de um projeto (path ou id)
$SK/gitlab-api.sh info <grupo/sub/repo> [conta]

# Criar projeto (default: private, com README, branch main)
$SK/gitlab-api.sh create-project <nome> [conta] [--group <grupo>] [--public] [--no-readme] [--description "..."]

# Criar grupo ou subgrupo
$SK/gitlab-api.sh create-group <nome> [conta] [--parent <id-ou-path>] [--public] [--description "..."]

# Deletar projeto (exige CONFIRM=yes no ambiente)
CONFIRM=yes $SK/gitlab-api.sh delete-project <grupo/sub/repo> [conta]
```

### gitlab-scaffold.sh

```bash
SK=~/.claude/skills/gitlab/scripts

# Clona um template numa pasta local (sem push — só prepara o esqueleto)
$SK/gitlab-scaffold.sh laravel ~/projetos/unic/frontend/app [conta]

# Clona + ajusta composer.json/.env (nome + porta fixa) + instala + primeiro push
# (o projeto remoto precisa já existir)
$SK/gitlab-scaffold.sh laravel ~/projetos/unic/frontend/app eduardo \
  --remote unic/frontend/app --push --name "Unic" --port 7202 --install

# API JSON
$SK/gitlab-scaffold.sh api ~/projetos/unic/backend/api-app eduardo \
  --remote unic/backend/api-app --push --name "Unic" --port 7210
```

## Fluxo de execução

### Caso 1 — Listar projetos / grupos / info

Direto: rode `list-projects`, `list-groups` ou `info` e reporte ao usuário de forma legível. Não precisa confirmar — é read-only.

### Caso 2 — Criar projeto OU grupo isolado

1. Confirme com o usuário: nome, conta (default eduardo), grupo de destino, visibilidade (default private).
2. Rode `create-group` ou `create-project`.
3. Reporte o path + URL + clone URL.

Como criação é ação remota visível, confirme o resumo antes (a menos que o usuário já tenha dado todos os detalhes claramente).

### Caso 3 — "Criar um sistema/projeto novo completo" (o caso forte)

O usuário quer um projeto novo com a estrutura completa. Faça assim:

1. **Pergunte o essencial** (se não foi dito): nome do projeto (slug), e quais partes quer (frontend? backend? devops? todos?), e a pasta-base local onde clonar.
2. **Crie o grupo raiz** (se não existir): `create-group <projeto> eduardo`
3. **Crie os subgrupos** necessários: `create-group frontend eduardo --parent <projeto>` etc.
4. **Crie os repos** dentro dos subgrupos:
   - frontend → `create-project app eduardo --group <projeto>/frontend` (+ admin, web)
   - backend → `create-project api-app eduardo --group <projeto>/backend` (só se houver consumidor não-web)
   - devops → `create-project server-general eduardo --group <projeto>/devops` (+ server-database, docs, database-backup)
5. **Faça scaffolding** de cada repo a partir do template, clonando na pasta local e empurrando:
   - `gitlab-scaffold.sh laravel <pasta>/frontend/app eduardo --remote <projeto>/frontend/app --push --name "<Nome>" --port <porta-fixa>`
   - `gitlab-scaffold.sh api <pasta>/backend/api-app eduardo --remote <projeto>/backend/api-app --push --name "<Nome>" --port <porta-fixa>`
   - Porta fixa: siga a tabela de portas do projeto (`rules/dev-servers.md` — fronts N000-N009, backs N010+).
   - Para devops, o template tem 4 subpastas; clone o base-devops e separe cada subpasta em seu repo (ver nota abaixo).
6. **Lembre o usuário** dos placeholders a ajustar: `.env` (`APP_KEY` via `php artisan key:generate`, `DB_*`, `AWS_*` do Spaces, `MAIL_*`, `SANCTUM_STATEFUL_DOMAINS`), `php artisan migrate --seed`, domínios e IPs no devops.

> **Nota sobre devops**: como `base-devops` é mono-repo de 4 templates, o jeito mais simples é: clonar o base-devops uma vez numa pasta temporária, e pra cada subpasta (`server-general`, etc) criar o repo e empurrar só aquela subpasta. Ou, se o usuário preferir, manter tudo num único repo `devops` do projeto. **Pergunte qual ele quer.**

### Confirmação antes de ações remotas

Criar grupos/projetos e push são ações remotas. Sempre mostre um resumo do que vai criar antes de executar, especialmente no Caso 3 (que cria vários recursos). Para deleção, **sempre** confirme explicitamente e use `CONFIRM=yes`.

## Defaults do usuário (confirmados)

- **Visibilidade**: `private`
- **Branch default**: `main`
- **Descrição do projeto**: **NÃO** setar (deixar vazio). O padrão do usuário é repos sem descrição — não passe `--description` em `create-project`/`create-group` a menos que ele peça explicitamente.
- **Auto-clone**: sim, na pasta que o usuário escolher (pergunte a pasta-base se não foi dita)
- **Conta default**: `eduardo`
- **Estrutura**: projeto > {frontend, backend, devops} > repos

## Erros comuns

- `composer install` falha com `Could not find package team-lecdt/base-laravel-shared`: o app não tem o repositório VCS do GitLab em `composer.json` (`"repositories": [{"type": "vcs", "url": "https://gitlab.com/<projeto>/backend/shared.git"}]`) ou falta `composer config --global gitlab-token.gitlab.com <token>` (use o token do `gitlab-login.sh` sem imprimir).
- `No application encryption key has been specified`: faltou `php artisan key:generate` (rode o scaffold com `--install`).

- `404 Group Not Found` ao criar projeto com `--group`: o grupo/subgrupo ainda não existe. Crie o grupo antes.
- `400 has already been taken`: já existe projeto/grupo com esse path. Escolha outro nome ou use o existente.
- `protected branch` no push: a `main` é protegida. O scaffold já trata fazendo merge `--allow-unrelated-histories` quando há README inicial. Se persistir, o usuário precisa desproteger a branch temporariamente ou dar push via MR.
- Token sem escopo `api`: regenerar PAT com escopo `api` em https://gitlab.com/-/user_settings/personal_access_tokens
