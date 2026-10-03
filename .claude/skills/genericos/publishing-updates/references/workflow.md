# Workflow — step by step

This is the exact script to follow. Do not skip steps, do not reorder. Every decision goes through `AskUserQuestion`.

The user was explicit: **ask about everything**. When in doubt, ask. Err on the side of more questions, not fewer.

---

## Step 0 — Bootstrap

Before asking anything:

1. `Read projects/` directory (glob `~/.claude/skills/publishing-updates/projects/*.json`) to discover registered projects.
2. If the directory is empty or has zero `.json` files, offer to create one on the fly (see `projects.md`) — but do NOT proceed to step 1 without at least one registered project.
3. Check if `scripts/git-digest.mjs` and `scripts/annotate.mjs` are present. They should be — they ship with this skill.

## Step 1 — Confirm intent

Use `AskUserQuestion` with a single question:

- Question: "Você quer publicar uma atualização. Posso prosseguir?" / "Publishing a product update. Ready to start?"
- Options: `Sim, bora`, `Primeiro me mostra o que vai fazer`, `Cancelar`.
- Header: `Atualização`.

If the user picks "mostra primeiro", summarize in 3 sentences what the skill will do (interview + git digest + images + copy + send), then re-ask.

If cancelled, stop.

## Step 2 — Pick the project

Use `AskUserQuestion`:

- Question: "Qual projeto vai receber essa atualização?"
- Options: one per `projects/*.json` discovered in Step 0. Each option label is the project's `displayName` (or `name`). Add `Outro (configurar agora)` as the last option.
- Header: `Projeto`.

If user picks "Outro", walk them through creating a new `projects/<slug>.json`. See `projects.md` for the schema. Ask for: name, displayName, repos (array of absolute paths), updateEndpoint, uploadEndpoint, auth kind (`local-sanctum` for Laravel apps on disk), supportedLanguages, optional discordWebhookEnv. Save the file, then continue with the newly-created project.

Load the chosen project's JSON into memory as `project`.

## Step 3 — Time range / scope of changes

Use `AskUserQuestion`:

- Question: "De onde você quer que eu tire o que foi feito?"
- Options:
  - `Sessão atual (últimas X horas)` — default X is 6, but adapt based on `project.defaultWindowHours`.
  - `Desde o último commit em main` (diff HEAD vs origin/main).
  - `Apenas os commits do dia`.
  - `Eu descrevo o que foi feito` (skip git digest).
  - `Range customizado` (user types dates/shas).
- Header: `Escopo`.

## Step 4 — Run git digest

If user didn't pick "Eu descrevo":

1. Invoke `scripts/git-digest.mjs` via Bash. Pass project path(s), time window, and filter rules (conventional commit types to keep, paths to ignore).
2. Read the JSON output.
3. Render a human summary (markdown list grouped by repo and type: features, fixes, improvements).
4. Use `AskUserQuestion`:
   - Question: "Isso representa bem o que você quer anunciar?"
   - Options: `Sim, seguir`, `Adicionar coisas manualmente`, `Remover alguns itens`, `Refazer com outro escopo`.
   - Header: `Escopo`.

If "Adicionar / Remover", enter an edit loop (re-show, ask again) until user approves.

If user skipped git digest in Step 3, ask them to describe the changes in free text. Parse into an internal change list (`{ type, title, description, repo }`).

## Step 5 — Languages

Use `AskUserQuestion`:

- Question: "Em quais idiomas essa atualização vai sair?"
- Options built from `project.supportedLanguages`. Example UnicPages: `Só português`, `PT + EN`, `PT + EN + ES`, `Escolher manualmente`.
- Header: `Idiomas`.

Store selected languages in `langs` array. See `languages.md` for how to handle translation of the final copy (the translation happens in Step 8, not here).

## Step 6 — Images

Ask in a SINGLE AskUserQuestion with 5 clear options (user said to ask about images thoroughly):

- Question: "Como vão ser as imagens da atualização?"
- Options:
  - `Sem imagens`
  - `Tenho prints salvos em pasta local` — skill will ask for the path, list files, let user pick which go in.
  - `Vou colar URLs de imagens já hospedadas` — user pastes URLs, no upload needed.
  - `Tenho prints + quero anotar com setas/números` — skill will ask what to highlight and call `annotate.mjs`.
  - `Gerar nova imagem com IA` — only offer this option if the project has `aiImageEndpoint` configured. Otherwise omit.
- Header: `Imagens`.

Based on choice:

