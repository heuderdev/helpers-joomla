import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, interpolate, spring } from 'remotion'
import { cores, fontes, seguro } from './marca.js'
import { curvas, dur, ms, escalaEntrada, movingHold } from './motion.js'
import { molas } from './movimento.jsx'
import { Palco, Legenda, Assinatura, faixa } from './cenas.jsx'
import icones from './icones.json'
import bancos from './bancos.json'
import marcas from './marcas.json'

/*
 * CENAS DO VÍDEO "CARTÕES E FATURAS".
 *
 * O objeto do tema é o CARTÃO — um retângulo físico. A forma visual explora
 * isso: leque de cartões, ciclo de fatura como trilho de calendário, compras
 * caindo dentro do cartão certo.
 *
 *   "todos na mesma tela"    → leque de cartões abrindo
 *   "fechamento/vencimento"  → trilho do ciclo com dois marcadores
 *   "cada compra na fatura"  → compras caindo e sendo absorvidas pelo cartão
 *   "fechou/aberto/pago"     → faturas como abas empilhadas com selo
 */

const CARTOES = [
  { banco: 'nubank', nome: 'Nubank', limite: 'R$ 8.500', fecha: 'dia 3', vence: 'dia 10' },
  { banco: 'itau', nome: 'Itaú', limite: 'R$ 12.000', fecha: 'dia 8', vence: 'dia 15' },
  { banco: 'c6', nome: 'C6', limite: 'R$ 5.200', fecha: 'dia 12', vence: 'dia 20' }
]

/** Cartão de crédito desenhado — o objeto que carrega este vídeo. */
function Cartao({ dados, largura = 420, brilho = 0 }) {
  const alt = largura * 0.63
  return (
    <div
      style={{
        width: largura,
        height: alt,
        borderRadius: largura * 0.055,
        background: `linear-gradient(140deg, ${cores.superficieAlta}, ${cores.superficie})`,
        border: `1px solid ${brilho > 0.3 ? cores.azul : cores.borda}`,
        boxShadow: brilho > 0.3 ? `0 0 50px ${cores.azul}55` : '0 18px 44px rgba(0,0,0,0.55)',
        padding: largura * 0.06,
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'space-between'
      }}
    >
      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
        <div style={{ width: largura * 0.16, height: largura * 0.16 }} dangerouslySetInnerHTML={{ __html: bancos[dados.banco]?.svg || '' }} />
        <div style={{ width: largura * 0.11, height: largura * 0.085, borderRadius: 6, background: `linear-gradient(135deg, #C9A227, #8A6D12)` }} />
      </div>
      <div>
        <div style={{ fontFamily: fontes.corpoLeve, fontSize: largura * 0.055, color: cores.textoFraco }}>limite</div>
        <div style={{ fontFamily: fontes.titulo, fontSize: largura * 0.095, color: cores.texto, letterSpacing: -1 }}>{dados.limite}</div>
      </div>
    </div>
  )
}

/* ------------------------------------------------------------------ leque */

/** Os cartões abrindo em leque a partir de uma pilha. */
export function CenaLequeCartoes({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const lista = dados.cartoes || CARTOES

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 190 }}>
        <div style={{ position: 'relative', width: 900, height: 560 }}>
          {lista.map((c, i) => {
            const abre = spring({ frame: frame - (3 + i * 5), fps, config: molas.suave })
            const meio = (lista.length - 1) / 2
            const desvio = (i - meio) * 210 * abre
            const giro = (i - meio) * 9 * abre
            const sobe = Math.abs(i - meio) * 34 * abre

            return (
              <div
                key={c.banco}
                style={{
                  position: 'absolute',
                  left: 450 + desvio - 210,
                  top: 120 + sobe + movingHold(i, 5, 110),
                  opacity: abre,
                  transform: `rotate(${giro}deg) scale(${escalaEntrada(abre, 0.82)})`,
                  zIndex: i === Math.round(meio) ? 3 : 1
                }}
              >
                <Cartao dados={c} largura={420} brilho={i === Math.round(meio) ? abre : 0} />
              </div>
            )
          })}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Todos os cartões|na mesma tela.'} destaque={dados.destaque || ['cartões']} sub={dados.sub} atraso={0} tamanho={dados.tamanho || 70} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ------------------------------------------------------------- ciclo trilho */

/**
 * O ciclo da fatura como trilho.
 *
 * Uma linha de dias com dois marcadores — fechamento e vencimento — e um
 * cursor andando. Explica o ciclo do cartão, que é justamente o que confunde
 * as pessoas, sem precisar de texto explicativo.
 */
