# Troubleshooting

Common errors and recovery paths. Load only when an error actually happens.

## `401 Unauthorized` on update endpoint

Cause: missing or expired token.

1. `local-sanctum`: clear the cache (`send.mjs --clear-token-cache --key <slug>`) and retry — a new token is minted. If it still fails, the route may be missing `auth:sanctum` alignment (e.g. the app uses a different guard/model than `.secrets.json` → `model`).
2. `bearer`/`static`: check the env var named in `project.auth.tokenEnv` is set; the token may be expired or revoked.
3. Legacy UnicPages admin (Node): the JWT may be expired — clear the cache so `local-jwt` re-signs it.
4. Retry once.

## `403 This action is unauthorized` (Laravel)

The Sanctum token lacks the ability the route requires (`abilities:updates:publicar` / `storage:upload`), or the `abilities` middleware alias isn't registered in `bootstrap/app.php`. Fix `abilities` in `.secrets.json`, clear the token cache, retry.

## `422 Unprocessable Content` (Laravel)

FormRequest validation failed. The body has `{ message, errors: { field: [..] } }` — show the `errors` to the user. `slug` → "has already been taken" means collision (treat like 409 below).

## `419 Page Expired` / HTML response instead of JSON

The endpoint is under the `web` middleware group (CSRF) instead of `routes/api.php`, or `Accept: application/json` is missing. The API must live in `routes/api.php`.

## `401 Unauthorized` on upload endpoint

The upload API usually accepts both admin and regular user tokens. Try:

- Admin token (`project.auth`; with `local-sanctum` make sure the abilities include `storage:upload`).
- If that fails, ask the user for a fresh user JWT — the same they'd use when logged into the app.

## `409 Conflict — slug already exists`

The updates table has a unique index on `slug` (Laravel returns 422 with `errors.slug`; legacy Node returns 409).

1. Append `-2`, then `-3`, etc. to the proposed slug.
2. Re-show the new slug via `AskUserQuestion`: "Slug mudou pra `foo-bar-2` por causa de colisão. Ok?". Options: `Ok, publicar`, `Escolher outro`.

## `413 Payload Too Large` on upload

The image is larger than the upload limit (usually 10MB).

1. Try `sharp` to downscale locally: `scripts/annotate.mjs --resize` can do this (or call sharp inline via Bash).
2. If still fails, ask user to pick a different image.

## `429 Too Many Requests`

Respect `Retry-After` header if present, wait, retry once. On second failure, abort.

## Network errors

ECONNREFUSED / ENOTFOUND / ETIMEDOUT:

1. Check the endpoint URL is correct.
2. Ask user: "API `{endpoint}` não respondeu. Quer tentar de novo ou cancelar?"

## Sharp not installed

`scripts/annotate.mjs` depends on `sharp`. If the script exits with "Cannot find module 'sharp'":

1. Try `npx --yes sharp-cli` (falls back to a CLI).
2. If that also fails, skip annotation and tell the user: "Não consegui anotar a imagem localmente. Vou subir a original sem as setas. Você pode adicionar manualmente depois."

## Git digest returns zero changes

User may have run git ops outside the window. Offer:

- `Expandir o intervalo de tempo` (re-ask Step 3).
- `Descrever o que foi feito manualmente` (skip git, go to Step 5 with user-provided change list).

## User cancels mid-flow

Save any work already done to `archive/<project>/.draft-<timestamp>.json` so it can be picked up later. Tell the user the path.

## Payload validation fails after compose

If the server rejects with a 400 that hints at a missing field (e.g. `title.pt is required`), check the compose output. Re-compose with explicit instructions to the LLM, not a retry of the same output.

## Discord webhook returns 404

The webhook was deleted or rotated. Tell the user to check the Discord server integration settings, update the env var named in `project.discordWebhookEnv`, and retry.

## Image annotation produces visual garbage

If the output of `annotate.mjs` looks off (user reports), fall back to the original image. Tell the user the annotation was not applied.
