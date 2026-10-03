#!/bin/bash
# otimiza-video.sh — pega o MP4 cru do record.mjs e gera o TRIO pronto pro lecdt.com:
#   <out>.mp4  (H.264 reescalado, leve, faststart)
#   <out>.webm (VP9)
#   <out>.jpg  (poster do 1º frame)
#
# Config idêntica à dos vídeos que já estão no site (public/videos/projetos).
# Largura: desktop 1100px | mobile 600px. 30fps, SEM áudio.
#
# Uso:
#   ./otimiza-video.sh <entrada.mp4> <saida-sem-extensao> <desktop|mobile>
# Ex:
#   ./otimiza-video.sh ~/Desktop/rec/itaipu-desktop.mp4 \
#     ".../lecdt.com/public/videos/projetos/itaipu-desktop" desktop

set -e
IN="$1"; OUT="$2"; MODE="${3:-desktop}"
if [ -z "$IN" ] || [ -z "$OUT" ]; then
  echo "uso: ./otimiza-video.sh <entrada.mp4> <saida-sem-extensao> <desktop|mobile>"; exit 1
fi
[ -f "$IN" ] || { echo "entrada não existe: $IN"; exit 1; }

if [ "$MODE" = "mobile" ]; then W=600; VBCAP=1100k; else W=1100; VBCAP=1500k; fi
BUF=$(( ${VBCAP%k} * 2 ))k
mkdir -p "$(dirname "$OUT")"

# MP4 H.264 — teto de bitrate garante arquivo leve
ffmpeg -y -loglevel error -i "$IN" \
  -vf "scale=${W}:-2:flags=lanczos,fps=30" -an \
  -c:v libx264 -profile:v high -crf 30 -preset slow -pix_fmt yuv420p \
  -maxrate ${VBCAP} -bufsize ${BUF} -movflags +faststart \
  "${OUT}.mp4"

# WebM VP9
ffmpeg -y -loglevel error -i "$IN" \
  -vf "scale=${W}:-2:flags=lanczos,fps=30" -an \
  -c:v libvpx-vp9 -crf 38 -b:v ${VBCAP} -row-mt 1 -deadline good -cpu-used 3 \
  "${OUT}.webm"

# Poster (1º frame, leve avanço pra não pegar tela preta)
ffmpeg -y -loglevel error -ss 0.3 -i "$IN" -frames:v 1 \
  -vf "scale=${W}:-2:flags=lanczos" -q:v 5 "${OUT}.jpg"

echo "OK: ${OUT}.{mp4,webm,jpg}  ($(du -h "${OUT}.mp4" | cut -f1) mp4)"
