#!/usr/bin/env bash
#
# Gera a biblioteca de efeitos e trilha com a Sound Generation da ElevenLabs.
#
#   ./gerar-sfx.sh            # só o que falta
#   ./gerar-sfx.sh --forcar   # regera tudo (gasta crédito)
#   ./gerar-sfx.sh pop trava  # só esses
#
# Os arquivos ficam em projeto/public/audio-lib/ e são REUTILIZÁVEIS entre
# vídeos — gere uma vez, use sempre.
set -euo pipefail

SKILL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LIB="$SKILL/audio-lib"
mkdir -p "$LIB/sfx" "$LIB/trilha"

KEY="$(node "$HOME/.claude/scripts/elevenlabs-key.mjs" 2>/dev/null | tail -1)"
[ -n "$KEY" ] || { echo "ERRO: sem chave da ElevenLabs" >&2; exit 1; }

FORCAR=0
ALVOS=()
for a in "$@"; do
  [ "$a" = "--forcar" ] && FORCAR=1 || ALVOS+=("$a")
done

# nome|duração|influência|prompt
#
# Os prompts são em INGLÊS de propósito: o modelo de som foi treinado nesse
# idioma e responde melhor. "premium fintech" e "no reverb tail" são o que
# mantém os efeitos discretos — som com cauda longa suja a narração.
EFEITOS=(
  "pop|1|0.6|very short soft UI pop, clean digital interface blip, no reverb tail, premium fintech app"
  "whoosh|1|0.6|short clean whoosh transition, subtle air swish, modern motion graphics, no music"
  "trava|1|0.6|soft mechanical lock click, premium metallic latch closing, short and clean"
  "chime|1.5|0.6|very short soft success chime, single note, clean digital, premium fintech confirmation"
  "impacto|2|0.6|deep soft bass impact, cinematic sub drop, clean and short, premium brand reveal"
  "digita|1.5|0.6|very soft keyboard typing blips, subtle digital message send, short"
  "erro|1|0.6|short subtle negative blip, soft descending tone, muted, no harshness"
  "papel|1.5|0.6|soft paper shuffle whoosh, documents sliding, short and clean"
)

TRILHAS=(
  "a|22|0.35|minimal ambient electronic underscore for fintech ad, soft pulsing synth bass, subtle arpeggio, no drums, no vocals, calm confident loop, 90 bpm"
  "b|22|0.35|minimal ambient electronic underscore, warm synth pad with gentle rhythmic pulse, corporate tech, no drums, no vocals, seamless"
)

quer () {
  [ ${#ALVOS[@]} -eq 0 ] && return 0
  for a in "${ALVOS[@]}"; do [ "$a" = "$1" ] && return 0; done
  return 1
}

gerar () {
  local nome="$1" dur="$2" infl="$3" prompt="$4" destino="$5" lufs="$6"
  quer "$nome" || return 0
  if [ -f "$destino" ] && [ "$FORCAR" = "0" ]; then
    printf "  %-10s (já existe)\n" "$nome"; return 0
  fi

  local tmp; tmp="$(mktemp -t sfx).mp3"
  python3 -c "
import json,sys
print(json.dumps({'text':sys.argv[1],'duration_seconds':float(sys.argv[2]),'prompt_influence':float(sys.argv[3])}))
" "$prompt" "$dur" "$infl" > "$tmp.json"

  curl -s -X POST "https://api.elevenlabs.io/v1/sound-generation" \
    -H "xi-api-key: $KEY" -H "Content-Type: application/json" \
    -d @"$tmp.json" -o "$tmp" --max-time 120

  # A Sound Generation costuma devolver SILÊNCIO no início — sem cortar, o
  # efeito dispara atrasado e parece dessincronizado. O fade de 15ms na entrada
  # evita estalo; o de 40ms na saída evita corte seco na cauda.
  ffmpeg -y -v error -i "$tmp" \
    -af "silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.02,afade=t=in:st=0:d=0.015,areverse,afade=t=in:st=0:d=0.04,areverse,loudnorm=I=${lufs}:TP=-2:LRA=11" \
    -c:a libmp3lame -q:a 2 "$destino"
  rm -f "$tmp" "$tmp.json"
  printf "  %-10s %ss\n" "$nome" "$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$destino")"
}

echo "▸ Efeitos (−20 LUFS)"
for e in "${EFEITOS[@]}"; do
  IFS='|' read -r n d i p <<< "$e"
  gerar "$n" "$d" "$i" "$p" "$LIB/sfx/$n.mp3" "-20"
done

echo "▸ Trilha (−26 LUFS)"
for t in "${TRILHAS[@]}"; do
  IFS='|' read -r n d i p <<< "$t"
  # a trilha não leva silenceremove: cortar o início quebra o loop
  quer "$n" || continue
  if [ -f "$LIB/trilha/$n.mp3" ] && [ "$FORCAR" = "0" ]; then
    printf "  %-10s (já existe)\n" "$n"; continue
  fi
  tmp="$(mktemp -t trilha).mp3"
  python3 -c "
import json,sys
print(json.dumps({'text':sys.argv[1],'duration_seconds':float(sys.argv[2]),'prompt_influence':float(sys.argv[3])}))
" "$p" "$d" "$i" > "$tmp.json"
  curl -s -X POST "https://api.elevenlabs.io/v1/sound-generation" \
    -H "xi-api-key: $KEY" -H "Content-Type: application/json" -d @"$tmp.json" -o "$tmp" --max-time 180
  ffmpeg -y -v error -i "$tmp" -af "loudnorm=I=-26:TP=-3:LRA=11" -c:a libmp3lame -q:a 2 "$LIB/trilha/$n.mp3"
  rm -f "$tmp" "$tmp.json"
  printf "  %-10s %ss\n" "$n" "$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$LIB/trilha/$n.mp3")"
done

echo ""
echo "✓ $LIB"
