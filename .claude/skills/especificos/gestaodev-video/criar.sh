#!/bin/bash
#
# Cria vídeo(s) motion do Gestão Dev, do roteiro ao mp4 pronto pro Instagram.
#
# Uso:
#   criar.sh proposta            # um vídeo
#   criar.sh --todos             # todos os roteiros
#   criar.sh proposta --so-audio # só a narração (pra ouvir antes de renderizar)
#   criar.sh proposta --recortar # refaz o corte de vales sem gastar crédito TTS
#
set -euo pipefail

SKILL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJETO="$SKILL/projeto"
SAIDA="${SAIDA:-$HOME/Downloads/gestaodev-videos}"

cd "$PROJETO"

# ---------------------------------------------------------------- dependências
if [ ! -d node_modules ]; then
  echo "Instalando dependências (só na primeira vez, ~2 min)..."
  pnpm install
fi

if [ ! -x "$HOME/.local/bin/auto-editor" ]; then
  echo "ERRO: auto-editor não encontrado em ~/.local/bin/" >&2
  echo "  Instale com: pip install auto-editor" >&2
  exit 1
fi

for chave in ~/.elevenlabs-key; do
  [ -f "$chave" ] || { echo "ERRO: falta $chave" >&2; exit 1; }
done

# ---------------------------------------------------------------- argumentos
ALVO="${1:-}"
[ -z "$ALVO" ] && { echo "Informe o roteiro (ex: proposta) ou --todos" >&2; exit 1; }

EXTRA=""
[[ "$*" == *"--recortar"* ]] && EXTRA="--recortar"
[[ "$*" == *"--forcar"* ]] && EXTRA="$EXTRA --forcar"

# ---------------------------------------------------------------- 1. narração
echo "▸ Narração"
if [ "$ALVO" = "--todos" ]; then
  node gerar.mjs --todos $EXTRA
  VIDEOS=$(ls roteiros/*.json | xargs -n1 basename | sed 's/\.json//')
else
  node gerar.mjs "$ALVO" $EXTRA
  VIDEOS="$ALVO"
fi

[[ "$*" == *"--so-audio"* ]] && { echo "✓ Áudio em public/audio/ (--so-audio)"; exit 0; }

# ---------------------------------------------------------------- 2. render
mkdir -p /tmp/gd-render "$SAIDA"
echo ""
echo "▸ Render"
for v in $VIDEOS; do
  printf "  %-12s " "$v"
  ./node_modules/.bin/remotion render src/index.js "$v" "/tmp/gd-render/$v.mp4" >/tmp/gd-render/$v.log 2>&1
  tail -1 "/tmp/gd-render/$v.log" | grep -oE '[0-9.]+ MB' || { echo "FALHOU (veja /tmp/gd-render/$v.log)"; exit 1; }
done

# ---------------------------------------------------------------- 3. entrega
#
# Reencode obrigatório: o Remotion sai em `yuvj420p` (full range) e vários
# players interpretam o range errado, deixando o preto lavado. Aqui o vídeo é
# convertido para range TV e o áudio normalizado em -14 LUFS, que é o alvo do
# Instagram.
echo ""
echo "▸ Entrega"
for v in $VIDEOS; do
  ffmpeg -y -v error -i "/tmp/gd-render/$v.mp4" \
    -vf "scale=in_range=full:out_range=limited,format=yuv420p" \
    -color_range tv -colorspace bt709 -color_primaries bt709 -color_trc bt709 \
    -c:v libx264 -crf 19 -preset slow -profile:v high -level 4.0 \
    -af "loudnorm=I=-14:TP=-1.5:LRA=11" \
    -c:a aac -b:a 192k -ar 48000 -movflags +faststart \
    "$SAIDA/$v.mp4"

  dur=$(ffprobe -v error -show_entries format=duration -of csv=p=0 "$SAIDA/$v.mp4")
  tam=$(ls -la "$SAIDA/$v.mp4" | awk '{printf "%.1f", $5/1048576}')
  printf "  %-12s %5.1fs  %sMB\n" "$v" "$dur" "$tam"
done

echo ""
echo "✓ $SAIDA"
