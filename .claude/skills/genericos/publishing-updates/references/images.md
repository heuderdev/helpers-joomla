# Image handling

The skill supports 4 image sources (+ an optional 5th). Load this file only when Step 6 of the workflow needs it.

## Sources

### 1. Local folder (most common)

User keeps screenshots under a folder like `~/updates/prints/`. Default path if user doesn't say: try `~/updates/prints/` first, then `~/Desktop` (Mac screenshot default).

Procedure:

1. `Bash: ls -lahtr "<dir>"` to list the most recent images first.
2. Filter to image extensions: `.png`, `.jpg`, `.jpeg`, `.webp`, `.gif`.
3. If the list is empty, tell the user and ask for another path.
4. Use `AskUserQuestion` with `multiSelect=true` to let them pick multiple files. Header: `Imagens`.
5. For each picked file, ask (one by one) a short **alt text** in the primary language.

### 2. URLs already hosted

User pastes URLs in free text, one per line. Validate each with a quick HEAD request using `scripts/send.mjs --validate-url <url>`. If a URL is unreachable (≥400 or network error), flag it and ask the user to confirm keeping it or drop it.

### 3. Annotated screenshots

After picking files from a local folder (source 1), offer annotation. For each image:

- Use `AskUserQuestion`:
  - Question: "Como você quer anotar essa imagem?" (prefix with the filename)
  - Options:
    - `Seta apontando`
    - `Retângulo destacando área`
    - `Número (passo 1, 2...)`
    - `Combinar vários`
    - `Deixar como está`
  - Header: `Anotação`.

- Based on choice, ask where (9-zone grid):
  - `TL` (top-left) | `TC` (top-center) | `TR` (top-right)
  - `ML` | `MC` | `MR`
  - `BL` | `BC` | `BR`

- For `Combinar vários`, loop: ask type + zone until the user says "chega".

Store all annotation data as a JSON array. Example:

```json
[
  { "type": "arrow", "target": "TR", "color": "#ff3b30", "label": null },
  { "type": "rect", "target": "MC", "color": "#ff3b30" },
  { "type": "step", "target": "BL", "number": 1, "color": "#ff3b30" }
]
```

Call `scripts/annotate.mjs` at **Step 9** (not yet — we want to avoid regenerating if the user changes their mind during the copy review):

```bash
node ~/.claude/skills/publishing-updates/scripts/annotate.mjs \
  --input "/path/to/original.png" \
  --output "/tmp/annotated-<hash>.png" \
  --annotations-json '<JSON>'
```

The script exits 0 on success, 1 on failure. On failure, fall back to the original image and warn the user.

### 4. AI-generated images (optional)

Only offer if the active project has `aiImageEndpoint` in its JSON config. When offered, ask for an English prompt describing what to generate, then call the endpoint with the project's auth. Most projects will NOT have this configured.

## Upload

Once the image set is final (in Step 9):

- For each `kind: 'local'` or `kind: 'annotated'`, upload via `scripts/send.mjs --upload`. The script returns the final CDN URL.
- For `kind: 'url'`, skip (already hosted).

Swap the `<img src="...">` in every language's HTML body to the final URLs.

## Cover image

The first image selected is used as the update's `cover` field unless the user explicitly says otherwise. If no images were selected, ask:

- `AskUserQuestion`: "Essa atualização não tem capa. Quer subir uma imagem de capa separada? (Recomendo fortemente — fica melhor na listagem.)"
- Options: `Subir uma imagem de capa agora`, `Publicar sem capa`.
- Header: `Capa`.

If user picks "subir capa", reuse source 1 or 2.

## Sizes and formats

- Max width: read from `project.uploadMaxWidth` (default 1600).
- The upload endpoint resizes server-side, so any source size is fine.
- Prefer PNG/WebP for screenshots with text (crisper); JPEG for photos.
- If user provides a video file (.mp4 etc), politely decline — this skill is for images only. Tell them to link the video in the update body as `<a>` instead.
