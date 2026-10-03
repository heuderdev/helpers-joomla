#!/usr/bin/env node
// Exporta nós de um arquivo Figma como imagem, via API REST.
// Uso:
//   node export.mjs <fileKey> <nodeIds separados por virgula> [--out DIR] [--scale N] [--format png|jpg|svg|pdf]
//   node export.mjs <fileKey> --page "Nome da Pagina" --sections   (exporta todos os frames de cada section)
//
// O token sai de FIGMA_TOKEN (env ou .env ao lado da skill).

import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = path.dirname(fileURLToPath(import.meta.url))
const API = 'https://api.figma.com/v1'

function carregaToken() {
  if (process.env.FIGMA_TOKEN) return process.env.FIGMA_TOKEN
  const envPath = path.join(__dirname, '..', '.env')
  if (fs.existsSync(envPath)) {
    const linha = fs.readFileSync(envPath, 'utf8').split('\n').find((l) => l.startsWith('FIGMA_TOKEN='))
    if (linha) return linha.slice('FIGMA_TOKEN='.length).trim()
  }
  console.error('FIGMA_TOKEN não encontrado (env ou .env da skill).')
  process.exit(1)
}
const TOKEN = carregaToken()

async function api(rota) {
  const r = await fetch(`${API}${rota}`, { headers: { 'X-Figma-Token': TOKEN } })
  if (!r.ok) {
    console.error(`Figma API ${r.status}: ${await r.text()}`)
    process.exit(1)
  }
  return r.json()
}

// A API limita a quantidade de ids por chamada; 40 é folgado e rápido.
async function urlsDasImagens(fileKey, ids, formato, escala) {
  const urls = {}
  for (let i = 0; i < ids.length; i += 40) {
    const lote = ids.slice(i, i + 40)
    const q = new URLSearchParams({ ids: lote.join(','), format: formato, scale: String(escala) })
    const { images, err } = await api(`/images/${fileKey}?${q}`)
    if (err) { console.error('Figma:', err); process.exit(1) }
    Object.assign(urls, images)
  }
  return urls
}

// A CDN do Figma derruba conexão em lote grande, então cada arquivo tem retry próprio.
async function baixa(url, destino, tentativas = 4) {
  for (let t = 1; t <= tentativas; t++) {
    try {
      const r = await fetch(url)
      if (!r.ok) throw new Error(`HTTP ${r.status}`)
      fs.writeFileSync(destino, Buffer.from(await r.arrayBuffer()))
      return
    } catch (e) {
      if (t === tentativas) throw e
      await new Promise((r) => setTimeout(r, 400 * t))
    }
  }
}

// Descobre os frames de uma página, agrupados por section e já na ordem visual (x crescente).
async function frentesDaPagina(fileKey, nomePagina) {
  const doc = await api(`/files/${fileKey}?depth=3`)
  const pagina = doc.document.children.find((p) => p.name === nomePagina)
  if (!pagina) {
    const nomes = doc.document.children.map((p) => p.name).join(', ')
    console.error(`Página "${nomePagina}" não existe. Disponíveis: ${nomes}`)
    process.exit(1)
  }
  return pagina.children
    .filter((n) => n.type === 'SECTION')
    .map((sec) => ({
      secao: sec.name,
      ids: (sec.children || [])
        .filter((f) => f.type === 'FRAME')
        .sort((a, b) => (a.absoluteBoundingBox?.x ?? 0) - (b.absoluteBoundingBox?.x ?? 0))
        .map((f) => f.id)
    }))
}

const args = process.argv.slice(2)
if (!args.length) {
  console.error('uso: export.mjs <fileKey> <ids|--page NOME --sections> [--out DIR] [--scale N] [--format png]')
  process.exit(1)
}
const fileKey = args[0]
const opt = (nome, padrao) => {
  const i = args.indexOf(`--${nome}`)
  return i >= 0 ? args[i + 1] : padrao
}
const saida = opt('out', './figma-export')
const escala = Number(opt('scale', '1'))
const formato = opt('format', 'png')
fs.mkdirSync(saida, { recursive: true })

const nomePagina = opt('page', null)
if (nomePagina && args.includes('--sections')) {
  const grupos = await frentesDaPagina(fileKey, nomePagina)
  const todos = grupos.flatMap((g) => g.ids)
  console.log(`${grupos.length} seções, ${todos.length} frames. Exportando...`)
  const urls = await urlsDasImagens(fileKey, todos, formato, escala)
  let n = 0
  for (const [indice, grupo] of grupos.entries()) {
    const pasta = path.join(saida, String(indice + 1).padStart(2, '0') + '-' + grupo.secao.replace(/[^\w]+/g, '-').toLowerCase())
    fs.mkdirSync(pasta, { recursive: true })
    for (const [i, id] of grupo.ids.entries()) {
      const url = urls[id]
      if (!url) { console.warn(`sem imagem para ${id}`); continue }
      const arquivo = path.join(pasta, `${String(i + 1).padStart(2, '0')}.${formato}`)
      await baixa(url, arquivo)
      n++
    }
    console.log(`  ${grupo.secao}: ${grupo.ids.length} slides`)
  }
  console.log(`\n${n} arquivos em ${path.resolve(saida)}`)
} else {
  const ids = args[1].split(',').map((s) => s.trim()).filter(Boolean)
  const urls = await urlsDasImagens(fileKey, ids, formato, escala)
  for (const [i, id] of ids.entries()) {
    const url = urls[id]
    if (!url) { console.warn(`sem imagem para ${id}`); continue }
    const arquivo = path.join(saida, `${String(i + 1).padStart(2, '0')}-${id.replace(':', '-')}.${formato}`)
    await baixa(url, arquivo)
  }
  console.log(`${ids.length} arquivos em ${path.resolve(saida)}`)
}
