#!/usr/bin/env node
/*
 * Escolhe qual chave da ElevenLabs usar.
 *
 * Lê `~/.elevenlabs-keys` (uma chave por linha, `apelido=sk_...`, em ordem de
 * preferência), pergunta o saldo de cada uma à API e devolve a PRIMEIRA que
 * ainda comporta o trabalho. Quando a de cima zera, a de baixo entra sozinha —
 * sem editar script nenhum.
 *
 * Uso:
 *   node elevenlabs-key.mjs                 # imprime a chave utilizável
 *   node elevenlabs-key.mjs --precisa 8000  # exige folga para 8000 caracteres
 *   node elevenlabs-key.mjs --status        # tabela de saldo (NÃO imprime chaves)
 *
 * A chave só vai para o stdout no modo padrão, porque é assim que quem chama a
 * consome (`KEY=$(node elevenlabs-key.mjs)`). Todo o resto — diagnóstico, erro,
 * qual conta foi escolhida — sai no stderr, para nunca contaminar esse valor
 * nem vazar a chave num log de build.
 */
import { readFileSync } from 'fs'
import path from 'path'
import { homedir } from 'os'

const ARQUIVO = path.join(homedir(), '.elevenlabs-keys')

const argv = process.argv.slice(2)
const STATUS = argv.includes('--status')
const precisaIdx = argv.indexOf('--precisa')
// Margem mínima: pedir TTS com saldo quase zerado falha no meio do arquivo.
const PRECISA = precisaIdx >= 0 ? Number(argv[precisaIdx + 1]) || 0 : 1

function carregarChaves() {
  let bruto
  try {
    bruto = readFileSync(ARQUIVO, 'utf8')
  } catch {
    throw new Error(`não encontrei ${ARQUIVO}. Crie o arquivo com uma linha "apelido=sk_..." por chave.`)
  }

  const chaves = bruto
    .split('\n')
    .map((l) => l.trim())
    .filter((l) => l && !l.startsWith('#'))
    .map((l) => {
      const i = l.indexOf('=')
      return i === -1 ? null : { apelido: l.slice(0, i).trim(), chave: l.slice(i + 1).trim() }
    })
    .filter((k) => k && k.chave.startsWith('sk_'))

  if (!chaves.length) throw new Error(`nenhuma chave válida em ${ARQUIVO}`)
  return chaves
}

// Saldo de uma chave. Nunca lança: uma chave revogada não pode derrubar a
// escolha — ela só perde a vez para a próxima da lista.
async function saldo({ apelido, chave }) {
  try {
    const r = await fetch('https://api.elevenlabs.io/v1/user/subscription', {
      headers: { 'xi-api-key': chave }
    })
    if (!r.ok) return { apelido, chave, erro: `HTTP ${r.status}`, restam: 0 }
    const d = await r.json()
    const restam = Math.max(0, (d.character_limit || 0) - (d.character_count || 0))
    return { apelido, chave, tier: d.tier, limite: d.character_limit, usados: d.character_count, restam }
  } catch (e) {
    return { apelido, chave, erro: e.message.slice(0, 60), restam: 0 }
  }
}

const chaves = carregarChaves()
const saldos = await Promise.all(chaves.map(saldo))

if (STATUS) {
  // Diagnóstico: mostra saldo e NUNCA a chave.
  for (const s of saldos) {
    const linha = s.erro
      ? `${s.apelido.padEnd(10)} ERRO: ${s.erro}`
      : `${s.apelido.padEnd(10)} ${String(s.tier).padEnd(8)} ${s.usados}/${s.limite} — restam ${s.restam}`
    process.stdout.write(linha + '\n')
  }
  process.exit(0)
}

const escolhida = saldos.find((s) => !s.erro && s.restam >= PRECISA)

if (!escolhida) {
  process.stderr.write('Nenhuma chave da ElevenLabs tem saldo suficiente:\n')
  for (const s of saldos) {
    process.stderr.write(`  ${s.apelido}: ${s.erro ? s.erro : `restam ${s.restam}`}\n`)
  }
  process.stderr.write(`(preciso de ${PRECISA} caracteres)\n`)
  process.exit(1)
}

process.stderr.write(`[elevenlabs] usando a conta "${escolhida.apelido}" (${escolhida.restam} créditos)\n`)
process.stdout.write(escolhida.chave)
