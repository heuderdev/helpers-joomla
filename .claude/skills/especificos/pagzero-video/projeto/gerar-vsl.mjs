#!/usr/bin/env node
/**
 * gerar-vsl.mjs — a narração da VSL e os tempos reais de cada cena.
 *
 * O `roteiro.js` sempre citou este arquivo como existente, mas ele nunca foi
 * escrito: por isso `tempos.json` estava zerado e a VSL rodava com as
 * durações-alvo do roteiro, sem locução.
 *
 * Diferenças em relação ao `gerar.mjs` (que faz os tutoriais):
 *
 *  · Modelo `eleven_v3`, não `multilingual_v2`. As falas carregam audio tags
 *    ([rápida, animada], [curiosa], [confiante]) e o v2 as IGNORA — a direção
 *    inteira seria perdida.
 *  · A fonte é um módulo JS (`src/vsl/narracao.js`), não `roteiros/*.json`.
 *  · O corte de pausas usa o `silencedetect` com remapeamento explícito, e
 *    não o fator linear do `gerar.mjs`: o corte não é uniforme ao longo do
 *    áudio, então escalar tudo pelo mesmo fator desloca o fim.
 *
 * Uso:
 *   node gerar-vsl.mjs            # gera narração + tempos.json
 *   SEED=7 node gerar-vsl.mjs     # outro take (o modelo é estocástico)
 *   node gerar-vsl.mjs --so-tempos  # recalcula tempos do áudio já baixado
 */
import { execFileSync, spawnSync } from 'node:child_process'
import { writeFileSync, readFileSync, existsSync, mkdirSync } from 'node:fs'
import { homedir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const RAIZ = path.dirname(fileURLToPath(import.meta.url))
const SRC = path.join(RAIZ, 'src', 'vsl')
const PUB = path.join(RAIZ, 'public', 'vsl', 'audio')
const BRUTOS = path.join(RAIZ, 'audio-bruto')

const FPS = 30
const SEED = Number(process.env.SEED || 42)
const SO_TEMPOS = process.argv.includes('--so-tempos')

// Deixa esta folga de respiração em cada pausa. Cortar tudo cola as palavras
// e a locução perde a prosódia que o modelo escolheu.
const PAUSA = Number(process.env.PAUSA || 0.18)
const LIMIAR = process.env.LIMIAR || '-38dB'
const CAUDA_FINAL = 0.9

const { falas, textoCompleto, VOZ } = await import(path.join(SRC, 'narracao.js'))

mkdirSync(PUB, { recursive: true })
mkdirSync(BRUTOS, { recursive: true })

const bruto = path.join(BRUTOS, 'vsl.mp3')
const alinhamento = path.join(BRUTOS, 'vsl.json')
const finalMp3 = path.join(PUB, 'narracao.mp3')

// ---------------------------------------------------------------- narração

if (!SO_TEMPOS) {
  const KEY = execFileSync('node', [path.join(homedir(), '.claude/scripts/elevenlabs-key.mjs')],
    { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] }).trim()

  console.error(`==> ${falas.length} falas · ${textoCompleto.length} caracteres · seed ${SEED}`)
  console.error(`    voz ${VOZ.id} · ${VOZ.modelo} · stability ${VOZ.ajustes.stability}`)

  const r = await fetch(
    `https://api.elevenlabs.io/v1/text-to-speech/${VOZ.id}/with-timestamps`, {
      method: 'POST',
      headers: { 'xi-api-key': KEY, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        text: textoCompleto,
        model_id: VOZ.modelo,
        voice_settings: VOZ.ajustes,
        // Nada é expandido automaticamente: o roteiro já vem por extenso.
        apply_text_normalization: 'off',
        seed: SEED
      })
    })
  if (!r.ok) {
    console.error(`ElevenLabs ${r.status}: ${(await r.text()).slice(0, 400)}`)
    process.exit(1)
  }
  const d = await r.json()
  writeFileSync(bruto, Buffer.from(d.audio_base64, 'base64'))
  writeFileSync(alinhamento, JSON.stringify(d.alignment))
  console.error(`==> bruto: ${bruto}`)
}

