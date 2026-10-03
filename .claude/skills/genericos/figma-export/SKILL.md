---
name: figma-export
description: Exporta telas/frames de arquivos do Figma como PNG, JPG, SVG ou PDF usando a API REST oficial, e dá acesso autenticado à API do Figma (ler arquivo, listar páginas, nós, estilos). Use SEMPRE que precisar TIRAR imagens de um arquivo Figma (exportar carrossel, slides, telas, ícones, assets), ou quando precisar consultar a estrutura de um arquivo sem depender do MCP. Complementa o MCP do Figma - o MCP é melhor para CRIAR e EDITAR design; esta skill é melhor para EXPORTAR em lote e ler metadados rápido.
---

# Figma Export

Acesso autenticado à API REST do Figma. Serve para **exportar imagens em lote** (o MCP exporta um nó por vez, o que fica lento acima de ~10 telas) e para **ler a estrutura** de um arquivo.

Token pessoal já configurado em `.env` ao lado deste arquivo (`FIGMA_TOKEN`). Conta: `espartanos@lecdt.com`.

## Quando usar esta skill vs o MCP do Figma

| Tarefa | Use |
|---|---|
| Exportar 1 tela pra conferir visualmente | MCP (`get_screenshot`) |
| Exportar 10+ telas / um carrossel inteiro / assets | **Esta skill** |
| Criar ou editar design | MCP (`use_figma`) |
| Ler tokens, cores, medidas de um nó | MCP (`get_design_context`) |
| Listar páginas/seções/frames rápido | **Esta skill** |

## Exportar

```bash
cd ~/.claude/skills/figma-export

# uma página inteira, agrupando por section (ideal pra carrossel)
node scripts/export.mjs <FILE_KEY> --page "Posts" --sections --out ~/Downloads/carrosseis

# nós específicos
node scripts/export.mjs <FILE_KEY> 106:3,106:12,106:14 --out ./saida

# retina / outros formatos
node scripts/export.mjs <FILE_KEY> --page "Posts" --sections --scale 2
node scripts/export.mjs <FILE_KEY> 1:23 --format svg
```

**FILE_KEY** sai da URL: `figma.com/design/<FILE_KEY>/Nome?node-id=...`

Com `--page ... --sections`, cada section vira uma pasta numerada e os frames são nomeados `01.png`, `02.png`… **na ordem visual** (esquerda pra direita), que é a ordem em que o carrossel deve ser postado.

## Consultar a API direto

```bash
set -a; . ~/.claude/skills/figma-export/.env; set +a

# quem sou eu (testa o token)
curl -s -H "X-Figma-Token: $FIGMA_TOKEN" https://api.figma.com/v1/me

# estrutura do arquivo (depth evita baixar a árvore inteira)
curl -s -H "X-Figma-Token: $FIGMA_TOKEN" \
  "https://api.figma.com/v1/files/<FILE_KEY>?depth=2"

# nós específicos
curl -s -H "X-Figma-Token: $FIGMA_TOKEN" \
  "https://api.figma.com/v1/files/<FILE_KEY>/nodes?ids=106:3,106:12"
```

## Detalhes que importam

- **Ordem dos frames.** A ordem no painel de camadas do Figma **não** é a ordem visual. O script ordena por `absoluteBoundingBox.x`, que é o que vale pra carrossel. Se os frames estiverem empilhados na vertical, ordene por `y`.
- **Lotes de 40 ids** por chamada de `/images`. O script já fatia sozinho; pedir 70 de uma vez dá erro ou timeout.
- **A URL da imagem expira** (poucos minutos). Baixe logo depois de gerar, não guarde o link.
- **`scale`** aceita 0.01 a 4. Para Instagram, `1` já entrega 1080x1350 se o frame tiver esse tamanho.
- **Rate limit.** Exportações grandes podem dar HTTP 429. Se acontecer, espere e refaça só o que faltou.
- **Arquivo de outra conta** retorna 403 mesmo com token válido. Confirme que o arquivo está compartilhado com `espartanos@lecdt.com`.

## Segurança

`.env` tem permissão 600 e o `.gitignore` cobre `.env*`. Nunca imprima o token em log, commit ou mensagem.
