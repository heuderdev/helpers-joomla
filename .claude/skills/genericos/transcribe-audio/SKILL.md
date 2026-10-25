---
name: transcribe-audio
description: Transcreve arquivos de áudio (opus, ogg, m4a, mp3, wav, aac, flac, webm, mp4) usando whisper.cpp local com aceleração Metal/GPU. Converte automaticamente o formato se necessário e opcionalmente gera um .mp3 ao lado do original. Use SEMPRE que o usuário pedir "transcreva esse áudio", "transcreve aí", "o que ele falou", "manda a transcrição", "passa pra texto", "converte e transcreve", ou enviar um path de áudio (.opus do WhatsApp, .m4a, .ogg, .mp3, etc.).
---

# Transcribe Audio

Skill de transcrição de áudio local, sem API externa, usando `whisper.cpp` com modelo `medium` em português por padrão. Aceleração Metal no Apple Silicon (M1 Pro: ~10s para 1 min de áudio).

## Comportamento de saída — CRÍTICO

Quando o usuário pedir uma transcrição, **retorne SOMENTE o texto transcrito**, num único bloco de citação ou parágrafo. Sem introdução, sem preâmbulo, sem resumo, sem perguntar se ele quer mais alguma coisa.

**FAÇA:**

> [texto da transcrição aqui]

**NÃO FAÇA:**
- "Aqui está a transcrição:" antes do texto
- "Transcrição pronta em X segundos."
- Resumo do conteúdo
- Análise do que ele falou
- Perguntar "quer que eu faça algo com isso?"

Exceção única: se o áudio falhou em transcrever (erro do whisper) ou o arquivo não existe, reporte o erro em uma linha. Se o usuário PEDIR explicitamente um resumo, análise ou ação derivada, aí sim faça.

## Quando usar

- Path de áudio no input do usuário: `/Users/.../algo.opus`, `~/Downloads/audio.m4a`, etc.
- Frases como: "transcreve esse áudio", "passa pra texto", "o que ele falou", "manda a transcrição"
- Usuário pede explicitamente "converte e transcreve" → passe `--mp3`

**Não use** quando o usuário só quer ouvir, editar ou cortar o áudio (sem transcrever).

## Execução

Use o script `scripts/transcribe.sh` (caminho absoluto: `~/.claude/skills/transcribe-audio/scripts/transcribe.sh`).

### Comando padrão (português, modelo medium)

```bash
bash ~/.claude/skills/transcribe-audio/scripts/transcribe.sh "<path do áudio>"
```

### Variações

| Pedido do usuário | Comando |
|---|---|
| "transcreve esse áudio" | `transcribe.sh "<path>"` |
| "converte pra mp3 e transcreve" | `transcribe.sh "<path>" --mp3` |
| "transcreve em inglês" | `transcribe.sh "<path>" --lang en` |
| "auto-detecta o idioma" | `transcribe.sh "<path>" --lang auto` |
| "usa o modelo large" | `transcribe.sh "<path>" --model large` |
| "transcreve rápido" (qualidade menor) | `transcribe.sh "<path>" --model base` |

### Modelos disponíveis

| Modelo | Tamanho | Quando usar |
|---|---|---|
| `tiny` | 75 MB | Testes rápidos, baixa qualidade |
| `base` | 142 MB | Áudio claro em PT/EN |
| `small` | 466 MB | Bom equilíbrio |
| `medium` | 1.4 GB | **Padrão — recomendado** |
| `large` | 2.9 GB | Áudios com ruído, sotaques fortes, múltiplos idiomas |

Modelos são baixados sob demanda em `~/.whisper-models/` e ficam em cache. Primeira execução com um modelo novo demora ~30s-2min de download.

## Parsing do path do áudio

O usuário pode mandar o path em vários formatos. Aceite todos:

- Path absoluto com aspas: `"/Users/.../WhatsApp Audio.opus"`
- Path com espaços sem aspas: `/Users/.../WhatsApp Audio.opus`
- Path relativo: `~/Downloads/audio.m4a`
- Path no meio de frase: "transcreve esse aqui /Users/.../audio.opus pra mim"

**Sempre** passe o path entre aspas duplas no bash. Expanda `~` antes (o bash expande sozinho quando o path NÃO está entre aspas; quando está, use `${HOME}` ou rode via `eval` — o jeito mais seguro é deixar `bash` interpretar via `bash -c` ou usar `"${path/#~/$HOME}"`).

Forma mais simples e robusta:
```bash
bash ~/.claude/skills/transcribe-audio/scripts/transcribe.sh "/Users/eduardolecdt/Downloads/arquivo.opus"
```

## Dependências e auto-setup

O script auto-resolve dependências:
- `ffmpeg` (deve estar instalado — `brew install ffmpeg`)
- `whisper-cpp` (auto-instala via brew se faltar)
- Modelo ggml (auto-baixa do HuggingFace na primeira execução)

Cache permanente em `~/.whisper-models/`.

## Fluxo interno do script

1. Valida path do arquivo
2. Verifica `ffmpeg` e `whisper-cli` (instala whisper-cpp se faltar)
3. Baixa modelo se não estiver em cache
4. Converte input → WAV 16kHz mono (formato ideal whisper)
5. Se `--mp3`: gera `.mp3` ao lado do original (128kbps)
6. Roda `whisper-cli` com Metal/GPU, `-nt -np` (sem timestamps, sem logs)
7. Limpa output (trim de espaços, remove linhas vazias)
8. Stdout = transcrição limpa. Stderr = logs.

## Exemplo end-to-end

**Usuário:**
> "transcreve aí `/Users/eduardolecdt/Downloads/WhatsApp Audio 2026-05-11 at 10.33.30.opus`"

**Você (assistente):** chama o Bash:
```bash
bash ~/.claude/skills/transcribe-audio/scripts/transcribe.sh "/Users/eduardolecdt/Downloads/WhatsApp Audio 2026-05-11 at 10.33.30.opus"
```

**Você responde ao usuário:**

> Mano, eu subi um PR lá na madrugada, tá. [...]

(Apenas o texto da transcrição, em blockquote. Sem mais nada.)

## Formatos suportados

Qualquer formato que o `ffmpeg` consiga decodificar:
- WhatsApp: `.opus`
- iPhone: `.m4a`, `.caf`
- Android: `.ogg`, `.amr`, `.3gp`
- Universal: `.mp3`, `.wav`, `.aac`, `.flac`
- Vídeo (extrai áudio): `.mp4`, `.mov`, `.mkv`, `.webm`

## Erros comuns

| Erro | Causa | Fix |
|---|---|---|
| `arquivo não encontrado` | path errado ou escape de espaço | Aspas no path |
| `falha na conversão com ffmpeg` | formato corrompido | Verificar com `ffprobe` |
| `falha ao baixar modelo` | sem internet ou HuggingFace fora | Tentar de novo, ou usar modelo menor |
| Transcrição vazia | áudio mudo ou idioma errado | Tentar `--lang auto` |
| Transcrição com erros de palavras | modelo muito pequeno | Subir para `--model large` |
