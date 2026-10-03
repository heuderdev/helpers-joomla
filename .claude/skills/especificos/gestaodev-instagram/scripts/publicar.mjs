#!/usr/bin/env node
/**
 * Agendador de posts do @gestao.dev — substitui o Postiz.
 *
 * A Graph API do Instagram não agenda: ela publica na hora em que é chamada.
 * Quem agenda, portanto, é a máquina. Este script roda de 10 em 10 minutos
 * pelo cron, olha a fila em `posts/` e publica o que já passou da hora.
 *
 * Cada post é um .json em ~/.gestaodev-social/posts/:
 *   {
 *     "quando": "2026-08-16T09:00",   // horário LOCAL; publica a partir daí
 *     "pasta":  "/caminho/com/os/jpg", // 2 a 10 arquivos, ordem alfabética
 *     "legenda": "..."
 *   }
 *
 * Publicado, o arquivo é movido para `publicados/` com o resultado dentro —
 * é isso que impede o mesmo post de sair duas vezes se o cron reentrar.
 *
 * Uso manual:
 *   node publicar.mjs           # processa a fila (o que o cron faz)
 *   node publicar.mjs --agora <post.json>   # publica ignorando o horário
 *   node publicar.mjs --listar  # mostra a fila
 */
import { readFileSync, readdirSync, writeFileSync, renameSync, existsSync, appendFileSync } from 'fs'
import { pathToFileURL } from 'url'
import { createHash } from 'crypto'
import { homedir } from 'os'
import path from 'path'

const BASE = path.join(homedir(), '.gestaodev-social')
const FILA = path.join(BASE, 'posts')
const FEITOS = path.join(BASE, 'publicados')
const LOG = path.join(BASE, 'logs', 'publicacoes.log')

const API = 'https://graph.facebook.com/v21.0'
const API_UPLOAD = '/Users/eduardolecdt/Empresas/Gestão Dev/Repositórios/backend/api-upload'

const TOKEN = readFileSync(path.join(homedir(), '.gestaodev-ig-token'), 'utf8').trim()
const IG_ID = readFileSync(path.join(homedir(), '.gestaodev-ig-id'), 'utf8').trim()

function registrar(msg) {
  const linha = `[${new Date().toISOString()}] ${msg}\n`
  process.stderr.write(linha)
  appendFileSync(LOG, linha)
}

// ---------------------------------------------------------------- Spaces

