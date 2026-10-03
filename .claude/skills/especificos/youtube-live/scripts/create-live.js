#!/usr/bin/env node
/**
 * create-live.js — cria uma transmissão ao vivo completa no YouTube.
 *
 * Faz, na ordem:
 *   1. liveBroadcasts.insert  -> cria o broadcast (o "evento" da live)
 *   2. liveStreams.insert     -> cria o stream (gera a chave RTMP)
 *   3. liveBroadcasts.bind    -> liga o broadcast ao stream
 *   4. thumbnails.set         -> (opcional) sobe a capa
 *
 * Flags:
 *   --title "..."            (obrigatório)
 *   --description "..."       (opcional)
 *   --start "2026-07-20T20:00:00-03:00"  (ISO 8601 com timezone; obrigatório)
 *   --privacy public|unlisted|private    (default: public)
 *   --thumbnail /caminho/capa.png        (opcional)
 *   --madeForKids                        (flag; default: NÃO é conteúdo infantil)
 *
 * Saída: JSON em stdout com { ok, watchUrl, studioUrl, rtmpUrl, streamKey, ... }
 */
const fs = require('fs');
const { getYoutube } = require('./lib');

function parseArgs(argv) {
  const args = { privacy: 'public', madeForKids: false };
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--title') args.title = argv[++i];
    else if (a === '--description') args.description = argv[++i];
    else if (a === '--start') args.start = argv[++i];
    else if (a === '--privacy') args.privacy = argv[++i];
    else if (a === '--thumbnail') args.thumbnail = argv[++i];
    else if (a === '--madeForKids') args.madeForKids = true;
  }
  return args;
}

function fail(msg, extra = {}) {
  console.log(JSON.stringify({ ok: false, error: msg, ...extra }));
  process.exit(1);
}

async function main() {
  const a = parseArgs(process.argv);

  if (!a.title) return fail('Falta --title');
  if (!a.start) return fail('Falta --start (ISO 8601, ex: 2026-07-20T20:00:00-03:00)');
  const startDate = new Date(a.start);
  if (isNaN(startDate.getTime())) return fail('--start inválido: ' + a.start);
  if (!['public', 'unlisted', 'private'].includes(a.privacy)) {
    return fail('--privacy deve ser public|unlisted|private');
  }
  if (a.thumbnail && !fs.existsSync(a.thumbnail)) {
    return fail('Thumbnail não encontrada: ' + a.thumbnail);
  }

  let yt;
  try {
    yt = getYoutube();
  } catch (e) {
    if (e.message === 'SEM_TOKEN') {
      return fail('SEM_TOKEN', { hint: 'Rode: node scripts/auth.js' });
    }
    throw e;
  }

  // 1) Broadcast (o evento da live)
  const broadcast = await yt.liveBroadcasts.insert({
    part: ['snippet', 'status', 'contentDetails'],
    requestBody: {
      snippet: {
        title: a.title,
        description: a.description || '',
        scheduledStartTime: startDate.toISOString(),
      },
      status: {
        privacyStatus: a.privacy,
        selfDeclaredMadeForKids: a.madeForKids,
      },
      contentDetails: {
        enableAutoStart: true,   // a live começa sozinha quando o encoder conectar
        enableAutoStop: true,
        latencyPreference: 'normal',
      },
    },
  });
  const broadcastId = broadcast.data.id;

  // 2) Stream (gera chave RTMP)
  const stream = await yt.liveStreams.insert({
    part: ['snippet', 'cdn', 'contentDetails'],
    requestBody: {
      snippet: { title: a.title + ' — stream' },
      cdn: {
        frameRate: 'variable',
        resolution: 'variable',
        ingestionType: 'rtmp',
      },
      contentDetails: { isReusable: true },
    },
  });
  const streamId = stream.data.id;
  const ingest = stream.data.cdn.ingestionInfo;

  // 3) Bind broadcast <-> stream
  await yt.liveBroadcasts.bind({
    id: broadcastId,
    part: ['id', 'contentDetails'],
    streamId: streamId,
  });

  // 4) Thumbnail (opcional)
  let thumbUploaded = false;
  if (a.thumbnail) {
    try {
      await yt.thumbnails.set({
        videoId: broadcastId,
        media: { body: fs.createReadStream(a.thumbnail) },
      });
      thumbUploaded = true;
    } catch (e) {
      // não aborta a live por causa da capa
      thumbUploaded = 'erro: ' + (e.errors?.[0]?.reason || e.message);
    }
  }

  console.log(JSON.stringify({
    ok: true,
    broadcastId,
    streamId,
    title: a.title,
    privacy: a.privacy,
    scheduledStart: startDate.toISOString(),
    watchUrl: `https://youtube.com/watch?v=${broadcastId}`,
    studioUrl: `https://studio.youtube.com/video/${broadcastId}/livestreaming`,
    rtmpUrl: ingest.ingestionAddress,
    backupRtmpUrl: ingest.backupIngestionAddress,
    streamKey: ingest.streamName,
    thumbnail: thumbUploaded,
  }, null, 2));
}

main().catch((e) => {
  const reason = e.errors?.[0]?.reason || e.message;
  fail(reason, { raw: e.message });
});
