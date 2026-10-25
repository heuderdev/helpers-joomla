#!/usr/bin/env node
/**
 * gerar-narracao.mjs — gera a narração da VSL e os tempos de cada fala.
 *
 * Um request só para o roteiro inteiro (frase isolada soa como leitor de tela),
 * e os cortes saem dos timestamps por caractere que a própria API devolve.
 */
import { execFileSync, spawnSync } from 'node:child_process'
import { writeFileSync, mkdirSync } from 'node:fs'
import { homedir } from 'node:os'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const AQUI = path.dirname(fileURLToPath(import.meta.url))
const { falas, textoCompleto, VOZ } = await import(path.join(AQUI, 'narracao.js'))

const SEED = Number(process.env.SEED || 42)
const OUT = path.join(AQUI, 'audio')
mkdirSync(OUT, { recursive: true })

const KEY = execFileSync('node', [path.join(homedir(), '.claude/scripts/elevenlabs-key.mjs')],
  { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] }).trim()

console.error(`==> ${falas.length} falas, ${textoCompleto.length} caracteres, seed ${SEED}`)

const r = await fetch(`https://api.elevenlabs.io/v1/text-to-speech/${VOZ.id}/with-timestamps`, {
  method: 'POST',
  headers: { 'xi-api-key': KEY, 'Content-Type': 'application/json' },
  body: JSON.stringify({
    text: textoCompleto,
    model_id: VOZ.modelo,
    voice_settings: VOZ.ajustes,
    apply_text_normalization: 'off',
    seed: SEED
  })
})
if (!r.ok) { console.error(`ElevenLabs ${r.status}: ${(await r.text()).slice(0, 400)}`); process.exit(1) }

const d = await r.json()
const bruto = path.join(OUT, `vsl-${SEED}.bruto.mp3`)
const mp3 = path.join(OUT, `vsl-${SEED}.mp3`)
writeFileSync(bruto, Buffer.from(d.audio_base64, 'base64'))
writeFileSync(path.join(OUT, `vsl-${SEED}.alignment.json`), JSON.stringify(d.alignment))

// --- corte de pausas -------------------------------------------------------
// 12% do primeiro take era silêncio. Cortamos as pausas longas e REMAPEAMOS os
// timestamps: sem isso a animação seguiria os tempos do áudio antigo e todo o
// vídeo descolaria da fala. Fator linear não serve — o corte não é uniforme.
const PAUSA_MAX = Number(process.env.PAUSA || 0.18)
const LIMIAR = process.env.LIMIAR || '-38dB'

const detec = spawnSync('ffmpeg', ['-hide_banner', '-v', 'info', '-i', bruto,
  '-af', `silencedetect=n=${LIMIAR}:d=${PAUSA_MAX}`, '-f', 'null', '-'],
  { encoding: 'utf8' }).stderr || ''   // o silencedetect escreve em stderr

const cortados = []
{
  const re = /silence_start: ([\d.]+)[\s\S]*?silence_end: ([\d.]+)/g
  let m
  while ((m = re.exec(detec))) {
    const de = parseFloat(m[1]), ate = parseFloat(m[2])
    // Deixa PAUSA_MAX de respiração: cortar tudo cola as palavras.
    if (ate - de > PAUSA_MAX) cortados.push({ de: de + PAUSA_MAX / 2, ate: ate - PAUSA_MAX / 2 })
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
    `concat=n=${trechos.length}:v=0:a=1,loudnorm=I=-14:TP=-1.5:LRA=11[out]`
  execFileSync('ffmpeg', ['-y', '-v', 'error', '-i', bruto, '-filter_complex', filtro,
    '-map', '[out]', '-c:a', 'libmp3lame', '-q:a', '2', mp3], { stdio: 'inherit' })
} else {
  execFileSync('ffmpeg', ['-y', '-v', 'error', '-i', bruto,
    '-af', 'loudnorm=I=-14:TP=-1.5:LRA=11', '-c:a', 'libmp3lame', '-q:a', '2', mp3], { stdio: 'inherit' })
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

// --- cortes por fala -------------------------------------------------------
// O array devolvido não é idêntico ao enviado, então o cursor não pode ser
// narracao.length: casamos pela cauda de 3 palavras, sem as [tags].
const al = d.alignment
const devolvido = (al.characters || []).join('')
const fins = al.character_end_times_seconds.map((t) => t - desloca(t))

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

const CAUDA_FINAL = 0.9
const cortes = []
let cursor = 0

falas.forEach((f, i) => {
  const fimTexto = fimDaFala(f.fala, cursor)
  const fimFala = fins[Math.min(fimTexto - 1, fins.length - 1)]
  const ultima = i === falas.length - 1
  const inicioProxima = fins[Math.min(fimTexto, fins.length - 1)]
  // Corta no MEIO da respiração, não no fim exato da fala: a imagem troca
  // enquanto a locutora respira, e o corte deixa de ser percebido.
  const corte = ultima ? fimFala + CAUDA_FINAL : fimFala + (inicioProxima - fimFala) / 2
  cortes.push({
    id: f.id,
    inicio: +(cortes.length ? cortes[cortes.length - 1].fim : 0).toFixed(3),
    fim: +corte.toFixed(3),
    dur: +(corte - (cortes.length ? cortes[cortes.length - 1].fim : 0)).toFixed(3),
    fala: f.fala
  })
  cursor = fimTexto
})

const total = cortes[cortes.length - 1].fim
writeFileSync(path.join(AQUI, 'tempos.json'),
  JSON.stringify({ voz: VOZ.id, seed: SEED, audio: path.basename(mp3), total, cortes }, null, 1))

console.error(`==> ${mp3}`)
console.error(`==> total ${total.toFixed(1)}s (${Math.floor(total / 60)}:${String(Math.round(total % 60)).padStart(2, '0')})`)
const curtas = cortes.filter((c) => c.dur < 1.2)
if (curtas.length) console.error(`==> atenção: ${curtas.length} fala(s) abaixo de 1.2s: ${curtas.map((c) => c.id).join(', ')}`)
console.log(mp3)
