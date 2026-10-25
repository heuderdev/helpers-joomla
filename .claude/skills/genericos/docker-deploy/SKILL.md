---
name: docker-deploy
description: Build e push de imagem Docker de apps Laravel (PHP-FPM 8.3 + nginx ou FrankenPHP, composer install --no-dev, build do Vite, artisan optimize) para o GitLab Container Registry. Faz login automaticamente via ~/gitlab-login.sh (sem expor senhas), detecta plataforma/projeto/repo a partir do diretório atual ou repo git, executa cleanup + build linux/amd64 + push. Use quando o usuário pedir "build do docker", "deploy da imagem", "build e push", "subir imagem pro registry", "rodar o build", ou equivalentes em qualquer repo que tenha um Dockerfile.
---

# Docker Deploy (Laravel)

Skill que reproduz o fluxo manual do usuário para apps **Laravel 12** (monólito Blade + Alpine + Tailwind ou API):
1. Login no `registry.gitlab.com` usando `~/gitlab-login.sh <conta>`
2. Carrega `GITLAB_TOKEN` do `.env` do repo (passado como secret BuildKit)
3. `docker builder prune -a` (limpa cache)
4. `docker build --platform linux/amd64 --secret id=gitlab_token,env=GITLAB_TOKEN -t registry.gitlab.com/<group>/<platform>/<repo>:latest .`
5. `docker push registry.gitlab.com/<group>/<platform>/<repo>:latest`

Os Dockerfiles desses repos usam `RUN --mount=type=secret,id=gitlab_token ...` (BuildKit) — em Laravel, o uso típico é autenticar o **Composer** em pacotes privados do GitLab (`composer config gitlab-token.gitlab.com`) durante o `composer install`. O token nunca aparece em layer da imagem, em log do build, nem em metadata. O Dockerfile de referência está na seção **Dockerfile Laravel de referência**.

Funciona com **qualquer projeto/conta** registrada em `~/gitlab-login.sh`. Por padrão a conta é `eduardo` (group `luminiapp`).

## Quando usar

- Usuário pede "build do docker", "build e push", "deploy da imagem", "subir pro registry", "rodar o deploy"
- Usuário menciona um repo específico ("build da api", "deploy do admin")
- Após mudanças em código que precisam ir pro registry

**Não use** se o usuário ainda está editando código sem ter pedido deploy. Não use se faltar um Dockerfile no diretório.

## Fluxo de execução

### Passo 1 — Identificar parâmetros

Determine três coisas:

1. **Diretório do build** — onde está o `Dockerfile`. Tipicamente:
   - Apps backend/frontend: `<repo>/docker/Dockerfile` (build context = `<repo>/docker/`)
   - Apps Laravel (monólito ou API): `<repo>/Dockerfile` (build context = `<repo>/`, porque o Dockerfile copia `composer.json`, `package.json` e o código)
   - Apps devops (mysql-backup): `<repo>/Dockerfile` (build context = `<repo>/`)
   - Confira com `ls <repo>/docker/Dockerfile` antes de assumir.

2. **`<group>` / `<platform>` / `<repo>`** — derivados do path:
   - Path típico: `.../Repositórios/<platform>/<repo>` onde platform ∈ {`frontend`, `backend`, `devops`}
   - Group default: `luminiapp` (a menos que o `git remote -v` diga outra coisa)
   - **Sempre confirme com o `git remote get-url origin`** — extrair `<group>/<platform>/<repo>` da URL `gitlab.com/<group>/<platform>/<repo>(.git)`

3. **Conta de login** — qual entrada do `~/gitlab-login.sh` usar:
   - `luminiapp` → conta `eduardo`
   - Outros groups → mapear pelo nome (ex.: `boxcarclub` → `boxcarclub`, `mudeei` → `mudeei`)
   - Em caso de dúvida, pergunte ao usuário antes de prosseguir.

Se faltar alguma informação ou houver ambiguidade (ex.: dois Dockerfiles, sem `.env`), **pare e pergunte**.

### Passo 2 — Pré-checks

Antes do build, valide:

```bash
# Dockerfile existe?
ls "<build_context>/Dockerfile"

# .env tem GITLAB_TOKEN?
grep -q '^GITLAB_TOKEN=' "<repo_root>/.env" && echo OK

# É Laravel? (artisan + composer.lock versionados — o build usa o lock)
ls "<repo_root>/artisan" "<repo_root>/composer.lock"

# .dockerignore exclui vendor/, node_modules/, .env e storage/*.log?
grep -E '^(vendor|node_modules|\.env)' "<repo_root>/.dockerignore"
```

