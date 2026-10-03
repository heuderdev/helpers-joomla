#!/usr/bin/env node
/**
 * montar-3d.mjs — o Ato 1 como plano-sequência 3D.
 * A câmera chega em cada estação no instante da fala correspondente.
 */
import { readFileSync, writeFileSync, existsSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const AQUI = path.dirname(fileURLToPath(import.meta.url))
const SKILL = path.resolve(AQUI, '..')
const s = (v) => JSON.stringify(String(v ?? ''))

const tempos = JSON.parse(readFileSync(path.join(AQUI, 'tempos.json'), 'utf8'))
const cortes = tempos.cortes.filter((c) => c.id <= 9)
const t0 = cortes[0].inicio
const dur = +(cortes[cortes.length - 1].fim - t0).toFixed(3)

// Onde cada estação vive no espaço. Z alternado gera paralaxe.
// X/Y são coordenadas de COMP: a estação 1 fica no centro da tela (960,540),
// e as seguintes se afastam. Assim o rig da câmera aponta para o conteúdo.
const CX = 960, CY = 540
// Passo X constante: distância irregular faz a câmera acelerar e frear em
// ritmos diferentes a cada trecho, e é isso que lê como "não fluido".
// Y e Z variam pouco e de forma alternada — só o suficiente para haver
// paralaxe, sem tirar o conteúdo do enquadramento.
// A câmera a dist=1500 enxerga ~1543px de largura, e o conteúdo mais largo
// (painel + gráfico) mede ~1500px: não cabia, e a estação vizinha invadia o
// quadro. Com dist=2100 ela enxerga ~2160px, e o passo de 3400 mantém a
// vizinha fora de campo com folga.
const PASSO = 3400
const EST = [
  { id: 1, dy:    0, z:    0, ry:   0 },
  { id: 2, dy:  -90, z:  520, ry:  16 },
  { id: 3, dy:   70, z: -180, ry: -13 },
  { id: 4, dy:  -60, z:  640, ry:  18 },
  { id: 5, dy:    0, z: -240, ry: -10 },
  { id: 6, dy:  100, z:  480, ry:  15 },
  { id: 7, dy:  -70, z: -200, ry: -12 },
  { id: 8, dy:    0, z:  700, ry:  19 },
  { id: 9, dy:   60, z: -160, ry:  -9 }
].map((e, i) => ({ id: e.id, x: CX + i * PASSO, y: CY + e.dy, z: e.z, ry: e.ry }))

const SAIDA = path.join(process.env.HOME, 'Downloads/vsl-after')
const aep = path.join(SAIDA, 'ato1-3d.aep')

const L = []
L.push(`#include ${s(path.join(SKILL, 'lib/ae.jsx'))}`)
for (const f of ['marca.jsx', 'gestos.jsx', 'motion.jsx', 'cena3d.jsx', 'ato1-3d.jsx'])
  L.push(`#include ${s(path.join(AQUI, f))}`)

L.push(`var _rel = [];`)
L.push(`try {`)
L.push(`novoProjeto();`)
L.push(`tema(false);`)
L.push(`var comp = novaComp("Ato1", {largura:1920, altura:1080, duracao:${(dur + 0.6).toFixed(2)}, fps:30, fundo:"#FFFFFF"});`)
L.push(`comp.motionBlur = true;`)
// Fundo branco 2D no fim da pilha: o espaço 3D fica sobre ele.
L.push(`var _bg = comp.layers.addSolid(hex(C.fundo), "fundo", 1920, 1080, 1);`)

// --- estações ---
for (const [i, e] of EST.entries()) {
  const c = cortes.find((x) => x.id === e.id)
  const t = +(c.inicio - t0).toFixed(3)
  L.push(``)
  L.push(`// ═══ estação ${e.id} · chega em ${t}s`)
  L.push(`try {`)
  L.push(`  var _e${e.id} = achatar(A13D.e${e.id}(comp, ${t}));`)
  L.push(`  var _dz${e.id} = [];`)
  L.push(`  for (var z${e.id} = 0; z${e.id} < _e${e.id}.length; z${e.id}++) {`)
  // Camadas mais tardias na lista vêm à frente: escalona -14px por índice,
  // limitado para não distorcer a perspectiva.
  L.push(`    _dz${e.id}.push(-Math.min(z${e.id} * 14, 190));`)
  L.push(`  }`)
  L.push(`  moverEstacao(_e${e.id}, ${e.x}, ${e.y}, ${e.z}, _dz${e.id});`)
  L.push(`  girarEstacao(_e${e.id}, ${e.x}, ${e.y}, ${e.z}, ${e.ry});`)
  L.push(`  _rel.push("OK estacao ${e.id} (" + _e${e.id}.length + " camadas)");`)
  L.push(`} catch(e) { _rel.push("FALHA estacao ${e.id} :: " + e.toString() + " @" + e.line); }`)
}

function CY_OFF() { return 0 }

// --- câmera ---
// Chega na estação no início da fala e sai um pouco antes do fim: o
// deslocamento acontece durante a respiração da locutora.
// A câmera CHEGA 0.25s ANTES da fala e SAI 0.75s antes do fim: o
// deslocamento inteiro acontece enquanto a locutora respira, e o conteúdo já
// está parado e enquadrado quando ela começa a falar sobre ele.
// Trajeto LONGO: a câmera sai 1.5s antes do fim da fala e chega 0.35s antes
// da próxima. Antes o deslocamento tinha ~1s e passava rápido demais.
const ANTES = 0.35, ANTECIPA_SAIDA = 1.5
const paradas = []
for (const [i, e] of EST.entries()) {
  const c = cortes.find((x) => x.id === e.id)
  const ini = +(c.inicio - t0).toFixed(3)
  const fim = +(c.fim - t0).toFixed(3)
  if (i === 0) {
    // Abre já quase enquadrado, só com um avanço suave: começar longe demais
    // gasta os primeiros segundos viajando em vez de comunicando.
    paradas.push({ t: 0, x: e.x - 260, y: e.y, z: e.z - 420, ry: e.ry * 0.5 })
    paradas.push({ t: 0.9, x: e.x, y: e.y, z: e.z, ry: e.ry })
  } else {
    paradas.push({ t: Math.max(0, ini - ANTES), x: e.x, y: e.y, z: e.z, ry: e.ry })
  }
  const saida = i === EST.length - 1 ? fim : Math.max(ini, fim - ANTECIPA_SAIDA)
  paradas.push({ t: saida, x: e.x, y: e.y, z: e.z, ry: e.ry })
}
const ult = EST[EST.length - 1]
paradas.push({ t: dur + 0.5, x: ult.x, y: ult.y, z: ult.z - 300, ry: ult.ry })

L.push(``)
L.push(`// ═══ a câmera que atravessa tudo`)
L.push(`var _rig = cameraViajante(comp, ${JSON.stringify(paradas)}, {dist:2100, foco:true, abertura:34});`)
L.push(`_rel.push("OK camera (${paradas.length} paradas)");`)

L.push(`salvar(${s(aep)});`)
L.push(`} catch(e) { _rel.push("FATAL :: " + e.toString() + " @" + e.line); }`)
L.push(`escrever(${s(aep + '.log')}, _rel.join("\\n"));`)

execFileSync('mkdir', ['-p', SAIDA])
const jsx = path.join(SAIDA, 'ato1-3d.jsx')
writeFileSync(jsx, L.join('\n'), 'utf8')
console.error(`==> 9 estações, ${dur.toFixed(1)}s, ${paradas.length} paradas de câmera`)
execFileSync(path.join(SKILL, 'scripts/rodar.sh'), [jsx, '900'], { stdio: 'inherit' })
if (existsSync(aep + '.log')) {
  const rel = readFileSync(aep + '.log', 'utf8')
  console.error(rel.replace(/OK /g, '\nOK ').replace(/FALHA/g, '\nFALHA'))
}
console.log(aep)
