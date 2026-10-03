# publishing-updates skill

Interactive Claude Code skill that turns the work done in the current session into a polished product update, then ships it to one or more destinations (admin API, Discord, local `.md` archive).

## Invocation

Natural language, any of:

- "publica o update pra unicpages"
- "manda a atualização pra [projeto]"
- "publish the update"
- "send the changelog"

Or via slash: `/publishing-updates`.

## Flow at a glance

1. Confirm intent
2. Pick project (from `projects/*.json`)
3. Pick time range (last session / since main / manual list)
4. Run `git-digest.mjs`, confirm scope
5. Pick languages (PT only / PT+EN / PT+EN+ES / custom)
6. Pick images (none / local folder / URLs / annotate / AI-generate if supported)
7. Pick destinations (API admin / Discord / local .md — multi)
8. Compose title + body per language, approval loop
9. Upload images to CDN, rewrite body URLs
10. Send to each destination, show results
11. Summary

Every decision uses `AskUserQuestion`. Nothing is assumed.

## Files

```
SKILL.md                    # Skill metadata + entry point
README.md                   # This file
.secrets.json               # chmod 600 — paths to backend .env files, never passwords
.token-cache.json           # chmod 600 — signed JWT cache with TTL (auto-created)
references/                 # Workflow and rules split by topic (progressive disclosure)
scripts/
  git-digest.mjs            # Walks repos, outputs structured JSON of changes
  annotate.mjs              # Sharp + SVG overlay (arrows, rectangles, numbered steps)
  send.mjs                  # Upload + POST update + Discord + local Sanctum token (Laravel) / local JWT (legacy)
projects/
  exemplo-laravel.json      # Laravel admin (local-sanctum) — use as a starting point
  unicpages.json            # UnicPages (legacy Node backend, local-jwt)
archive/                    # Per-project .md archive (created on first publish)
```

## Authentication: local Sanctum token (Laravel, no passwords)

For Laravel projects whose admin app is on your machine, the skill mints a short-lived **Sanctum personal access token** itself. No login HTTP, no password, no Keychain, no secret copied anywhere.

### How it works

1. `.secrets.json` points to the Laravel app:
   ```json
   "exemplo-laravel": {
     "appDir": "/.../frontend/admin",
     "email": "eduardo@lecdt.com",
     "model": "App\\Models\\Administrador",
     "abilities": ["updates:publicar", "storage:upload"]
   }
   ```
2. At send time, `send.mjs` runs inside `appDir`:
   `php artisan tinker --execute '... ->createToken("publishing-updates", $abilities, now()->addSeconds($ttl))->plainTextToken'`
   (email/model/abilities go in as env vars — never interpolated into PHP).
3. Caches the token in `.token-cache.json` (chmod 600) until 60 s before it expires.

Requirements: `php` on the PATH, `composer install` done in the app, `laravel/sanctum` installed and the model using `HasApiTokens`.

### Sanity check

```sh
node ~/.claude/skills/publishing-updates/scripts/send.mjs \
  --get-token \
  --config ~/.claude/skills/publishing-updates/projects/exemplo-laravel.json
```

Expected: `{"ok":true,"tokenPreview":"12|AbCdEf...xyz"}`. Full token never printed.

### Clearing the cache

```sh
node ~/.claude/skills/publishing-updates/scripts/send.mjs --clear-token-cache --key exemplo-laravel
```

### Legacy: local JWT signing (UnicPages Node backend)

`projects/unicpages.json` still uses `auth.kind: "local-jwt"`: `send.mjs` reads `TOKEN_ADMIN`/`MONGO_STRING` from the Node backend's `.env` (path in `.secrets.json`), resolves `idAdmin` in Mongo and signs the JWT with `jsonwebtoken`/`mongoose` taken from that backend's `node_modules` (`nodeModulesHints`). Only relevant while that backend is Node.

## Discord webhook

Webhook URLs are secrets. Projects declare only the env var name (`"discordWebhookEnv": "EXEMPLO_DISCORD_WEBHOOK"`); export the URL in your shell profile. `send.mjs --discord --config projects/<slug>.json --payload embed.json` resolves it.

## Adding a new project

If the new project's backend is on your machine (you own it):

1. Copy `projects/exemplo-laravel.json` to `projects/<slug>.json` (`auth.kind: "local-sanctum"`, see `references/projects.md`).
2. Add an entry in `.secrets.json` with `appDir`, `email`, optional `model` and `abilities`.
3. Verify: `node scripts/send.mjs --get-token --config projects/<slug>.json`.

If the app is remote and you only have credentials, use `auth.kind: "bearer"` with an env var holding a pre-minted Sanctum token (create it in the app: `php artisan tinker` → `$user->createToken('publishing-updates', ['updates:publicar'])`).

## Dependencies

- Node 20+ to run the CLI scripts (native `FormData`, `Blob`, `fetch`).
- For `local-sanctum` kind: PHP 8.3+ and the Laravel app with `vendor/` installed and `laravel/sanctum`.
- For `local-jwt` kind (legacy): any repo on disk with `jsonwebtoken` + `mongoose` (resolved via `nodeModulesHints`).
- For image annotation: `sharp` somewhere on disk (picked up from any backend). Without sharp, annotation is skipped and the original image is uploaded.
