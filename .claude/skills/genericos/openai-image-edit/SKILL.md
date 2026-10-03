---
name: openai-image-edit
description: Edita e gera imagens usando a OpenAI gpt-image-1 (endpoint /v1/images/edits e /v1/images/generations). Remove elementos/logos/marcas d'água de uma foto (com máscara para área específica ou prompt para a imagem inteira), remove fundo gerando PNG transparente, troca fundo, muda estilo, ou cria imagens novas a partir de texto. Use quando o usuário pedir "tira esse elemento da imagem", "remove o logo/marca d'água", "apaga isso da foto", "remove o fundo", "deixa em PNG transparente", "troca o fundo", "edita essa imagem com IA", "gera uma imagem de X", ou equivalentes.
---

# OpenAI Image Edit (gpt-image-1)

Skill para editar/gerar imagens com o modelo de imagem da OpenAI (`gpt-image-1`).
Tudo roda via `edit-image.py` (ao lado deste arquivo), que não tem dependências
obrigatórias além do Python 3 (usa `urllib` da stdlib). Pillow só é necessário
para gerar máscaras retangulares (`mask`).

> **OBS sobre o nome do modelo:** o que algumas plataformas (ex.: Freepik) chamam
> de "GPT Image" / "gpt2" é o **`gpt-image-1` da OpenAI**. NÃO é o GPT-2 (modelo de
> texto de 2019, que não gera imagens). Esta skill chama a API oficial da OpenAI.

## Pré-requisitos

1. **API key** em `.env` ao lado deste SKILL.md:
   ```
   OPENAI_API_KEY=sk-...
   ```
   O `.env` tem `chmod 600` e nunca deve ser commitado. A key também pode vir da
   env var `OPENAI_API_KEY` (tem prioridade sobre o `.env`).
2. **Billing ativo** na conta OpenAI (não funciona no tier gratuito).
3. **Pillow** (opcional, só pro subcomando `mask`): `python3 -m pip install Pillow`.

## Os quatro modos

| Subcomando | O que faz | Quando usar |
|-----------|-----------|-------------|
| `edit`  | Edita uma imagem segundo um prompt; com `--mask` edita só uma área | Remover/trocar elemento, mudar estilo, trocar fundo |
| `rmbg`  | Remove o fundo → PNG transparente (`background=transparent`) | "deixa em PNG", "tira o fundo", recorte de produto |
| `gen`   | Cria imagem nova a partir de texto (sem imagem de entrada) | Gerar arte/ilustração do zero |
| `mask`  | Gera uma máscara PNG (retângulo transparente = área a editar) | Preparar máscara para um `edit` cirúrgico |

## Decisão crítica: com máscara ou sem máscara?

Esta é a decisão mais importante. Escolha errado e o resultado fica ruim ou caro.

