---
name: remove-silence
description: Remove silêncios de vídeos (mp4, mov, mkv, webm). DOIS métodos conforme a duração — vídeo curto (<1h) usa o pipeline ffmpeg de segmentos do tauri-admin; vídeo LONGO (aula/workshop/live de horas) usa `auto-editor` com encoder de hardware h264_videotoolbox, que corta contínuo sem gerar segmentos. SEMPRE checar a duração com ffprobe antes de escolher. Use SEMPRE que o usuário pedir "remove os silêncios", "corta os silêncios desse vídeo", "tira as pausas", "deixa só as partes falando", "acelera o vídeo cortando silêncio", ou enviar um path de vídeo pedindo limpeza.
---

# Remove Silence

Skill para remover silêncios de vídeos sem perder sincronia A/V. Reproduz exatamente o pipeline do `tauri-admin` (referência: `Edu Sites/Repositórios/frontend/tauri-admin/teste-pipeline.py`).

## Quando usar

- Path de vídeo + pedido de remover silêncio/pausa: `/Users/.../video.mp4`
- Frases como: "recorta os silêncios", "tira as pausas", "deixa só onde estou falando", "acelera cortando silêncio"

**Não use** quando o usuário quer só transcrever (use `transcribe-audio`), gerar HLS, ou subir pra algum lugar.

## ⚠️ PRIMEIRO: qual é a duração do vídeo?

**Rode isto ANTES de escolher o método:**

```bash
ffprobe -v error -show_entries format=duration -of csv=p=0 "<path>"
```

| Duração | Método | Por quê |
|---|---|---|
| **< 1 hora** | `remove_silence.py` (abaixo) | Pipeline de segmentos, tudo bem nessa escala |
| **> 1 hora** (aula, workshop, live) | **`auto-editor`** — ver seção abaixo | O pipeline de segmentos é **INVIÁVEL** aqui |

**Por que o script não serve pra vídeo longo:** ele corta cada trecho falado num `.mp4` separado
e depois concatena. Num vídeo de 6h37 isso gerou **2661 segmentos** (~670 MB de temporários) e
não terminava. O `auto-editor` corta **contínuo, numa passada só**, sem arquivo intermediário.

### Vídeo longo → auto-editor (comando validado)

Instalado em `~/.local/bin/auto-editor`. Comando exato, já usado com sucesso no
Workshop E-commerce DIA 1 (4h51) e DIA 2 (6h37):

```bash
cd ~/Downloads && auto-editor "<vídeo>.mp4" \
  --edit "audio:threshold=4%" \
  --margin 0.3s \
  -c:v h264_videotoolbox -b:v 6M \
  -o "<vídeo>-sem-silencio.mp4"
```

**`-c:v h264_videotoolbox` é obrigatório** — é o encoder por hardware do Mac. Sem ele, o encode
de software em vídeo de horas não termina em tempo útil.

**Sobre o bitrate — CHECAR O DISCO ANTES:**

```bash
df -h ~ | tail -1 | awk '{print "livre:",$4}'
```

Saída ≈ `bitrate × duração`. A 6M, um vídeo de 6h37 gera **~18 GB**; a 2.5M, **~7 GB**.
Um encode a 6M já **morreu por falta de espaço** com 25 GB livres, e MP4 interrompido é
**perda total** (`moov atom not found` — o índice só é escrito no final).

Vídeo baixado do YouTube já vem comprimido (~0.6 Mbps num workshop de tela). Reencodar a 6M
infla ~10x **sem ganhar qualidade** — não há detalhe no original pra recuperar. **Use `2.5M`**
como padrão; só suba se o material de origem for realmente de alta qualidade e houver disco.

- `--threshold 4%` — volume abaixo disso é silêncio (equivale ao `--noise` do script)
- `--margin 0.3s` — folga preservada em volta da fala (equivale ao `--padding`)
- Rode **em background** e acompanhe: `pgrep -f auto-editor` + crescimento do arquivo com `stat -f%z`
- Inspecionar cortes sem encodar: trocar o `-o` por `--export v3 -o tl.json` (gera só a timeline)

## Execução (vídeos curtos, < 1h)

Use o script `scripts/remove_silence.py`:

```bash
python3 ~/.claude/skills/remove-silence/scripts/remove_silence.py "<path do vídeo>"
```

