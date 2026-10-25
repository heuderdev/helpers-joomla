# Commit → user-facing update

This file explains how to turn git history into copy that makes sense for end users.

## Conventional Commits primer

The skill assumes projects use Conventional Commits:

```
<type>[optional scope]: <description>

[optional body]

[optional footer(s)]
```

Types that matter for a user-facing update:

- `feat:` — new feature (include, highlight)
- `fix:` — user-visible bug fix (include)
- `perf:` — performance improvement (include if noticeable)
- `improve:` — UX improvement (include)
- `revert:` — rollback (include with a short explanation)

Types to filter out by default:

- `chore:` — internal tasks
- `refactor:` — code restructuring with no behavior change
- `test:` — test changes
- `docs:` — documentation (unless user-facing docs, then ask)
- `build:` — build system
- `ci:` — CI config
- `style:` — formatting

`project.keepCommitTypes` overrides the default filter.

## Noise filters

Even within kept types, some commits don't deserve a mention:

- Messages like "WIP", "wip", "temp", "test", "asdf" — exclude.
- Messages mentioning only internal paths (`.claude/`, `CLAUDE.md`, `.env`, lock files) — exclude.
- Commits that only touch `ignoreGlobs` paths — exclude.
- Commits with `[skip changelog]` or `[internal]` in the body — exclude.

## Deduplication

If the same surface area got multiple commits in the window (e.g. "fix clone-page" three times), group them into a single bullet and describe the final state, not each incremental change.

## Translation from engineer-speak to user-speak

Rewrite technical phrasing into plain, benefit-oriented language.

Examples:

- `feat: regenerate IDs post clone via timestamp+counter`
  → "Clones agora recebem IDs únicos automaticamente, evitando conflitos entre elementos."

- `fix: clip must be positive when screenshotting degenerate sections`
  → "Corrigido um erro raro durante a clonagem de páginas com seções invisíveis."

- `feat: add forward URL parameters toggle in editor links`
  → "Novo toggle nos links do editor: ao ativar, os parâmetros da URL atual (como utm_source) são automaticamente repassados quando o visitante clicar."

Key heuristics:

- Start from the user's point of view, not the developer's. Ask: what will the user see, do, or benefit from?
- Avoid jargon unless the audience is technical (check `project.audience` if set).
- Group related changes under a single heading. A user doesn't want 12 bullets about one feature.
- Use present tense or present-perfect in the update body ("Adicionamos...", "Agora você pode...").
- When there's both a feature and a fix for the same area, lead with the feature and mention the fix as a follow-up line.

## Dev vs prod awareness

If the session set env vars like `WORKER_ENV=dev`, `DEBUG=true`, etc., that's a strong signal that some of the changes were **experimental** and should NOT appear in the public update. In Step 4 (after showing the digest), explicitly ask:

- "Parte dessas mudanças é de teste local (`dev` tag). Quer filtrar?"
- Options: `Sim, tirar as de dev`, `Manter tudo`, `Eu escolho uma a uma`.

## Scope field (optional)

When commits use `feat(scope):` notation, group bullets by scope in the digest. Example: all `feat(editor):` go under an "Editor" heading, all `feat(clones):` under "Clones".

## Breaking changes

Commits with `BREAKING CHANGE:` in the body or `!` after the type (e.g. `feat!:`) get a dedicated "Mudanças que exigem atenção" section at the top of the update. Always include these — they're the most important.