- **COM máscara (`--mask`)** → a edição fica **restrita à área marcada**; o resto da
  foto é preservado pixel a pixel. **Use sempre que o elemento a remover/trocar é
  pequeno e localizado** (logo num canto, placa de carro, marca d'água, objeto pontual).
  É o modo mais previsível e o que menos "estraga" a imagem.
- **SEM máscara** → o modelo **regenera a imagem inteira** seguindo o prompt. Use só
  quando a mudança é global (mudar estilo, trocar fundo todo) ou quando o elemento
  ocupa grande parte da imagem. Com `--input-fidelity high` (default) ele tenta
  preservar rostos/detalhes, mas ainda assim pode alterar coisas que você não pediu.

### ⚠️ Bug importante: `input_fidelity` + `mask`

A OpenAI tem um bug conhecido: **se você enviar `input_fidelity` JUNTO com uma máscara,
a API cai silenciosamente para o `dall-e-2`** (modelo antigo, resultado pior). Por isso
o script **omite automaticamente `input_fidelity` quando há `--mask`** — a máscara já
garante que o resto da imagem não muda, então não há perda. Não force os dois juntos.

## Mecânica da máscara

- Deve ser PNG, **mesmas dimensões** da imagem de entrada.
- **Alpha = 0 (transparente)** marca a área **a editar**.
- **Alpha = 255 (opaco)** marca a área **a preservar**.
- O `mask` do script gera isso automaticamente a partir de retângulos `x1,y1,x2,y2`.
  Para máscaras de forma irregular, peça ao usuário para pintar no Photoshop/Figma
  (ou use a interface da Freepik, que deixa pintar com o mouse).

## Parâmetros de custo/qualidade

- `--quality low|medium|high` (default `medium`). `low` ≈ US$ 0,01–0,02/img;
  `medium` ≈ US$ 0,04–0,07/img; `high` ≈ US$ 0,17–0,25/img. **Edição também cobra
  os input tokens da imagem enviada** (uns centavos a mais).
- `--size auto|1024x1024|1024x1536|1536x1024` (default `auto`). Para preservar
  proporção de fotos retangulares, escolha o size mais próximo do original.
- Comece em `medium`. Só suba para `high` em imagens hero/destaque.

## Prompts que funcionam (boas práticas)

A pesquisa e a prática mostram alguns padrões:

- **Seja específico sobre o que manter:** termine o prompt com algo como
  *"keep everything else identical / unchanged"*. Isso reduz alterações indesejadas.
- **Remoção de objeto (com máscara):** descreva com o que preencher, não só "remova".
  - ✅ `"remove the license plate logo and replace with a clean blank dark license plate matching the vehicle"`
  - ✅ `"remove the watermark, reconstruct the background naturally"`
  - ❌ `"tira a placa"` (vago — o modelo não sabe com o que preencher)
- **Prompts em inglês** tendem a sair um pouco melhores que em português (o modelo
  foi treinado majoritariamente em inglês), mas ambos funcionam.
- **Remover fundo:** o `rmbg` já manda um prompt bom; só ajuste se o recorte estiver
  comendo partes brancas do objeto (bug conhecido) — nesse caso descreva o objeto
  explicitamente: *"keep the white sneakers fully intact, remove only the background"*.
- **Troca de estilo:** *"convert to flat vector illustration, same composition"*.

### Banco de prompts prontos

| Objetivo | Prompt sugerido |
|----------|-----------------|
| Remover logo/marca (com máscara) | `remove the logo/emblem in the masked area, fill naturally to match the surrounding surface` |
| Apagar placa de carro | `remove the license plate graphic, replace with a clean blank dark plate matching the car` |
| Remover pessoa/objeto do fundo | `remove the person/object, reconstruct the background seamlessly` |
| Trocar fundo | `replace the background with <descrição>, keep the subject exactly as is` |
| Remover fundo p/ PNG | use o subcomando `rmbg` |
| Limpar/clarear foto | `enhance lighting and clarity, keep composition and subject identical` |

## Fluxo de uso recomendado

1. **Analise a imagem primeiro** (com a tool Read) para decidir: máscara ou não?
   E onde está o elemento (coordenadas aproximadas para o retângulo da máscara).
2. **Se for área pontual:** gere a máscara, mostre/descreva ao usuário, depois `edit --mask`.
3. **Rode em `medium` primeiro.** Mostre o resultado ao usuário antes de gastar com `high`.
4. **Nunca sobrescreva o original** — salve em arquivo novo (`-o foto.novo.png`).
   Só substitua o original depois que o usuário aprovar.
5. **Para lote** (várias fotos com o mesmo elemento na mesma posição), reutilize a mesma
   máscara/coordenadas e itere. Confirme o custo total estimado com o usuário antes.

## Exemplos completos

```bash
cd ~/.claude/skills/openai-image-edit

# 1) Apagar a placa "ROAS" de um carro (cirúrgico, com máscara)
python3 edit-image.py mask /caminho/carro.png --rect 150,310,250,360 -o /tmp/carro.mask.png
python3 edit-image.py edit /caminho/carro.png --mask /tmp/carro.mask.png \
  -p "remove the license plate logo and replace with a clean blank dark license plate matching the car, keep everything else identical" \
  -o /caminho/carro.novo.png --quality medium

# 2) Remover logo dourado grande (imagem inteira, sem máscara)
python3 edit-image.py edit /caminho/banner.png \
  -p "remove the golden ROAS TRANSFER circular logo completely, keep the rest of the image identical" \
  -o /caminho/banner.novo.png --quality high

# 3) Remover fundo -> PNG transparente
python3 edit-image.py rmbg /caminho/produto.jpg -o /caminho/produto.png

# 4) Gerar imagem nova
python3 edit-image.py gen -p "a sleek black executive van, studio lighting, white background" \
  -o /caminho/van.png --size 1536x1024
```

## Segurança

- ❌ Nunca imprima a API key (nem parcial) em mensagens ou logs.
- ❌ Nunca commite o `.env`. Ele fica em `~/.claude/skills/openai-image-edit/.env` (fora de qualquer repo).
- ✅ Se a key vazar (foi colada em chat, etc.), oriente o usuário a revogá-la em
  `platform.openai.com/api-keys` e gerar uma nova.
- ✅ Confirme o custo estimado com o usuário antes de processar lotes grandes ou usar `high`.

## Capas de YouTube do Eduardo (preferências fixas)

Quando o Eduardo pedir uma **capa pro YouTube** (thumbnail), seguir SEMPRE, sem perguntar:

- **Modelo:** `gpt-image-2` (sempre).
- **Resolução final:** `1920x1080` (16:9). Como o `gpt-image-2` só gera `1536x1024`/
  `1024x1536`/`1024x1024`, gerar em **`1536x1024`** e fazer **upscale para 1920x1080**
  com Pillow (LANCZOS) no final.
- **Rosto do Eduardo:** usar a foto `/Users/eduardolecdt/Empresas/Edu Sites/identidade/foto-perfil-3.png`
  como referência (`--ref`) para o rosto dele aparecer na capa. SÓ NÃO usar se ele
  pedir explicitamente "sem meu rosto" / "não usa minha foto".
- **Otimizar o PNG** no final pra ficar mais leve (`oxipng`/`pngquant` se disponível,
  senão `Pillow optimize=True`). Thumbnail do YouTube tem limite de 2MB.
- **Entregar na pasta Downloads:** ao terminar, copiar a capa final para
  `~/Downloads/` (além de salvar onde foi gerada).
- **Prompt perfeito:** sempre montar um prompt rico em inglês descrevendo a cena que
  o Eduardo pediu, e **enviar as imagens de referência** (foto do rosto + bandeiras/
  cenários + print de código Laravel/Blade quando fizer sentido) via `--ref`, em vez de só
  descrever em texto. Quanto mais referência visual real, mais fiel a capa.

## Específico do projeto New Class Tur (rebranding Roas → New Class Tur)

Há logos da Roas gravados dentro de fotos (não em arquivos separados):
- **Frota** (`img/frota/*.png`, `img/main-fmobile.png`, `img/main-fbrowser.png`): logo
  "ROAS" na **posição da placa** dos veículos → caso ideal de `mask` + `edit` (área pequena).
- **`img/main-fbrowser.png`**: logo dourado "ROAS TRANSFER" grande → `edit` sem máscara
  pode ser necessário, ou máscara cobrindo o círculo dourado.
- `img/logo-roas-menu.png` e `img/roas-hor.png`: **já são o logo New Class Tur** (apesar
  do nome do arquivo) — NÃO precisam de edição, só renomear o arquivo.
