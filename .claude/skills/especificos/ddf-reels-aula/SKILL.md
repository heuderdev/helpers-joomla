---
name: ddf-reels-aula
description: Monta reels 1080x1920 do Delivery do Futuro a partir de vídeos brutos de palestra/aula (.MOV do iPhone). Trata a voz, transcreve com timestamps por palavra, corta silêncios e gagueiras, e monta no Remotion com legenda karaokê, zoom/pan dirigido e motion pesado (cards 3D, números girando, barras, partículas, setas, grade de quadrantes). Use SEMPRE que o Eduardo mandar um ou mais vídeos de palestra/evento pedindo "monta esse vídeo", "faz o reels disso", "edita esses cortes", "usa remotion pra montar", ou enviar .MOV/.MP4 de aula do Delivery do Futuro.
---

# ddf-reels-aula

Reels de aula do Delivery do Futuro: vídeo em tela cheia, legenda karaokê, câmera com zoom nos alvos e motion 3D.

**Projeto:** `/Users/eduardolecdt/Empresas/Team Lecdt/Repositórios/Magali Fonseca/Delivery do Futuro/Materiais Graficos/Videos Editados`
**Studio:** `npm run studio` → porta **3355**

## Antes de tudo

O Eduardo quer **ver no studio primeiro**. Só renderize quando ele aprovar e pedir.

## Pipeline — 6 passos por vídeo

Escolha um `<id>` curto e descritivo do conteúdo (`palestra1`, `combo-margem`, …), nunca com sufixo de versão.

### 1. Vídeo → `public/videos/<id>.mp4` (1080x1920 @30fps)

`.MOV` do iPhone vem **1920x1080 com `rotation=-90`** — o `scale` já sai certo, não use `transpose`.

```bash
ffmpeg -i ENTRADA.MOV -map 0:v:0 -an \
  -vf "scale=1080:1920:flags=lanczos,format=yuv420p,fps=30" \
  -c:v libx264 -preset slow -crf 19 -tune film \
  -g 15 -keyint_min 15 -sc_threshold 0 -bf 0 \
  -profile:v high -level 4.2 \
  -colorspace bt709 -color_primaries bt709 -color_trc bt709 -color_range tv \
  -map_metadata -1 -movflags +faststart -y /tmp/<id>.enc.mp4

# obrigatório: remove o box HDR "amve" do iPhone, que faz o ffprobe devolver
# "30/1," e o validar.mjs reprovar o fps. Só o elementary stream limpa.
ffmpeg -i /tmp/<id>.enc.mp4 -c copy -f h264 -y /tmp/<id>.h264
ffmpeg -r 30 -i /tmp/<id>.h264 -c copy -movflags +faststart -y public/videos/<id>.mp4
```

`-g 15` não é opcional: **sem keyframes densos o preview do studio trava** em cada seek.
O remux apara alguns centésimos do fim — confira a duração depois e ajuste o último corte.

### 2. Voz → `public/voz/voz-<id>.m4a` (-16 LUFS)

Áudio de sala com microfone, gravado de longe:

```bash
ffmpeg -i ENTRADA.MOV -map 0:a:0 \
  -af "aformat=channel_layouts=mono,highpass=f=90,afftdn=nr=14:nf=-28,\
equalizer=f=250:t=q:w=1.2:g=-3,equalizer=f=2800:t=q:w=1.4:g=4,equalizer=f=6500:t=q:w=2:g=-3,\
acompressor=threshold=-20dB:ratio=3:attack=8:release=180:makeup=3,\
alimiter=limit=0.95,loudnorm=I=-16:TP=-1.5:LRA=11" \
  -ar 48000 -c:a aac -b:a 192k -y public/voz/voz-<id>.m4a
```

### 3. Palavras → `src/<id>.palavras.json`

Transcreva a **voz já tratada** (dá timestamps melhores que o áudio bruto):

```bash
ffmpeg -i public/voz/voz-<id>.m4a -ar 16000 -ac 1 -c:a pcm_s16le -y /tmp/<id>.wav
whisper-cli -m ~/.whisper-models/ggml-large-v3.bin -l pt -f /tmp/<id>.wav \
  -ml 1 --output-csv -of /tmp/<id>
```

`-ml 1` devolve **sub-tokens** (`F`/`ala`/`tur`), não palavras. Remonte juntando os tokens que
**não** começam com espaço e grudando a pontuação solta na anterior — ver `montar.mjs` ao lado.

