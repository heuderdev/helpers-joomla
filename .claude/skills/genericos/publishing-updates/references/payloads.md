# Payload shapes

This skill supports multiple target API shapes. The active shape is determined by `project.payloadShape`.

## Shape: `api-admin-i18n` (default — Laravel admin API and UnicPages)

Expects i18n fields.

```http
POST {updateEndpoint}
Content-Type: application/json
Accept: application/json
Authorization: Bearer {Sanctum token}      # Laravel (local-sanctum / bearer)
x-access-token: {JWT}                      # legacy UnicPages (local-jwt)

{
  "title":    { "pt": "...", "en": "...", "es": "..." },
  "content":  { "pt": "<html>", "en": "<html>", "es": "<html>" },
  "slug":     "kebab-case",
  "cover":    "https://cdn.../cover.png",
  "dateUpdate": "2026-04-20T15:30:00.000Z"
}
```

Required keys (validated by the Laravel `StoreUpdateRequest`, see `projects.md`): `title.pt`, `content.pt`, `slug`. Laravel apps use snake_case: send `date_update` instead of `dateUpdate` when the target is Laravel. `cover` and `dateUpdate` default on the server if omitted. Languages not selected should be omitted (server defaults to empty string).

## Shape: `api-generic-flat`

Flat payload, one language only.

```json
{
  "title": "...",
  "content": "<html>",
  "slug": "kebab-case",
  "cover": "https://...",
  "publishedAt": "2026-04-20T..."
}
```

## Shape: `markdown-only`

No API call. Just save the `.md` locally. Good for projects that publish from a file (Astro content collections, MkDocs, etc.).

## Shape: `custom`

The project config specifies a `payloadTemplate` (handlebars-like placeholders) and the skill fills it in. Example:

```jsonc
{
  "payloadShape": "custom",
  "payloadTemplate": {
    "announcement": {
      "headline": "{{title.pt}}",
      "body_html": "{{content.pt}}",
      "slug": "{{slug}}",
      "image": "{{cover}}"
    }
  }
}
```

Placeholders available: `title.<lang>`, `content.<lang>`, `slug`, `cover`, `dateUpdate`, `images[].url`, `images[].alt`.

## Auth variants

### static token
Header: `{auth.header}: {env[auth.tokenEnv]}`

### bearer
Header: `Authorization: Bearer {env[auth.tokenEnv]}`

### local-sanctum (Laravel)
Header: `Authorization: Bearer <token>` minted by `php artisan tinker` in the local admin app (see `projects.md`).

### local-jwt (legacy Node)
Header: `x-access-token: <JWT signed locally with the backend secret>`.

## Response handling

All shapes:

- 2xx → success, parse response body if JSON to extract `id`, `slug`, `url`.
- 400 / 422 → validation error (Laravel returns 422 with `errors`), show the errors, ask user what to fix.
- 401 → token invalid/expired. Suggest checking env var, or trigger login flow.
- 409 (or 422 with `errors.slug`) → slug collision. Regenerate slug with `-2` suffix, re-ask user.
- 413 → payload too large (image probably). Re-encode or ask user to drop images.
- 429 → rate limited. Wait `retry-after` seconds and retry once, then fail.
- 5xx → server error. Save pending JSON (see workflow.md Step 10 fallback) and abort.
