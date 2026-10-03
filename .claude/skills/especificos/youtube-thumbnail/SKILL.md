---
name: youtube-thumbnail
description: Cria capas (thumbnails) para o YouTube do Eduardo usando OpenAI gpt-image-2. Gera uma capa épica 1920x1080 (16:9) a partir de um briefing, sempre usando a foto real do Eduardo como referência de rosto e logos/prints de projeto como referência visual. Use SEMPRE que o Eduardo pedir "cria uma capa pro youtube", "thumbnail", "capa do vídeo", "faz a capa", ou enviar logo/print pedindo uma capa. NÃO confundir com a skill openai-image-edit (essa é só pra editar/remover elementos de imagens existentes).
---

# YouTube Thumbnail (capas do Eduardo)

Skill dedicada a **criar capas para o YouTube do Eduardo**. Roda em cima do
`edit-image.py` da skill `openai-image-edit` (não duplicar o script). A diferença
é que aqui as **regras de capa são fixas e obrigatórias** — não perguntar, só seguir.

> Quando o pedido for **editar/remover algo de uma imagem existente** (tirar logo,
> remover fundo, trocar fundo), use a skill `openai-image-edit`. Esta skill é só
> pra **gerar capa nova do zero**.

## Caminhos importantes

- Script de imagem: `~/.claude/skills/openai-image-edit/edit-image.py`
- API key: `~/.claude/skills/openai-image-edit/.env` (`OPENAI_API_KEY=...`)
- **Foto do rosto do Eduardo (referência fixa):**
  `/Users/eduardolecdt/Empresas/Edu Sites/identidade/foto-perfil-3.png`

## Regras fixas (SEMPRE, sem perguntar)

1. **Modelo:** SEMPRE `gpt-image-2`. Nunca outro.
2. **Subcomando:** SEMPRE `edit` (nunca `gen`), porque o `gen` não aceita `--ref`.
   A **foto do rosto é a imagem base** (argumento posicional), e logos/prints do
   projeto vão como `--ref` (podem repetir).
3. **Rosto do Eduardo = a foto real.** Enviar `foto-perfil-3.png` para a IA e
   instruí-la a **manter o rosto IDÊNTICO ao da referência** — pele, óculos, barba,
   formato do rosto. **NÃO estilizar em 3D/cartoon, NÃO inventar outro rosto.**
   Só não usar a foto se o Eduardo pedir explicitamente "sem meu rosto".
4. **Geração em `1536x1024`** (único 16:9-ish que o gpt-image-2 faz bem) com
   `--quality high` e `--input-fidelity high` (preserva o rosto da foto base).
5. **Formato final 1920x1080 (16:9) SEM ESTICAR.** O `1536x1024` é 3:2, não 16:9.
   NUNCA fazer resize direto pra 1920x1080 (isso ESTICA e quebra o rosto).
   Fazer **crop central proporcional** pra 16:9 e depois upscale proporcional:
   ```python
   from PIL import Image
   src = Image.open(RAW).convert("RGB")
   w, h = src.size                       # 1536 x 1024
   target = 16/9
   # corta topo/base pra virar 16:9 sem distorcer
   new_h = int(round(w / target))        # 1536/1.777 = 864
   top = (h - new_h) // 2
   cropped = src.crop((0, top, w, top + new_h))   # 1536 x 864 (16:9 exato)
   final = cropped.resize((1920, 1080), Image.LANCZOS)  # mesma proporção -> sem esticar
   final.save(FINAL, optimize=True)
   ```
6. **Tons roxos como cor principal** (default da identidade do Eduardo), salvo
   se ele pedir outra paleta.
7. **Espaço pra título:** deixar um canto (geralmente superior-esquerdo) mais limpo
   pra ele escrever o título depois.
8. **PNG leve (< 2MB, limite do YouTube).** Otimizar no final:
   `oxipng`/`pngquant` se disponível; senão Pillow `optimize=True`; se ainda passar
   de 2MB, requantizar (`img.quantize(colors=256, method=Image.FASTOCTREE)`).
9. **Entregar em `~/Downloads/`** com nome descritivo (`capa-<slug>.png`) e
   apagar o `-raw` intermediário no final.

## Prompt perfeito (boas práticas)

- **Sempre em inglês**, rico e cinematográfico, descrevendo a cena que o Eduardo pediu.
- **Sempre mandar as imagens de referência reais** (`--ref logo.png --ref print.png`)
  em vez de só descrever — quanto mais referência visual, mais fiel a capa.
- Reforçar no prompt: *"keep the man's face PHOTOREALISTIC and IDENTICAL to the
  reference photo — same person, same glasses, same beard, do not stylize or
  cartoonify the face"*.
- Reproduzir fielmente prints de tela (login, dashboard) que ele mandar.
- Pedir composição com espaço livre num canto pra título.
- Alto contraste, iluminação dramática, estética premium de tech/coding channel.

## Pipeline (passo a passo)

```bash
SCRIPT=~/.claude/skills/openai-image-edit/edit-image.py
PERFIL="/Users/eduardolecdt/Empresas/Edu Sites/identidade/foto-perfil-3.png"
RAW="$HOME/Downloads/capa-<slug>-raw.png"
FINAL="$HOME/Downloads/capa-<slug>.png"

# 1) Gera com gpt-image-2, foto real como base + logos/prints como --ref
python3 "$SCRIPT" edit "$PERFIL" \
  --model gpt-image-2 --size 1536x1024 --quality high --input-fidelity high \
  --ref /caminho/logo.png --ref /caminho/print.png \
  -o "$RAW" \
  -p "<prompt épico em inglês, rosto idêntico à foto, tons roxos>"

# 2) Crop central 16:9 + upscale proporcional pra 1920x1080 (SEM esticar) — ver bloco Python acima
# 3) Otimizar < 2MB
# 4) rm "$RAW"
```

## Custo

`--quality high` em `1536x1024` ≈ US$ 0,17–0,25 por capa + input tokens da foto/refs.
É uma capa hero, então `high` é o esperado. Não precisa confirmar custo de capa única.

## Segurança

- Nunca imprimir/commitar a API key (vem do `.env` da skill `openai-image-edit`).
- A foto do rosto é pessoal — não subir pra lugar nenhum além da API da OpenAI.
