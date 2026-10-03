#!/usr/bin/env bash
# upload.sh — sobe uma imagem local pro DigitalOcean Spaces via endpoint de storage
# da API Laravel (skill api-upload-files) e imprime SÓ a URL pública
# (pra usar como --ref no nano-banana-pro).
#
# Uso: ./upload.sh /caminho/imagem.png [maxWidth]
# Requer:
#   SITE_MOCKUP_APP_DIR  → raiz do app Laravel com POST /api/storage/imagem
#                          (default: ~/Empresas/Edu Sites/Repositórios/frontend/admin)
#   UPLOAD_TOKEN ou UPLOAD_USER_EMAIL no .env do app (ver skill api-upload-files)
set -euo pipefail

IMG="${1:?uso: upload.sh <arquivo> [maxWidth]}"
MAXW="${2:-1920}"
APP_DIR="${SITE_MOCKUP_APP_DIR:-$HOME/Empresas/Edu Sites/Repositórios/frontend/admin}"
UPLOADER="$(cd "$(dirname "$0")/.." && pwd)/api-upload-files/upload.sh"
[ -x "$UPLOADER" ] || UPLOADER="$HOME/.claude/skills/api-upload-files/upload.sh"

[ -f "$IMG" ] || { echo "arquivo não existe: $IMG" >&2; exit 1; }
[ -f "$APP_DIR/artisan" ] || { echo "app Laravel não encontrado em $APP_DIR (defina SITE_MOCKUP_APP_DIR)" >&2; exit 1; }
[ -x "$UPLOADER" ] || { echo "skill api-upload-files não encontrada ($UPLOADER)" >&2; exit 1; }

# api-upload-files sobe o `php artisan serve` se preciso, gera o token Sanctum e derruba no fim
RESP="$("$UPLOADER" --app-dir "$APP_DIR" --type imagem --max-width "$MAXW" "$IMG")" || {
  echo "falha no upload: $RESP" >&2; exit 1; }

URL="$(printf '%s' "$RESP" | php -r '$r=json_decode(stream_get_contents(STDIN),true); echo $r["results"][0]["url"] ?? "";')"
[ -n "$URL" ] || { echo "falha no upload: $RESP" >&2; exit 1; }
printf '%s\n' "$URL"
