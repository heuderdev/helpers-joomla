---
name: postiz-publish
description: Publica e agenda posts nas redes sociais do usuário (X, Instagram, LinkedIn, Facebook, Threads, TikTok, YouTube, Reddit, Discord, etc.) usando a Postiz Public API. Lista os canais conectados, sobe mídia (imagem/vídeo) e cria o post — publicando agora, agendando pra uma data, ou salvando como rascunho. Use SEMPRE que o usuário pedir "publica isso nas redes", "posta no Instagram/X/LinkedIn", "agenda um post", "manda pro Postiz", "sobe esse conteúdo pras redes sociais", "publica em todos os canais", ou enviar um texto/imagem pedindo pra postar.
---

# Postiz Publish

Publica conteúdo nas redes sociais do usuário via [Postiz Public API](https://docs.postiz.com/public-api/introduction). Um post pode ir pra vários canais de uma vez, com ou sem mídia, publicado agora / agendado / rascunho.

Todo o trabalho de rede é feito pelo script `scripts/postiz.mjs` (Node ≥18, usa `fetch`/`FormData` nativos). Você orquestra: descobre o que o usuário quer, chama o script, reporta o resultado.

## Pré-requisitos

- **`POSTIZ_API_KEY`** no ambiente. Pega em **Settings > Developers > Public API** no Postiz. Se não estiver setada, o script avisa — peça a chave ao usuário e rode com `POSTIZ_API_KEY=... node ...` (ou oriente a exportar).
- **`POSTIZ_API_URL`** (opcional) só se for self-hosted: `https://SEU-DOMINIO/api/public/v1`. Default é a cloud `https://api.postiz.com/public/v1`.

## Fluxo (seja interativo, não assuma)

1. **Descubra os canais.** Rode `channels` pra ver os canais conectados (id, name, provider). Mostre ao usuário e pergunte **em quais** ele quer postar — a não ser que já tenha dito ("posta no meu Instagram").
2. **Confirme o conteúdo.** Texto do post e mídia (imagem/vídeo). Se o usuário mandou uma imagem/arquivo, use o path dela como `--media`.
3. **Confirme o timing:** publicar **agora** (`now`), **agendar** (`schedule` + data em UTC ISO8601) ou **rascunho** (`draft`).
4. **Mostre um preview** com `--dry-run` (imprime o payload sem publicar) e peça o OK antes de publicar de verdade — publicar é uma ação externa e irreversível.
5. **Publique** e reporte o retorno (id do post/grupo).

## ⚠️ Cuidados aprendidos na prática

- **`delete` remove por GRUPO.** A API deleta *todos os posts do mesmo grupo* do alvo — não só o post pedido. Nunca delete um post de teste sem confirmar que ele não compartilha grupo com um post real. Sempre confirme com o usuário antes de deletar.
- **Deletar no Postiz ≠ despublicar.** O que já foi ao ar na rede continua publicado; o delete só remove o rastreio/analytics dentro do Postiz.
- **Cada rede tem campos obrigatórios próprios.** Rode `settings <id>` ANTES de postar numa rede nova pra ver o schema (ex.: Instagram exige `post_type`, `maxLength` 2200). O comando `post` já injeta defaults pra Instagram e X; pras demais com exigências, use `settings` + `publish`.

## Comandos

Sempre rode a partir da pasta da skill:

```bash
# 1) listar canais conectados
node ~/.claude/skills/postiz-publish/scripts/postiz.mjs channels

# 2) publicar agora num canal, com uma imagem (faz upload sozinho)
node ~/.claude/skills/postiz-publish/scripts/postiz.mjs post \
  --channel <ID_DO_CANAL> \
  --content "Texto do post 🚀" \
  --media /caminho/imagem.png

# 3) mesmo post em vários canais ao mesmo tempo
node .../postiz.mjs post --channel <ID1> --channel <ID2> --content "..." 

# 4) agendar pra uma data (UTC!)
node .../postiz.mjs post --channel <ID> --type schedule \
  --date 2026-07-20T13:00:00.000Z --content "..."

# 5) preview sem publicar
node .../postiz.mjs post --channel <ID> --content "..." --dry-run

# 6) só subir mídia e pegar {id, path}
node .../postiz.mjs upload /caminho/foto.jpg https://exemplo.com/img.png

# 7) publicar um payload JSON montado à mão (controle total das settings)
node .../postiz.mjs publish payload.json

# 8) ver o schema/campos obrigatórios de um canal (rode ANTES de postar em rede nova)
node .../postiz.mjs settings <ID_DO_CANAL>

# 9) listar posts (default -7d a +30d; use --date / --date-end pra ampliar)
node .../postiz.mjs list

# 10) alternar um post entre rascunho e agendado (mantém a data)
node .../postiz.mjs status <ID_DO_POST> schedule   # promove rascunho -> fila
node .../postiz.mjs status <ID_DO_POST> draft

# 11) deletar um post — CUIDADO: remove todos do mesmo grupo. Confirme antes.
node .../postiz.mjs delete <ID_DO_POST>
```

Flags do `post`: `--channel` (repetível), `--content` / `--content-file`, `--media` (repetível, path local ou URL), `--type now|schedule|draft` (default `now`), `--date <ISO8601 UTC>`, `--shortlink`, `--dry-run`.

## Detalhes que importam

- **`__type` por canal:** o script busca o provider de cada canal e preenche o `__type` automaticamente (ex.: `x`, `instagram`, `linkedin`). Pra maioria dos canais isso basta.
- **Settings específicas de plataforma:** alguns providers exigem campos extras nas `settings` (ex.: título no YouTube/Reddit, privacidade no TikTok, `who_can_reply_post` no X). Quando o alvo precisar disso, **monte o payload JSON à mão** e use `publish` — veja a referência de cada provider em `references/providers.md` e a doc oficial. O `post` cobre o caso comum (texto + mídia).
- **Datas em UTC.** `schedule` exige `--date` em ISO8601 UTC (ex.: `2026-07-20T13:00:00.000Z`). Converta o horário local do usuário (BRT = UTC-3) antes.
- **Mídia:** formatos aceitos JPEG, PNG, GIF, WebP, AVIF, BMP, TIFF, MP4. Limite 50MB. Uma URL em `--media` usa upload-from-url; um path local faz multipart.
- **Rate limits:** ~100 posts/hora e 30 requests/hora em `channels`. Se der HTTP 429, aguarde.

## Referências

- `references/providers.md` — o `__type` e as settings extras por plataforma (montar payload à mão).
- Doc oficial: https://docs.postiz.com/public-api/introduction
