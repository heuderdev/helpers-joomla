#!/usr/bin/env bash
# Transcreve áudio com whisper.cpp local (Apple Silicon GPU via Metal).
#
# Uso:
#   transcribe.sh <arquivo> [--lang pt|en|es|auto] [--model tiny|base|small|medium|large] [--mp3]
#
# Saída:
#   stdout = transcrição limpa (sem timestamps, sem logs)
#   stderr = logs (instalação, conversão, modelo)
#
# Códigos de saída:
#   0 ok | 1 uso inválido | 2 arquivo inexistente | 3 dependência faltando | 4 falha de ffmpeg | 5 falha de whisper

set -euo pipefail

MODEL="medium"
LANG="pt"
KEEP_MP3=0
INPUT=""

while [[ $# -gt 0 ]]; do
  case "$1" in
    --lang)  LANG="$2"; shift 2 ;;
    --model) MODEL="$2"; shift 2 ;;
    --mp3)   KEEP_MP3=1; shift ;;
    -h|--help)
      sed -n '2,12p' "$0" | sed 's/^# \{0,1\}//'
      exit 0
      ;;
    *)
      if [[ -z "$INPUT" ]]; then INPUT="$1"; else
        echo "argumento desconhecido: $1" >&2; exit 1
      fi
      shift
      ;;
  esac
done

if [[ -z "$INPUT" ]]; then
  echo "uso: transcribe.sh <arquivo> [--lang pt|en|es|auto] [--model medium] [--mp3]" >&2
  exit 1
fi
if [[ ! -f "$INPUT" ]]; then
  echo "arquivo não encontrado: $INPUT" >&2
  exit 2
fi

# Dependências
if ! command -v ffmpeg >/dev/null 2>&1; then
  echo "ffmpeg não instalado. Rode: brew install ffmpeg" >&2
  exit 3
fi
if ! command -v whisper-cli >/dev/null 2>&1; then
  echo "whisper-cpp não instalado. Instalando..." >&2
  brew install whisper-cpp >&2 || { echo "falha ao instalar whisper-cpp" >&2; exit 3; }
fi

# Modelo
MODELS_DIR="$HOME/.whisper-models"
MODEL_FILE="$MODELS_DIR/ggml-$MODEL.bin"
mkdir -p "$MODELS_DIR"
if [[ ! -f "$MODEL_FILE" ]]; then
  echo "Baixando modelo whisper '$MODEL' (uma vez só)..." >&2
  curl -L --fail --progress-bar \
    -o "$MODEL_FILE" \
    "https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-$MODEL.bin" >&2 \
    || { echo "falha ao baixar modelo $MODEL" >&2; rm -f "$MODEL_FILE"; exit 3; }
fi

# Conversão pra WAV 16kHz mono (formato ideal pro whisper)
TMPDIR="$(mktemp -d)"
trap 'rm -rf "$TMPDIR"' EXIT
WAV="$TMPDIR/audio.wav"
echo "Convertendo para WAV 16kHz mono..." >&2
ffmpeg -i "$INPUT" -ar 16000 -ac 1 -c:a pcm_s16le "$WAV" -y -loglevel error >&2 \
  || { echo "falha na conversão com ffmpeg" >&2; exit 4; }

# Geração opcional de MP3 ao lado do original
if [[ $KEEP_MP3 -eq 1 ]]; then
  EXT_LOWER="$(echo "${INPUT##*.}" | tr '[:upper:]' '[:lower:]')"
  if [[ "$EXT_LOWER" != "mp3" ]]; then
    MP3_OUT="${INPUT%.*}.mp3"
    ffmpeg -i "$INPUT" -c:a libmp3lame -b:a 128k "$MP3_OUT" -y -loglevel error >&2 \
      && echo "MP3 gerado: $MP3_OUT" >&2
  fi
fi

# Transcrição: -nt sem timestamps, -np sem logs internos do whisper
LANG_ARGS=()
if [[ "$LANG" != "auto" ]]; then
  LANG_ARGS=(-l "$LANG")
fi

echo "Transcrevendo (modelo: $MODEL, idioma: $LANG)..." >&2
whisper-cli \
  -m "$MODEL_FILE" \
  -f "$WAV" \
  "${LANG_ARGS[@]}" \
  -nt \
  -np \
  2>/dev/null \
  | sed -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' \
  | awk 'NF' \
  || { echo "falha na transcrição" >&2; exit 5; }
