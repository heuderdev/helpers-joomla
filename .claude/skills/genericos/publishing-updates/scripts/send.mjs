#!/usr/bin/env node
// send.mjs — uploads images and POSTs the final update.
//
// Commands:
//   --upload --config <project.json> --file <path>            → { url }
//   --validate-url --url <url>                                → { ok, status }
//   --update --config <project.json> --payload <path-to-json> → { status, body }
//   --discord (--webhook <url> | --webhook-env <VAR> | --config <project.json>) --payload <path-to-json> → { status, ok }
//   --get-token --config <project.json>                       → { ok, tokenPreview }
//   --clear-token-cache [--key <slug>]                        → { ok, cleared }
//
// Auth kinds (auth.kind in project config):
//   - "static"     → token from process.env[authConfig.tokenEnv]
//   - "bearer"     → same, but Authorization: Bearer <token>
//   - "local-sanctum" → (Laravel, recomendado) mint a short-lived Sanctum personal access token
//                    by running `php artisan tinker` inside the local Laravel admin app named in
//                    .secrets.json (appDir + email + model + abilities). No password, no HTTP login.
//                    Sent as Authorization: Bearer <token>.
//   - "local-jwt"  → (legado, APIs Node/Express) sign a JWT locally using a secret + payload from a
//                    backend .env file. Resolves idAdmin from Mongo via `email` when needed.
//
// Token preview is the only token info ever printed. Full JWTs never leak to stdout/stderr.

import { readFileSync, writeFileSync, existsSync, chmodSync } from 'node:fs'
import { basename, dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { execFileSync } from 'node:child_process'

const SKILL_DIR = dirname(dirname(fileURLToPath(import.meta.url)))
const SECRETS_PATH = join(SKILL_DIR, '.secrets.json')
const TOKEN_CACHE_PATH = join(SKILL_DIR, '.token-cache.json')

// ─── tiny utilities ───────────────────────────────────────────────────────────

function parseArgs (argv) {
  const args = {}
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i]
    if (a.startsWith('--')) {
      const key = a.slice(2)
      const next = argv[i + 1]
      if (!next || next.startsWith('--')) { args[key] = true } else { args[key] = next; i++ }
    }
  }
  return args
}

function readSecrets () {
  if (!existsSync(SECRETS_PATH)) return {}
  try { return JSON.parse(readFileSync(SECRETS_PATH, 'utf8')) } catch { return {} }
}

function readTokenCache () {
  if (!existsSync(TOKEN_CACHE_PATH)) return {}
  try { return JSON.parse(readFileSync(TOKEN_CACHE_PATH, 'utf8')) } catch { return {} }
}

function writeTokenCache (cache) {
  writeFileSync(TOKEN_CACHE_PATH, JSON.stringify(cache, null, 2))
  try { chmodSync(TOKEN_CACHE_PATH, 0o600) } catch {}
}

// Parses a dotenv-style file into a plain object (no external dep).
function parseDotenv (path) {
  if (!existsSync(path)) throw new Error(`.env not found at ${path}`)
  const out = {}
  const raw = readFileSync(path, 'utf8')
  for (const line of raw.split(/\r?\n/)) {
    const m = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/i)
    if (!m) continue
    let v = m[2]
    if ((v.startsWith('"') && v.endsWith('"')) || (v.startsWith("'") && v.endsWith("'"))) {
      v = v.slice(1, -1)
    }
    out[m[1]] = v
  }
  return out
}

// Resolves a dep from one of the project's backend node_modules trees (or npx fallback).
// We piggy-back on UnicPages backends which already have jsonwebtoken + mongoose installed.
async function resolveDepFrom (candidateDirs, pkg) {
  const { createRequire } = await import('node:module')
  for (const dir of candidateDirs) {
    try {
      const req = createRequire(`${dir}/`)
      return req(pkg)
    } catch {}
  }
  // last resort: global
  try {
    const { createRequire } = await import('node:module')
    return createRequire(import.meta.url)(pkg)
  } catch {
    throw new Error(`Could not resolve '${pkg}' from any of: ${candidateDirs.join(', ')}`)
  }
}

