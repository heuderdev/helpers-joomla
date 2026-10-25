# Discord embed format

When Discord is one of the selected destinations, build the payload following the UnicPages conventions (the project's `CLAUDE.md` in the UnicPages frontend documents this).

## Rules (transcribed from UnicPages CLAUDE.md)

- **Each feature/fix is its own embed** — do NOT use fields inside a single embed.
- Main title follows the pattern: `🚀 UnicPages — Atualizações de DD de Mês de YYYY` (or the equivalent for the project).
- The first embed is title-only + general description (no specific feature).
- Each subsequent embed: title with emoji + feature name, description with well-spaced paragraphs.
- Use `\n\n` between paragraphs and `\n\n_ _` at the end of each embed except the last, to create visual breathing room between cards.
- Color: `5793266` for all embeds (matches UnicPages brand).
- No footer — the date is already in the main title.
- Only include user-facing changes. Refactors, SEO tweaks, CLAUDE.md edits, infra changes → skip.

## What to include

- New features the user will notice.
- Bug fixes that the user felt.
- UX improvements.
- New shortcuts.
- Visual changes.

## What to exclude

- Internal refactors.
- SEO meta tweaks.
- Dependency bumps.
- Infra config changes.
- CLAUDE.md/Docs.
- Code that doesn't change visible behavior.

## Payload shape

```json
{
  "embeds": [
    {
      "title": "🚀 UnicPages — Atualizações de 20 de Abril de 2026",
      "description": "Novidades pra deixar sua experiência ainda melhor.\n\n_ _",
      "color": 5793266
    },
    {
      "title": "🎨 Nova aba Estilo traduzida",
      "description": "Todos os campos da aba Estilo agora mostram os nomes em português, inglês e espanhol — de acordo com seu idioma.\n\nOs valores continuam em inglês (Flex, None, Auto, etc.) porque ajudam a manter o padrão CSS.\n\n_ _",
      "color": 5793266
    },
    {
      "title": "🔗 Repassar parâmetros da URL em links",
      "description": "Novo toggle no editor de links: ao ativar, os parâmetros da URL atual (ex: utm_source) são automaticamente anexados no link quando o visitante clicar.",
      "color": 5793266
    }
  ]
}
```

## How to post

Via `Bash` using `curl`:

```bash
curl -X POST "${!WEBHOOK_ENV}"  # WEBHOOK_ENV=<project.discordWebhookEnv>; prefer send.mjs --discord --config \
  -H "Content-Type: application/json" \
  -d @<path-to-payload.json>
```

Status 204 = success. Anything else = show the body back to the user and ask what to do.

## Project differences

Not every project will post to Discord. Build the embed only if `project.discordWebhookEnv` is set and the env var exists. If it's not, just don't show the "Discord" option in Step 7.

## Converting HTML to Discord text

Embeds don't accept HTML. When the update body is HTML (the admin payload), convert to plain text for Discord:

- `<h3>` → `**title**\n\n`
- `<p>` → `text\n\n`
- `<strong>` / `<b>` → `**...**`
- `<em>` / `<i>` → `*...*`
- `<a href="url">text</a>` → `[text](url)`
- `<img src="url">` → skip (Discord embeds have a separate `image.url` field; use the first image of the update as the embed-level image)
- `<ul><li>` → `• ...\n`
- Strip all other tags, keep inner text.

## Length limits

- Embed description: max 4096 chars. If longer, split into multiple embeds.
- Total payload: max 10 embeds per message. If the update has more sections, send multiple messages.
