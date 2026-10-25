#!/usr/bin/env node
/**
 * record.mjs — grava um vídeo de scroll suave de um site, do topo até o fim.
 *
 * Estratégia (mais nítida que page.screencast em headless):
 *  - mede a altura total da página
 *  - scrolla em passos pequenos a VELOCIDADE CONSTANTE (px/frame fixos)
 *  - captura 1 screenshot por frame
 *  - ffmpeg monta os frames em MP4 H.264 (yuv420p, compatível em tudo)
 *  - a DURAÇÃO é consequência do tamanho do site (mais alto = vídeo mais longo)
 *
 * Uso:
 *   node record.mjs <url> <out.mp4> [desktop|mobile] [pxPerFrame] [fps]
 * Defaults: desktop, 12 px/frame, 60 fps  (~ scroll calmo)
 */
import { existsSync, mkdirSync, rmSync, readdirSync } from 'fs'
import { join, dirname } from 'path'
import { homedir, tmpdir } from 'os'
import { pathToFileURL } from 'url'
import { spawnSync } from 'child_process'

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
const url   = process.argv[2]
const out   = process.argv[3]
const mode  = process.argv[4] || 'desktop'
const PX    = Number(process.argv[5] || 12)   // px por frame (velocidade)
const FPS   = Number(process.argv[6] || 60)
if (!url || !out) { console.error('uso: node record.mjs <url> <out.mp4> [desktop|mobile] [pxPerFrame] [fps]'); process.exit(1) }

// desktop = proporção MacBook Pro 16" em pontos (1728x1117). DPR 1 = render rápido.
// (pra Retina cheia 3456x2234, troque DPR desktop p/ 2)
const VW  = mode === 'mobile' ? 393  : 1728
const VH  = mode === 'mobile' ? 852  : 1117
const DPR = mode === 'mobile' ? 3    : 1
// dimensões finais de saída (pixels reais) — H.264 exige PARES, então arredonda
const even = n => (n % 2 === 0 ? n : n - 1)
const OW = even(VW * DPR)
const OH = even(VH * DPR)

// ── acha puppeteer-core em ~/Empresas (igual shot.mjs) ──────────────────────
function findPuppeteer() {
  const hits = []
  const walk = (dir, d) => {
    if (d > 9) return
    let es = []; try { es = readdirSync(dir, { withFileTypes: true }) } catch { return }
    for (const e of es) {
      if (!e.isDirectory() || e.name === '.git') continue
      const full = join(dir, e.name)
      if (e.name === 'puppeteer-core') {
        const esm = join(full, 'lib/esm/puppeteer/puppeteer-core.js')
        if (existsSync(esm)) hits.push(esm)
      }
      walk(full, d + 1)
    }
  }
  walk(join(homedir(), 'Empresas'), 0)
  hits.sort().reverse()
  return hits[0]
}
const pp = findPuppeteer()
if (!pp) { console.error('puppeteer-core não encontrado'); process.exit(2) }
const puppeteer = (await import(pathToFileURL(pp).href)).default

const framesDir = join(tmpdir(), 'rec-frames-' + Math.abs(hashStr(out)))
if (existsSync(framesDir)) rmSync(framesDir, { recursive: true, force: true })
mkdirSync(framesDir, { recursive: true })
if (!existsSync(dirname(out))) mkdirSync(dirname(out), { recursive: true })

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--no-sandbox', '--hide-scrollbars', '--disable-gpu', `--force-device-scale-factor=${DPR}`]
})
const page = await browser.newPage()
if (mode === 'mobile') {
  await page.setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1')
  await page.setViewport({ width: VW, height: VH, deviceScaleFactor: DPR, isMobile: true, hasTouch: true })
} else {
  await page.setViewport({ width: VW, height: VH, deviceScaleFactor: DPR })
}
await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 })
try { await page.evaluate(() => document.fonts && document.fonts.ready) } catch {}
await new Promise(r => setTimeout(r, 3500)) // deixa a SPA assentar

// some com elementos fixos que "viajam" e atrapalham (chat/whatsapp/cookie/acessibilidade)
await page.addStyleTag({ content: `
  [class*="whatsapp" i],[id*="whatsapp" i],[class*="cookie" i],[id*="cookie" i],
  [class*="chat" i],[id*="chat" i],[class*="widget" i],[class*="float" i],
  [aria-label*="WhatsApp" i],[aria-label*="chat" i],
  a[href*="wa.me"],a[href*="api.whatsapp"],a[href*="whatsapp.com"]{ display:none !important; }
` }).catch(()=>{})
// fallback JS: esconde qualquer elemento position:fixed pequeno colado nos cantos inferiores
await page.evaluate(() => {
  const vw = innerWidth, vh = innerHeight
  for (const el of document.querySelectorAll('body *')) {
    const s = getComputedStyle(el)
    if (s.position !== 'fixed') continue
    const r = el.getBoundingClientRect()
    const small = r.width < 160 && r.height < 160
    const bottom = r.bottom > vh - 180
    const side = r.left > vw - 220 || r.right < 220
    if (small && bottom && side) el.style.display = 'none'
  }
}).catch(()=>{})

const total = await page.evaluate(() => Math.max(
  document.body.scrollHeight, document.documentElement.scrollHeight,
  document.body.offsetHeight, document.documentElement.offsetHeight))
const maxScroll = Math.max(0, total - VH)

let y = 0, i = 0
// 1s parado no topo
for (let h = 0; h < FPS; h++) { await shot(); }
while (y < maxScroll) {
  y = Math.min(maxScroll, y + PX)
  await page.evaluate(sy => window.scrollTo(0, sy), y)
  await shot()
}
// 1s parado no fim
for (let h = 0; h < FPS; h++) { await shot(); }

await browser.close()

// ── monta o MP4 com ffmpeg ──────────────────────────────────────────────────
const ff = spawnSync('ffmpeg', [
  '-y', '-framerate', String(FPS),
  '-i', join(framesDir, 'f%06d.png'),
  '-vf', `scale=${OW}:${OH}:flags=lanczos,format=yuv420p`,
  '-c:v', 'libx264', '-preset', 'slow', '-crf', '18', '-movflags', '+faststart',
  out
], { stdio: 'inherit' })
rmSync(framesDir, { recursive: true, force: true })
if (ff.status !== 0) { console.error('ffmpeg falhou'); process.exit(3) }
console.log('OK video:', out, `(${i} frames, ~${(i/FPS).toFixed(1)}s)`)

async function shot() {
  await page.screenshot({ path: join(framesDir, `f${String(i).padStart(6,'0')}.png`) })
  i++
}
function hashStr(s){let h=0;for(let k=0;k<s.length;k++){h=(h*31+s.charCodeAt(k))|0}return h}
