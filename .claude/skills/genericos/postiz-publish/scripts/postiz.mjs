#!/usr/bin/env node
/**
 * postiz.mjs — CLI helper para a Postiz Public API.
 *
 * Subcomandos:
 *   channels                          Lista os canais conectados (id, name, provider)
 *   upload <arquivo|url> [...mais]    Sobe mídia e imprime {id, path} de cada uma
 *   publish <payload.json>            Publica/agenda um post a partir de um payload pronto
 *   post                              Monta o payload e publica a partir de flags (ver abaixo)
 *
 * Modo `post` (o mais usado):
 *   --channel <id>            (repetível) id do canal alvo
 *   --type <now|schedule|draft>   default: now
 *   --date <ISO8601>          obrigatório quando --type schedule (UTC)
 *   --content <texto>         texto do post (use --content-file pra textos longos)
 *   --content-file <arquivo>  lê o texto de um arquivo
 *   --media <arquivo|url>     (repetível) mídia a subir e anexar
 *   --shortlink               encurta links (default: false)
 *   --dry-run                 monta e imprime o payload, NÃO publica
 *
 * Auth: usa POSTIZ_API_KEY do ambiente. Base: POSTIZ_API_URL (default cloud).
 */

const API_URL = (process.env.POSTIZ_API_URL || "https://api.postiz.com/public/v1").replace(/\/$/, "");
const API_KEY = process.env.POSTIZ_API_KEY;

function die(msg, code = 1) {
  console.error(`\x1b[31merro:\x1b[0m ${msg}`);
  process.exit(code);
}

if (!API_KEY) die("POSTIZ_API_KEY não definida no ambiente. Configure a chave (Settings > Developers > Public API).");

async function api(path, { method = "GET", body, headers = {}, isForm = false } = {}) {
  const res = await fetch(`${API_URL}${path}`, {
    method,
    headers: { Authorization: API_KEY, ...(isForm ? {} : body ? { "Content-Type": "application/json" } : {}), ...headers },
    body: isForm ? body : body ? JSON.stringify(body) : undefined,
  });
  const text = await res.text();
  let data;
  try { data = text ? JSON.parse(text) : null; } catch { data = text; }
  if (!res.ok) {
    const hint = { 401: "chave inválida/ausente", 403: "sem permissão sobre o recurso", 413: "arquivo acima de 50MB", 429: "rate limit — aguarde (100/h posts, 30/h integrations)" }[res.status];
    die(`HTTP ${res.status}${hint ? ` (${hint})` : ""} em ${method} ${path}\n${typeof data === "string" ? data : JSON.stringify(data, null, 2)}`);
  }
  return data;
}

// ---- upload: aceita path local ou URL ----
async function uploadOne(src) {
  if (/^https?:\/\//i.test(src)) {
    // upload-from-url
    return api("/upload", { method: "POST", body: { url: src } });
  }
  const { readFile } = await import("node:fs/promises");
  const { basename } = await import("node:path");
  let buf;
  try { buf = await readFile(src); } catch { die(`arquivo não encontrado: ${src}`); }
  const form = new FormData();
  form.append("file", new Blob([buf]), basename(src));
  return api("/upload", { method: "POST", body: form, isForm: true });
}

// defaults obrigatórios por provider — cobrem o post simples (texto+mídia) sem o
// usuário precisar montar payload à mão. Para settings avançadas, use `publish`.
function defaultSettings(type) {
  switch (type) {
    case "instagram":
    case "instagram-standalone":
      return { post_type: "post", collaborators: [] }; // feed carousel/foto
    case "x":
      return { who_can_reply_post: "everyone" };
    default:
      return {};
  }
}

function parseFlags(argv) {
  const out = { channel: [], media: [] };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    const next = () => argv[++i];
    switch (a) {
      case "--channel": out.channel.push(next()); break;
      case "--media": out.media.push(next()); break;
      case "--type": out.type = next(); break;
      case "--date": out.date = next(); break;
      case "--date-end": out.dateEnd = next(); break;
      case "--content": out.content = next(); break;
      case "--content-file": out.contentFile = next(); break;
      case "--shortlink": out.shortLink = true; break;
      case "--dry-run": out.dryRun = true; break;
      default: die(`flag desconhecida: ${a}`);
    }
  }
  return out;
}

async function cmdChannels() {
  const list = await api("/integrations");
  const rows = (Array.isArray(list) ? list : []).map((i) => ({
    id: i.id, name: i.name, provider: i.identifier || i.provider, profile: i.profile, disabled: !!i.disabled,
  }));
  console.log(JSON.stringify(rows, null, 2));
}

