#!/bin/bash
# Pipeline completo de edição de story/reel:
#   1. sincroniza voz limpa com o vídeo (correlação cruzada)
#   2. corta o começo barulhento (pops/estalos/assovios de teste de mic)
#   3. remove silêncios internos (fluido)
#   4. queima legenda word-level em tempo real
#
# Uso: process_story.sh <video> <audio_limpo> <saida.mp4> [--no-sub]
#
# Requer: ffmpeg, whisper-cli, numpy, modelo ggml-medium em ~/.whisper-models/
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"
export PATH="$HOME/Library/Python/3.13/bin:$PATH"

VIDEO="$1"; AUDIO="$2"; OUT="$3"; NOSUB="$4"
SP="$(dirname "$OUT")/.work-$(basename "${OUT%.*}")"
mkdir -p "$SP"
log(){ echo -e "\033[36m[story]\033[0m $1"; }

# ---------- 1. SINCRONIZAR (correlação cruzada — método confiável) ----------
DELAY=$(python3 "$DIR/xcorr_sync.py" "$VIDEO" "$AUDIO")
DELAY_MS=$(python3 -c "print(max(0,int(round($DELAY*1000))))")
log "sync por correlação: delay ${DELAY}s (${DELAY_MS}ms)"

ffmpeg -y -i "$AUDIO" -af "aformat=channel_layouts=stereo,adelay=${DELAY_MS}|${DELAY_MS}" \
  "$SP/sync_aud.m4a" 2>/dev/null
# monta em alta qualidade (CRF 14), CFR p/ os cortes; áudio no tamanho do vídeo
ffmpeg -y -i "$VIDEO" -i "$SP/sync_aud.m4a" -map 0:v:0 -map 1:a:0 \
  -c:v libx264 -preset medium -crf 14 -r 30 -pix_fmt yuv420p \
  -c:a aac -b:a 256k -movflags +faststart -fps_mode cfr -shortest \
  "$SP/sync_hq.mp4" 2>/dev/null

# ---------- 2. CORTAR COMEÇO BARULHENTO (pops/estalos antes da fala) ----------
ffmpeg -y -i "$SP/sync_hq.mp4" -vn -ac 1 -ar 16000 "$SP/sa.wav" 2>/dev/null
SPEECH=$(python3 "$DIR/find_speech.py" "$SP/sa.wav" -30)
# recua ~0.12s p/ não comer o ataque da 1a palavra
TRIM=$(python3 -c "print(max(0, $SPEECH - 0.12))")
log "fala real começa em ${SPEECH}s — cortando início barulhento até ${TRIM}s"
ffmpeg -y -ss "$TRIM" -i "$SP/sync_hq.mp4" \
  -c:v libx264 -preset medium -crf 14 -pix_fmt yuv420p -r 30 \
  -c:a aac -b:a 256k -movflags +faststart -fps_mode cfr \
  "$SP/trimmed.mp4" 2>/dev/null

# ---------- 3. REMOVER SILÊNCIOS internos (fluido) ----------
# calibra noise ~2dB acima do mean; min 0.35 / padding 0.08 = seco mas natural
MEAN=$(ffmpeg -i "$SP/trimmed.mp4" -af volumedetect -vn -f null - 2>&1 | grep -oE "mean_volume: -?[0-9.]+" | grep -oE "\-?[0-9.]+")
NOISE=$(python3 -c "print(round($MEAN + 2))")
log "removendo silêncios (mean ${MEAN}dB -> noise ${NOISE}dB, fluido)"
python3 ~/.claude/skills/remove-silence/scripts/remove_silence.py "$SP/trimmed.mp4" \
  --noise "$NOISE" --min 0.35 --padding 0.08 -o "$SP/nosilence.mp4" >/dev/null 2>&1

# ---------- 4. FILTRO DE CINEMA "Clean Cinema" (padrão) ----------
# Natural e sutil: leve contraste/saturação, sombras/altas suavizadas, vinheta discreta.
# Mantém as cores reais do setup. É o filtro padrão quando o Eduardo pede "filtro de cinema".
GRADE="eq=contrast=1.08:saturation=1.06:gamma=0.99,curves=b='0/0.03 1/0.97':r='0/0 1/0.98',vignette=PI/6"
log "aplicando filtro Clean Cinema"
ffmpeg -y -i "$SP/nosilence.mp4" -vf "$GRADE" \
  -c:v libx264 -preset medium -crf 14 -pix_fmt yuv420p \
  -c:a copy -movflags +faststart "$SP/graded.mp4" 2>/dev/null

# ---------- 5. LEGENDA word-level em tempo real (por cima do grade) ----------
if [ "$NOSUB" == "--no-sub" ]; then
  cp "$SP/graded.mp4" "$OUT"
  log "sem legenda (--no-sub)"
else
  ffmpeg -y -i "$SP/graded.mp4" -ac 1 -ar 16000 "$SP/fa.wav" 2>/dev/null
  whisper-cli -m ~/.whisper-models/ggml-medium.bin -l pt -f "$SP/fa.wav" \
    -ml 1 -sow --output-json -of "$SP/words" >/dev/null 2>&1
  python3 "$DIR/make_ass.py" "$SP/words.json" "$SP/sub.ass" >/dev/null
  ffmpeg -y -i "$SP/graded.mp4" -vf "subtitles='$SP/sub.ass'" \
    -c:v libx264 -preset medium -crf 14 -pix_fmt yuv420p \
    -c:a copy -movflags +faststart "$OUT" 2>/dev/null
  log "legenda queimada"
fi

rm -rf "$SP"
echo "OK: $OUT"
