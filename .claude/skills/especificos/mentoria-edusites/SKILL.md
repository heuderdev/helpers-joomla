---
name: mentoria-edusites
description: Processa calls de mentoria do EduSites do início ao fim — corrige o áudio multi-faixa do OBS (as vozes vêm em faixas separadas), remove silêncios sem picotar a conversa, transcreve a call inteira e gera um PDF profissional (fundo branco, preto, logo EduSites) com diagnóstico do mentorado e checklist de ações por prazo. Use SEMPRE que o Eduardo mandar o vídeo de uma call de mentoria pedindo "tira os silêncios", "monta o PDF da call", "transcreve a mentoria", "relatório pro mentorado", "checklist de ações", ou enviar gravação de mentoria com anotações.
---

# Mentoria EduSites — vídeo + PDF

Pipeline completo de pós-produção de uma call de mentoria: **áudio → vídeo editado → transcrição → PDF do mentorado**.

## Contexto que evita os erros clássicos

### 1. O OBS grava as vozes em faixas SEPARADAS

As gravações saem com **6 faixas de áudio mono** no MP4:

| Faixa | Conteúdo | Nível típico |
|---|---|---|
| 1 | Voz do Eduardo (microfone) | ~-30 dB |
| 2 | Voz do mentorado (vem do "Tela Mac" = áudio do sistema/Meet) | ~-20 dB |
| 3 a 6 | Silêncio digital absoluto | -91 dB |

**A voz do mentorado NUNCA some** — ela está na faixa 2. Como a maioria dos players toca só a primeira faixa, parece que não foi gravada. No OBS o "Microfone Móvel" costuma estar mutado (`-inf dB`), mas isso não importa: a voz dele entra pelo áudio do sistema.

Sempre confirme antes de assumir (as faixas podem variar entre gravações):

```bash
for i in 1 2 3 4 5 6; do
  printf "faixa %s -> " "$i"
  ffmpeg -i "$VIDEO" -ss 600 -t 60 -map 0:$i -af volumedetect -f null - 2>&1 \
    | grep 'mean_volume:' | sed -E 's/.*mean_volume: //'
done
```

Armadilhas do ffmpeg aqui:
- **`-v error` suprime a saída do `volumedetect`** — não use nesse comando.
- **`-ss` antes do `-i` falha** com múltiplas faixas — coloque depois do `-i`.

### 2. A ORDEM importa: mixar ANTES de cortar

Este é o erro que arruína a entrega. O `auto-editor` decide os cortes olhando o áudio que existe no arquivo. Se você cortar antes de mixar, ele analisa só a faixa 1 e **todo trecho em que o mentorado fala vira "silêncio" e é deletado** — o vídeo perde metade da conversa e fica picotado.

```
CERTO:  mixar as 2 vozes no vídeo → cortar silêncio → entregar
ERRADO: cortar silêncio → mixar as 2 vozes  (destrói a fala do mentorado)
```

Calibragem esperada: numa call de mentoria as duas pessoas falam quase o tempo todo, então a redução real fica em torno de **10%**. Se der 40-50%, quase certamente você cortou antes de mixar e comeu a fala de alguém — refaça.

### 3. Sincronismo

Verifique antes de mixar (normalmente já está tudo alinhado, `start_time=0` em todas):

```bash
ffprobe -v error -select_streams a -show_entries stream=index,start_time,duration -of json "$VIDEO"
```

Se todas começam em `0.000000` com a mesma duração, **não há defasagem a corrigir** — mixe direto.

## Passo a passo

### Passo 1 — Diagnosticar as faixas

Rode o loop de `volumedetect` acima. Identifique qual faixa é o Eduardo e qual é o mentorado. Para confirmar quem é quem, transcreva 40s do mesmo trecho de cada faixa: se saírem textos diferentes, estão separadas de verdade.

### Passo 2 — Master sincronizado (mixa as 2 vozes)

```bash
ffmpeg -y -v error -i "$VIDEO" -filter_complex \
  "[0:1]volume=3.2[a1];[0:2]volume=1.0[a2];\
   [a1][a2]amix=inputs=2:duration=first:normalize=0,dynaudnorm=f=250:g=15:p=0.9[a]" \
  -map 0:v -map "[a]" -c:v copy -c:a aac -b:a 192k -ac 2 master.mp4
```

- `volume=3.2` na faixa 1 compensa o mic do Eduardo estar ~10 dB mais baixo. **Ajuste conforme a medição** — a meta é as duas vozes ficarem parelhas.
- `-c:v copy` não re-encoda o vídeo: é rápido e sem perda.
- `duration=first` evita que o áudio estenda além do vídeo.

### Passo 3 — Cortar silêncio (vídeo + áudio juntos)

```bash
auto-editor master.mp4 --edit "audio:threshold=3%" --margin 0.3sec \
  --video-codec libx264 --video-bitrate 5M --audio-bitrate 192k -o "$SAIDA"
```

