#!/bin/bash
#
# Prepara os criativos de um carrossel para publicação.
#
# Exporta a section do Figma em PNG, converte para JPEG (o Content Publishing
# do Instagram NÃO aceita PNG nem WebP) e deixa tudo em ~/.gestaodev-social/criativos/<slug>/.
#
# Uso: preparar.sh <slug> <"Nome da Section no Figma">
#   preparar.sh carrossel-2 "Carrossel 2"
#
set -euo pipefail

SLUG="${1:?informe o slug, ex: carrossel-2}"
SECTION="${2:?informe o nome da section no Figma, ex: \"Carrossel 2\"}"

FILE_KEY='bZNvNdwWZrWeD3bYl9e3cH'
PAGINA='Identidade'
BASE="$HOME/.gestaodev-social"
DESTINO="$BASE/criativos/$SLUG"
TEMP=$(mktemp -d)
trap 'rm -rf "$TEMP"' EXIT

echo "Exportando \"$SECTION\" do Figma..."
node "$HOME/.claude/skills/figma-export/scripts/export.mjs" \
  "$FILE_KEY" --page "$PAGINA" --sections --out "$TEMP" >/dev/null

# A skill de export numera as pastas (01-assets, 02-carrossel-1...), então o
# nome da section vira só parte do caminho. Localiza pelo sufixo.
ALVO=$(find "$TEMP" -maxdepth 1 -type d -iname "*$(echo "$SECTION" | tr ' A-Z' '-a-z')" | head -1)
if [ -z "$ALVO" ]; then
  echo "Section \"$SECTION\" não encontrada. Disponíveis:" >&2
  ls "$TEMP" >&2
  exit 1
fi

mkdir -p "$DESTINO"
rm -f "$DESTINO"/*.jpg

# Qualidade 92: abaixo disso aparece banding nos fundos escuros do Gestão Dev.
for f in "$ALVO"/*.png; do
  n=$(basename "$f" .png)
  sips -s format jpeg -s formatOptions 92 "$f" --out "$DESTINO/$n.jpg" >/dev/null 2>&1
done

QTD=$(ls -1 "$DESTINO"/*.jpg 2>/dev/null | wc -l | tr -d ' ')
echo "$QTD imagens em $DESTINO"
[ "$QTD" -ge 2 ] && [ "$QTD" -le 10 ] || { echo "Carrossel precisa de 2 a 10 imagens" >&2; exit 1; }
echo "$DESTINO"
