#!/usr/bin/env bash
# finalizar.sh — junta os 6 atos, aplica narração, trilha e SFX, e masteriza.
#
# Hierarquia de volume (o que separa mixagem de "tudo junto"):
#   narração  -14 LUFS   a voz manda, sempre
#   efeitos   -20 LUFS   6 dB abaixo: percebe-se, não disputa
#   trilha    -26 LUFS   12 dB abaixo: sente-se, não se escuta
# Os SFX já saem nesses níveis da geração; aqui é só posicionamento e master.
set -euo pipefail

VSL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIR="${AE_SAIDA:-$HOME/Downloads/vsl-after}"
SAIDA="${1:-$DIR/vsl-pagzero-after.mp4}"
LIB="$VSL/audio-lib"
NARRACAO="$VSL/audio/vsl-42.mp3"

[ -f "$NARRACAO" ] || { echo "erro: narração não encontrada: $NARRACAO" >&2; exit 1; }

# --- 1. concatena os atos ---------------------------------------------------
lista="$(mktemp)"; trap 'rm -f "$lista" "$lista".mp4' EXIT
for a in 1 2 3 4 5 6; do
  f="$DIR/ato$a.mp4"
  [ -f "$f" ] || { echo "erro: falta $f (rode montar.mjs e render.sh)" >&2; exit 1; }
  echo "file '$f'" >> "$lista"
done
echo "==> concatenando os 6 atos" >&2
ffmpeg -y -v error -f concat -safe 0 -i "$lista" -c copy "$lista.mp4"

VIDEO_DUR=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$lista.mp4")
echo "==> vídeo: ${VIDEO_DUR}s" >&2

# --- 2. monta a faixa de efeitos -------------------------------------------
# Cada SFX entra ancorado no EVENTO da cena (o número que assenta, a moeda que
# chega), nunca no corte: efeito em toda passagem vira tique nervoso.
mapa="$VSL/sons.txt"
entradas=(-i "$lista.mp4" -i "$NARRACAO")
filtros=()
mix="[1:a]volume=1.0[voz];"
n=2

if [ -f "$LIB/trilha/a.mp3" ]; then
  entradas+=(-stream_loop -1 -i "$LIB/trilha/a.mp3")
  # Fade de 1.2s na entrada e 2s na saída: trilha que entra seca denuncia o corte.
  filtros+=("[${n}:a]atrim=0:${VIDEO_DUR},asetpts=PTS-STARTPTS,volume=0.5,afade=t=in:st=0:d=1.2,afade=t=out:st=$(python3 -c "print(max(0,$VIDEO_DUR-2))"):d=2[trilha];")
  trilha="[trilha]"; n=$((n+1))
else trilha=""; fi

sfx=""
if [ -f "$mapa" ]; then
  while read -r som tempo vol; do
    [ -z "${som:-}" ] && continue
    case "$som" in \#*) continue;; esac
    f="$LIB/sfx/$som.mp3"
    [ -f "$f" ] || continue
    entradas+=(-i "$f")
    ms=$(python3 -c "print(int(float('$tempo')*1000))")
    filtros+=("[${n}:a]adelay=${ms}|${ms},volume=${vol}[s${n}];")
    sfx="$sfx[s${n}]"
    n=$((n+1))
  done < "$mapa"
fi

qtd=$(( ${#entradas[@]} ))
echo "==> $(( (qtd-2)/2 )) faixa(s) de áudio além da narração" >&2

grafo="${filtros[*]:-}${mix}[voz]${trilha}${sfx}amix=inputs=$(python3 -c "print(1+(1 if '$trilha' else 0)+len('$sfx'.split('[s'))-1)"):duration=first:dropout_transition=0,apad=whole_dur=${VIDEO_DUR},aformat=channel_layouts=stereo,loudnorm=I=-14:TP=-1.5:LRA=11:measured_I=-12:linear=true[a]"

echo "==> mixando e masterizando" >&2
ffmpeg -y -v error "${entradas[@]}" \
  -filter_complex "$grafo" \
  -map 0:v -map "[a]" \
  -vf "scale=in_range=full:out_range=limited,format=yuv420p" \
  -color_range tv -colorspace bt709 -color_primaries bt709 -color_trc bt709 \
  -c:v libx264 -crf 19 -preset slow -profile:v high -level 4.0 \
  -c:a aac -b:a 192k -ar 48000 -movflags +faststart \
  -t "$VIDEO_DUR" "$SAIDA"

echo "$SAIDA"