export function CenaCicloFatura({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const FECHA = dados.fecha ?? 0.42
  const VENCE = dados.vence ?? 0.78
  const cursor = faixa(frame, 4, 46, 0, 1, curvas.padrao)
  const L = 880

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 120, paddingBottom: 120, gap: 110 }}>
        <div style={{ position: 'relative', width: L, height: 200 }}>
          {/* trilho */}
          <div style={{ position: 'absolute', top: 96, left: 0, width: L, height: 8, borderRadius: 4, backgroundColor: cores.superficie }} />
          {/* percorrido */}
          <div
            style={{
              position: 'absolute',
              top: 96,
              left: 0,
              width: L * cursor,
              height: 8,
              borderRadius: 4,
              background: `linear-gradient(90deg, ${cores.azul}, ${cores.azulClaro})`,
              boxShadow: `0 0 24px ${cores.azul}88`
            }}
          />

          {[
            { pos: FECHA, rotulo: 'fecha', dia: dados.diaFecha || 'dia 3', cor: cores.azulClaro },
            { pos: VENCE, rotulo: 'vence', dia: dados.diaVence || 'dia 10', cor: cores.laranja }
          ].map((m) => {
            const passou = cursor >= m.pos
            const p = faixa(frame, 4 + m.pos * 42, 4 + m.pos * 42 + 10, 0, 1)
            return (
              <div key={m.rotulo} style={{ position: 'absolute', left: L * m.pos - 70, top: 0, width: 140, textAlign: 'center' }}>
                <div style={{ fontFamily: fontes.corpo, fontSize: 28, color: passou ? m.cor : cores.cinza, opacity: p }}>{m.rotulo}</div>
                <div style={{ fontFamily: fontes.corpoLeve, fontSize: 24, color: cores.textoFraco, opacity: p, marginBottom: 12 }}>{m.dia}</div>
                <div
                  style={{
                    width: passou ? 30 : 18,
                    height: passou ? 30 : 18,
                    borderRadius: '50%',
                    backgroundColor: passou ? m.cor : cores.superficieAlta,
                    margin: '0 auto',
                    boxShadow: passou ? `0 0 26px ${m.cor}aa` : 'none',
                    transform: `translateY(${passou ? -6 : 0}px)`
                  }}
                />
              </div>
            )
          })}

          {/* cursor */}
          <div
            style={{
              position: 'absolute',
              left: L * cursor - 3,
              top: 78,
              width: 6,
              height: 44,
              borderRadius: 3,
              backgroundColor: cores.texto,
              opacity: cursor < 1 ? 0.9 : 0
            }}
          />
        </div>

        <div style={{ display: 'flex', gap: 20, opacity: faixa(frame, 30, 30 + dur.entrada, 0, 1) }}>
          {(dados.cartoes || CARTOES).map((c) => (
            <div
              key={c.banco}
              style={{
                display: 'flex',
                alignItems: 'center',
                gap: 14,
                padding: '18px 26px',
                borderRadius: 20,
                backgroundColor: cores.superficie,
                border: `1px solid ${cores.borda}`
              }}
            >
              <div style={{ width: 40, height: 40 }} dangerouslySetInnerHTML={{ __html: bancos[c.banco]?.svg || '' }} />
              <div>
                <div style={{ fontFamily: fontes.corpo, fontSize: 26, color: cores.texto }}>{c.nome}</div>
                <div style={{ fontFamily: fontes.corpoLeve, fontSize: 22, color: cores.textoFraco }}>
                  {c.fecha} · {c.vence}
                </div>
              </div>
            </div>
          ))}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Fecha e vence.|Cada um no seu dia.'} destaque={dados.destaque || ['dia.']} atraso={0} tamanho={dados.tamanho || 68} />
    </Palco>
  )
}

/* ------------------------------------------------------- compras entrando */

/**
 * As compras caindo DENTRO do cartão.
 *
 * Logos vêm de cima e são absorvidos por um cartão no rodapé, que acumula o
 * total. É a metáfora literal de "cada compra aparece na fatura certa".
 */