Se `.env` não tem `GITLAB_TOKEN` ou não existe, avise o usuário e pare. Se o `.dockerignore` não exclui `.env`, **pare** — o `.env` com `APP_KEY` e credenciais iria parar dentro da imagem.

Mostre ao usuário um resumo antes de executar:

```
Vou fazer build e push de:
  Repo:      <repo_root>
  Build dir: <build_context>
  Imagem:    registry.gitlab.com/<group>/<platform>/<repo>:latest
  Conta:     <conta>
```

### Passo 3 — Login

Executar via Bash:

```bash
~/gitlab-login.sh <conta>
```

Esse script faz `docker logout` + `docker login` com `--password-stdin` (não vaza senha no histórico). Se falhar, pare e mostre o erro.

### Passo 4 — Carregar GITLAB_TOKEN e buildar com secret

Extraia só o `GITLAB_TOKEN` do `.env` (não use `source` porque outras envs como `APP_KEY=base64:...`, `DB_PASSWORD` ou `MAIL_PASSWORD` podem ter `=`, `&` ou `$` que quebram o parser do shell), exporte como variável de ambiente, e passe via `--secret` para o BuildKit:

```bash
cd <build_context> && \
  export GITLAB_TOKEN=$(grep '^GITLAB_TOKEN=' <repo_root>/.env | cut -d= -f2-) && \
  docker builder prune -a -f && \
  DOCKER_BUILDKIT=1 docker build --platform linux/amd64 \
    --provenance=false --sbom=false \
    --secret id=gitlab_token,env=GITLAB_TOKEN \
    -t registry.gitlab.com/<group>/<platform>/<repo>:latest . && \
  docker push registry.gitlab.com/<group>/<platform>/<repo>:latest
```

Notas:
- `--secret id=gitlab_token,env=GITLAB_TOKEN` pega o valor da var de ambiente `GITLAB_TOKEN` e expõe no Dockerfile como `/run/secrets/gitlab_token` durante a execução do `RUN --mount=type=secret,id=gitlab_token ...`. O token **nunca** persiste em layer da imagem.
- O Dockerfile precisa ter `# syntax=docker/dockerfile:1.7` (ou superior) no topo. Se não tiver, é um Dockerfile antigo no formato `ARG GITLAB_TOKEN` — alerte o usuário e ofereça migrar.
- `DOCKER_BUILDKIT=1` garante BuildKit (já é default em versões recentes do Docker, mas explicitar não custa).
- **`--provenance=false --sbom=false` são OBRIGATÓRIOS para Docker Swarm.** Sem elas, o BuildKit moderno anexa um *attestation manifest* e publica a imagem como **OCI image index** com duas entradas: a `linux/amd64` real e uma com `"architecture": "unknown"`. O Swarm não entende esse formato — resolve o digest do index, tenta puxar e rejeita a task com `No such image: <imagem>@sha256:<digest>`, apontando justamente o digest recém-publicado. O serviço fica preso em `0/1` e a aplicação sai do ar.
  Para conferir se uma imagem está no formato certo: `docker manifest inspect <imagem>` deve devolver `application/vnd.docker.distribution.manifest.v2+json` e **nenhuma** ocorrência de `unknown`. Se vier `image.index.v1+json` com `unknown`, refaça o build com as flags.
  (Incidente real: 5 serviços da PagZero caíram simultaneamente após um deploy sem essas flags.)
- `grep ... | cut -d= -f2-` é mais robusto que `source .env` para `.env` que tenha `&`, `=` ou caracteres especiais nos valores.
- **Nunca** passe `APP_KEY`, `DB_PASSWORD` etc. como `--build-arg`: eles ficam gravados no histórico da imagem. Segredos de runtime entram via `environment`/`secrets` da stack (Swarm) ou `.env` montado no container, nunca no build.
- `docker builder prune -a -f` usa `-f` (force) para evitar prompt interativo.
- Build sempre `--platform linux/amd64` (servidor é Linux x86_64).
- Se algum passo falhar, **não execute os seguintes** (`&&` já garante isso).

### Passo 5 — Reportar resultado

Resumo curto: imagem publicada, tag, próximos passos sugeridos (ex.: "redeploy a stack no servidor com `docker stack deploy ...`" se for um repo de app, ou nada se for só uma imagem). Em apps Laravel, lembre que **migrations não rodam no build**: depois do deploy, rode `docker exec <container> php artisan migrate --force` (ou deixe o entrypoint fazer isso numa única réplica) e reinicie os workers de fila (`php artisan queue:restart`).

## Como detectar os parâmetros automaticamente

