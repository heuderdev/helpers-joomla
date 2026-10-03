#!/usr/bin/env node
// send-discord.mjs — posta um relatório (markdown) no webhook do Discord.
// Uso: node send-discord.mjs <arquivo.md> ["Título opcional"]
// Lê o webhook de REVISOR_SAAS_DISCORD_WEBHOOK (env) ou do .webhook ao lado do script.
import { readFileSync, existsSync } from 'fs'
import { dirname, join } from 'path'
import { fileURLToPath } from 'url'

const __dir = dirname(fileURLToPath(import.meta.url))
const file = process.argv[2]
const title = process.argv[3] || ''
if (!file) { console.error('uso: node send-discord.mjs <arquivo.md> ["título"]'); process.exit(1) }

let webhook = process.env.REVISOR_SAAS_DISCORD_WEBHOOK
if (!webhook) {
  const wf = join(__dir, '.webhook')
  if (existsSync(wf)) webhook = readFileSync(wf, 'utf8').trim()
}
if (!webhook) { console.error('webhook não configurado (.webhook ou REVISOR_SAAS_DISCORD_WEBHOOK)'); process.exit(2) }

const md = readFileSync(file, 'utf8')

// Discord: 2000 chars por mensagem. Quebra preservando linhas; evita cortar no meio.
const LIMIT = 1900
function chunk(text) {
  const out = []
  let buf = ''
  for (const line of text.split('\n')) {
    // linha sozinha maior que o limite: quebra dura
    if (line.length > LIMIT) {
      if (buf) { out.push(buf); buf = '' }
      for (let i = 0; i < line.length; i += LIMIT) out.push(line.slice(i, i + LIMIT))
      continue
    }
    if ((buf + '\n' + line).length > LIMIT) { out.push(buf); buf = line }
    else buf = buf ? buf + '\n' + line : line
  }
  if (buf) out.push(buf)
  return out
}

const parts = chunk(title ? `${title}\n\n${md}` : md)
let n = 0
for (const content of parts) {
  n++
  const res = await fetch(webhook + '?wait=true', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ content, allowed_mentions: { parse: [] } }),
  })
  if (!res.ok) {
    const body = await res.text().catch(() => '')
    console.error(`falha na parte ${n}/${parts.length}: HTTP ${res.status} ${body}`)
    process.exit(3)
  }
  // respeita rate limit básico do Discord entre mensagens
  if (n < parts.length) await new Promise(r => setTimeout(r, 700))
}
console.log(`OK: ${parts.length} mensagem(ns) enviada(s) ao Discord`)
