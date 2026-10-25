---
name: unicpages-edit
description: Edição rápida e barata de drafts da UnicPages com cache local + curl direto na api-app. Use quando o usuário pedir para editar texto, cor, estilo, link, imagem, ou qualquer ajuste pontual numa página UnicPages — em vez de carregar o JSON gigante via MCP. Funciona em três modos: (1) pull/push do draft inteiro pra cache local, (2) edição offline via jq (set-text, set-style, find, tree), (3) sync de volta. Para edições simples remotas, prefere usar o tool MCP unicpages_element_patch (já otimizado). Para edições em lote, operações repetidas, ou inspeção pesada → use esta skill.
---

# UnicPages Edit Skill

Edita drafts da UnicPages sem queimar tokens com o JSON gigante. Existem **dois caminhos** e a escolha depende do volume da edição:

## Decisão rápida — qual caminho usar?

| Cenário | Use | Por quê |
|---|---|---|
| 1 mudança simples (texto, cor, link) | **MCP** `unicpages_element_patch` | Uma chamada só, já é granular |
| 2-5 mudanças na mesma página | **MCP** `unicpages_element_patch` (em sequência) | Cada patch valida e salva |
| 5+ mudanças, ou exploração pesada da página | **Esta skill** (pull → editar local → push) | 1 GET + N edits locais (0 tokens) + 1 PUT |
| Procurar onde aparece "X" e listar tudo | **MCP** `unicpages_element_find` | Já retorna só matches |
| Listar a árvore inteira de uma section | **MCP** `unicpages_section_tree` | Já retorna só metadata |
| Edição em lote (trocar "$197" → "$97" em 30 lugares) | **Esta skill** + script custom | Local com jq/sed, depois push |

Quando estiver na dúvida, **prefira MCP** — é mais simples. Esta skill brilha quando o ciclo de pull/edit/push compensa porque você vai fazer muitas operações.

## Pré-requisitos

- Variável `UNICPAGES_TOKEN` no ambiente ou arquivo `~/.unicpages/token` com o JWT.
  - O token é o mesmo do MCP — está nos logs da sessão OAuth ou na `~/.unicpages/token`.
- `jq` instalado (`brew install jq`).
- `curl` (já vem no macOS).

## Scripts disponíveis

Todos rodam a partir de `~/.claude/skills/unicpages-edit/scripts/`.

| Script | O que faz |
|---|---|
| `pull.sh <pageId>` | Baixa o draft → `~/.cache/unicpages/<pageId>.json` (+ etag/hash) |
| `push.sh <pageId>` | Envia o cache local pro servidor (PUT). Avisa se houve mudança remota desde o pull |
| `tree.sh <pageId> [section_id_or_index] [desktop\|mobile]` | Tree achatada (sem content/style) — usa o cache |
| `find.sh <pageId> <query>` | grep por texto/id/tag no cache, retorna lista de matches |
| `get.sh <pageId> <elementId>` | Imprime o JSON do elemento (desktop+mobile) do cache |
| `set-text.sh <pageId> <elementId> <texto>` | Edita content.text (espelha button/link) em desktop+mobile |
| `set-style.sh <pageId> <elementId> <jsonpath> <json_value>` | Aplica patch num campo de style (ex: `.background.solid.color '"#FF0000"'`) |
| `patch.sh <pageId> <elementId> <patch_json>` | Patch genérico: faz deep-merge do JSON em content/style/etc no cache |
| `diff.sh <pageId>` | Mostra o diff do cache vs último pull (o que vai ser enviado no push) |
| `validate.sh <pageId>` | Roda o schema Zod do MCP localmente (precisa do diretório do MCP clonado) |

## Fluxo típico — edição em lote

```bash
SCRIPTS=~/.claude/skills/unicpages-edit/scripts
PAGE=p_abc123

# 1. Baixa
$SCRIPTS/pull.sh $PAGE

# 2. Explora barato (jq + grep no cache, 0 tokens API)
$SCRIPTS/tree.sh $PAGE | head -40
$SCRIPTS/find.sh $PAGE "Comprar"

# 3. Edita várias coisas localmente
$SCRIPTS/set-text.sh $PAGE el_btn_cta "Compre já"
$SCRIPTS/set-text.sh $PAGE el_h1_hero "Nova promoção"
$SCRIPTS/set-style.sh $PAGE el_btn_cta '.background.solid.color' '"#FF6600"'

# 4. Conferência
$SCRIPTS/diff.sh $PAGE

# 5. Manda pro servidor (1 PUT, 1 validação)
$SCRIPTS/push.sh $PAGE
```

## Quando NÃO usar esta skill

- Criar seções novas → use o **especialista-unicpages** (que conhece schema) + `unicpages_section_add` ou `unicpages_section_add_from_template`. Esta skill é só pra editar o que já existe.
- Aplicar templates → use os tools MCP `unicpages_*_template`.
- Publicar → publicação é só pelo editor frontend.

## Detalhes de segurança e corretude

- Cache fica em `~/.cache/unicpages/<pageId>.json` e `~/.cache/unicpages/<pageId>.etag`.
- `push.sh` faz um `GET` rápido antes do PUT pra comparar hash. Se o draft remoto mudou desde o pull, **aborta** e pede pra rodar `pull.sh` de novo (evita sobrescrever edição feita no editor visual).
- Todos os patches são **idempotentes** — rodar 2x produz o mesmo estado.
- `set-style.sh` aceita jsonpath estilo jq (`.fontSize`, `.padding.top`). Se o caminho não existe, é criado com `+=`. Se quiser deletar, passe `null`.
- API endpoint: `https://api-app.unicpages.com/drafts/page/<pageId>` (header `x-access-token`).

## Como esta skill se relaciona com o MCP

A skill **não substitui** o MCP — ela complementa. Ambos atacam o mesmo problema (JSON gigante no contexto) por dois ângulos:

- **MCP** (tools `unicpages_element_*` adicionados em 2026-05): granularidade no servidor. Cada chamada faz 1 GET + 1 patch + 1 PUT. Bom pra 1-5 edições.
- **Skill local**: granularidade no cliente. 1 GET → N edições offline → 1 PUT. Bom pra 5+ edições ou exploração pesada.

Use **MCP por padrão**. Caia pra skill só quando o volume justifica.
