# Projects registry

Each project is a JSON file at `~/.claude/skills/publishing-updates/projects/<slug>.json`. Slug = kebab-case short name (e.g. `unicpages`, `gestao-dev`).

## Schema

```jsonc
{
  "name": "unicpages",
  "displayName": "UnicPages",

  // Repos to scan for git changes. Relative paths are resolved against $HOME.
  // A project can span multiple repos (frontend + backend + shared).
  "repos": [
    "/Users/eduardolecdt/Empresas/<Empresa>/Repositórios/frontend/app",
    "/Users/eduardolecdt/Empresas/<Empresa>/Repositórios/frontend/admin",
    "/Users/eduardolecdt/Empresas/<Empresa>/Repositórios/backend/api-app"
  ],

  // How many hours to look back by default when asking the user "last session".
  "defaultWindowHours": 12,

  // Paths/filenames to ignore when extracting changes (internal noise).
  "ignoreGlobs": [
    "**/CLAUDE.md",
    "**/composer.lock",
    "**/package-lock.json",
    "**/.claude/**",
    "**/.env*",
    "**/vendor/**",
    "**/node_modules/**",
    "**/public/build/**",
    "**/storage/**",
    "**/bootstrap/cache/**"
  ],

  // Conventional Commits types that should appear in the user update.
  // Types not listed are filtered out (e.g. chore, refactor, test).
  "keepCommitTypes": ["feat", "fix", "perf", "improve"],

  // Admin API configuration.
  "updateEndpoint": "https://admin.exemplo.com/api/updates",
  "updateMethod": "POST",

  // How to authenticate. Kinds supported by send.mjs:
  //   - { kind: "local-sanctum", secretsKey: "<slug>", cacheTtlSeconds: 86400 } — RECOMMENDED for Laravel.
  //     Mints a short-lived Sanctum token via `php artisan tinker` in the local admin app (.secrets.json → appDir).
  //   - { kind: "bearer", tokenEnv: "EXEMPLO_ADMIN_TOKEN" } — pre-minted Sanctum token in an env var (remote apps).
  //   - { kind: "static", tokenEnv: "...", header: "x-access-token" } — custom header (legacy APIs).
  //   - { kind: "local-jwt", secretsKey: "unicpages" } — legacy Node/Express backends (see below).
  "auth": {
    "kind": "local-sanctum",
    "secretsKey": "exemplo-laravel",
    "cacheTtlSeconds": 86400
  },

  // Upload endpoint for images. Accepts multipart/form-data.
  // Laravel: the same app exposes POST /api/storage/imagem (see skill api-upload-files).
  "uploadEndpoint": "https://admin.exemplo.com/api/storage/imagem",
  "uploadMaxWidth": 1600,
  "uploadAuth": {
    "kind": "local-sanctum",
    "secretsKey": "exemplo-laravel",
    "cacheTtlSeconds": 86400
  },

  // Languages the update supports. "pt" must be first if the target API requires it.
  "supportedLanguages": ["pt", "en", "es"],

  // How the payload is shaped. See references/payloads.md for the templates.
  "payloadShape": "api-admin-i18n",

  // Optional: NAME of the env var holding the Discord webhook URL.
  // The URL itself is a secret (anyone with it can post) — never put it in this JSON.
  "discordWebhookEnv": "EXEMPLO_DISCORD_WEBHOOK",

  // Optional: emoji policy. UnicPages uses emojis in Discord but NOT in admin update copy.
  "allowEmojis": { "admin": false, "discord": true },

  // Optional: the public URL template so we can show the final link after publish.
  "publicUrlTemplate": "https://exemplo.com/updates/{slug}"
}
```

Ready-made configs: `projects/exemplo-laravel.json` (Laravel, `local-sanctum`) and `projects/unicpages.json` (legacy Node backend, `local-jwt`).

## Creating a new project on the fly

If the user picks "Outro (configurar agora)" in Step 2, ask sequentially:

1. `name` — kebab-case slug.
2. `displayName` — human name.
3. `repos` — accept absolute paths separated by newlines.
4. `updateEndpoint` — URL.
5. Auth kind:
   - `local-sanctum` (recommended, Laravel app on disk): ask for the admin app folder (`appDir`, must contain `artisan`), the user `email`, the model class (default `App\\Models\\User`) and abilities (default `["*"]`; prefer `["updates:publicar", "storage:upload"]`). Use `secretsKey = <slug>`.
   - `bearer`: remote Laravel app — ask for the env var name that will hold a pre-minted Sanctum token.
   - `static` / `local-jwt`: legacy non-Laravel backends only.
6. `uploadEndpoint` — URL (or `null` if not needed).
7. `supportedLanguages` — comma-separated list.
8. `discordWebhookEnv` — optional: the NAME of the env var with the webhook URL (e.g. `<SLUG>_DISCORD_WEBHOOK`). Tell the user to export it in their shell profile; never write the URL in the JSON.

Save to `projects/<name>.json` + add an entry to `.secrets.json` (never with password).
Use `JSON.stringify(obj, null, 2)`.

## Laravel admin API (expected contract)

The recommended target is a Laravel admin app exposing:

```http
POST /api/updates
Authorization: Bearer <Sanctum token with ability updates:publicar>
Accept: application/json
Content-Type: application/json

{
  "title":   { "pt": "...", "en": "...", "es": "..." },
  "content": { "pt": "<html>", "en": "<html>", "es": "<html>" },
  "slug": "kebab-case-slug",
  "cover": "https://cdn.url",
  "date_update": "2026-04-20T..."
}
→ 201 { "data": { "id": 12, "slug": "...", "url": "..." } }   (API Resource)
```