// ─── local-sanctum auth (Laravel) ─────────────────────────────────────────────
//
// Project auth config:
//   { kind: "local-sanctum", secretsKey: "meu-projeto", cacheTtlSeconds: 86400 }
// .secrets.json entry:
//   "meu-projeto": {
//     "appDir": "/abs/path/to/frontend/admin",   // Laravel app root (has artisan + vendor/)
//     "email": "you@example.com",                // who owns the token
//     "model": "App\\Models\\Administrador",       // optional, defaults to App\\Models\\User (must use HasApiTokens)
//     "abilities": ["updates:publicar", "storage:upload"] // optional, defaults to ["*"]
//   }
//
// Behavior: runs `php artisan tinker --execute` in appDir, creating a token with expiresAt = now + ttl.
// Values are passed via environment variables (never interpolated into PHP code). Token cached with TTL.
function getOrMintSanctumToken (authConfig) {
  const secrets = readSecrets()
  const creds = secrets[authConfig.secretsKey]
  if (!creds) throw new Error(`No entry for secretsKey='${authConfig.secretsKey}' in .secrets.json`)
  if (!creds.appDir) throw new Error(`Missing 'appDir' for '${authConfig.secretsKey}' in .secrets.json`)
  if (!creds.email) throw new Error(`Missing 'email' for '${authConfig.secretsKey}' in .secrets.json`)
  if (!existsSync(join(creds.appDir, 'artisan'))) throw new Error(`artisan not found in ${creds.appDir}`)

  const cache = readTokenCache()
  const cacheKey = authConfig.secretsKey
  const now = Math.floor(Date.now() / 1000)
  const entry = cache[cacheKey]
  if (entry && entry.token && entry.expiresAt > now && entry.appDir === creds.appDir) {
    return entry.token
  }

  const ttl = authConfig.cacheTtlSeconds || 86400
  const php = [
    '$model = getenv("PU_MODEL");',
    '$u = $model::where("email", getenv("PU_EMAIL"))->first();',
    'if (! $u) { fwrite(STDERR, "not-found"); exit(1); }',
    'echo "TOKEN:" . $u->createToken("publishing-updates", json_decode(getenv("PU_ABILITIES"), true), now()->addSeconds((int) getenv("PU_TTL")))->plainTextToken;'
  ].join(' ')

  let out
  try {
    out = execFileSync('php', ['artisan', 'tinker', '--execute', php], {
      cwd: creds.appDir,
      env: {
        ...process.env,
        PU_MODEL: creds.model || 'App\\Models\\User',
        PU_EMAIL: creds.email,
        PU_ABILITIES: JSON.stringify(creds.abilities || ['*']),
        PU_TTL: String(ttl)
      },
      stdio: ['ignore', 'pipe', 'pipe']
    }).toString()
  } catch (e) {
    const stderr = e.stderr?.toString() || ''
    if (stderr.includes('not-found')) throw new Error(`No user with email='${creds.email}' in ${creds.model || 'App\\Models\\User'}`)
    throw new Error(`php artisan tinker failed in ${creds.appDir} (is laravel/sanctum installed and the model using HasApiTokens?)`)
  }

  const m = out.match(/TOKEN:(\S+)/)
  if (!m) throw new Error('Sanctum token not found in tinker output')
  const token = m[1]

  cache[cacheKey] = { token, appDir: creds.appDir, expiresAt: now + ttl - 60 }
  writeTokenCache(cache)
  return token
}

