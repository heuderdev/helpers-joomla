---
name: magnific-image
description: Gera imagens com IA usando a API da Magnific (Freepik) — vários modelos text-to-image e de edição (Mystic, Flux, Seedream, Nano Banana Pro/Gemini 3, Imagen, Z-Image). Quando o usuário pedir uma imagem, esta skill SUGERE os 2-3 modelos que mais se encaixam no pedido (com o custo em créditos de cada um), PERGUNTA qual usar, e então gera. Use SEMPRE que o usuário pedir "gera uma imagem de X", "cria uma arte/ilustração/foto", "faz uma thumbnail", "gera com a magnific", "quero uma imagem realista/cartoon/poster", "edita essa imagem mantendo o personagem", ou enviar referência pedindo uma nova imagem. NÃO confundir com youtube-thumbnail (capa específica do canal do Eduardo) nem openai-image-edit (remoção de elementos via OpenAI).
---

# Magnific Image (Freepik API)

Gera imagens com IA pela **Magnific** (marca da Freepik). Vários modelos, cada um
com força e custo diferentes. Tudo roda via `generate.py` (ao lado deste arquivo),
que só usa a stdlib do Python 3.

- **Base URL:** `https://api.magnific.com`
- **Auth:** header `x-magnific-api-key` (chave já em `.env` ao lado deste SKILL.md)
- **Fluxo:** assíncrono — POST cria a task, polling no GET até `COMPLETED`, baixa a imagem.

## Comportamento OBRIGATÓRIO desta skill

Quando o usuário pedir uma imagem, **NÃO escolha o modelo sozinho**. Faça:

1. **Interprete o pedido** (foto realista? ilustração? poster com texto? thumbnail?
   edição mantendo um personagem/rosto? logo/ícone?).
2. **Sugira 2-3 modelos** que mais se encaixam, usando a tabela abaixo — e mostre o
   **custo aproximado em créditos por imagem** de cada um. Coloque o mais indicado primeiro.
3. **Pergunte qual usar** com a ferramenta de pergunta (AskUserQuestion), listando os
   modelos sugeridos como opções (o recomendado primeiro, com "(Recomendado)").
4. Só depois de escolhido, **gere** com `generate.py`.

> Os custos abaixo são **estimativas de referência em créditos** (a Magnific não expõe
> a tabela exata via API pública). Sempre apresente como "~X créditos (estimado)".
> Para o saldo/uso real, rode `python3 generate.py --balance` (só funciona em planos
> Business/Enterprise com Analytics API).

## Tabela de modelos (escolha por tipo de pedido)

| Modelo (`--model`)      | Melhor para                                              | Refs? | Custo aprox. (créditos/img) |
|-------------------------|---------------------------------------------------------|-------|-----------------------------|
| `nano-banana-pro`       | **Thumbnails, posters, texto perfeito na imagem**, cenas complexas, usar refs por URL (Gemini 3 Pro Image) | URL   | ~24 (alto, topo de linha)   |
| `seedream-v4-5-edit`    | **Editar imagem mantendo personagem/rosto** (até 5 refs, aceita arquivo local) | local/URL | ~8                     |
| `seedream-v4-5`         | Imagem geral de alta qualidade, posters, cenas detalhadas | não | ~8                          |
| `seedream-v5-lite`      | Mesma pegada do Seedream, mais barato/rápido            | não   | ~5                          |
| `mystic`                | **Fotorrealismo extremo**, retratos hiper-reais, ultra-HD | não | ~10 (varia por engine/res)  |
| `flux-2-pro`            | Arte/ilustração premium, composição forte               | não   | ~9                          |
| `flux-pro-v1-1`         | Geração rápida e barata de boa qualidade                | não   | ~5                          |
| `imagen4-ultra`         | Imagens do Google Imagen 4 (realismo, tipografia)       | não   | ~8                          |
| `z-image`               | Rápido e econômico para rascunhos/conceitos             | não   | ~2 (mais barato)            |

**Guia rápido de recomendação:**
- "thumbnail / capa / poster / imagem COM TEXTO legível" → `nano-banana-pro` (1º), `flux-2-pro`, `seedream-v4-5`
- "foto realista / retrato / produto fotográfico" → `mystic` (1º), `imagen4-ultra`, `seedream-v4-5`
- "ilustração / arte / conceito / cartoon" → `flux-2-pro` (1º), `seedream-v4-5`, `z-image` (econômico)
- "edita ESSA imagem / mantém o personagem / troca o fundo mantendo o sujeito" → `seedream-v4-5-edit` (1º), `nano-banana-pro`
- "rápido / barato / só um rascunho" → `z-image` (1º), `flux-pro-v1-1`, `seedream-v5-lite`