function lerEnvUpload() {
  const bruto = readFileSync(path.join(API_UPLOAD, '.env'), 'utf8')
  const env = {}
  for (const linha of bruto.split('\n')) {
    const igual = linha.indexOf('=')
    if (igual < 1 || linha.trim().startsWith('#')) continue
    env[linha.slice(0, igual).trim()] = linha.slice(igual + 1).trim().replace(/^["']|["']$/g, '')
  }
  return env
}

/*
 * Sobe direto pro S3 em vez de usar a rota /storage/imagem da api-upload.
 *
 * Aquela rota passa pelo middleware `converterWebp` e devolve sempre um .webp,
 * e o Content Publishing do Instagram só aceita JPEG. A rota /storage/arquivo
 * preserva o formato, mas a whitelist dela é PDF/XML/JSON/CSV/TXT.
 *
 * Então: mesmas credenciais, mesmo bucket, pasta `social/` separada.
 */
/*
 * O SDK da AWS é carregado do node_modules da api-upload, por caminho absoluto.
 *
 * A skill mora em ~/.claude/skills e não tem node_modules próprio; um `import`
 * estático de '@aws-sdk/client-s3' quebra na hora de resolver o pacote (e
 * NODE_PATH não vale para ESM). Import dinâmico com file:// resolve, e ainda
 * mantém o SDK fora do caminho de `--listar`, que nem toca no S3.
 */
async function carregarS3() {
  const alvo = path.join(API_UPLOAD, 'node_modules/@aws-sdk/client-s3/dist-es/index.js')
  if (!existsSync(alvo)) {
    throw new Error(`SDK da AWS não encontrado em ${alvo}. Rode "pnpm install" na api-upload.`)
  }
  return import(pathToFileURL(alvo).href)
}

async function subirImagens(pasta) {
  const { S3Client, PutObjectCommand } = await carregarS3()
  const env = lerEnvUpload()
  const s3 = new S3Client({
    endpoint: `https://${env.S3_ENDPOINT}`,
    credentials: { accessKeyId: env.S3_ACCESS_KEY, secretAccessKey: env.S3_SECRET_KEY },
    region: 'us-east-1'
  })

  const arquivos = readdirSync(pasta)
    .filter((n) => /\.jpe?g$/i.test(n))
    .sort()

  if (arquivos.length < 2 || arquivos.length > 10) {
    throw new Error(`Carrossel aceita de 2 a 10 imagens — ${pasta} tem ${arquivos.length}`)
  }

  const urls = []
  for (const nome of arquivos) {
    const conteudo = readFileSync(path.join(pasta, nome))
    // Hash do conteúdo no nome: republicar o mesmo criativo reaproveita a URL
    // em vez de acumular duplicata no bucket.
    const hash = createHash('sha1').update(conteudo).digest('hex').slice(0, 12)
    const chave = `social/gestaodev/${hash}-${nome}`

    await s3.send(new PutObjectCommand({ Bucket: env.S3_BUCKET, ACL: 'public-read', Body: conteudo, ContentType: 'image/jpeg', Key: chave }))
    urls.push(`https://${env.S3_BUCKET}.${env.S3_ENDPOINT}/${chave}`)
  }
  return urls
}

// ---------------------------------------------------------------- Instagram

/*
 * Travessao, seta e afins sao PROIBIDOS na legenda (decisao do Eduardo).
 *
 * Alem de nao ser como ele escreve, esse e o traco tipografico que mais
 * denuncia texto gerado por IA. A checagem roda ANTES de subir qualquer
 * imagem, porque a Graph API nao edita legenda de post ja publicado: se
 * passar, so da pra corrigir na mao pelo app.
 */
const PROIBIDOS = Object.freeze([
  { simbolo: '—', nome: 'travessao (em dash)', troca: 'virgula, dois-pontos ou reescrever a frase' },
  { simbolo: '–', nome: 'meia-risca (en dash)', troca: '"a" ou "ate" em intervalos' },
  { simbolo: '→', nome: 'seta', troca: 'nada, ou hifen simples se for lista' },
  { simbolo: '•', nome: 'bullet', troca: 'quebra de linha' }
])

function validarLegenda(legenda) {
  if (typeof legenda !== 'string' || !legenda.trim()) throw new Error('Post sem legenda')

  const achados = PROIBIDOS.filter(({ simbolo }) => legenda.includes(simbolo))
  if (achados.length) {
    const detalhe = achados.map(({ simbolo, nome, troca }) => `  "${simbolo}" (${nome}): use ${troca}`).join('\n')
    throw new Error(`Legenda tem caractere proibido:\n${detalhe}\n\nCorrija o .json antes de publicar. A Graph API nao edita legenda depois de publicada.`)
  }

  // Limite duro da Meta. Estourar devolve erro so no ultimo passo, depois de
  // ja ter subido as imagens e criado 7 containers.
  if (legenda.length > 2200) throw new Error(`Legenda tem ${legenda.length} caracteres; o limite do Instagram e 2200`)
}

async function chamar(caminho, corpo) {
  const params = new URLSearchParams({ ...corpo, access_token: TOKEN })
  const dados = await (await fetch(`${API}/${caminho}`, { method: 'POST', body: params })).json()
  if (dados.error) throw new Error(`${dados.error.code}: ${dados.error.message}`)
  return dados
}

/*
 * O container pai não fica pronto na hora — a Meta baixa e processa as imagens
 * em background. Chamar media_publish antes de FINISHED devolve erro.
 */
async function aguardar(id, tentativas = 40) {
  for (let i = 0; i < tentativas; i++) {
    const url = `${API}/${id}?fields=status_code,status&access_token=${TOKEN}`
    const dados = await (await fetch(url)).json()
    if (dados.error) throw new Error(`${dados.error.code}: ${dados.error.message}`)
    if (dados.status_code === 'FINISHED') return
    if (dados.status_code === 'ERROR' || dados.status_code === 'EXPIRED') {
      throw new Error(`Container ${id} ficou ${dados.status_code}: ${dados.status || 'sem detalhe'}`)
    }
    await new Promise((r) => setTimeout(r, 3000))
  }
  throw new Error(`Container ${id} não ficou pronto em ${tentativas * 3}s`)
}

async function publicarCarrossel(urls, legenda) {
  validarLegenda(legenda)

  const filhos = []
  for (const [i, imagem] of urls.entries()) {
    const { id } = await chamar(`${IG_ID}/media`, { image_url: imagem, is_carousel_item: 'true' })
    registrar(`  slide ${i + 1}/${urls.length} → ${id}`)
    filhos.push(id)
  }

  const pai = await chamar(`${IG_ID}/media`, { media_type: 'CAROUSEL', children: filhos.join(','), caption: legenda })
  await aguardar(pai.id)

  const { id } = await chamar(`${IG_ID}/media_publish`, { creation_id: pai.id })

  const detalhe = await (await fetch(`${API}/${id}?fields=permalink&access_token=${TOKEN}`)).json()
  return { id, permalink: detalhe.permalink }
}

// ---------------------------------------------------------------- Fila

async function processarPost(arquivo) {
  const caminho = path.join(FILA, arquivo)
  const post = JSON.parse(readFileSync(caminho, 'utf8'))

  // Valida a legenda ANTES de subir imagem: barra o post sem deixar lixo no
  // bucket nem container orfao na Meta.
  validarLegenda(post.legenda)

  registrar(`publicando ${arquivo}`)
  const urls = await subirImagens(post.pasta)
  const resultado = await publicarCarrossel(urls, post.legenda)

  /*
   * Grava o resultado e MOVE o arquivo para fora da fila antes de qualquer
   * outra coisa. Se o cron reentrar (execução anterior demorou mais que o
   * intervalo), o post já não está mais em `posts/` e não sai duplicado.
   */
  post.publicado = { ...resultado, em: new Date().toISOString() }
  writeFileSync(caminho, JSON.stringify(post, null, 2))
  renameSync(caminho, path.join(FEITOS, arquivo))

  registrar(`OK ${arquivo} → ${resultado.permalink}`)
  return resultado
}

function pendentes() {
  const agora = new Date()
  return readdirSync(FILA)
    .filter((n) => n.endsWith('.json'))
    .map((nome) => ({ nome, post: JSON.parse(readFileSync(path.join(FILA, nome), 'utf8')) }))
    .filter(({ post }) => new Date(post.quando) <= agora)
    .sort((a, b) => new Date(a.post.quando) - new Date(b.post.quando))
}

async function principal() {
  const arg = process.argv[2]

  if (arg === '--listar') {
    const todos = readdirSync(FILA).filter((n) => n.endsWith('.json'))
    if (!todos.length) return console.log('Fila vazia.')
    for (const nome of todos.sort()) {
      const post = JSON.parse(readFileSync(path.join(FILA, nome), 'utf8'))
      const atrasado = new Date(post.quando) <= new Date() ? ' (na hora)' : ''
      console.log(`${post.quando}${atrasado}  ${nome}`)
    }
    return
  }

  if (arg === '--agora') {
    const alvo = process.argv[3]
    if (!alvo || !existsSync(alvo)) throw new Error('Informe o caminho do .json do post')
    const post = JSON.parse(readFileSync(alvo, 'utf8'))
    validarLegenda(post.legenda)
    const urls = await subirImagens(post.pasta)
    const resultado = await publicarCarrossel(urls, post.legenda)
    registrar(`OK (manual) → ${resultado.permalink}`)
    return console.log(JSON.stringify(resultado, null, 2))
  }

  const fila = pendentes()
  if (!fila.length) return

  /*
   * Um post por execução. O cron roda de 10 em 10 minutos, então dois posts
   * agendados pro mesmo horário saem com 10 min de intervalo — o que é melhor
   * pro alcance do que despejar tudo de uma vez.
   */
  const { nome } = fila[0]
  try {
    await processarPost(nome)
  } catch (erro) {
    registrar(`ERRO ${nome}: ${erro.message}`)
    process.exitCode = 1
  }
}

principal().catch((erro) => {
  registrar(`FALHA: ${erro.message}`)
  process.exit(1)
})
