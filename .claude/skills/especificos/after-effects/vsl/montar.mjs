#!/usr/bin/env node
/**
 * montar.mjs — roteiro + tempos da narração -> projeto do After Effects.
 *
 * A duração de cada cena vem do `tempos.json`, que saiu dos timestamps reais
 * da locução. Nada de tempo escolhido à mão: trocou uma frase, roda de novo e
 * a animação inteira se reajusta.
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const AQUI = path.dirname(fileURLToPath(import.meta.url))
const SKILL = path.resolve(AQUI, '..')
const ROTEIRO = path.join(process.env.HOME, '.claude/skills/pagzero-video/projeto/src/vsl/roteiro.js')

const s = (v) => JSON.stringify(String(v ?? ''))
const n = (v, d = 0) => (typeof v === 'number' && isFinite(v) ? v : d)

// ---------------------------------------------------------------- entrada

const { cenas } = await import(ROTEIRO)
const tempos = JSON.parse(readFileSync(path.join(AQUI, 'tempos.json'), 'utf8'))
const porId = new Map(tempos.cortes.map((c) => [c.id, c]))

const SAIDA = process.env.AE_SAIDA || path.join(process.env.HOME, 'Downloads/vsl-after')
const aep = path.join(SAIDA, 'vsl.aep')

// Um ato por comp: 35 cenas numa comp só deixa o AE pesado e o render frágil.
// Renderizamos ato a ato e concatenamos no ffmpeg.
const ATOS = [1, 2, 3, 4, 5, 6]
const soAto = process.env.ATO ? Number(process.env.ATO) : null

// ---------------------------------------------------------------- geração

const L = []
L.push(`#include ${s(path.join(SKILL, 'lib/ae.jsx'))}`)
L.push(`#include ${s(path.join(AQUI, 'marca.jsx'))}`)
L.push(`#include ${s(path.join(AQUI, 'gestos.jsx'))}`)
L.push(`#include ${s(path.join(AQUI, 'ambiente.jsx'))}`)
L.push(`#include ${s(path.join(AQUI, 'blocos.jsx'))}`)
L.push(`var _rel = [];`)
L.push(`try {`)
L.push(`novoProjeto();`)
L.push(`app.project.bitsPerChannel = 8;`)

const alvos = soAto ? [soAto] : ATOS
const infoAtos = []

for (const ato of alvos) {
  const doAto = cenas.filter((c) => c.ato === ato)
  if (!doAto.length) continue

  // O ato começa quando começa a primeira fala dele.
  const t0 = porId.get(doAto[0].id)?.inicio ?? 0
  const fim = porId.get(doAto[doAto.length - 1].id)?.fim ?? (t0 + 10)
  const durAto = +(fim - t0).toFixed(3)

  const nomeComp = `Ato${ato}`
  infoAtos.push({ ato, comp: nomeComp, inicio: t0, dur: durAto })

  L.push(`// ══════════════════════════ ATO ${ato} ══════════════════════════`)
  L.push(`var comp = novaComp(${s(nomeComp)}, {largura:1920, altura:1080, duracao:${(durAto + 0.5).toFixed(2)}, fps:30, fundo:"#0B0A0D"});`)
  L.push(`comp.motionBlur = true;`)

  // Fundo do ato (tema pode alternar por cena, mas o solid base evita
  // transparência aparecendo nas transições).
  L.push(`var _bgAto = comp.layers.addSolid(hex("#0B0A0D"), "fundo", 1920, 1080, 1);`)
  L.push(`_bgAto.moveToEnd();`)

  doAto.forEach((cena, idx) => {
    const corte = porId.get(cena.id)
    if (!corte) { L.push(`_rel.push("SEM TEMPO cena ${cena.id}");`); return }

    const t = +(corte.inicio - t0).toFixed(3)
    const dur = +corte.dur.toFixed(3)
    const bloco = cena.bloco
    const props = JSON.stringify(cena.props || {})

    L.push(``)
    L.push(`// ── cena ${cena.id} · ${bloco} · ${dur}s`)
    L.push(`tema(${cena.claro ? 'true' : 'false'});`)

    // Fundo próprio quando a cena inverte para o tema claro.
    if (cena.claro) {
      L.push(`var _bg${cena.id} = comp.layers.addSolid(hex(C.fundo), "bg${cena.id}", 1920, 1080, 1);`)
      L.push(`_bg${cena.id}.inPoint = ${t}; _bg${cena.id}.outPoint = ${(t + dur).toFixed(3)};`)
      L.push(`anim(_bg${cena.id}.property("Transform").property("Opacity"), [[${t}, 0],[${(t + 0.18).toFixed(3)}, 100]], {ease:50});`)
      L.push(`anim(_bg${cena.id}.property("Transform").property("Opacity"), [[${(t + dur - 0.18).toFixed(3)}, 100],[${(t + dur).toFixed(3)}, 0]], {ease:50});`)
    }

    L.push(`try {`)
    L.push(`  var _ctx${cena.id} = {t:${t}, dur:${dur}, i:${idx}, cena:${JSON.stringify({ id: cena.id, icone: cena.icone || null, claro: !!cena.claro })}};`)
    L.push(`  var _c${cena.id} = achatar(BLOCOS[${s(bloco)}](comp, ${props}, _ctx${cena.id}));`)
    L.push(`  entrada(_c${cena.id}, ${s(cena.entrada || 'subir')}, ${t}, ${dur}, ${idx});`)

    // matchCut é saída da cena ANTERIOR (a câmera entra no elemento dela).
    const proxima = doAto[idx + 1]
    if (proxima?.passagem?.tipo === 'matchCut') {
      const esc = n(proxima.passagem.escalaSaida, 12)
      L.push(`  matchCutSaida(_c${cena.id}, ${(t + dur - 0.5).toFixed(3)}, ${esc}, 0.5);`)
    }

    L.push(`  recortar(_c${cena.id}, ${t}, ${dur});`)
    L.push(`  _rel.push("OK cena ${cena.id} ${bloco}");`)
    L.push(`} catch(e) { _rel.push("FALHA cena ${cena.id} (${bloco}) :: " + e.toString() + " @" + e.line); }`)

    // Passagem que TRAZ esta cena (sobre a anterior).
    const pa = cena.passagem
    if (pa && idx > 0) {
      if (pa.tipo === 'iris') {
        L.push(`passagemIris(comp, ${t}, {cx:${n(pa.cx, 960)}, cy:${n(pa.cy, 540)}});`)
      } else if (pa.tipo === 'estouro') {
        L.push(`passagemEstouro(comp, ${t}, {});`)
      } else if (pa.tipo === 'whip') {
        L.push(`passagemWhip(comp, ${(t - 0.16).toFixed(3)}, {sentido:${n(pa.sentido, 1)}});`)
      }
      // matchCut e empurra já foram aplicados na cena anterior.
    }
  })

  // Vinheta e câmera por ato: a vida que impede a cena de "morrer".
  L.push(`tema(false);`)
  L.push(`ambienteDoAto(comp, ${durAto.toFixed(2)}, {});`)
  L.push(`vinheta(comp);`)
  L.push(`_rel.push("OK ato ${ato} (${durAto}s)");`)
}

L.push(`salvar(${s(aep)});`)
L.push(`_rel.push("OK salvo");`)
L.push(`} catch(e) { _rel.push("FATAL :: " + e.toString() + " @" + e.line); }`)
L.push(`escrever(${s(aep + '.log')}, _rel.join("\\n"));`)

execFileSync('mkdir', ['-p', SAIDA])
const jsx = path.join(SAIDA, 'vsl.jsx')
writeFileSync(jsx, L.join('\n'), 'utf8')

console.error(`==> ${alvos.length} ato(s), ${cenas.filter((c) => alvos.includes(c.ato)).length} cenas`)
for (const a of infoAtos) console.error(`    ato ${a.ato}: ${a.dur.toFixed(1)}s`)
writeFileSync(path.join(SAIDA, 'atos.json'), JSON.stringify(infoAtos, null, 1))

execFileSync(path.join(SKILL, 'scripts/rodar.sh'), [jsx, '1800'], { stdio: 'inherit' })

const log = aep + '.log'
if (existsSync(log)) {
  const rel = readFileSync(log, 'utf8')
  const falhas = rel.split('\n').filter((l) => l.startsWith('FALHA') || l.startsWith('FATAL'))
  if (falhas.length) { console.error('\n' + falhas.join('\n')) }
  const ok = rel.split('\n').filter((l) => l.startsWith('OK cena')).length
  console.error(`==> ${ok} cenas montadas, ${falhas.length} falha(s)`)
}
if (!existsSync(aep)) { console.error('erro: .aep não foi gerado'); process.exit(2) }
console.log(aep)