if (!existsSync(bruto)) { console.error(`erro: falta ${bruto}`); process.exit(1) }
const al = JSON.parse(readFileSync(alinhamento, 'utf8'))

// ---------------------------------------------------------------- pausas

// 12% do primeiro take era silêncio. Cortamos as pausas longas e remapeamos
// os timestamps: sem isso a animação seguiria os tempos do áudio original e o
// vídeo inteiro descolaria da fala.
const detec = spawnSync('ffmpeg', ['-hide_banner', '-v', 'info', '-i', bruto,
  '-af', `silencedetect=n=${LIMIAR}:d=${PAUSA}`, '-f', 'null', '-'],
  { encoding: 'utf8' }).stderr || ''

const cortados = []
{
  const re = /silence_start: ([\d.]+)[\s\S]*?silence_end: ([\d.]+)/g
  let m
  while ((m = re.exec(detec))) {
    const de = parseFloat(m[1]), ate = parseFloat(m[2])
    if (ate - de > PAUSA) cortados.push({ de: de + PAUSA / 2, ate: ate - PAUSA / 2 })
  }
}

if (cortados.length) {
  const trechos = []
  let pos = 0
  for (const c of cortados) { if (c.de > pos) trechos.push([pos, c.de]); pos = c.ate }
  trechos.push([pos, 1e6])
  const filtro = trechos.map(([a, b], i) =>
    `[0:a]atrim=start=${a.toFixed(3)}${b < 1e6 ? `:end=${b.toFixed(3)}` : ''},asetpts=PTS-STARTPTS[p${i}]`
  ).join(';') + ';' + trechos.map((_, i) => `[p${i}]`).join('') +
    // -14 LUFS é o padrão de vídeo web. O master anterior da VSL saiu a -24.6,
    // 10 dB abaixo: quem assistia achava que o som estava baixo.
    `concat=n=${trechos.length}:v=0:a=1,loudnorm=I=-14:TP=-1.5:LRA=11[out]`
  execFileSync('ffmpeg', ['-y', '-v', 'error', '-i', bruto, '-filter_complex', filtro,
    '-map', '[out]', '-c:a', 'libmp3lame', '-q:a', '2', finalMp3], { stdio: 'inherit' })
} else {
  execFileSync('ffmpeg', ['-y', '-v', 'error', '-i', bruto,
    '-af', 'loudnorm=I=-14:TP=-1.5:LRA=11', '-c:a', 'libmp3lame', '-q:a', '2', finalMp3],
    { stdio: 'inherit' })
}

/** Quanto tempo foi removido antes do instante `t` do áudio bruto. */
const desloca = (t) => {
  let fora = 0
  for (const c of cortados) {
    if (t >= c.ate) fora += c.ate - c.de
    else if (t > c.de) fora += t - c.de
  }
  return fora
}
console.error(`==> ${cortados.length} pausas cortadas (${desloca(1e6).toFixed(1)}s)`)

// ---------------------------------------------------------------- cortes

const devolvido = (al.characters || []).join('')
const fins = al.character_end_times_seconds.map((t) => t - desloca(t))

/**
 * Índice, no texto devolvido, do fim da fala.
 * Nunca use `fala.length` como cursor: o array `characters` não é idêntico ao
 * texto enviado e o erro acumula. Casamos pela cauda de 3 palavras, sem as
 * [tags] — que colapsam para ~0.06s mas ocupam índices.
 */
function fimDaFala(fala, de) {
  const limpa = fala.replace(/\[[^\]]*\]/g, ' ').replace(/\s+/g, ' ').trim()
  const palavras = limpa.split(' ').filter(Boolean)
  const tenta = (agulha) => {
    const i = devolvido.indexOf(agulha, de)
    return i < 0 ? -1 : i + agulha.length
  }
  let fim = tenta(palavras.slice(-3).join(' '))
  if (fim < 0) fim = tenta(palavras[palavras.length - 1])
  if (fim < 0) fim = Math.min(de + limpa.length, devolvido.length)
  return fim
}