Laravel side (reference — build it with the **especialista-laravel** agent):

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'abilities:updates:publicar', 'throttle:30,1'])
    ->post('updates', [UpdateController::class, 'store'])->name('updates.store');

// app/Http/Requests/StoreUpdateRequest.php → rules()
return [
    'title' => ['required', 'array'],
    'title.pt' => ['required', 'string', 'max:160'],
    'title.*' => ['nullable', 'string', 'max:160'],
    'content' => ['required', 'array'],
    'content.pt' => ['required', 'string'],
    'content.*' => ['nullable', 'string'],
    'slug' => ['required', 'string', 'alpha_dash', 'max:160', Rule::unique('updates', 'slug')],  // 422 on collision
    'cover' => ['nullable', 'url'],
    'date_update' => ['nullable', 'date'],
];
```

The model stores `title`/`content` as JSON columns (`protected function casts(): array { return ['title' => 'array', 'content' => 'array', 'date_update' => 'datetime']; }`), and `content` HTML must be sanitized server-side (e.g. `mews/purifier`) before being rendered with `{!! !!}` in Blade.

Images go to `POST /api/storage/imagem` (`multipart/form-data`, field `file`, optional `maxWidth`) → `{ "status": "ok", "body": { "url": "https://cdn..." } }` — same contract as the **api-upload-files** skill.

## UnicPages defaults (legacy Node backend)

UnicPages' admin API (Node/Express) expects:

```json
POST /updates
Headers: { "x-access-token": "<JWT>" }
Body: {
  "title": { "pt": "...", "en": "...", "es": "..." },
  "content": { "pt": "<html>", "en": "<html>", "es": "<html>" },
  "slug": "kebab-case-slug",
  "cover": "https://cdn.url",
  "dateUpdate": "2026-04-20T..."
}
```

Its upload API expects `multipart/form-data` with field `file` and optional `maxWidth` at `https://api-upload.unicpages.com/storage/imagem` → `{ body: { url: "https://cdn..." } }`.

## Secrets layout

Skill directory:

```
~/.claude/skills/publishing-updates/
├── .secrets.json         # chmod 600 — paths to backend .env files, never passwords
├── .token-cache.json     # chmod 600 — JWT cache + cached idAdmin (auto-created)
└── projects/*.json       # per-project config, NO tokens, NO passwords
```

### `.secrets.json` example for local-sanctum kind (Laravel)

```json
{
  "exemplo-laravel": {
    "appDir": "/.../frontend/admin",
    "email": "you@example.com",
    "model": "App\\Models\\Administrador",
    "abilities": ["updates:publicar", "storage:upload"]
  }
}
```

No passwords, no secrets: only the app path and who owns the token. `send.mjs` runs `php artisan tinker --execute` inside `appDir` (values passed via env vars, never interpolated into PHP code) and caches the token until 60 s before its `expires_at`.

### `.secrets.json` example for local-jwt kind (legacy Node)

```json
{
  "unicpages": {
    "envPath": "/.../backend/api-admin/.env",
    "secretEnvVar": "TOKEN_ADMIN",
    "mongoEnvVar": "MONGO_STRING",
    "collection": "admins",
    "email": "you@example.com",
    "payloadKey": "idAdmin",
    "nodeModulesHints": ["/.../backend/api-admin", "/.../backend/api-sites"]
  }
}
```

No passwords. The backend's own `.env` holds the JWT secret; this skill just reads the path.

### local-sanctum kind — step by step (inside send.mjs)

1. Check `appDir/artisan` exists.
2. Reuse the cached token if still valid for the same `appDir`.
3. Else run `php artisan tinker --execute` → `$model::where('email', ...)->first()->createToken('publishing-updates', $abilities, now()->addSeconds($ttl))->plainTextToken`.
4. Cache with TTL in `.token-cache.json` (chmod 600). Revoke all at once with `php artisan tinker --execute='App\\Models\\User::where("email", "...")->first()->tokens()->where("name", "publishing-updates")->delete();'`.

### local-jwt kind — step by step (inside send.mjs, legacy Node)

1. Parse the file at `envPath` to read the JWT secret (`TOKEN_ADMIN`) and Mongo URI (`MONGO_STRING`).
2. Resolve `idAdmin`:
   - If cached in `.token-cache.json`, reuse.
   - Else query Mongo via `mongoose` (resolved from `nodeModulesHints`) with `{ email }` in the `admins` collection.
3. Sign `jwt.sign({ data: { [payloadKey]: idAdmin } }, TOKEN_ADMIN, { expiresIn: ... })`.
4. Cache the token with TTL in `.token-cache.json` (default 24h).

## Security

- `.secrets.json` is chmod 600 and contains NO tokens, NO passwords — only filesystem paths and emails.
- Discord webhook URLs live in env vars named by `discordWebhookEnv` — never in `projects/*.json` (those files get copied and shared).
- Sanctum tokens are scoped by abilities and expire (`expires_at`); prune old ones with `php artisan sanctum:prune-expired --hours=24`.
- The JWT secret lives only in the backend's `.env` (the same file the server reads).
- Mongo URI lives only in the backend's `.env`.
- JWTs and cached `idAdmin` live in `.token-cache.json` (chmod 600). Default TTL 24h, configurable per-project via `auth.cacheTtlSeconds`.
- Token preview in logs: only first 12 + last 6 chars. Full token never printed.
- Webhooks (Discord) may be logged with last 6 chars only.
- `static`/`bearer` kinds (env var with pre-minted JWT) are still supported for external projects where you can't read the backend `.env`.
