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
import { readFileSync, writeFileSync, mkdirSync, existsSync, unlinkSync, readdirSync, copyFileSync } from 'fs'
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

  /*
   * O CORTE DE SILÊNCIO É OPT-IN e por padrão fica DESLIGADO (`CORTAR=1` liga).
   *
   * O `auto-editor` foi feito para gravação humana, onde há hesitação e tempo
   * morto de verdade. Num TTS não existe dead air acidental: os vales entre as
   * orações SÃO a prosódia que o modelo escolheu — cortá-los foi uma das causas
   * da locução sair entrecortada.
   *
   * Há um segundo motivo, mais grave: o remapeamento aplica um FATOR LINEAR
   * (depois/antes) aos timestamps, mas o corte remove silêncio em pontos
   * específicos — a compressão não é uniforme ao longo do áudio. Com o corte
   * ligado, a animação dessincroniza progressivamente da fala. Sem corte,
   * `fator` é 1 e os tempos ficam exatos.
   */
  const cortar = process.env.CORTAR === '1'

  function prepararAudio() {
    if (cortar) {
      execFileSync(AUTO_EDITOR, [bruto, '--edit', `audio:threshold=${LIMIAR}`, '--margin', MARGEM, '-o', arquivoAudio, '--no-open'], { stdio: 'ignore' })
    } else {
      copyFileSync(bruto, arquivoAudio)
    }
    return duracaoDe(arquivoAudio) / duracaoDe(bruto)
  }

  /*
   * Remapeia os timestamps para o áudio cortado.
   *
   * O fator linear (depois/antes) está ERRADO quando há corte: o silêncio sai
   * em pontos específicos, então a compressão não é uniforme e o desvio cresce
   * ao longo do arquivo — era o que fazia a última cena não encaixar na fala.
   *
   * A fonte de verdade é o próprio auto-editor: `--export v1` devolve os
   * `chunks` como [inicioFrame, fimFrame, velocidade], onde velocidade 99999
   * marca o trecho descartado. Com isso dá para saber exatamente quanto tempo
   * foi removido ANTES de cada instante — que é o deslocamento correto.
   */
  function remapear(al) {
    const saidaV1 = path.join(RAIZ, `.cortes-${id}.json`)
    execFileSync(AUTO_EDITOR, [bruto, '--edit', `audio:threshold=${LIMIAR}`, '--margin', MARGEM, '--export', 'v1', '-o', saidaV1, '--no-open'], { stdio: 'ignore' })

    const { chunks } = JSON.parse(readFileSync(saidaV1, 'utf8'))
    unlinkSync(saidaV1)

    /* Os chunks vêm em frames do timebase do auto-editor (30fps por padrão). */
    const TB = 30
    const cortados = chunks
      .filter((c) => c[2] >= 99999)
      .map(([a, b]) => ({ de: a / TB, ate: b / TB }))

    /** Quanto tempo foi removido antes do instante `t` do áudio original. */
    const desloca = (t) => {
      let fora = 0
      for (const c of cortados) {
        if (t >= c.ate) fora += c.ate - c.de
        else if (t > c.de) fora += t - c.de
      }
      return fora
    }

    return {
      ...al,
      character_start_times_seconds: al.character_start_times_seconds.map((t) => t - desloca(t)),
      character_end_times_seconds: al.character_end_times_seconds.map((t) => t - desloca(t))
    }
  }


  if (soRecortar) {
    prepararAudio()
    const bruto0 = JSON.parse(readFileSync(path.join(BRUTOS, `${id}.json`), 'utf8'))
    writeFileSync(arquivoTs, JSON.stringify(cortar ? remapear(bruto0) : bruto0, null, 2))
    process.stderr.write(`[${id}] recorte refeito (margem ${MARGEM})\n`)
  } else if (FORCAR || !existsSync(arquivoAudio) || !existsSync(arquivoTs)) {
    process.stderr.write(`[${id}] gerando ${texto.length} caracteres...\n`)

    const resposta = await fetch(`https://api.elevenlabs.io/v1/text-to-speech/${roteiro.vozEleven}/with-timestamps`, {
      method: 'POST',
      headers: { 'xi-api-key': KEY, 'Content-Type': 'application/json' },
      body: JSON.stringify({
        text: texto,
        /*
         * O `eleven_v3` É suportado neste endpoint e devolve `alignment`
         * válido — testado na API, embora a doc não o liste aqui. Isso importa
         * porque o v3 é a única alavanca real de expressividade que resta: o
         * multilingual_v2 não modela estado emocional, só produz uma leitura
         * prosodicamente correta e UNIFORME. Nenhum ajuste de stability/speed
         * cria variação de energia ou ataque de frase, porque o modelo não tem
         * representação disso — era o teto que fazia a locução soar "lida".
         *
         * Bônus: o alignment do v3 vem 1:1 com o texto enviado (o v2 normaliza
         * e devolve menos caracteres, o que exigia casar por texto).
         */
        model_id: roteiro.modelo || 'eleven_v3',
        /*
         * Calibrado para locução publicitária em pt-BR, voz nativa, modelo v3.
         *
         * `stability: 0.45` — ≤0.5 é OBRIGATÓRIO no v3: em "Robust" (valor
         * alto) o modelo SUPRIME as audio tags e as marcações de direção do
         * roteiro simplesmente não têm efeito.
         *
         * Sem `speed`. Ele condiciona a geração e comprime as excursões de
         * entonação — a 1.10 (topo da faixa) estava achatando a melodia
         * justamente enquanto se tentava ganhar fluidez. Locução publicitária
         * ganha energia por CONTRASTE (acelera no benefício, respira antes do
         * CTA), e isso vem das tags e da pontuação, não de velocidade constante.
         *
         * Sem `style`. A própria ElevenLabs recomenda zero: a voz já carrega o
         * estilo da locutora, e o slider empilhava ênfase artificial por cima,
         * criando picos de entonação desconectados entre si.
         */
        voice_settings: roteiro.voz || { stability: 0.45, similarity_boost: 0.8, use_speaker_boost: true },
        /*
         * Desligada: o roteiro JÁ escreve tudo por extenso ("cinquenta reais",
         * "pê dê efe"), então a normalização — treinada majoritariamente em
         * inglês — é uma etapa que só pode errar.
         */
        apply_text_normalization: 'off',
        /*
         * Seed FIXO garante reprodutibilidade, mas tranca numa única tomada — e
         * o modelo é estocástico: a mesma frase variou de 8,6s a 11,2s entre
         * seeds, com diferença audível de entrega. Locutor humano grava vários
         * takes e o diretor escolhe; `SEED=n` faz o mesmo aqui.
         *
         * Escolher take = pegar o melhor take INTEIRO. Não costure trechos de
         * takes diferentes: reintroduz o "cada frase num take" que já custou
         * retrabalho.
         */
        seed: Number(process.env.SEED || roteiro.seed || 42)
      })
    })

    if (!resposta.ok) throw new Error(`[${id}] ElevenLabs ${resposta.status}: ${await resposta.text()}`)

    const dados = await resposta.json()
    mkdirSync(BRUTOS, { recursive: true })
    writeFileSync(bruto, Buffer.from(dados.audio_base64, 'base64'))
    writeFileSync(path.join(BRUTOS, `${id}.json`), JSON.stringify(dados.alignment, null, 2))

    prepararAudio()

    const al = cortar ? remapear(dados.alignment) : dados.alignment

    writeFileSync(arquivoTs, JSON.stringify(al, null, 2))
    process.stderr.write(`[${id}] ${duracaoDe(bruto).toFixed(1)}s → ${duracaoDe(arquivoAudio).toFixed(1)}s${cortar ? ' (cortado)' : ''}\n`)
  }

  // ---- cortes derivados dos timestamps
  const al = JSON.parse(readFileSync(arquivoTs, 'utf8'))
  const fins = al.character_end_times_seconds

  /*
   * O cursor precisa andar pelo texto QUE O MODELO DEVOLVEU, não pelo texto do
   * roteiro.
   *
   * O alignment vem com um array `characters` — e ele NÃO é idêntico ao texto
   * enviado: o modelo normaliza. No roteiro de 992 caracteres, voltaram 958.
   * Contar `cena.narracao.length` sobre esse array desloca o cursor um pouco a
   * cada cena, o erro acumula, e no fim do vídeo os cortes já não batem com a
   * fala — foi o que fez a última cena não encaixar.
   *
   * A correção é procurar cada narração dentro do texto devolvido, ignorando o
   * que difere (espaços, pontuação normalizada). Assim o índice é sempre o real.
   */
  const devolvido = (al.characters || []).join('')

  /** Índice, no texto devolvido, do fim da narração desta cena. */
  function fimDaCena(narracao, de) {
    /*
     * Casa pelas últimas palavras da narração: são o que identifica o ponto de
     * corte, e sobrevivem melhor à normalização do que a frase inteira.
     */
    /*
     * Remove tags de direção antes de casar com o texto devolvido.
     *
     * As audio tags do v3 (`[animada]`, `[pausa]`) NÃO são faladas, mas ainda
     * ocupam índices no alignment. Procurar a frase com a tag dentro nunca
     * casaria; e casar pela cauda (últimas palavras) já pula a tag, que fica
     * sempre no início do trecho.
     */
    const limpa = narracao.replace(/<[^>]*>/g, ' ').replace(/\[[^\]]*\]/g, ' ').trim()
    const palavras = limpa.split(/\s+/).filter(Boolean)
    const cauda = palavras.slice(-3).join(' ')

    const acha = (agulha) => {
      const alvo = agulha.replace(/\s+/g, ' ')
      const idx = devolvido.indexOf(alvo, de)
      return idx < 0 ? -1 : idx + alvo.length
    }

    let fim = acha(cauda)
    if (fim < 0) fim = acha(palavras[palavras.length - 1])
    if (fim < 0) fim = Math.min(de + limpa.length, devolvido.length)
    return fim
  }

  const cenas = []
  let cursor = 0
  let frameAnterior = 0

  for (const [i, cena] of roteiro.cenas.entries()) {
    const fimTexto = fimDaCena(cena.narracao, cursor)
    const fimFala = fins[Math.min(fimTexto - 1, fins.length - 1)]
    const ehUltima = i === roteiro.cenas.length - 1

    /*
     * O corte cai no MEIO do intervalo entre o fim desta fala e o início da
     * próxima: a imagem troca enquanto a locutora respira, não no instante
     * exato em que a palavra termina.
     */
    const inicioProxima = fins[Math.min(fimTexto, fins.length - 1)]
    const corte = ehUltima ? fimFala + (roteiro.caudaFinal ?? 0.8) : fimFala + (inicioProxima - fimFala) / 2

    const frameCorte = Math.round(corte * FPS)

    cenas.push({
      id: cena.id,
      inicio: frameAnterior,
      frames: frameCorte - frameAnterior,
      narracao: cena.narracao,
      conteudo: cena.conteudo,
      // a transição de entrada desta cena viaja junto: o Video.jsx lê daqui
      transicao: cena.transicao
    })

    frameAnterior = frameCorte
    cursor = fimTexto
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
