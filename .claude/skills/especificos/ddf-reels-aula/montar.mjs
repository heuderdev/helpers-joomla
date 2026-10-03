// Remonta o CSV do whisper (-ml 1, que devolve sub-tokens) em palavras.
// uso: node montar.mjs /tmp/<id>.csv > src/<id>.palavras.json
import fs from 'node:fs'

const arquivo = process.argv[2]
if (!arquivo) {
  console.error('uso: node montar.mjs <arquivo.csv>')
  process.exit(1)
}

const linhas = fs.readFileSync(arquivo, 'utf8').trim().split('\n').slice(1)
const toks = linhas
  .map((l) => {
    const m = l.match(/^(\d+),(\d+),"?(.*?)"?$/)
    return m ? { ini: +m[1] / 1000, fim: +m[2] / 1000, raw: m[3].replace(/""/g, '"') } : null
  })
  .filter((t) => t && t.raw.trim() !== '')

const palavras = []
for (const tok of toks) {
  const comecaPalavra = /^\s/.test(tok.raw)
  const txt = tok.raw.trim()
  const soPontuacao = /^[.,!?:;…]+$/.test(txt)
  // sub-token que não abre com espaço (ou pontuação solta) gruda na palavra anterior
  if (palavras.length && (!comecaPalavra || soPontuacao)) {
    const ult = palavras[palavras.length - 1]
    ult.t += txt
    ult.fim = tok.fim
  } else {
    palavras.push({ t: txt, ini: tok.ini, fim: tok.fim })
  }
}

const saida = palavras.map((p) => ({ t: p.t, ini: +p.ini.toFixed(2), fim: +p.fim.toFixed(2) }))
console.log(JSON.stringify(saida))
console.error(`${saida.length} palavras · ${saida[saida.length - 1].fim.toFixed(2)}s`)
console.error(saida.map((p) => p.t).join(' '))
