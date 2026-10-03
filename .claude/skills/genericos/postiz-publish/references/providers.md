# Settings por provider (Postiz)

O `__type` dentro de `settings` identifica a plataforma. Muitas plataformas aceitam **só** o `__type` (texto + mídia). Outras exigem campos extras — nesses casos, **monte o payload à mão e use `publish payload.json`**, porque o comando `post` só preenche `__type`.

Fonte: https://docs.postiz.com/public-api/providers/

## Só precisam do `__type` (o comando `post` já resolve)

`instagram`, `facebook`, `threads`, `mastodon`, `bluesky`, `linkedin`, `discord`, `telegram`, `slack`, `medium` e afins — texto + `image[]` bastam. Confira sempre o `identifier` real com o comando `channels` (ex.: pode ser `linkedin-page`, `instagram-standalone`, etc.).

## Precisam de campos extras

### X / Twitter — `__type: "x"`
- **`who_can_reply_post`** (obrigatório): `everyone` | `following` | `mentionedUsers` | `subscribers` | `verified`
- `community` (opcional): URL `https://x.com/i/communities/[ID]`
- `made_with_ai` (bool, opcional), `paid_partnership` (bool, opcional)

### YouTube — `__type: "youtube"`
- **`title`** (obrigatório, 2–100 chars)
- **`type`** (obrigatório): `public` | `unlisted` | `private`
- `selfDeclaredMadeForKids`: `"yes"` | `"no"`
- `thumbnail`: `{ id, path }` de mídia já upada
- `tags`: `[{ value, label }]`
- Mídia deve ser um vídeo (MP4).

### TikTok — `__type: "tiktok"`
Obrigatórios: **`privacy_level`** (`PUBLIC_TO_EVERYONE` | `MUTUAL_FOLLOW_FRIENDS` | `FOLLOWER_OF_CREATOR` | `SELF_ONLY`), **`duet`** (bool), **`stitch`** (bool), **`comment`** (bool), **`autoAddMusic`** (`"yes"`/`"no"`), **`brand_content_toggle`** (bool), **`brand_organic_toggle`** (bool), **`content_posting_method`** (`DIRECT_POST` | `UPLOAD`).
Opcionais: `title` (≤90 chars), `video_made_with_ai` (bool).

### Reddit — `__type: "reddit"`
- **`subreddit`** (sem o `r/`), **`title`** (≥2 chars)
- **`type`**: `self` | `link` | `image` | `video`
- `url` (obrigatório se `type: link`)
- `is_flair_required` (bool); `flair`: `{ id, name }` (se exigido)

### Pinterest — `__type: "pinterest"`
- **`board`** (obrigatório): id do board
- `title` (≤100), `link` (URL de destino), `dominant_color` (hex)

## Exemplo — payload à mão pro X com resposta restrita

```json
{
  "type": "now",
  "date": "2026-07-15T18:00:00.000Z",
  "shortLink": false,
  "tags": [],
  "posts": [
    {
      "integration": { "id": "SEU_ID_DO_X" },
      "value": [
        { "content": "Novidade saindo do forno 🔥", "image": [{ "id": "IMG_ID", "path": "https://uploads.postiz.com/arquivo.png" }] }
      ],
      "settings": { "__type": "x", "who_can_reply_post": "everyone" }
    }
  ]
}
```

Suba a mídia antes com `upload` pra pegar `{ id, path }` e cole em `image[]`.