- Use **`libx264`**, não `h264_videotoolbox` — o encoder de hardware já gerou artefatos.
- Sem `--edit "audio:stream=1"`: queremos que ele considere o áudio mixado inteiro.
- Flags que **não existem** nesta versão (29.x) e quebram na hora: `--extras`, `--video-quality-scale`. Confirme com `auto-editor --help`.
- Demora ~10 min para uma call de 1h.

**Rode encode e `mv` no MESMO job em background.** Se você deixar um watcher separado esperando, o harness pode matar o watcher e derrubar o encode junto.

### Passo 4 — Transcrever

Modelo `small` é ~5x mais rápido que o `medium` e a qualidade serve:
`/Users/eduardolecdt/Library/Application Support/Screen Studio/models/ggml-small.bin`
(o `medium` fica em `~/.whisper-models/ggml-medium.bin`)

Extraia o áudio mixado, **divida em blocos de ~9 min com 20s de sobreposição** (evita perder frases nas emendas) e transcreva bloco a bloco:

```bash
ffmpeg -y -v error -i master.mp4 -ac 1 -ar 16000 call.wav
for i in 0 1 2 3 4 5 6; do
  ffmpeg -y -v error -i call.wav -ss $((i*540)) -t 560 -ac 1 -ar 16000 b$i.wav
done
for i in 0 1 2 3 4 5 6; do
  whisper-cli -m "$MODELO" -f b$i.wav -l pt -otxt -of bloco$i -nt -t 4
done
```

Cuidados:
- **Rode em foreground, um ou dois blocos por chamada.** Loops longos via `nohup` são mortos ao desanexar e você perde tudo. Cada bloco leva ~1 min com o `small`.
- **`setsid` não existe no macOS** — não tente usar.
- O `-oj` (JSON) pode não gerar arquivo; o `-otxt` é confiável.

### Passo 5 — Gerar o PDF

Use `scripts/gerar-pdf.py`, que já contém o template com a identidade correta:

```bash
python3 ~/.claude/skills/mentoria-edusites/scripts/gerar-pdf.py \
  --json dados.json --saida "/caminho/Relatorio.pdf"
```

O script recebe um JSON com o conteúdo e monta o HTML → PDF via Chrome headless. A estrutura do JSON está em `scripts/exemplo.json`.

**Identidade visual (fixa):**
- Fundo **branco**, cor principal **preto** (`#0d0d0f`), cinzas para apoio
- **Logo EduSites no topo** de cada documento (`assets/logo-edusites.png`)
- Sem vermelho, sem capa preta — sóbrio e imprimível
- Destaques: caixas com borda preta à esquerda; números em preto

Sempre inclua `-webkit-print-color-adjust: exact` no CSS, senão o Chrome descarta os fundos ao imprimir.

### Passo 6 — Conferir antes de entregar

Não confie no "terminou sem erro":

```bash
# Duração final e streams (esperado: 1 vídeo + 1 áudio)
ffprobe -v error -show_entries format=duration -show_entries stream=codec_type -of json "$SAIDA"

# As DUAS vozes sobreviveram? Transcreva 45s do resultado
ffmpeg -y -v error -i "$SAIDA" -ss 1400 -t 45 -ac 1 -ar 16000 check.wav
whisper-cli -m "$MODELO" -f check.wav -l pt -nt -t 4
```

Se a transcrição do resultado mostrar só o Eduardo falando, a fala do mentorado foi cortada — refaça a partir do Passo 2.

Confira também o PDF visualmente (`pdftoppm -png -r 80 -f 1 -l 1 arquivo.pdf p`) e leia a página renderizada.

## Estrutura do PDF (o que funciona)

1. **Cabeçalho** — logo + nome do mentorado, data, duração, meta do mês
2. **Onde você está hoje** — KPIs (faturamento, custo fixo, resultado), perfil, histórico financeiro, contexto pessoal, objetivo declarado
3. **Diagnóstico** — tabela de gargalos: o que foi observado → correção
4. **O plano** — fases com prazo, tabela de reinvestimento
5. **Checklist** — checkboxes agrupados por prazo (Hoje / Esta semana / Ao longo do mês) + seção com os compromissos do Eduardo
6. **Posicionamento** — cores, marca, percepção desejada, próxima call

Escreva a partir da **transcrição real**, não só das anotações do Eduardo: a call sempre tem definições que não foram anotadas (valores, escadas de reinvestimento, alinhamentos de expectativa). Cite falas textuais em blocos de citação quando forem marcantes.

Assunto sensível (saúde, dívida, problemas familiares) entra quando for relevante ao plano — mas avise o Eduardo que está lá, já que o documento vai para o mentorado.

## Onde salvar

- Vídeo, PDF e transcrição: `~/Downloads` (o Eduardo pede assim para enviar direto)
- Arquivo do OBS original: **nunca apague** — é a única fonte para refazer
- Áudios separados por pessoa (úteis para revisar): `ffmpeg -i "$VIDEO" -map 0:1 -c:a aac eduardo.m4a` e `-map 0:2` para o mentorado
