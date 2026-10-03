#!/usr/bin/env node
/**
 * shot.mjs — captura prints FIÉIS de um site (desktop + mobile) para mockups.
 *
 * Lições aprendidas (não regredir):
 *  - Sites SPA (Nuxt/Inertia) e páginas Blade+Alpine com lazy-load carregam imagens e animações DEPOIS do load. Esperar networkidle
 *    + delay extra, senão fotos não carregam e o mockup sai com "bloco branco".
 *  - O `--window-size` do Chrome cru NÃO dispara as media queries mobile do site
 *    (título vaza/corta). É OBRIGATÓRIO emular device real via puppeteer
 *    setViewport({ isMobile: true, hasTouch: true, deviceScaleFactor: 3 }).
 *
 * Uso:
 *   node shot.mjs <url> <outDir>
 * Gera: <outDir>/desktop.png e <outDir>/mobile.png
 */
import { existsSync, mkdirSync, readdirSync } from 'fs'
import { join } from 'path'
import { homedir } from 'os'
import { pathToFileURL } from 'url'

const CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
const url = process.argv[2]
const outDir = process.argv[3] || join(homedir(), 'Desktop', 'mockups-out')
if (!url) { console.error('uso: node shot.mjs <url> <outDir>'); process.exit(1) }
if (!existsSync(outDir)) mkdirSync(outDir, { recursive: true })

// ── descobre o puppeteer-core em qualquer projeto do usuário ────────────────
function findPuppeteer() {
  const roots = [join(homedir(), 'Empresas')]
  const hits = []
  function walk(dir, depth) {
    if (depth > 9) return
    let entries = []
    try { entries = readdirSync(dir, { withFileTypes: true }) } catch { return }
    for (const e of entries) {
      if (!e.isDirectory()) continue
      if (e.name === '.git') continue
      const full = join(dir, e.name)
      if (e.name === 'puppeteer-core' && existsSync(join(full, 'package.json'))) {
        const esm = join(full, 'lib/esm/puppeteer/puppeteer-core.js')
        if (existsSync(esm)) hits.push(esm)
        else if (existsSync(join(full, 'cjs.js'))) hits.push(join(full, 'cjs.js'))
      }
      walk(full, depth + 1)
    }
  }
  for (const r of roots) walk(r, 0)
  // prefere a maior versão (caminho com @24... etc) — ordena por string desc
  hits.sort().reverse()
  return hits[0]
}

const ppPath = findPuppeteer()
if (!ppPath) {
  console.error('puppeteer-core não encontrado em ~/Empresas. Instale em algum projeto ou ajuste o script.')
  process.exit(2)
}
const puppeteer = (await import(pathToFileURL(ppPath).href)).default

const browser = await puppeteer.launch({
  executablePath: CHROME,
  headless: true,
  args: ['--no-sandbox', '--hide-scrollbars', '--disable-gpu']
})

async function settle(page, ms = 4000) {
  // espera fontes prontas + delay pra imagens/animações da SPA assentarem
  try { await page.evaluate(() => document.fonts && document.fonts.ready) } catch {}
  await new Promise(r => setTimeout(r, ms))
}

// ── DESKTOP ─────────────────────────────────────────────────────────────────
{
  const page = await browser.newPage()
  await page.setViewport({ width: 1440, height: 900, deviceScaleFactor: 2 })
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 })
  await settle(page)
  await page.screenshot({ path: join(outDir, 'desktop.png') })
  console.log('OK desktop:', join(outDir, 'desktop.png'))
  await page.close()
}

// ── MOBILE (emulação real — dispara media queries) ──────────────────────────
{
  const page = await browser.newPage()
  await page.setUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1')
  await page.setViewport({ width: 393, height: 852, deviceScaleFactor: 3, isMobile: true, hasTouch: true })
  await page.goto(url, { waitUntil: 'networkidle2', timeout: 60000 })
  await settle(page)
  await page.screenshot({ path: join(outDir, 'mobile.png') })
  console.log('OK mobile:', join(outDir, 'mobile.png'))
  await page.close()
}

await browser.close()
console.log('DONE')