Por padrão o output é salvo ao lado do original como `<nome>-sem-silencio.mp4`. O script imprime no stdout o path final.

### Variações

| Pedido do usuário | Comando |
|---|---|
| "recorta os silêncios" | `remove_silence.py "<path>"` |
| "salva em outro lugar" | `remove_silence.py "<path>" -o "<saída>"` |
| "mais agressivo, corta pausas curtas" | `remove_silence.py "<path>" --min 0.3` |
| "menos agressivo, só pausas longas" | `remove_silence.py "<path>" --min 1.0` |
| **"fluido" / "bem seco" / "corta mais agressivo que o padrão"** | **calibrar pelo áudio — ver "Preset fluido" abaixo** |
| "considera silêncio mais alto" (ambiente ruidoso) | `remove_silence.py "<path>" --noise -25` |
| "ajusta folga em volta do silêncio" | `remove_silence.py "<path>" --padding 0.25` |

### Preset fluido (corte agressivo, calibrado pelo áudio)

Quando o Eduardo pedir vídeo **"bem fluido"**, **"mais agressivo que a skill"**, **"seco"** ou similar,
NÃO chute os parâmetros — **calibre pelo volume real do áudio** (o default `-30dB` é conservador
demais pra fala próxima/alta e corta pouco). Passo a passo:

1. Meça o volume médio do áudio:
   ```bash
   ffmpeg -i "<path>" -af "volumedetect" -vn -f null - 2>&1 | grep mean_volume
   ```
2. Use `--noise` ≈ **2 dB acima do mean_volume** (ex.: mean `-22.5dB` → `--noise -20`), com
   `--min 0.2` e `--padding 0.06`:
   ```bash
   remove_silence.py "<path>" --noise -20 --min 0.2 --padding 0.06 -o "<saída>"
   ```

Referência (2026-07): vídeo de fala com mean `-22.5dB` → `--noise -20 --min 0.2 --padding 0.06`
cortou **-10.6s de 77s** (48 silêncios), vs. só -2.9s no default. Esse é o alvo do "fluido".
Se o Eduardo quiser AINDA mais seco, suba pra `--noise -18 --padding 0.04` (risco: começar a
comer respiradas/início de palavra — avisar).

### Defaults (iguais ao tauri-admin)

- `--noise`: `-30.0` dB
- `--min`: `0.6` segundos
- `--padding`: `0.15` segundos (folga preservada nas bordas)

## Fluxo interno

1. `ffprobe` mede a duração original
2. `ffmpeg silencedetect` detecta intervalos de silêncio
3. Calcula os segmentos com áudio (com padding nas bordas)
4. Corta cada segmento em paralelo (re-encode H.264/AAC, `preset veryfast`, `crf 20`)
5. Concatena via `concat demuxer` com `-c copy` (fallback re-encode se timebases divergirem)
6. Remove os segmentos temporários

Saída sempre re-encodada garante A/V sincronizado e duração exata.

## Dependências

- `ffmpeg` e `ffprobe` (`brew install ffmpeg`)
- Python 3 (stdlib apenas — sem dependências externas)

## Comportamento de resposta

Reporte ao usuário **só o essencial**:
- Path do arquivo final
- Duração original → final + quanto foi cortado
- Quantos silêncios foram removidos

Sem preâmbulo, sem explicar o pipeline a menos que perguntem.

## Formatos suportados

Qualquer container que o ffmpeg encode/decode: `.mp4`, `.mov`, `.mkv`, `.webm`, `.avi`. A saída sempre é re-encodada para H.264 + AAC em MP4 (faststart) — então mesmo `.mkv` de entrada vira `.mp4` na saída por padrão; passe `-o` para escolher outro container.

## Erros comuns

| Erro | Causa | Fix |
|---|---|---|
| `vídeo não encontrado` | path errado | Aspas no path |
| `corte segmento X falhou` | container exótico, codec incompatível | Re-encode prévio: `ffmpeg -i in.xxx -c:v libx264 -c:a aac out.mp4` |
| `concat falhou` | timebases muito divergentes | Script já tem fallback automático com re-encode na junção |
| Saída sem silêncios cortados | áudio com fundo barulhento ou silêncios curtos | Subir `--noise` para `-25` ou baixar `--min` para `0.3` |
