---
name: editor-stories
description: Edita stories/reels verticais (falando pra câmera) trocando o áudio do vídeo por uma gravação limpa e SINCRONIZANDO perfeitamente pela voz (correlação cruzada), depois corta os barulhos/estalos do início e os silêncios internos (fluido), e queima uma legenda word-level em tempo real bem sutil. Use SEMPRE que o Eduardo mandar um (ou vários) vídeo .mov + um áudio limpo regravado pedindo pra "trocar o áudio", "sincronizar a voz limpa", "cortar os silêncios/respiros/estalos", "botar legenda em tempo real", "deixar fluido", ou editar reels/stories em lote.
---

# Editor de Stories/Reels

Pipeline de edição para vídeos verticais (pessoa falando pra câmera) onde o
Eduardo regravou o áudio limpo separado (ex: no Gravador do Mac) e quer:
1. **trocar** o áudio ruim do vídeo pela gravação limpa, **sincronizado com a boca**
2. **cortar** os barulhos/estalos do início (pops de teste de mic, "barulho de botão", assovios)
3. **remover** os silêncios internos deixando **fluido**
4. **legenda** word-level em tempo real, sutil (estilo Reels)

## Quando usar

- Eduardo manda `IMG_XXXX.mov` (ou vários) + `Vídeo N.m4a` / áudio limpo e pede pra editar
- "troca o áudio desse vídeo pela gravação limpa", "sincroniza a voz"
- "corta os silêncios / respiros / estalos", "deixa fluido / seco"
- "bota legenda em tempo real", "legenda sutil"
- Edição em lote de stories/reels

## Uso — tudo de uma vez

```bash
bash ~/.claude/skills/editor-stories/scripts/process_story.sh \
  "<video.mov>" "<audio_limpo.m4a>" "<saida.mp4>"
```

Faz sync + corte de barulho + corte de silêncio + legenda. Saída pronta pra postar.

- `--no-sub` no fim = sem legenda (só sync + cortes).

### Em lote

Descubra a correspondência **pelo conteúdo** (transcreva vídeo e áudio e bata o
assunto — NÃO confie só na duração), depois rode um por um. Geralmente é
ordem = ordem (1º áudio → 1º vídeo).

## Como cada etapa funciona (e por que assim)

### 1. Sincronização — correlação cruzada (CONFIÁVEL)
`xcorr_sync.py` casa o **padrão de energia da fala inteira** entre o áudio
original do vídeo e o áudio limpo, e retorna o delay exato a aplicar no limpo.

- **Por que não silencedetect/limiar**: a tomada limpa tem ritmo/pausas
  diferentes; alinhar por "primeiro som" erra porque estalos e níveis se
  confundem. Correlação cruzada acerta o alinhamento da fala de forma robusta.
- O delay é aplicado com `adelay`; o vídeo é montado em CRF 14 (alta qualidade),
  CFR 30fps (necessário pros cortes), áudio cortado no tamanho do vídeo (`-shortest`).

### 2. Cortar início barulhento
`find_speech.py` acha o 1º bloco de som com energia **sustentada** (≥0.4s) =
início da fala real. Pops/estalos são picos curtos (<0.15s) e ficam de fora.
Corta o vídeo a partir daí (recuando 0.12s pra não comer o ataque da 1ª palavra).
Isso remove "barulho de botão", assovios e pops que o remove-silence NÃO pega
(têm energia, não são silêncio).

### 3. Remover silêncios (fluido)
Usa a skill `remove-silence` calibrada pelo volume: `noise = mean_volume + 2dB`,
`--min 0.35 --padding 0.08` → corte seco mas natural, sem comer palavras.

### 4. Filtro de cinema — "Clean Cinema" (padrão)
Color grade natural e sutil aplicado sempre por padrão (o Eduardo aprovou este e
rejeitou versões mais agressivas tipo teal&orange/moody). Mantém as cores reais
do setup (o roxo, a pele), só realça:
```
eq=contrast=1.08:saturation=1.06:gamma=0.99,curves=b='0/0.03 1/0.97':r='0/0 1/0.98',vignette=PI/6
```
Quando o Eduardo pedir "filtro de cinema", é ESTE. Aplicado ANTES da legenda,
pra legenda branca ficar limpa por cima.

### 5. Legenda word-level
Transcreve o áudio final com `whisper-cli -ml 1 -sow --output-json` (timestamps
por palavra) e `make_ass.py` gera um `.ass` com blocos de 3 palavras, Montserrat
58pt branca com contorno sutil, embaixo (Alignment 2, MarginV 240).

**Bug histórico crítico** (já corrigido no make_ass.py): o `Format:` da seção
`[Events]` DEVE incluir o campo `Name` entre `Style` e `MarginL`. Sem ele o
libass desalinha os campos e vaza um `,`/`0` pro início do texto (aparecia
",Muito bem" na tela). Não remova esse campo.

## Ajustes comuns

| Pedido | O quê mudar |
|---|---|
| Legenda maior/menor | `make_ass.py <json> <ass> <fontsize>` (default 58) |
| Mais/menos palavras por bloco | `make_ass.py <json> <ass> <fontsize> <maxwords>` (default 3) |
| Mais fluido/seco | baixar `--min` p/ 0.25 e `--padding` p/ 0.05 no process_story.sh |
| Menos agressivo | subir `--min` p/ 0.6 |
| Sem legenda | passar `--no-sub` |

## Dependências

- `ffmpeg` / `ffprobe`
- `whisper-cli` (brew install whisper-cpp) + modelo `~/.whisper-models/ggml-medium.bin`
- `numpy` (`pip install --user --break-system-packages numpy`)
- fonte **Montserrat** instalada (`~/Library/Fonts/`)
- skill `remove-silence` (usada internamente)

## Saída

Sempre H.264 + AAC em MP4 (faststart), 1080x1920, pronto pra Instagram/TikTok.
Reporte ao Eduardo: path final, duração original → final, e que o áudio foi
sincronizado + barulhos/silêncios cortados + legenda aplicada.
