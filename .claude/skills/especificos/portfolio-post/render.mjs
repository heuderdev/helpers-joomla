#!/usr/bin/env node
/* ════════════════════════════════════════════════════════════════════════
   render.mjs — gera os slides PNG do carrossel de portfólio (Team Lecdt).

   Uso:
     node render.mjs <dados.json> <pasta-saida>

   <dados.json>: objeto com os placeholders do template (ver SKILL.md).
   Campos especiais:
     - "stack": array de strings ou {nome,icone} -> vira a lista <li> do slide 6,
       com ícone de tecnologia (assets/tecnologias/tecnologia-<slug>.webp).
     - imagens (IMG_*) devem ser caminhos ABSOLUTOS de arquivo; o script
       converte para file:// automaticamente.
   Regras de tipografia aplicadas automaticamente:
     - anti-órfã: cola as duas últimas palavras de cada texto com NBSP.
   ════════════════════════════════════════════════════════════════════════ */
import puppeteer from '/opt/homebrew/lib/node_modules/puppeteer/lib/esm/puppeteer/puppeteer.js'
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs'
import { dirname, resolve, isAbsolute } from 'node:path'
import { fileURLToPath, pathToFileURL } from 'node:url'

const __dir = dirname(fileURLToPath(import.meta.url))
const [, , dadosPath, outDir] = process.argv
if (!dadosPath || !outDir) {
  console.error('uso: node render.mjs <dados.json> <pasta-saida>')
  process.exit(1)
}

const dados = JSON.parse(readFileSync(dadosPath, 'utf8'))
mkdirSync(outDir, { recursive: true })

const NBSP = ' '

// ── regra anti-orfa: nunca uma palavra sozinha na ultima linha ──
// Cola as duas ultimas palavras com NBSP para que quebrem sempre juntas.
function semOrfa(txt) {
  if (typeof txt !== 'string' || !txt.trim()) return txt
  return txt.replace(/\s+(\S+)\s*$/u, NBSP + '$1')
}
const CAMPOS_TEXTO = [
  'CAPA_TITULO', 'CAPA_SUB',
  'S2_TITULO', 'S2_TEXTO',
  'S3_TITULO', 'S3_LEGENDA',
  'S4_TITULO', 'S4_LEGENDA',
  'S5_TITULO', 'S5_TEXTO',
  'S6_TITULO', 'S6_TEXTO',
  'S7_TITULO', 'S7_TEXTO'
]
for (const k of CAMPOS_TEXTO) if (dados[k]) dados[k] = semOrfa(dados[k])

// ── monta a lista da stack com icones de tecnologia ──
// Item pode ser "Laravel 12" ou "Nuxt 3" (acha icone por alias/slug) ou {nome,icone}.
const ALIAS = {
  'nuxt': 'nuxtjs', 'nuxt 3': 'nuxtjs', 'nuxtjs': 'nuxtjs',
  'vue': 'vue', 'vuejs': 'vue',
  'js': 'javascript', 'javascript': 'javascript',
  'ts': 'typescript', 'typescript': 'typescript',
  'sass': 'sass', 'scss': 'sass', 'css': 'css', 'html': 'html',
  'figma': 'figma', 'node': 'node', 'nodejs': 'node', 'node.js': 'node',
  'mongo': 'mongodb', 'mongodb': 'mongodb', 'postgres': 'postgresql', 'postgresql': 'postgresql',
  'mysql': 'mysql', 'redis': 'redis', 'pinia': 'pinia', 'vite': 'vite',
  'react': 'react', 'next': 'nextjs', 'nextjs': 'nextjs', 'tailwind': 'tailwind',
  'docker': 'docker', 'aws': 'aws', 'firebase': 'firebase', 'supabase': 'supabase',
  'stripe': 'stripe', 'php': 'php', 'laravel': 'laravel', 'python': 'python',
  'laravel 12': 'laravel', 'blade': 'laravel', 'php 8': 'php', 'php 8.3': 'php',
  'tailwind css': 'tailwind', 'tailwindcss': 'tailwind',
  'flutter': 'flutter', 'swift': 'swift', 'kotlin': 'kotlin', 'wordpress': 'wordpress',
  'seo': null, 'seo tecnico': null, 'seo técnico': null, 'ux': null, 'ui': null, 'ux/ui': null
}
const slugify = s => s.toLowerCase().trim().replace(/[.\s]+/g, '')

if (Array.isArray(dados.stack)) {
  dados.STACK_ITENS = dados.stack.map(item => {
    const nome = typeof item === 'string' ? item : item.nome
    const icoSlug = typeof item === 'object' && item.icone
      ? item.icone
      : (ALIAS[nome.toLowerCase().trim()] ?? slugify(nome))
    let img = ''
    if (icoSlug) {
      const p = resolve(__dir, 'assets/tecnologias', `tecnologia-${icoSlug}.webp`)
      if (existsSync(p)) img = `<img src="${pathToFileURL(p).href}" alt="">`
      else console.warn(`! icone nao encontrado para "${nome}" (slug: ${icoSlug})`)
    }
    return `<li>${img}${nome}</li>`
  }).join('')
}

// ── converte caminhos de imagem absolutos -> file:// ──
for (const k of Object.keys(dados)) {
  if (k.startsWith('IMG_') && typeof dados[k] === 'string' && dados[k]) {
    const p = isAbsolute(dados[k]) ? dados[k] : resolve(process.cwd(), dados[k])
    if (!existsSync(p)) { console.error(`! imagem nao encontrada: ${p}`); process.exit(1) }
    dados[k] = pathToFileURL(p).href
  }
}

// ── default de acento (mantem mono se nao houver cor de projeto) ──
if (!dados.ACENTO) dados.ACENTO = '#ffffff'

// ── preenche o template ──
let html = readFileSync(resolve(__dir, 'template.html'), 'utf8')
html = html.replace(/\{\{(\w+)\}\}/g, (_, key) => (dados[key] ?? '').toString())

const faltando = [...html.matchAll(/\{\{(\w+)\}\}/g)].map(m => m[1])
if (faltando.length) console.warn('! placeholders sem valor:', [...new Set(faltando)].join(', '))

// Grava o HTML DENTRO da pasta da skill para que ./assets/ resolva.
const htmlPath = resolve(__dir, '_carrossel.tmp.html')
writeFileSync(htmlPath, html)

// ── renderiza + captura cada .slide ──
const browser = await puppeteer.launch({
  headless: 'new',
  args: ['--no-sandbox', '--force-color-profile=srgb', '--font-render-hinting=none']
})
const page = await browser.newPage()
await page.setViewport({ width: 1080, height: 1350, deviceScaleFactor: 2 })
await page.goto(pathToFileURL(htmlPath).href, { waitUntil: 'networkidle0', timeout: 60000 })
await page.evaluateHandle('document.fonts.ready')
await new Promise(r => setTimeout(r, 1200))

const total = await page.$$eval('.slide', els => els.length)
const arquivos = []
for (let i = 1; i <= total; i++) {
  const el = await page.$(`#slide-${i}`)
  if (!el) continue
  await el.scrollIntoView()
  await new Promise(r => setTimeout(r, 250))
  const file = resolve(outDir, `slide-${String(i).padStart(2, '0')}.png`)
  await el.screenshot({ path: file })
  arquivos.push(file)
  console.log(`OK ${file}`)
}

await browser.close()
console.log(`\n${arquivos.length} slides gerados em: ${outDir}`)