## Como gerar

```bash
# text-to-image simples
python3 generate.py --model mystic --prompt "a hyperrealistic portrait of a lion, golden hour" \
  --aspect 1:1 --engine realism --out leao.png

# thumbnail/poster com texto (Gemini 3)
python3 generate.py --model nano-banana-pro --prompt "..." --aspect 16:9 --resolution 2K --out capa.png

# edição mantendo personagem (refs locais OU URLs, até 5)
python3 generate.py --model seedream-v4-5-edit --prompt "put this character in a snowy mountain" \
  --ref personagem.png --ref estilo.jpg --out resultado.png
```

## Recortar / remover fundo (transparência real)

Para **tirar o fundo** de uma imagem — foto de produto, veículo, pessoa — use o
`remove-bg.py` (endpoint `POST /v1/ai/beta/remove-background`):

```bash
# a API só aceita URL pública; para arquivo local, suba antes (skill api-upload-files)
python3 remove-bg.py --url "https://cdn.exemplo.com/carro.webp" --out recorte.webp

# preservando o tamanho exato do original
python3 remove-bg.py --url "https://cdn.exemplo.com/carro.webp" --out recorte.webp --size 1024x683
```

Devolve PNG/WebP **RGBA com alpha real** (valida antes de gravar; falha se vier opaco).

⚠️ **Use SEMPRE este endpoint para recorte — nunca um modelo generativo.** Ele é um
modelo de *segmentação*: só separa o sujeito do fundo, então é impossível alterar o
objeto. Já os generativos redesenham a imagem inteira e estragam detalhes:
- `gpt-image-1` → mexe no veículo (testado, resultado inutilizável)
- `nano-banana-pro` → ótimo para editar/remover elementos, mas devolve **RGB sem alpha**,
  ou seja, destrói a transparência do arquivo
- máscara manual (Python/ImageMagick por cor) → detecta reflexos junto e cria tarjas
  cobrindo parte do objeto

Se precisar dos dois (editar E recortar), a ordem é: `nano-banana-pro` para a edição →
`remove-bg.py` para devolver a transparência.

### Parâmetros do `generate.py`
- `--model` — um dos da tabela (obrigatório).
- `--prompt` — descrição da imagem (obrigatório). Prompts em inglês rendem melhor.
- `--aspect` — `9:16`, `16:9`, `1:1`, `2:3`, `3:4`, `4:3`. O script converte sozinho para o
  formato nomeado quando o modelo (Seedream/Mystic) exige (`widescreen_16_9` etc).
- `--resolution` — `1K` | `2K` | `4K` (quando o modelo suporta; ex. nano-banana-pro).
- `--engine` — só `mystic`: `realism` (default), `fluid`, `zen`, `flexible`, `super_real`, `editorial_portraits`.
- `--ref` — imagem de referência (arquivo local OU URL). Repetível. Só em `seedream-v4-5-edit`
  (aceita local) e `nano-banana-pro` (exige **URL pública**).
- `--out` — caminho de saída (default `magnific-out.png`). Se o modelo retornar várias, vira `_0`, `_1`.
- `--balance` — mostra uso de créditos (planos Business/Enterprise).

## Detalhes importantes

- **Refs no `nano-banana-pro` precisam ser URLs públicas** (a API não aceita arquivo local
  nesse modelo). Se o usuário só tem arquivo local e quer manter um rosto/personagem, prefira
  `seedream-v4-5-edit`, que aceita o arquivo direto (base64). Se ele insistir no nano-banana,
  suba a imagem para um host público antes (ex. o R2 do projeto) e passe a URL.
- O script faz o polling sozinho (até ~4 min). Se der `FAILED` ou estourar o tempo, mostre o erro.
- A chave de API é a mesma do app tauri-admin (gerador de cortes). Já está no `.env` da skill.

## Pré-requisitos
1. `MAGNIFIC_API_KEY` no `.env` ao lado deste SKILL.md (já configurado) ou na env var.
2. Python 3 (sem libs extras — usa `urllib`).
