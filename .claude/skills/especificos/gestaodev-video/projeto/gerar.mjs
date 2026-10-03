#!/usr/bin/env node
/**
 * Gera a narração de UM roteiro da série e atualiza o índice de composições.
 *
 * Uso:
 *   node gerar.mjs proposta          # gera o que falta
 *   node gerar.mjs proposta --forcar # regera mesmo se já existe
 *   node gerar.mjs --todos           # todos os roteiros de roteiros/
 *
 * Pipeline por roteiro:
 *   texto contínuo → ElevenLabs (com timestamps) → corte de vales → tempos.json
 *
 * Três decisões que já custaram retrabalho e não devem ser revertidas:
 *
 * 1. A fala vai num request SÓ. Frase isolada é entoada como sentença fechada,
 *    e oito delas seguidas soam como leitor de tela.
 * 2. O corte de vales é feito pelo auto-editor, não pelo `silencedetect` do
 *    ffmpeg: o ElevenLabs deixa ruído de fundo nas pausas, então o detector do
 *    ffmpeg não enxerga nada nem a -22dB, enquanto há ~25% de vales no áudio.
 * 3. A cena troca no MEIO do intervalo entre falas, não no fim exato da atual.
 *    A imagem muda enquanto o locutor respira.
 */
import { readFileSync, writeFileSync, mkdirSync, existsSync, unlinkSync, readdirSync } from 'fs'
import { execFileSync } from 'child_process'
import { homedir } from 'os'
import path from 'path'

const RAIZ = path.dirname(new URL(import.meta.url).pathname)
const ROTEIROS = path.join(RAIZ, 'roteiros')
const AUDIO = path.join(RAIZ, 'public/audio')
const SRC = path.join(RAIZ, 'src')
// Áudio ORIGINAL do TTS, preservado: reajustar o corte de vales não deve
// custar crédito de novo. `--recortar` reprocessa a partir daqui.
const BRUTOS = path.join(RAIZ, 'audio-bruto')
const FPS = 30

const FORCAR = process.argv.includes('--forcar')
const LIMIAR = process.env.LIMIAR || '0.03'
// 0.16s devolve ~0,3s de respiro em volta de cada trecho: fala humana tem
// micro-pausa entre frases; o que ela não tem é vogal arrastada.
const MARGEM = process.env.MARGEM || '0.08s'
/*
 * Chave da ElevenLabs: escolhida pelo helper, que percorre `~/.elevenlabs-keys`
 * e devolve a primeira conta com saldo. Quando uma zera, a próxima entra
 * sozinha. O `~/.elevenlabs-key` antigo segue como fallback.
 */
function chaveElevenLabs() {
  const helper = path.join(homedir(), '.claude/scripts/elevenlabs-key.mjs')
  try {
    return execFileSync('node', [helper], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] }).trim()
  } catch {
    return readFileSync(path.join(homedir(), '.elevenlabs-key'), 'utf8').trim()
  }
}

const KEY = chaveElevenLabs()
const AUTO_EDITOR = path.join(homedir(), '.local/bin/auto-editor')

function duracaoDe(arquivo) {
  return parseFloat(execFileSync('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', arquivo]).toString().trim())
}