async function cmdUpload(args) {
  if (!args.length) die("uso: upload <arquivo|url> [...]");
  const results = [];
  for (const src of args) {
    const r = await uploadOne(src);
    results.push({ id: r.id, path: r.path, name: r.name });
  }
  console.log(JSON.stringify(results, null, 2));
}

async function cmdPublish(args) {
  const file = args[0];
  if (!file) die("uso: publish <payload.json>");
  const { readFile } = await import("node:fs/promises");
  const payload = JSON.parse(await readFile(file, "utf8"));
  const r = await api("/posts", { method: "POST", body: payload });
  console.log(JSON.stringify(r, null, 2));
}

async function cmdPost(args) {
  const f = parseFlags(args);
  if (!f.channel.length) die("informe ao menos um --channel <id>");
  f.type ||= "now";
  if (f.type === "schedule" && !f.date) die("--type schedule exige --date <ISO8601 UTC>");

  let content = f.content;
  if (f.contentFile) {
    const { readFile } = await import("node:fs/promises");
    content = await readFile(f.contentFile, "utf8");
  }
  if (content == null) die("informe --content ou --content-file");

  // sobe mídias (uma vez, reaproveitada em todos os canais)
  const image = [];
  for (const src of f.media) {
    const r = await uploadOne(src);
    image.push({ id: r.id, path: r.path });
  }

  // __type deriva do provider de cada canal — buscamos a lista pra mapear
  const integrations = await api("/integrations");
  const byId = Object.fromEntries((integrations || []).map((i) => [i.id, i]));

  const posts = f.channel.map((id) => {
    const intg = byId[id];
    if (!intg) die(`canal ${id} não encontrado na sua conta (rode: channels)`);
    const type = intg.identifier || intg.provider;
    return {
      integration: { id },
      value: [{ content, image }],
      settings: { __type: type, ...defaultSettings(type) },
    };
  });

  const payload = {
    type: f.type,
    date: f.date || new Date(Date.now()).toISOString?.() || "1970-01-01T00:00:00.000Z",
    shortLink: !!f.shortLink,
    tags: [],
    posts,
  };
  // new Date() sem args não está disponível em alguns ambientes; garanta uma data válida
  if (!f.date) {
    try { payload.date = new Date().toISOString(); } catch { /* mantém fallback */ }
  }

  if (f.dryRun) { console.log(JSON.stringify(payload, null, 2)); return; }
  const r = await api("/posts", { method: "POST", body: payload });
  console.log(JSON.stringify(r, null, 2));
}

// mostra o schema/settings exigido por um canal (evita adivinhar campos como post_type)
async function cmdSettings(args) {
  const id = args[0];
  if (!id) die("uso: settings <id_do_canal>");
  const r = await api(`/integration-settings/${id}`);
  console.log(JSON.stringify(r, null, 2));
}

// lista posts numa janela de datas (default: -7d a +30d). state: QUEUE|PUBLISHED|ERROR|DRAFT
async function cmdList(args) {
  const f = parseFlags(args);
  const now = Date.now();
  const start = f.date || new Date(now - 7 * 864e5).toISOString();
  const end = f.dateEnd || new Date(now + 30 * 864e5).toISOString();
  const r = await api(`/posts?startDate=${encodeURIComponent(start)}&endDate=${encodeURIComponent(end)}`);
  const arr = (r && r.posts) || r || [];
  const rows = (Array.isArray(arr) ? arr : []).map((p) => ({
    id: p.id, state: p.state, publishDate: p.publishDate,
    integration: p.integration?.name, releaseURL: p.releaseURL,
    content: (p.content || "").slice(0, 80),
  }));
  console.log(JSON.stringify(rows, null, 2));
}

// alterna um post entre draft e schedule (mantém a data). útil pra promover rascunho.
async function cmdStatus(args) {
  const [id, status] = args;
  if (!id || !["draft", "schedule"].includes(status)) die("uso: status <id_do_post> <draft|schedule>");
  const r = await api(`/posts/${id}/status`, { method: "PUT", body: { status } });
  console.log(JSON.stringify(r, null, 2));
}

// remove um post (e todos do mesmo grupo). Irreversível.
async function cmdDelete(args) {
  const id = args[0];
  if (!id) die("uso: delete <id_do_post>");
  const r = await api(`/posts/${id}`, { method: "DELETE" });
  console.log(JSON.stringify(r ?? { deleted: id }, null, 2));
}

const [cmd, ...rest] = process.argv.slice(2);
const table = {
  channels: cmdChannels, settings: cmdSettings, upload: cmdUpload,
  publish: cmdPublish, post: cmdPost, list: cmdList, status: cmdStatus, delete: cmdDelete,
};
if (!table[cmd]) die(`subcomando inválido: ${cmd || "(vazio)"}\nuse: channels | upload | publish | post`);
await table[cmd](rest);