export function CenaComprasNoCartao({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const COMPRAS = dados.compras || [
    { marca: 'ifood', valor: 64.9 },
    { marca: 'amazon', valor: 189.9 },
    { marca: 'uber', valor: 21.4 },
    { marca: 'netflix', valor: 39.9 },
    { marca: 'spotify', valor: 21.9 }
  ]

  const absorvidas = COMPRAS.filter((_, i) => frame > 6 + i * 7 + 20).length
  const total = COMPRAS.slice(0, absorvidas).reduce((a, c) => a + c.valor, 0)

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 230, paddingBottom: 120 }}>
        <div style={{ position: 'relative', width: 900, height: 520 }}>
          {COMPRAS.map((c, i) => {
            const inicio = 6 + i * 7
            const cai = faixa(frame, inicio, inicio + 20, 0, 1, curvas.movimento)
            if (cai <= 0) return null
            const dx = (i - (COMPRAS.length - 1) / 2) * 165
            const x = dx * (1 - cai)
            const y = cai * 380
            const some = faixa(frame, inicio + 15, inicio + 20, 1, 0)

            return (
              <div
                key={i}
                style={{
                  position: 'absolute',
                  left: 450 + x - 44,
                  top: y,
                  width: 88,
                  height: 88,
                  opacity: some,
                  transform: `scale(${1 - cai * 0.35}) rotate(${(1 - cai) * (i % 2 ? 14 : -14)}deg)`
                }}
                dangerouslySetInnerHTML={{ __html: marcas[c.marca]?.svg || '' }}
              />
            )
          })}
        </div>

        <div style={{ position: 'relative', marginTop: -80 }}>
          <Cartao dados={dados.cartao || CARTOES[0]} largura={560} brilho={absorvidas > 0 ? 1 : 0} />
          <div
            style={{
              position: 'absolute',
              right: 28,
              bottom: 26,
              textAlign: 'right'
            }}
          >
            <div style={{ fontFamily: fontes.corpoLeve, fontSize: 24, color: cores.textoFraco }}>fatura</div>
            <div style={{ fontFamily: fontes.titulo, fontSize: 46, color: cores.azulClaro, letterSpacing: -1.5 }}>
              {total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
            </div>
          </div>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Cada compra|na fatura certa.'} destaque={dados.destaque || ['certa.']} atraso={0} tamanho={dados.tamanho || 70} />
    </Palco>
  )
}

/* ------------------------------------------------------------ abas fatura */

/**
 * As faturas como abas empilhadas.
 *
 * Cada mês é uma aba com seu selo (Paga / Em aberto / Próxima). Empilhadas em
 * perspectiva, mostram histórico e situação atual num único quadro.
 */
export function CenaAbasFatura({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const FATURAS = dados.faturas || [
    { mes: 'Maio', valor: 'R$ 2.184,30', estado: 'Paga' },
    { mes: 'Junho', valor: 'R$ 1.960,70', estado: 'Paga' },
    { mes: 'Julho', valor: 'R$ 2.412,50', estado: 'Em aberto' },
    { mes: 'Agosto', valor: 'R$ 338,00', estado: 'Próxima' }
  ]

  const CORES = { Paga: cores.verde, 'Em aberto': cores.laranja, Vencida: cores.vermelho, 'Próxima': cores.cinza }

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 200 }}>
        <div style={{ width: 860, display: 'flex', flexDirection: 'column', gap: 18 }}>
          {FATURAS.map((f, i) => {
            const p = spring({ frame: frame - (2 + i * 5), fps, config: molas.seco })
            const cor = CORES[f.estado] || cores.cinza
            const atual = f.estado === 'Em aberto'
            return (
              <div
                key={f.mes}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 24,
                  padding: atual ? '38px 34px' : '28px 34px',
                  borderRadius: 24,
                  backgroundColor: atual ? `${cores.azul}14` : cores.superficie,
                  border: `1px solid ${atual ? `${cores.azul}77` : cores.borda}`,
                  opacity: p,
                  transform: `translateX(${(1 - p) * (i % 2 ? 60 : -60)}px) scale(${escalaEntrada(p, 0.96)})`,
                  boxShadow: atual ? `0 0 44px ${cores.azul}33` : 'none'
                }}
              >
                <div style={{ flex: 1 }}>
                  <div style={{ fontFamily: fontes.corpo, fontSize: atual ? 38 : 32, color: cores.texto }}>{f.mes}</div>
                  <div style={{ fontFamily: fontes.corpoLeve, fontSize: 25, color: cores.textoFraco, marginTop: 2 }}>{f.valor}</div>
                </div>
                <span
                  style={{
                    padding: '12px 24px',
                    borderRadius: 999,
                    backgroundColor: `${cor}1e`,
                    border: `1px solid ${cor}77`,
                    fontFamily: fontes.corpo,
                    fontSize: 26,
                    color: cor
                  }}
                >
                  {f.estado}
                </span>
              </div>
            )
          })}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Paga, aberta|ou a vencer.'} destaque={dados.destaque || ['vencer.']} sub={dados.sub} atraso={0} tamanho={dados.tamanho || 72} />
    </Palco>
  )
}