- **Local folder**: ask path (default `~/updates/prints/` if it exists). `ls` the folder, show files with sizes. Use AskUserQuestion (multiSelect=true) to pick files. For each file, ask alt text (short, per image).
- **URLs**: user pastes newline-separated URLs in free text. Validate each URL with a HEAD request via `scripts/send.mjs --validate-url`.
- **Annotate**: after picking files, for each one ask what to highlight. Offer options: `Seta apontando pra um canto`, `Retângulo ao redor de área`, `Número em um ponto`, `Deixar como está`. If user picks an annotation, ask which region (9-zone grid: `canto superior esq`, `topo centro`, `canto superior dir`, `meio esq`, `centro`, `meio dir`, `canto inferior esq`, `base centro`, `canto inferior dir`). Save pending annotations as JSON and call `scripts/annotate.mjs` in Step 9 right before upload.
- **AI generate**: only if `project.aiImageEndpoint` exists. Ask for a short English prompt describing the image. Skill will call that endpoint. If the endpoint is not configured, do NOT offer this option in the first place.

Store everything in `images[]` (each entry: `{ kind: 'local'|'url'|'annotated', path?, url?, alt, annotations? }`).

## Step 7 — Destinations

Use `AskUserQuestion` with `multiSelect=true`:

- Question: "Pra onde vai essa atualização?"
- Options (build dynamically based on project config):
  - `API admin ({project.displayName})` — only if `project.updateEndpoint` exists.
  - `Discord` — only if `project.discordWebhookEnv` is set AND that env var exists in the shell (`[ -n "${VAR:-}" ]`).
  - `Arquivo .md local` (always available).
- Header: `Destinos`.

At least one destination is required. If user selects zero, re-ask.

## Step 8 — Compose the copy

Now Claude writes the actual update content. Follow `commit-format.md` guidance to extract user-facing changes only.

For each language in `langs`:

1. Generate **title** (short, ≤60 chars, no emoji unless the project explicitly allows — check `project.allowEmojis`).
2. Generate **body** in HTML (since the UnicPages editor stores HTML). Structure:
   - Short intro paragraph.
   - One `<h3>` per major feature/fix with explanation and benefit for the user.
   - Images placed inline (matching the images picked in Step 6) using `<img src="...">`. Keep alt text.
   - Final CTA if appropriate (e.g. "Saiba mais: link to docs").
3. Generate **slug** (lowercase kebab, ≤60 chars, no special chars, unique hint = short timestamp suffix if user hasn't provided one).
4. Generate **dateUpdate** = now, unless user already provided a date.

Render everything for the user. Use the following output format:

```
## Preview

### 🇧🇷 Português
**Título:** ...
**Slug:** ...
**Conteúdo:**
(rendered HTML, pretty-printed)

### 🇺🇸 English
(same structure)
```

Then `AskUserQuestion`:

- Question: "Tá bom assim?"
- Options: `Perfeito, publicar`, `Ajustar título`, `Ajustar corpo`, `Ajustar slug`, `Refazer do zero`, `Cancelar tudo`.
- Header: `Revisar`.

If any "Ajustar", enter an edit loop. The user can tell you exactly what to change, you regenerate just that piece, re-render, re-ask.

Loop until `Perfeito, publicar`.

## Step 9 — Upload images

For each image in `images[]`:

- `kind: 'url'` → already hosted, skip upload.
- `kind: 'local'` → call `scripts/send.mjs --upload` with the local path, project config, and auth token. Capture the returned CDN URL.
- `kind: 'annotated'` → call `scripts/annotate.mjs` first with the source path + annotations JSON, get output path, then upload as above.

After all uploads, rewrite the HTML body of every language so `<img>` src attributes point to the final CDN URLs.

## Step 10 — Final send

For each selected destination:

- **API admin**: `scripts/send.mjs --update` with the project config, final payload, and token. Show the HTTP status + response body in the chat.
- **Discord**: run `send.mjs --discord --config projects/<slug>.json --payload <embed.json>` — the script reads the webhook from the env var named in `discordWebhookEnv`. Never paste the webhook URL in the chat or in files. See `discord.md` for format. If the post succeeds, note it in the chat.
- **Local .md**: write to `~/.claude/skills/publishing-updates/archive/<projectName>/<slug>.md`. The file includes YAML frontmatter with all languages side by side, then the PT content rendered as markdown (for readability).

## Step 11 — Summary

Print a short summary of what happened:

- Project name
- Slug
- Destinations hit (✓/✗)
- URL of the published update (if the admin API returned one)
- Path of the local .md

End the session with a single question: "Publicar outra? Ou finalizamos aqui?" — `AskUserQuestion` with `Finalizar` and `Publicar outra`.

---

## Cancellation / error policy

- Any step where user selects "Cancelar" → stop immediately, do NOT clean up anything already written (locally), but do NOT send anything pending.
- Any script error (non-zero exit, parse error) → show the stderr, ask what to do: retry, skip this step, or abort.
- If an upload fails mid-batch → continue with already-uploaded images, skip the broken one, warn the user in the final summary.
- If the final POST fails → save the payload JSON to `archive/<project>/.pending-<slug>.json` so the user can retry manually later.