// ─── local-jwt auth (legado — APIs Node/Express) ──────────────────────────────
//
// Given a project auth config like:
//   { kind: "local-jwt", secretsKey: "unicpages", cacheTtlSeconds: 86400 }
// and a .secrets.json entry:
//   "unicpages": {
//     "envPath": "/abs/path/to/api-admin/.env",
//     "secretEnvVar": "TOKEN_ADMIN",        // the env var inside that .env that holds the JWT secret
//     "mongoEnvVar": "MONGO_STRING",        // optional, needed to auto-resolve idAdmin
//     "collection": "admins",               // optional, defaults to "admins"
//     "email": "you@example.com",           // used to find idAdmin if not cached
//     "payloadKey": "idAdmin",              // JWT payload key name (defaults to "idAdmin")
//     "nodeModulesHints": ["/abs/path/to/api-admin"] // dirs to resolve jsonwebtoken/mongoose from
//   }
//
// Behavior:
//   1. Parse .env to get TOKEN_ADMIN + MONGO_STRING (never printed).
//   2. Find or reuse cached idAdmin (cached in .token-cache.json under secretsKey → idAdmin).
//   3. Sign JWT locally with { data: { [payloadKey]: idAdmin } } and secret from .env.
//   4. Cache the JWT with TTL.
async function getOrSignLocalJwt (authConfig) {
  const secrets = readSecrets()
  const creds = secrets[authConfig.secretsKey]
  if (!creds) throw new Error(`No entry for secretsKey='${authConfig.secretsKey}' in .secrets.json`)
  if (!creds.envPath) throw new Error(`Missing 'envPath' for '${authConfig.secretsKey}' in .secrets.json`)
  if (!creds.secretEnvVar) throw new Error(`Missing 'secretEnvVar' for '${authConfig.secretsKey}'`)

  const cache = readTokenCache()
  const cacheKey = authConfig.secretsKey
  const now = Math.floor(Date.now() / 1000)
  const entry = cache[cacheKey]
  if (entry && entry.token && entry.expiresAt > now && entry.envPath === creds.envPath) {
    return entry.token
  }

  const env = parseDotenv(creds.envPath)
  const secret = env[creds.secretEnvVar]
  if (!secret) throw new Error(`'${creds.secretEnvVar}' not found in ${creds.envPath}`)

  const hints = [
    ...(creds.nodeModulesHints || []),
    dirname(creds.envPath)
  ]

  // Resolve idAdmin: from cache, from creds.payloadValue, or via Mongo lookup by email.
  let payloadValue = entry?.payloadValue || creds.payloadValue
  if (!payloadValue) {
    if (!creds.email) throw new Error(`Cannot resolve payload value: provide 'payloadValue' or 'email' in .secrets.json['${authConfig.secretsKey}']`)
    if (!creds.mongoEnvVar) throw new Error(`Cannot resolve payload value from email without 'mongoEnvVar' in .secrets.json['${authConfig.secretsKey}']`)
    const mongoUri = env[creds.mongoEnvVar]
    if (!mongoUri) throw new Error(`'${creds.mongoEnvVar}' not found in ${creds.envPath}`)

    const mongoose = await resolveDepFrom(hints, 'mongoose')
    await mongoose.connect(mongoUri)
    try {
      const col = mongoose.connection.db.collection(creds.collection || 'admins')
      const doc = await col.findOne({ email: creds.email }, { projection: { _id: 1 } })
      if (!doc) throw new Error(`No admin found with email='${creds.email}' in collection '${creds.collection || 'admins'}'`)
      payloadValue = doc._id.toString()
    } finally {
      await mongoose.disconnect()
    }
  }

  const jwt = await resolveDepFrom(hints, 'jsonwebtoken')
  const payloadKey = creds.payloadKey || 'idAdmin'
  const ttl = authConfig.cacheTtlSeconds || 86400
  const token = jwt.sign({ data: { [payloadKey]: payloadValue } }, secret, { expiresIn: `${ttl}s` })

  cache[cacheKey] = { token, payloadValue, envPath: creds.envPath, expiresAt: now + ttl - 60 }
  writeTokenCache(cache)
  return token
}

async function buildAuthHeaders (authConfig) {
  if (!authConfig) return {}
  const { kind = 'static', header } = authConfig
  let tokenValue

  if (kind === 'local-sanctum') {
    return { Authorization: `Bearer ${getOrMintSanctumToken(authConfig)}` }
  } else if (kind === 'local-jwt') {
    tokenValue = await getOrSignLocalJwt(authConfig)
  } else {
    tokenValue = process.env[authConfig.tokenEnv]
    if (!tokenValue) throw new Error(`Missing env var: ${authConfig.tokenEnv}`)
  }

  if (kind === 'bearer') return { Authorization: `Bearer ${tokenValue}` }
  return { [header || 'x-access-token']: tokenValue }
}

// ─── commands ─────────────────────────────────────────────────────────────────

async function cmdValidateUrl (args) {
  const res = await fetch(args.url, { method: 'HEAD' })
  return { ok: res.ok, status: res.status }
}