// A TransitionSeries SOBREPÕE as cenas que une: uma passagem de 11 frames
// consome 11 frames das duas vizinhas. Somadas, as 34 passagens comiam 10.3s
// e a imagem adiantava progressivamente em relação à fala — o vídeo terminava
// 10s antes do áudio. Cada cena precisa carregar a metade da sobreposição de
// entrada e de saída para o corte cair no instante certo da locução.
const DURACAO_PASSAGEM = { whip: 6, estouro: 8, iris: 10, matchCut: 11, empurra: 9 }
const roteiroSrc = readFileSync(path.join(SRC, 'roteiro.js'), 'utf8')
const tipoPassagem = {}
for (const m of roteiroSrc.matchAll(/id:\s*(\d+),[\s\S]{0,400}?passagem:\s*\{\s*tipo:\s*'(\w+)'/g)) {
  tipoPassagem[Number(m[1])] = m[2]
}
const framesPassagem = (id) => DURACAO_PASSAGEM[tipoPassagem[id]] || 0

const cenas = []
let cursor = 0
let frameAnterior = 0

falas.forEach((f, i) => {
  const fimTexto = fimDaFala(f.fala, cursor)
  const fimFala = fins[Math.min(fimTexto - 1, fins.length - 1)]
  const ultima = i === falas.length - 1
  const inicioProxima = fins[Math.min(fimTexto, fins.length - 1)]
  // A cena troca no MEIO da respiração, não no fim exato da fala: a imagem
  // muda enquanto a locutora respira e o corte deixa de ser percebido.
  const corte = ultima ? fimFala + CAUDA_FINAL : fimFala + (inicioProxima - fimFala) / 2
  const frameCorte = Math.round(corte * FPS)

  // Compensa a sobreposição: a cena ganha de volta a passagem que a traz e a
  // metade da que a leva embora.
  const entra = framesPassagem(f.id)
  const sai = i < falas.length - 1 ? framesPassagem(falas[i + 1].id) : 0
  const bruto = frameCorte - frameAnterior
  cenas.push({ id: f.id, inicio: frameAnterior, frames: bruto + entra })

  frameAnterior = frameCorte
  cursor = fimTexto
})

const tempos = { audio: 'vsl/audio/narracao.mp3', fps: FPS, duracaoTotal: frameAnterior, cenas }
writeFileSync(path.join(SRC, 'tempos.json'), JSON.stringify(tempos, null, 1))

const segundos = frameAnterior / FPS
console.error(`==> ${finalMp3}`)
console.error(`==> ${cenas.length} cenas · ${segundos.toFixed(1)}s (${Math.floor(segundos / 60)}:${String(Math.round(segundos % 60)).padStart(2, '0')})`)

// Cena curta demais não dá tempo de ler o dado que ela carrega. O piso de 3s
// do roteiro existe por isso; aqui só avisamos, porque quem manda é a fala.
const curtas = cenas.filter((c) => c.frames < FPS * 1.2)
if (curtas.length) {
  console.error(`==> atenção: ${curtas.length} cena(s) abaixo de 1.2s: ${curtas.map((c) => c.id).join(', ')}`)
}

// Validação: o áudio final e a soma das cenas têm que bater.
const durAudio = Number(execFileSync('ffprobe',
  ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', finalMp3],
  { encoding: 'utf8' }).trim())
const desvio = Math.abs(durAudio - segundos)
console.error(`==> áudio ${durAudio.toFixed(1)}s vs cenas ${segundos.toFixed(1)}s · desvio ${desvio.toFixed(2)}s`)
if (desvio > CAUDA_FINAL + 0.5) {
  console.error('==> ERRO: desvio grande demais, o remapeamento saiu errado')
  process.exit(2)
}
