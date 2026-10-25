# Template do texto `instructions` do servidor

Esse texto vai pro contexto do LLM em toda chamada. É onde você ensina como usar o MCP de verdade — schema, exemplos, armadilhas.

## Estrutura recomendada

```
# {{Nome}} MCP Server

[1 parágrafo: o que é o domínio, qual o "documento principal" que o LLM vai manipular]

## ABSOLUTELY FORBIDDEN — Server Will Reject With Validation Error

[Lista numerada das coisas que o LLM SEMPRE tenta fazer e que o servidor rejeita. Mostre erro vs correto lado a lado.]

1. **Raw CSS strings in dimension fields.** The server rejects:
   - `"fontSize": "62px"` ❌  → use `"fontSize": {"value": 62, "unit": "px"}` ✅
   ...

## Golden Rules — Read Before Anything Else

[5-10 regras curtas com **negrito** nas chaves]

1. The draft format is exactly: `{ desktop: { sections: [] }, mobile: { sections: [] } }`
2. Creating a project yields an EMPTY draft. You MUST populate it.
3. PREFER templates over building from scratch.
...

## Recommended Workflows

[Casos comuns como receitas curtas: "Create X (2 calls): 1. Y, 2. Z."]

### Create a Link in Bio (2 calls)
1. `create_project` with `{ name: "My Bio" }` — extract `pageId`.
2. `page_create_from_template` with `{ pageId, templateId: "bio-alex" }`.

### Edit an Existing Page (3 níveis)

Choose the cheapest tool. Each level is 10-100× cheaper than the next.

**Level 1 — pointwise edit:**
1. `element_find` → find id
2. `element_patch` → apply

**Level 2 — structural edit:**
1. `section_tree` → navigate
2. `section_get` → load
3. `section_replace` → save

**Level 3 — full rewrite:**
- `get_draft(full: true)` + `update_draft`

## {{Document}} Structure

[Mostre o JSON schema com exemplo MINIMAL e exemplo COMPLETO]

## {{Field A}} Variants

[Tabela ou bullets dos tipos válidos com exemplo de cada]

| Element | content |
|---|---|
| Text (h1-h6) | `{ "text": "..." }` |
| Link | `{ "text": "Click", "link": { "url": "...", "target": "_blank" } }` |

## {{Field B}} Variants

[Mais exemplos]

## Common Mistakes (do NOT make these)

[Lista numerada de erros frequentes do LLM com "use X instead of Y"]

1. Using `desktopData` / `mobileData` instead of `desktop` / `mobile`.
2. Forgetting the mobile version.
3. Stringifying JSON before passing to tools — all tools accept JSON objects directly.
...
```

## Tom

- Imperativo e direto: "Use X", "NEVER Y", "PREFER Z"
- ❌ e ✅ lado a lado em vez de explicar por texto
- Exemplos JSON curtos copiáveis
- Workflows numerados (2-call, 3-call, 5-10 calls)
- Tabelas pra variantes (tipo de elemento × content shape)

## Tamanho

Pode ser longo (~5-15 KB). Vai no contexto do LLM apenas no `initialize` da sessão, então não custa por chamada.

## Não confundir com `description` do tool

- `description` do TOOL: 1-3 frases. Quando usar, dicas, output.
- `instructions` do SERVIDOR: documento longo. Schema, exemplos, armadilhas, workflows.
