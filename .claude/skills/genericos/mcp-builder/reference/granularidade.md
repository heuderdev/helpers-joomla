# Granularidade — Economizando 99% de Tokens

Princípio: o LLM nunca deve ter que baixar JSON gigante pra fazer mudança pequena.

## Anti-padrão (NÃO faça)

Servidor expõe só:
- `get_page(pageId)` → retorna o JSON inteiro (80 KB)
- `update_page(pageId, content)` → recebe o JSON inteiro

Pra mudar 1 texto, LLM precisa:
1. Chamar `get_page` (80 KB IN no contexto)
2. Modificar localmente
3. Chamar `update_page` (80 KB OUT no contexto)
4. Total: ~160 KB no contexto por edição. Pra 50 edições: 8 MB.

## Padrão correto (3 níveis)

### Nível 1 — Pointwise (texto / cor / link único)
- `element_get(pageId, elementId, mode: 'summary'|'full')` — só o elemento
- `element_patch(pageId, elementId, patch)` — deep-merge de text/content/style
- `element_patch_bulk(pageId, patches[])` — N patches em 1 PUT
- `element_find(pageId, query)` — descobrir id sem baixar nada

### Nível 2 — Estrutural (adicionar/remover children, mudar layout)
- `section_tree(pageId, target, maxDepth)` — hierarquia achatada sem content/style
- `section_get(pageId, target)` — JSON completo só da seção
- `section_replace(pageId, target, newSection)`
- `section_add` / `section_remove` / `section_reorder`

### Nível 3 — Reescrita total
- `get_page(pageId, full: true)`
- `update_page(pageId, content)`

Documente nas `instructions` do servidor:

> Choose the cheapest tool for the edit. Level 1 is 10-100× cheaper than Level 2.
> NEVER reach for section_replace when an element-level patch is enough.

## Tools de descoberta barata

Antes de patchar, o LLM precisa saber o id. Forneça:

- **find por texto/tag/ids** — retorna `[{elementId, sectionId, tag, preview, path}]` (sub-KB)
- **tree achatada** — `{id, tag, name, depth, parentId, preview}` por nó, sem content/style
- **summary modes** — `element_get(mode: 'summary')` retorna só keys, sem nested

## Bulk patch pattern

Pra reescrever copy inteira de uma página:

```php
'patches' => [
    ['element_id' => 'el_hero_title', 'patch' => ['text' => 'Novo título']],
    ['element_id' => 'el_hero_desc',  'patch' => ['text' => 'Nova descrição']],
    ['element_id' => 'el_btn_cta',    'patch' => ['text' => 'Comprar', 'style' => ['background' => [/* ... */]]]],
    // ... até 200 patches (valide com 'patches' => ['required', 'array', 'max:200'])
]
```

Servidor (Action `AplicarPatchesEmLote`): 1 `SELECT ... FOR UPDATE` (`lockForUpdate()`) + N `aplicarPatchNoNo()` em memória + 1 `UPDATE`, tudo dentro de `DB::transaction()`. Se um elemento sumir, skip + reporta em `missing`. Os outros aplicam. O lock evita que duas sessões do LLM sobrescrevam o draft uma da outra.

## Espelhamento desktop ↔ mobile

Editores visuais costumam clonar elementos com sufixos divergentes (`_copy_123`, `_m`, etc). Patch que assume `id` igual quebra silenciosamente.

Solução: tool `find_orphan_mobile` que:
1. Lista todos ids desktop e mobile
2. Acha quem está só num lado
3. Sugere o par mobile mais provável (compare base do id removendo sufixos `_copy_\d+`, `_paste_\d+`, `_m`, + texto + tag + depth)
4. Retorna `{ desktopOnly: [{id, suggestedMobileId, confidence}], mobileOnly: [...] }`

## Cache local opcional (skill complementar)

Pra edições muito intensas, oferecer skill local que:
1. `pull(pageId)` → baixa draft pra `~/.cache/mcp/<pageId>.json` (endpoint JSON da API Laravel com token Sanctum)
2. N operações offline com jq/python
3. `push(pageId)` → 1 PUT no fim

Vantagem: zero tokens nas N operações intermediárias. Desvantagem: precisa do token do usuário no shell.

## Medindo o ganho

Conte no ToolSearch ou em logs (no Laravel: um middleware na rota MCP que loga tamanho do request/response com `Log::channel('mcp')`, ou Telescope em ambiente local):
- Tokens IN + OUT por edição
- Tempo de validação server-side
- Latência total

Numa migração real (UnicPages), edição típica passou de ~80 KB por mudança pra ~200 bytes = redução de **99.75%** em tokens.