Para cada repo dentro de `Repositórios/<platform>/<repo>`:

```bash
# A partir do cwd ou de um repo passado pelo usuário
cd "<repo_root>"
remote=$(git remote get-url origin)
# Ex.: https://gitlab.com/luminiapp/backend/api.git
path=$(echo "$remote" | sed -E 's|.*gitlab\.com[/:]||; s|\.git$||')
group=$(echo "$path" | cut -d/ -f1)   # luminiapp
platform=$(echo "$path" | cut -d/ -f2) # backend
repo=$(echo "$path" | cut -d/ -f3)    # api
```

Para a conta de login, use este mapeamento (ajuste se o usuário pedir outro):

| Group GitLab          | Conta `gitlab-login.sh` |
|----------------------|------------------------|
| `luminiapp`          | `eduardo`              |
| `finnanci` (legado)  | `eduardo`              |
| `boxcarclub`         | `boxcarclub`           |
| `clubetruckbox`      | `clubetruckbox`        |
| `mudeei`             | `mudeei`               |
| `itaiputransformadores` ou `itaipu` | `itaipu` |
| `gestaodev`          | `gestaodev`            |
| `locafacil`          | `locafacil`            |
| `oligoanalytics`     | `oligoanalytics`       |
| `aurea` ou `aureamedtech` | `aurea`           |
| `gift4cars`          | `gift4cars`            |

Se o group não estiver na tabela, pergunte ao usuário.

## Casos especiais

### Build context diferente do repo root

Repos legados colocam o `Dockerfile` em `docker/` e o build context **precisa ser essa subpasta** (porque o Dockerfile faz `git clone` do próprio repo de novo, e copia só uns arquivos locais). Nos repos Laravel novos o padrão é `Dockerfile` na raiz, com `COPY` do código (mais rápido e reproduzível que `git clone`). Para esses, o build é:

```bash
cd <repo_root>/docker && docker build ... .
```

Outros (apps Laravel, mysql-backup, ou qualquer repo onde o Dockerfile copie arquivos do próprio repo via `COPY`) usam o root como contexto:

```bash
cd <repo_root> && docker build ... .
```

**Como decidir**: se há `Dockerfile` na raiz, use a raiz. Se há `docker/Dockerfile`, use `docker/`. Se há ambos, pergunte.

### Múltiplos repos em sequência

Se o usuário pedir build de N repos ("build da api, do admin e do web"), faça **um login só** no início e depois itere. Não precisa relogar entre repos do mesmo group.

### Modo dry-run

Se o usuário quiser só ver o que seria executado, mostre os comandos sem rodar (use `echo` na frente). Útil para revisar antes de gastar bandwidth.

## Dockerfile Laravel de referência

Use quando o repo Laravel ainda não tiver Dockerfile, ou para revisar um existente. Multi-stage: **Composer** (vendor) → **Node** (build do Vite, que precisa do `vendor/` por causa de pacotes como Livewire/Ziggy) → **runtime** enxuto. O runtime padrão é **FrankenPHP** (um processo só, HTTP + PHP, ótimo para Swarm); a variante php-fpm + nginx vem logo abaixo.

```dockerfile
# syntax=docker/dockerfile:1.7

# ---------- 1. Dependências PHP ----------
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# Token do GitLab só existe durante este RUN (pacotes privados); nunca vira layer
RUN --mount=type=secret,id=gitlab_token \
    if [ -f /run/secrets/gitlab_token ]; then \
      composer config --global gitlab-token.gitlab.com "$(cat /run/secrets/gitlab_token)"; \
    fi && \
    composer install --no-dev --no-interaction --no-scripts --no-autoloader --prefer-dist && \
    rm -f /tmp/auth.json ~/.composer/auth.json /tmp/composer/auth.json
COPY . .
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

# ---------- 2. Assets (Vite + Tailwind) ----------
FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY --from=vendor /app /app
RUN npm run build && rm -rf node_modules

# ---------- 3. Runtime ----------
FROM dunglas/frankenphp:1-php8.3-alpine AS runtime
RUN install-php-extensions pdo_mysql pdo_pgsql redis intl zip bcmath gd exif pcntl opcache
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
WORKDIR /app
COPY --from=assets --chown=www-data:www-data /app /app
RUN rm -rf tests .git node_modules && \
    mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache && \
    chown -R www-data:www-data storage bootstrap/cache
ENV SERVER_NAME=:8080 APP_ENV=production LOG_CHANNEL=stderr
USER www-data
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=3s CMD wget -qO- http://127.0.0.1:8080/up || exit 1
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint
ENTRYPOINT ["entrypoint"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
```

