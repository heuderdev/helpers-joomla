---
name: publishing-updates
description: Publishes product updates/changelogs to any configured project (Laravel admin APIs via Sanctum, the legacy UnicPages admin, Discord, local markdown archive). Reads the current session's git changes and user-supplied screenshots, interviews the user interactively about language, images, targets and tone, generates title + body in the chosen languages, uploads images to the project's CDN, and POSTs the final update to the project's admin endpoint. Use this skill whenever the user asks to "publish the update", "announce what was shipped", "send the changelog", "mandar o update pra [projeto]", or any equivalent in Portuguese/English/Spanish.
---

# Publishing Updates

This skill turns the work the user just did into a polished product update entry and ships it to one or more destinations — an admin API (a Laravel app's `POST /api/updates`, or UnicPages' `/updates`), Discord, a local `.md` archive, or any mix of these.

The skill is **deliberately interactive**: it never assumes, it always asks. The user has said clearly they want the most thorough version possible.

## When to invoke

Trigger whenever the user asks, in natural language, any of:
- "publica o update", "manda o update pra unicpages", "envia a atualização"
- "publish the update", "send the changelog"
- Any phrasing that implies turning recent work into a user-facing announcement

Do NOT trigger for internal docs, CLAUDE.md, or commits — those are not user-facing updates.

## Reference files (progressive disclosure — read only when needed)

These files live in this skill directory. Do NOT load them all upfront — load each one ONLY at the moment the workflow step requires it:

- `references/workflow.md` — the full 8-step interactive workflow. **Load this first** after the skill is triggered.
- `references/projects.md` — how the project registry works, where to read/write `projects/*.json` configs.
- `references/images.md` — image sources the user can choose from (local dir, paste, upload on the fly) and how to call the annotator script.
- `references/languages.md` — how to handle single-lang vs multi-lang updates, including translation guidance.
- `references/discord.md` — Discord embed format (taken from UnicPages CLAUDE.md), when to offer it, payload structure.
- `references/commit-format.md` — Conventional Commits primer + how to interpret git log/diff to extract real user-facing changes (filtering out internal refactors, CLAUDE.md tweaks, lint fixes, etc.).
- `references/payloads.md` — request payload shapes per known project type (Laravel admin API, generic REST, markdown-only).
- `references/troubleshooting.md` — common errors (401 token, 413 file too big, slug collision, etc.) and how to recover.

## Helper scripts (in `scripts/`)

- `scripts/annotate.mjs` — Node script that takes an input image + instruction array and outputs an annotated copy with arrows/rectangles/numbered markers drawn via Sharp + SVG composite. Called via `Bash` with JSON args on stdin. Depends on `sharp` only (resolved from any local `node_modules` — in Laravel repos it usually isn't installed, so run `npm i -g sharp` or `npx`; if unavailable, skill falls back to using the original image without annotation and warns the user).
- `scripts/git-digest.mjs` — Node script that walks the configured repos, runs `git log` + `git diff --stat` for the last N hours/commits (configurable per project), filters noise (composer.lock/package-lock, vendor/, public/build/, .md internal, dependency bumps) and emits a structured JSON digest of user-facing changes. Output feeds the LLM context for copywriting.
- `scripts/send.mjs` — Node script that given a resolved payload + target config uploads images to the project's CDN, then POSTs the final update. Handles auth (`local-sanctum` mints a Sanctum token via `php artisan tinker` in the local Laravel app), multipart, retries, and Discord (webhook read from the env var in `discordWebhookEnv`).

Each script is invoked via `Bash` with the right CWD. They are plain Node ESM used as CLI utilities (Node 20+), no build step — the Laravel project itself doesn't need Node beyond Vite. `local-sanctum` auth needs `php` on the PATH and the app's `vendor/` installed.

## High-level flow (the details live in `references/workflow.md`)

1. Greet briefly, confirm this is an update publish request.
2. Ask which project (picker from `projects/*.json`).
3. Ask what time range to consider (last session, since last commit to main, manual list).
4. Run `git-digest.mjs` on the project's repos, show summary, ask user to confirm/edit the scope.
5. Ask which languages (PT only / PT+EN / PT+EN+ES / custom).
6. Ask about images (none / use files from a local path / paste URLs / annotate existing).
7. Ask about destinations (admin API / Discord / local .md archive — user picks one or more).
8. Generate title + body per language. **Show everything for approval**. Edit loop until user approves.
9. Upload images to the project's CDN endpoint, then POST the update. Show the response. Save `.md` to `archive/<project>/<slug>.md`.

## Hard rules (the user was emphatic about these)

- **Ask, never assume.** Language, images, tone, destinations, dates — every decision goes through `AskUserQuestion` with concrete options.
- **Show before sending.** Final payload is always rendered for approval before hitting any API. No silent posts.
- **Respect project config.** Each project has its own auth token env var, endpoints, and languages. Never hardcode; always read from `projects/<name>.json`.
- **Never commit/push anything.** This skill publishes updates to admin APIs — it does not touch git history. Writing new source code is out of scope.
- **Dev vs prod.** If the user's session was spent testing (e.g. `APP_ENV=local` features behind flags, queue jobs run with `QUEUE_CONNECTION=sync`), flag that and ask whether those experimental changes should appear in the public update or be filtered out.
- **Secrets.** Tokens are minted locally (Sanctum) or come from env vars named in the project config; Discord webhooks come from the env var in `discordWebhookEnv`. Never echo a raw token or webhook URL in logs, files or the chat.

## First action

When invoked, IMMEDIATELY read `references/workflow.md` (via Read tool) to load the full step-by-step script, then start at step 1. Do not improvise the flow from memory — the workflow file is the source of truth.