async function gerarRoteiro(id) {
  const roteiro = JSON.parse(readFileSync(path.join(ROTEIROS, `${id}.json`), 'utf8'))
  const arquivoAudio = path.join(AUDIO, `${id}.mp3`)
  const bruto = path.join(BRUTOS, `${id}.mp3`)
  const arquivoTs = path.join(RAIZ, `timestamps-${id}.json`)

  mkdirSync(AUDIO, { recursive: true })

  const texto = roteiro.cenas.map((c) => c.narracao).join(' ')

  const soRecortar = process.argv.includes('--recortar') && existsSync(bruto)

  if (soRecortar) {
    execFileSync(AUTO_EDITOR, [bruto, '--edit', `audio:threshold=${LIMIAR}`, '--margin', MARGEM, '-o', arquivoAudio, '--no-open'], { stdio: 'ignore' })
    const al0 = JSON.parse(readFileSync(path.join(BRUTOS, `${id}.json`), 'utf8'))
    const fator = duracaoDe(arquivoAudio) / duracaoDe(bruto)
    al0.character_end_times_seconds = al0.character_end_times_seconds.map((t) => t * fator)
    al0.character_start_times_seconds = al0.character_start_times_seconds.map((t) => t * fator)
    writeFileSync(arquivoTs, JSON.stringify(al0, null, 2))
    process.stderr.write(`[${id}] recorte refeito (margem ${MARGEM})\n`)
  } else if (FORCAR || !existsSync(arquivoAudio) || !existsSync(arquivoTs)) {
    process.stderr.write(`[${id}] gerando ${texto.length} caracteres...\n`)

    const resposta = await fetch(`https://api.elevenlabs.io/v1/text-to-speech/${roteiro.vozEleven}/with-timestamps`, {
      method: 'POST',
      headers: { 'xi-api-key': KEY, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        text: texto,
        model_id: 'eleven_multilingual_v2',
        voice_settings: { stability: 0.32, similarity_boost: 0.8, style: 0.42, use_speaker_boost: true }
      })
    })

    if (!resposta.ok) throw new Error(`[${id}] ElevenLabs ${resposta.status}: ${await resposta.text()}`)

    const dados = await resposta.json()
    mkdirSync(BRUTOS, { recursive: true })
    writeFileSync(bruto, Buffer.from(dados.audio_base64, 'base64'))
    writeFileSync(path.join(BRUTOS, `${id}.json`), JSON.stringify(dados.alignment, null, 2))

    /*
     * O corte precisa tirar a arrastada SEM zerar o respiro.
     *
     * Com `threshold=0.035` e `margin 0.04s` o resultado tinha ZERO pausas
     * acima de 0,05s — ou seja, a fala ficava sem ar nenhum entre as frases, o
     * que soa tão mecânico quanto o silêncio grande demais do começo. Fala
     * humana tem micro-pausa; o que ela não tem é vogal arrastada.
     *
     * `margin 0.16s` devolve ~0,3s de respiro em volta de cada trecho falado
     * (0,16 antes + 0,16 depois), preservando a pontuação da frase, e o limiar
     * mais baixo só remove os vales realmente longos.
     */
    execFileSync(AUTO_EDITOR, [bruto, '--edit', `audio:threshold=${LIMIAR}`, '--margin', MARGEM, '-o', arquivoAudio, '--no-open'], { stdio: 'ignore' })

    const antes = duracaoDe(bruto)
    const depois = duracaoDe(arquivoAudio)
    const fator = depois / antes

    const al = dados.alignment
    al.character_end_times_seconds = al.character_end_times_seconds.map((t) => t * fator)
    al.character_start_times_seconds = al.character_start_times_seconds.map((t) => t * fator)

    writeFileSync(arquivoTs, JSON.stringify(al, null, 2))
    process.stderr.write(`[${id}] ${antes.toFixed(1)}s → ${depois.toFixed(1)}s\n`)
  }

  // ---- cortes derivados dos timestamps
  const al = JSON.parse(readFileSync(arquivoTs, 'utf8'))
  const fins = al.character_end_times_seconds

  const cenas = []
  let cursor = 0
  let frameAnterior = 0

  for (const [i, cena] of roteiro.cenas.entries()) {
    cursor += cena.narracao.length
    const fimFala = fins[Math.min(cursor - 1, fins.length - 1)]
    const ehUltima = i === roteiro.cenas.length - 1

    const corte = ehUltima ? fimFala + (roteiro.caudaFinal ?? 0.8) : fimFala + (fins[Math.min(cursor, fins.length - 1)] - fimFala) / 2

    const frameCorte = Math.round(corte * FPS)

    cenas.push({
      id: cena.id,
      inicio: frameAnterior,
      frames: frameCorte - frameAnterior,
      narracao: cena.narracao,
      // Tudo que o montador precisa para desenhar a cena.
      conteudo: cena.conteudo
    })

    frameAnterior = frameCorte
    cursor += 1 // o espaço que separa as frases no texto contínuo
  }

  const tempos = { fps: FPS, duracaoTotal: frameAnterior, audioUnico: `audio/${id}.mp3`, cenas }
  writeFileSync(path.join(SRC, `tempos-${id}.json`), JSON.stringify(tempos, null, 2))

  const palavras = texto.split(/\s+/).length
  const seg = frameAnterior / FPS
  console.log(`${id.padEnd(12)} ${seg.toFixed(1)}s · ${palavras} palavras · ${Math.round((palavras / seg) * 60)} ppm`)

  return { id: roteiro.composicao || id, tempos }
}

const alvos = process.argv.includes('--todos')
  ? readdirSync(ROTEIROS)
      .filter((n) => n.endsWith('.json'))
      .map((n) => n.replace('.json', ''))
  : [process.argv[2]].filter((a) => a && !a.startsWith('--'))

if (!alvos.length) throw new Error('Informe o roteiro (ex: node gerar.mjs proposta) ou --todos')

const gerados = []
for (const id of alvos) gerados.push(await gerarRoteiro(id))

/*
 * O índice acumula: gerar um roteiro sozinho não pode apagar as composições
 * dos outros, senão renderizar em lote exigiria regerar tudo.
 */
const caminhoIndice = path.join(SRC, 'indice.json')
const anterior = existsSync(caminhoIndice) ? JSON.parse(readFileSync(caminhoIndice, 'utf8')).videos : []
const mapa = new Map(anterior.map((v) => [v.id, v]))
for (const v of gerados) mapa.set(v.id, v)

writeFileSync(caminhoIndice, JSON.stringify({ videos: [...mapa.values()] }, null, 2))
console.log(`\níndice: ${mapa.size} composições`)