`docker/entrypoint.sh` — o cache de config/rotas/views é gerado **no start** (não no build), porque o `config:cache` congela os valores de ambiente e o build não tem o `.env` de produção:

```sh
#!/bin/sh
set -e
php artisan optimize          # config:cache + route:cache + view:cache + event:cache
php artisan storage:link --force >/dev/null 2>&1 || true
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
  php artisan migrate --force  # ligue só em UMA réplica (ou num job de release)
fi
exec "$@"
```

`docker/php.ini` (produção):

```ini
opcache.enable=1
opcache.validate_timestamps=0
opcache.memory_consumption=256
opcache.max_accelerated_files=20000
realpath_cache_size=4096K
memory_limit=256M
upload_max_filesize=512M
post_max_size=512M
expose_php=Off
```

`.dockerignore` (obrigatório):

```
.git
.env
.env.*
!.env.example
vendor
node_modules
public/build
public/hot
storage/logs/*
storage/framework/cache/*
storage/framework/sessions/*
storage/framework/views/*
bootstrap/cache/*.php
tests
docker-compose*.yml
```

### Variante php-fpm + nginx

Troque o estágio 3 por `php:8.3-fpm-alpine` (extensões via `docker-php-ext-install` ou `install-php-extensions` do `mlocati/php-extension-installer`) e rode o nginx como **serviço separado** na stack, apontando `root /app/public;` e `fastcgi_pass app:9000;` — os assets de `public/build` precisam existir nos dois containers (copie o mesmo estágio `assets` na imagem do nginx). Evite supervisord com nginx + fpm no mesmo container: o Swarm perde o healthcheck real do PHP.

### Workers de fila e scheduler

Use **a mesma imagem** com outro `command` na stack, sem expor porta:

```yaml
queue:
  image: registry.gitlab.com/<group>/<platform>/<repo>:latest
  command: ["php", "artisan", "queue:work", "--tries=3", "--max-time=3600"]
scheduler:
  image: registry.gitlab.com/<group>/<platform>/<repo>:latest
  command: ["php", "artisan", "schedule:work"]
```

### Checagens pós-build (antes do push, se o usuário quiser validar)

```bash
docker run --rm <imagem> php -v                     # PHP 8.3
docker run --rm <imagem> php artisan --version      # Laravel 12.x
docker run --rm <imagem> php -m | grep -i opcache   # OPcache presente
docker run --rm <imagem> ls public/build/manifest.json   # assets do Vite gerados
docker run --rm <imagem> sh -c 'test ! -f .env && echo "sem .env na imagem: OK"'
```

## O que NÃO fazer

- ❌ Não printe `GITLAB_TOKEN` no terminal nem em mensagens (mesmo parcial).
- ❌ Não escreva o token em arquivos temporários do projeto.
- ❌ Não use `docker login --password <senha>` direto (apenas `~/gitlab-login.sh` que usa `--password-stdin`).
- ❌ Não rode `docker push` se o build falhou.
- ❌ Não confirme com o usuário comando por comando — confirme **uma vez no Passo 2** (resumo) e depois execute tudo.
- ❌ Não tente "consertar" um Dockerfile que falhou no build sem perguntar — mostre o erro e pergunte.

## Exemplo completo (api Laravel no group luminiapp)

```bash
# Passo 1 — Detecção automática
cd "/Users/eduardolecdt/Empresas/Lumini App/Repositórios/backend/api"
# remote = https://gitlab.com/luminiapp/backend/api.git
# group=luminiapp, platform=backend, repo=api
# build_context = . (Dockerfile na raiz, app Laravel)
# conta = eduardo

# Passo 2 — Pré-checks
ls Dockerfile artisan composer.lock && grep -q '^GITLAB_TOKEN=' .env && echo OK

# Passo 3 — Login
~/gitlab-login.sh eduardo

# Passo 4 — Build + push (com BuildKit secret)
export GITLAB_TOKEN=$(grep '^GITLAB_TOKEN=' .env | cut -d= -f2-) && \
  docker builder prune -a -f && \
  DOCKER_BUILDKIT=1 docker build --platform linux/amd64 \
    --provenance=false --sbom=false \
    --secret id=gitlab_token,env=GITLAB_TOKEN \
    -t registry.gitlab.com/luminiapp/backend/api:latest . && \
  docker push registry.gitlab.com/luminiapp/backend/api:latest

# Passo 5 — Reportar
echo "✅ registry.gitlab.com/luminiapp/backend/api:latest publicada"
# próximo: docker stack deploy + php artisan migrate --force numa réplica
```
