#!/usr/bin/env node
/**
 * shot-auth.mjs — loga num sistema e captura telas internas (alta resolução).
 *
 * Uso:
 *   node shot-auth.mjs <configJson>
 * onde configJson é o caminho de um JSON:
 *   {
 *     "loginUrl": "https://app.x.com/login",
 *     "email": "...", "password": "...",
 *     "outDir": "/abs/dir",
 *     "screens": [                      // telas internas a printar
 *       { "name": "dashboard", "url": "https://app.x.com/dashboard" },
 *       { "name": "clientes",  "url": "https://app.x.com/clientes" }
 *     ],
 *     "explore": true                   // se screens vazio: descobre links do menu
 *   }
 *
 * Captura em viewport MacBook 16" pontos (1728x1117) @2x = Retina nítida.
 * Mantém a sessão (mesma page/cookies) entre as telas.
 */
import { existsSync, mkdirSync, readFileSync, readdirSync } from 'fs'
import { join } from 'path'
import { homedir } from 'os'
import { pathToFileURL } from 'url'

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
const cfgPath = process.argv[2]
if (!cfgPath) { console.error('uso: node shot-auth.mjs <config.json>'); process.exit(1) }
const cfg = JSON.parse(readFileSync(cfgPath, 'utf8'))
const outDir = cfg.outDir
if (!existsSync(outDir)) mkdirSync(outDir, { recursive: true })

const VW = 1728, VH = 1117, DPR = 2

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

const browser = await puppeteer.launch({
  executablePath: CHROME, headless: true,
  args: ['--no-sandbox', '--hide-scrollbars', '--disable-gpu', `--force-device-scale-factor=${DPR}`]
})
const page = await browser.newPage()
await page.setViewport({ width: VW, height: VH, deviceScaleFactor: DPR })

const settle = async (ms = 3500) => {
  try { await page.evaluate(() => document.fonts && document.fonts.ready) } catch {}
  await new Promise(r => setTimeout(r, ms))
}

// ── LOGIN ───────────────────────────────────────────────────────────────────
console.log('→ abrindo login:', cfg.loginUrl)
await page.goto(cfg.loginUrl, { waitUntil: 'networkidle2', timeout: 60000 })
await settle()

// acha campo de email e senha de forma resiliente
const emailSel = await firstSel(page, [
  'input[type="email"]', 'input[name="email"]', 'input[id*="email" i]',
  'input[name="usuario"]', 'input[name="login"]', 'input[autocomplete="username"]',
  'input[placeholder*="mail" i]', 'input[type="text"]'
])
const passSel = await firstSel(page, [
  'input[type="password"]', 'input[name="password"]', 'input[name="senha"]',
  'input[autocomplete="current-password"]'
])
if (!emailSel || !passSel) {
  console.error('campos de login não encontrados (email:', emailSel, 'senha:', passSel, ')')
  await page.screenshot({ path: join(outDir, '_login-page.png') })
  await browser.close(); process.exit(3)
}
await page.type(emailSel, cfg.email, { delay: 20 })
await page.type(passSel, cfg.password, { delay: 20 })

// submit: botão "Entrar/Login" que NÃO seja Google/social; senão Enter no campo senha
const submitted = await page.evaluate(() => {
  const isSocial = el => /google|facebook|microsoft|apple|github/i.test(
    (el.textContent || '') + ' ' + (el.className || '') + ' ' + (el.getAttribute('href') || ''))
  const cands = [...document.querySelectorAll('button[type="submit"], input[type="submit"], button')]
  // 1) submit não-social
  let btn = cands.find(b => (b.type === 'submit' || b.tagName === 'INPUT') && !isSocial(b))
  // 2) botão com texto "entrar/acessar/login" não-social
  if (!btn) btn = cands.find(b => /entrar|acessar|^login|sign in/i.test(b.textContent) && !isSocial(b))
  if (btn) { btn.click(); return true }
  return false
})
if (!submitted) await page.keyboard.press('Enter')

// espera login: URL mudar OU campo de senha sumir (SPA loga sem navegação tradicional)
const loginUrl = page.url()
async function loggedIn() {
  if (page.url() !== loginUrl) return true
  const stillHasPass = await page.$('input[type="password"]')
  return !stillHasPass
}
let ok = false
for (let t = 0; t < 20; t++) {           // até ~20s
  await new Promise(r => setTimeout(r, 1000))
  if (await loggedIn()) { ok = true; break }
  // re-tenta o clique no "Entrar" a cada 5s (caso o 1º não tenha pegado)
  if (t === 5 || t === 10) {
    await page.evaluate(() => {
      const b = [...document.querySelectorAll('button,input[type="submit"]')]
        .find(x => /entrar|acessar|^login|sign in/i.test(x.textContent || x.value || '') &&
          !/google|facebook|apple|microsoft/i.test((x.textContent||'')+(x.className||'')))
      if (b) b.click()
    }).catch(()=>{})
    await page.keyboard.press('Enter').catch(()=>{})
  }
}
await settle(4000)
const afterLogin = page.url()
console.log('→ após login, url:', afterLogin, ok ? '(OK)' : '(NÃO confirmou login)')
await page.screenshot({ path: join(outDir, '00-pos-login.png') })

// ── DESCOBRIR TELAS (se não vieram no config) ───────────────────────────────
let screens = cfg.screens || []
if (screens.length === 0 && cfg.explore) {
  const base = new URL(afterLogin).origin
  const links = await page.evaluate((base) => {
    const seen = new Set()
    const out = []
    for (const a of document.querySelectorAll('a[href]')) {
      let href = a.getAttribute('href')
      if (!href || href.startsWith('#') || href.startsWith('javascript')) continue
      try { href = new URL(href, location.href).href } catch { continue }
      if (!href.startsWith(base)) continue
      if (seen.has(href)) continue
      seen.add(href)
      out.push({ name: (a.textContent || '').trim().slice(0, 30) || href, url: href })
    }
    return out
  }, base)
  screens = links.slice(0, 12)
  console.log('→ telas descobertas:', screens.length)
  screens.forEach(s => console.log('   -', s.name, s.url))
}

// ── CAPTURA CADA TELA ───────────────────────────────────────────────────────
let i = 1
for (const s of screens) {
  try {
    await page.goto(s.url, { waitUntil: 'networkidle2', timeout: 60000 })
    await settle(3500)
    const safe = String(s.name).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || `tela-${i}`
    const file = join(outDir, `${String(i).padStart(2,'0')}-${safe}.png`)
    await page.screenshot({ path: file })
    console.log('OK', file)
    i++
  } catch (e) { console.error('falhou', s.url, e.message) }
}

await browser.close()
console.log('DONE — telas capturadas:', i - 1)

async function firstSel(page, sels) {
  for (const sel of sels) {
    const el = await page.$(sel)
    if (el) return sel
  }
  return null
}
