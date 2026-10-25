#!/usr/bin/env node
/*
 * Pré-gera os ícones do VSL como ARQUIVOS em public/vsl/icones/.
 *
 * Por que existe: o @edusites/icons devolve o SVG como STRING, e string
 * injetada (dangerouslySetInnerHTML ou data URI montado em JS) não desenha —
 * nem no Remotion, nem no Chrome puro. Comprovado com teste lado a lado: o
 * mesmo SVG servido como arquivo renderiza; injetado, sai vazio.
 *
 * A cor entra no NOME do arquivo porque o `fill` é assado dentro do SVG na
 * geração: um <img> é documento isolado e não herda cor do CSS de fora.
 *
 * Rode depois de acrescentar um `icone:` novo no roteiro:
 *     node gerar-icones.mjs
 */
import { svgIcone, temIcone } from '@edusites/icons/core'
import { writeFileSync, mkdirSync } from 'fs'
import { cenas } from './src/vsl/roteiro.js'

const DESTINO = 'public/vsl/icones'
const CORES = {
  amarelo: '#FEBE00',
  vermelho: '#EF4444',
  verde: '#22C55E',
  branco: '#FFFFFF'
}
// 200px: o maior uso no vídeo é ~96px; gerar no dobro evita serrilhado quando
// a câmera dá zoom na cena.
const TAMANHO = 200

mkdirSync(DESTINO, { recursive: true })

const usados = [...new Set(cenas.map((c) => c.icone).filter(Boolean))]
const faltando = usados.filter((n) => !temIcone(n))

if (faltando.length) {
  console.error('Ícones que NÃO existem na lib:', faltando.join(', '))
  process.exit(1)
}

let n = 0
for (const nome of usados) {
  for (const [chave, cor] of Object.entries(CORES)) {
    writeFileSync(`${DESTINO}/${nome}--${chave}.svg`, svgIcone({ nome, cor, tamanho: TAMANHO }))
    n++
  }
}

console.log(`${n} arquivos (${usados.length} ícones x ${Object.keys(CORES).length} cores) em ${DESTINO}`)
