# Language handling

The user was explicit: ask about languages upfront. Some updates are single-language, others are multi.

## Supported languages (UnicPages)

- `pt` — Portuguese (pt-BR)
- `en` — English
- `es` — Spanish (es-ES / neutral Latin)

Other projects may support a different set. Always read from `project.supportedLanguages`.

## Decision in Step 5

Question: "Em quais idiomas essa atualização vai sair?"

Options (for a project supporting pt/en/es):

- `Só português`
- `Português + Inglês`
- `Todos (PT + EN + ES)`
- `Escolher manualmente` (multiSelect)

Store the chosen array in `langs`.

## Translation strategy in Step 8

For each chosen language:

- **Primary language** (always the user's native, or `pt` for UnicPages): write the title and body directly from the change list. This is the source of truth.
- **Secondary languages**: translate from the primary, preserving HTML structure (don't translate tag names, alt attributes, URLs, code blocks).

Key rules:

- Adapt natural phrasing, not literal word-by-word. Example: "Adicionamos..." in PT becomes "We added..." in EN, not "We have added...".
- Preserve technical terms in English when they're universal (e.g. "screenshots", "marquee", "slug"). But translate common UI terms when they have a natural local word (e.g. "login" → "entrar" in PT).
- Keep the tone consistent across languages — if PT is casual, EN stays casual; if PT is formal, EN matches.
- Preserve inline `<img>` tags exactly. Don't re-order them.
- Alt text: translate.

## Required vs optional languages in the payload

For UnicPages:

- `title.pt` and `content.pt` are **required** by the schema. If PT is not in `langs`, you MUST abort and tell the user — the API rejects.
- `title.en`, `title.es`, `content.en`, `content.es` default to empty string on the server. Safe to omit.

Build the payload like this:

```js
const titleObj = { pt: titles.pt }
const contentObj = { pt: contents.pt }
if (langs.includes('en')) { titleObj.en = titles.en; contentObj.en = contents.en }
if (langs.includes('es')) { titleObj.es = titles.es; contentObj.es = contents.es }
```

## Fallback when a project requires PT but user only wants EN

If the user picked `Só inglês` but the project requires `pt`, ask:

- "{projectName} exige um título/conteúdo em português. Quer que eu traduza automaticamente pra PT também, ou preferiu preencher manualmente?"
- Options: `Traduz automaticamente`, `Eu escrevo o PT depois`, `Cancelar`.

If auto-translate, do it; if "eu escrevo depois", abort and tell the user to go to the admin manually.

## Edge cases

- User wants a language the project doesn't support → warn: "Esse projeto não aceita `fr`. Quero continuar só com os suportados?".
- User wants only a language not in the project's required set → see fallback above.
- Titles >60 chars in one language but fine in another → shorten the long one, keep meaning. If you can't, ask the user.
