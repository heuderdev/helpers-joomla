#!/usr/bin/env node
/** montar-ato1.mjs — monta só o Ato 1 na identidade real (tema claro). */
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const AQUI = path.dirname(fileURLToPath(import.meta.url))
const SKILL = path.resolve(AQUI, '..')
const ROTEIRO = path.join(process.env.HOME, '.claude/skills/pagzero-video/projeto/src/vsl/roteiro.js')
const s = (v) => JSON.stringify(String(v ?? ''))

const { cenas } = await import(ROTEIRO)
const tempos = JSON.parse(readFileSync(path.join(AQUI, 'tempos.json'), 'utf8'))
const porId = new Map(tempos.cortes.map((c) => [c.id, c]))
const SAIDA = path.join(process.env.HOME, 'Downloads/vsl-after')
const aep = path.join(SAIDA, 'ato1.aep')

const doAto = cenas.filter((c) => c.ato === 1)
const t0 = porId.get(doAto[0].id).inicio
const durAto = +(porId.get(doAto[doAto.length - 1].id).fim - t0).toFixed(3)

const L = []
for (const f of ['lib/ae.jsx']) L.push(`#include ${s(path.join(SKILL, f))}`)
for (const f of ['marca.jsx', 'gestos.jsx', 'ambiente.jsx', 'motion.jsx', 'ato1.jsx'])
  L.push(`#include ${s(path.join(AQUI, f))}`)

L.push(`var _rel = [];`)
L.push(`try {`)
L.push(`novoProjeto();`)
L.push(`tema(false);`)   // TEMA CLARO: o site é branco
L.push(`var comp = novaComp("Ato1", {largura:1920, altura:1080, duracao:${(durAto + 0.5).toFixed(2)}, fps:30, fundo:"#FFFFFF"});`)
L.push(`comp.motionBlur = true;`)
L.push(`var _bg = comp.layers.addSolid(hex(C.fundo), "fundo", 1920, 1080, 1);`)
L.push(`_bg.moveToEnd();`)

doAto.forEach((cena, idx) => {
  const c = porId.get(cena.id)
  const t = +(c.inicio - t0).toFixed(3)
  const dur = +c.dur.toFixed(3)
  L.push(``)
  L.push(`// ── cena ${cena.id} · ${cena.bloco} · ${dur}s`)
  L.push(`try {`)
  L.push(`  var _ctx = {t:${t}, dur:${dur}, i:${idx}, props:${JSON.stringify(cena.props || {})}, cena:${JSON.stringify({ id: cena.id, icone: cena.icone || null })}};`)
  L.push(`  var _c = achatar(A1.cena${cena.id}(comp, _ctx));`)
  L.push(`  entrada(_c, ${s(cena.entrada || 'subir')}, ${t}, ${dur}, ${idx});`)
  const prox = doAto[idx + 1]
  if (prox?.passagem?.tipo === 'matchCut') {
    L.push(`  matchCutSaida(_c, ${(t + dur - 0.5).toFixed(3)}, ${prox.passagem.escalaSaida || 12}, 0.5);`)
  }
  L.push(`  recortar(_c, ${t}, ${dur});`)
  L.push(`  _rel.push("OK cena ${cena.id}");`)
  L.push(`} catch(e) { _rel.push("FALHA cena ${cena.id} :: " + e.toString() + " @" + e.line); }`)

  const pa = cena.passagem
  if (pa && idx > 0) {
    if (pa.tipo === 'iris') L.push(`passagemIris(comp, ${t}, {cx:${pa.cx ?? 960}, cy:${pa.cy ?? 540}, cor:C.fundo});`)
    else if (pa.tipo === 'estouro') L.push(`passagemEstouro(comp, ${t}, {});`)
    else if (pa.tipo === 'whip') L.push(`passagemWhip(comp, ${(t - 0.16).toFixed(3)}, {sentido:${pa.sentido ?? 1}, cor:C.fundo});`)
  }
})

L.push(`_rel.push("OK ato 1 (${durAto}s)");`)
L.push(`salvar(${s(aep)});`)
L.push(`} catch(e) { _rel.push("FATAL :: " + e.toString() + " @" + e.line); }`)
L.push(`escrever(${s(aep + '.log')}, _rel.join("\\n"));`)

execFileSync('mkdir', ['-p', SAIDA])
const jsx = path.join(SAIDA, 'ato1.jsx')
writeFileSync(jsx, L.join('\n'), 'utf8')
console.error(`==> Ato 1: ${doAto.length} cenas, ${durAto.toFixed(1)}s`)
execFileSync(path.join(SKILL, 'scripts/rodar.sh'), [jsx, '900'], { stdio: 'inherit' })
if (existsSync(aep + '.log')) {
  const rel = readFileSync(aep + '.log', 'utf8')
  const f = rel.split('\n').filter((l) => /FALHA|FATAL/.test(l))
  console.error(rel.replace(/OK /g, '\nOK '))
  if (f.length) process.exit(2)
}
console.log(aep)
