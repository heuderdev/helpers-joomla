---
name: youtube-live
description: Cria transmissões ao vivo (lives) no canal do YouTube do Eduardo via YouTube Data API v3. A skill PERGUNTA título, descrição, data/hora, privacidade e capa; então cria a live agendada, gera a chave RTMP pro OBS/Streamlabs, sobe a thumbnail e devolve o link + os dados pro encoder. Use SEMPRE que o Eduardo pedir "cria uma live", "agenda uma live no youtube", "programa uma transmissão", "monta a live de X", "sobe uma live", ou enviar título/descrição/capa pedindo pra montar a transmissão. NÃO confundir com youtube-thumbnail (essa só cria a capa) — mas ESTA pode chamar aquela pra gerar a capa.
---

# YouTube Live (transmissões do Eduardo)

Cria uma **live completa** no canal do Eduardo: broadcast agendado + stream RTMP +
bind + thumbnail. O trabalho pesado é feito por scripts Node; esta skill orquestra
a conversa e chama os scripts.

## Caminhos importantes

- Diretório da skill: `~/.claude/skills/youtube-live/`
- Script de auth: `scripts/auth.js` (roda 1x, salva o refresh_token)
- Script de criar live: `scripts/create-live.js`
- Credencial OAuth: `secrets/client_secret.json` (Desktop app, projeto `eduardo-sites`)
- Token salvo: `secrets/token.json` (gerado no primeiro auth)
- Skill de capa (reaproveitar): `youtube-thumbnail`

Nunca commitar `secrets/` nem `token.json` (já há `.gitignore`).

## Fluxo quando o Eduardo chamar a skill

### 1. Coletar as infos (PERGUNTAR sempre, uma a uma se preciso)

Colete, usando a ferramenta de perguntas quando fizer sentido:

- **Título** (obrigatório)
- **Descrição** (opcional — se não tiver, pode deixar vazia ou sugerir uma)
- **Data e hora de início** (obrigatório). Converter pro fuso do Brasil
  (`-03:00`). Formato final ISO: `2026-07-20T20:00:00-03:00`.
  Se ele disser "agora"/"já", use o horário atual + 2 min.
- **Privacidade** — SEMPRE perguntar: `public` | `unlisted` | `private`.
- **Capa (thumbnail)** — perguntar qual dos dois:
  - **(a)** Ele já tem o arquivo → pedir o caminho da imagem (ideal 1280x720, < 2MB).
  - **(b)** Gerar agora → invocar a skill `youtube-thumbnail` com o briefing dele,
    pegar o PNG final gerado em `~/Downloads/` e usar como `--thumbnail`.
  - Se ele não quiser capa agora, seguir sem — dá pra subir depois.

### 2. Garantir autenticação

Antes de criar, verificar se existe `secrets/token.json`.

- Se **NÃO existir** (ou o script retornar `"error":"SEM_TOKEN"`): avisar o Eduardo
  que ele precisa autorizar **uma vez**, e rodar:
  ```bash
  cd ~/.claude/skills/youtube-live && node scripts/auth.js
  ```
  Isso abre o navegador. Ele loga com a conta **do canal** e autoriza. O terminal
  mostra "Token salvo". (Se aparecer tela de "app não verificado", é normal —
  ele clica em "Avançado" → "Acessar (não seguro)", porque o app é dele mesmo.)
- Se já existir, seguir direto.

### 3. Criar a live

```bash
cd ~/.claude/skills/youtube-live && node scripts/create-live.js \
  --title "TÍTULO" \
  --description "DESCRIÇÃO" \
  --start "2026-07-20T20:00:00-03:00" \
  --privacy public \
  --thumbnail "/Users/eduardolecdt/Downloads/capa.png"
```

O script imprime um JSON. Campos que importam pro Eduardo:

- `watchUrl` — link da live (pra ele/divulgação)
- `studioUrl` — painel de controle no YouTube Studio
- `rtmpUrl` + `streamKey` — o que ele cola no **OBS** (Configurações → Transmissão →
  Serviço "Personalizado", Servidor = `rtmpUrl`, Chave = `streamKey`)
- `backupRtmpUrl` — servidor de backup (opcional)

### 4. Entregar pro Eduardo (formato da resposta)

Mostrar de forma limpa:

```
✅ Live criada e agendada!

📺 Título: ...
🕐 Início: <data/hora BR>  ·  🔒 <privacidade>
🔗 Link da live: <watchUrl>
🎛️  Painel (Studio): <studioUrl>

📡 Pro OBS (Configurações → Transmissão → Serviço: Personalizado):
   Servidor: <rtmpUrl>
   Chave:    <streamKey>

A live começa sozinha assim que o OBS conectar (enableAutoStart).
```

⚠️ **A `streamKey` é sensível** (quem tiver ela transmite no canal dele). Mostrar no
terminal local tá ok, mas avisar pra ele não postar em print público.

## Notas técnicas

- `enableAutoStart: true` — não precisa apertar "iniciar" no Studio; conectou o
  encoder, a live entra no ar. `enableAutoStop: true` encerra ao parar o encoder.
- O stream é `isReusable: true` — a mesma chave serve pra próximas lives se quiser.
- Escopo OAuth: `youtube.force-ssl` (criar lives + subir thumbnail).
- Quota: criar live consome cota da API (padrão 10k/dia é de sobra pra uso normal).
- Se der erro `liveStreamingNotEnabled`: o canal precisa ter transmissão ao vivo
  ativada uma vez em youtube.com/features (verificação por telefone, 24h na 1ª vez).

## Erros comuns

- `SEM_TOKEN` → rodar `node scripts/auth.js`.
- `invalid_grant` no create → token expirou/revogado → rodar auth de novo.
- Thumbnail retorna erro mas a live é criada → seguir; subir a capa depois pelo Studio.
- `--start` no passado → o YouTube recusa; garantir horário futuro.