**Whisper erra o vocabulário de delivery** e erra igual no `large-v3`. Já vistos:
`Corriga`→carro-chefe, `ícones`/`ídolos`→itens, `quadrinho`→quadrante, `cartão`→cardápio,
`ter sido sem viver`→reduzir o custo. Leia a transcrição inteira e corrija **por sequência de
texto** (nunca por índice — some/entra palavra e desalinha tudo), redistribuindo os tempos
dentro da janela da sequência antiga.

### 4. Cortes → `src/<id>.cortes.json`

`[[ini, fim], ...]` em segundos do vídeo original. Serve para:
- aparar as pontas (abrir num gancho, fechar na virada — não no "beleza, tudo bom?")
- cortar gagueira e repetição no meio (ele erra a conta e se corrige: fique só com a versão certa)

`silencedetect` costuma não achar nada (fala corrida + ruído de sala) — corte pelo **sentido**,
lendo o mapa de palavras.

### 5. Roteiro → entrada em `src/roteiros.js`

`<id>: { camera: [...], elementos: [...], sons: [...] }`.
**Tempos em segundos do vídeo JÁ CORTADO**, batendo com o instante exato da palavra falada.
Gere o mapa remapeado para cravar cada elemento:

```bash
node -e 'const p=require("./src/<id>.palavras.json"),b=require("./src/<id>.cortes.json");
let s=0;const r=[];for(const[i,f]of b){for(const w of p)if(w.ini>=i&&w.ini<f)r.push(`${(s+w.ini-i).toFixed(2)} ${w.t}`);s+=f-i}
console.log(r.join(" | "))'
```

**Emoji sempre literal** (🎓), nunca escape `\U0001F393` — sai literal na tela.
`texto` de selo/card3d usa `\n` de verdade (`whiteSpace: pre-line`); heredoc de Python escapa demais.

### 6. Registrar e validar

`src/Root.jsx`: id no array **`REELS`** (não `VIDEOS`, que é o formato antigo de depoimento com card).
`validar.mjs`: id na lista. Então:

```bash
node validar.mjs   # confere fps, se os cortes cabem e se nada cai fora da duração
npm run studio     # porta 3355
```

## Repertório de motion

`Motion.jsx` (o pesado, com 3D) — o Eduardo pediu **bastante**: ~1 elemento a cada 2s.

| tipo | para quê | props |
|---|---|---|
| `card3d` | bloco que gira em Y ao entrar — o cavalo de batalha | `chapeu, titulo, sub, emoji, lado` |
| `numero` | número grande girando em X | `valor, legenda` |
| `barras` | comparar valores que crescem | `itens:[{emoji,texto,valor,sufixo,cor}]` |
| `combo` | dois blocos que colidem e geram um resultado | `esquerda, direita, resultado` |
| `grade` | 4 quadrantes acendendo, um destacado | `itens:[{emoji,titulo,texto}], destaque` |
| `particulas` | brilhos subindo, dá textura ao impacto | `quantidade` |
| `seta` | direção (subiu/desceu) | `direcao, x, y, tamanho` |

`Elementos.jsx` (a camada quieta): `selo`, `etiqueta`, `faixa`, `lista`, `contador`, `emoji`, `pulso`, `flash`, `aviso`.
Sons em `public/audio/`: `pop, tick, ding, coin, swoosh, impact, riser, whoosh`. Empilhe 2 (impact+riser) nos momentos fortes.

## Câmera

`camera: [{ inicio, dur, escala, x, y }]` — `x`/`y` em fração do frame, plano cheio é o repouso.
Aproxime **só quando ele aponta pra algo** (o slide, o número, ele mesmo). Escalas 1.30–1.46.
**Nada de movimento perpétuo** — ele odeia deriva constante.

O `Camera.jsx` corrige a escala pra cima sozinho (`e >= 1/(1-2d)`), então o pan nunca trava
antes do alvo. Meça os alvos com uma grade decimal:

```bash
ffmpeg -ss T -i public/videos/<id>.mp4 -frames:v 1 \
  -vf "scale=432:768,drawgrid=w=43.2:h=76.8:t=1:c=cyan@0.7" -y /tmp/grade.jpg
```

## Identidade

Sem data do evento escrita ("pensa que é um reels") — só a logo pequena no topo.
Dourado `#DBC28E`, creme `#FAF2CB`, fundo `#0B0704`, fonte Alexandria.
Elementos na **metade de cima** (`PALCO_CHEIO.zona = 0.115`); a legenda vive embaixo e eles colidem se descerem.

## Render

Só quando ele aprovar:

```bash
npx remotion render src/index.js <id> saida/<id>.mp4 --codec=h264 --crf=18
```

Passa de 30MiB e não cabe no envio de arquivo — mande preview em CRF 24 e deixe o master em `saida/`.