async function cmdUpload (args) {
  const config = JSON.parse(readFileSync(args.config, 'utf8'))
  const fileBuffer = readFileSync(args.file)
  const fileName = basename(args.file)

  const form = new FormData()
  const blob = new Blob([fileBuffer])
  form.append('file', blob, fileName)
  if (config.uploadMaxWidth) form.append('maxWidth', String(config.uploadMaxWidth))

  const headers = await buildAuthHeaders(config.uploadAuth || config.auth)

  const res = await fetch(config.uploadEndpoint, { method: 'POST', headers, body: form })
  const text = await res.text()
  if (!res.ok) throw new Error(`Upload failed (${res.status}): ${text.slice(0, 500)}`)

  let body
  try { body = JSON.parse(text) } catch { body = { raw: text } }

  const url = body.body?.url || body.url || body.data?.url
  if (!url) throw new Error(`Upload OK but no url in response. Body: ${text.slice(0, 500)}`)
  return { url, raw: body }
}

async function cmdUpdate (args) {
  const config = JSON.parse(readFileSync(args.config, 'utf8'))
  const payload = JSON.parse(readFileSync(args.payload, 'utf8'))
  const headers = {
    'Content-Type': 'application/json',
    ...(await buildAuthHeaders(config.auth))
  }
  const method = config.updateMethod || 'POST'
  const res = await fetch(config.updateEndpoint, { method, headers, body: JSON.stringify(payload) })
  const text = await res.text()
  let body
  try { body = JSON.parse(text) } catch { body = { raw: text } }
  return { status: res.status, ok: res.ok, body }
}

// Webhook resolution order: --webhook <url> → --webhook-env <VAR> → project.discordWebhookEnv (via --config).
// Webhook URLs are secrets (anyone with the URL can post): keep them in env vars, never in project JSON.
function resolveWebhook (args) {
  if (typeof args.webhook === 'string') return args.webhook
  let envName = typeof args['webhook-env'] === 'string' ? args['webhook-env'] : null
  if (!envName && typeof args.config === 'string') {
    envName = JSON.parse(readFileSync(args.config, 'utf8')).discordWebhookEnv || null
  }
  if (!envName) throw new Error('No webhook: pass --webhook, --webhook-env or --config with discordWebhookEnv')
  const url = process.env[envName]
  if (!url) throw new Error(`Missing env var: ${envName}`)
  return url
}

async function cmdDiscord (args) {
  const payload = JSON.parse(readFileSync(args.payload, 'utf8'))
  const res = await fetch(resolveWebhook(args), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  })
  return { status: res.status, ok: res.ok }
}

async function cmdGetToken (args) {
  const config = JSON.parse(readFileSync(args.config, 'utf8'))
  const headers = await buildAuthHeaders(config.auth)
  const headerName = config.auth.header || 'x-access-token'
  const token = headers.Authorization?.replace(/^Bearer\s+/, '') || headers[headerName]
  if (!token) throw new Error('Token could not be obtained')
  return { ok: true, tokenPreview: token.slice(0, 12) + '...' + token.slice(-6) }
}

function cmdClearTokenCache (args) {
  const cache = readTokenCache()
  if (args.key) delete cache[args.key]
  else Object.keys(cache).forEach(k => delete cache[k])
  writeTokenCache(cache)
  return { ok: true, cleared: args.key || 'all' }
}

// ─── main ─────────────────────────────────────────────────────────────────────

async function main () {
  const args = parseArgs(process.argv)
  try {
    let result
    if (args.upload) result = await cmdUpload(args)
    else if (args['validate-url']) result = await cmdValidateUrl(args)
    else if (args.update) result = await cmdUpdate(args)
    else if (args.discord) result = await cmdDiscord(args)
    else if (args['get-token']) result = await cmdGetToken(args)
    else if (args['clear-token-cache']) result = cmdClearTokenCache(args)
    else {
      console.error('No command given. Use --upload, --validate-url, --update, --discord, --get-token or --clear-token-cache.')
      process.exit(1)
    }
    process.stdout.write(JSON.stringify(result, null, 2))
  } catch (err) {
    console.error(JSON.stringify({ error: err.message }))
    process.exit(1)
  }
}

main()
