import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, interpolate, spring } from 'remotion'
import { cores, fontes, seguro } from './marca.js'
import { curvas, dur, ms, escalaEntrada, blurDeMovimento, movingHold } from './motion.js'
import { molas } from './movimento.jsx'
import { Palco, Legenda, Assinatura, faixa } from './cenas.jsx'
import icones from './icones.json'
import marcas from './marcas.json'

/*
 * CENAS DO VÍDEO "ECONOMIA / QUANTO SOBROU".
 *
 * O tema é subtração: o que entra, o que sai, o que resta. A forma visual toda
 * gira em torno de VOLUME e EQUILÍBRIO — não de listas.
 *
 *   "cadê o dinheiro"        → moedas caindo e escoando por um ralo
 *   "entrou menos saiu"      → balança de dois pratos pendendo
 *   "taxa de poupança"       → cofre enchendo até a marca
 *   "não se engana"          → ruído sendo filtrado do sinal
 *   "meses anteriores"       → linha do tempo com a sobra de cada mês
 */

/* ------------------------------------------------------------------- ralo */

/**
 * O dinheiro escoando.
 *
 * Moedas caem do topo e desaparecem num ralo — só uma fração fica presa na
 * borda. É a sensação de "entrou e sumiu" antes de o produto explicar para
 * onde foi.
 */
export function CenaRalo({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const N = 26

  return (
    <Palco glow={0.5} cor={cores.vermelho}>
      <AbsoluteFill style={{ alignItems: 'center', paddingTop: 470 }}>
        <div style={{ position: 'relative', width: 900, height: 900 }}>
          {Array.from({ length: N }).map((_, i) => {
            /* cada moeda tem sua faixa de tempo e desvio lateral */
            const atraso = (i * 2.6) % 46
            const t = ((frame - atraso) % 52) / 52
            if (frame < atraso) return null

            const dx = Math.sin(i * 2.4) * 190
            const queda = curvas.movimento(Math.max(0, Math.min(1, t)))
            /* convergem para o centro conforme caem — o ralo suga */
            const x = dx * (1 - queda * 0.92)
            const y = queda * 560
            const some = t > 0.82 ? 1 - (t - 0.82) / 0.18 : 1

            return (
              <div
                key={i}
                style={{
                  position: 'absolute',
                  left: 450 + x - 22,
                  top: y,
                  width: 44,
                  height: 44,
                  borderRadius: '50%',
                  background: `linear-gradient(180deg, ${cores.azulSuave}, ${cores.azul})`,
                  opacity: some * 0.9,
                  transform: `scale(${1 - queda * 0.45})`,
                  boxShadow: `0 0 16px ${cores.azul}66`
                }}
              />
            )
          })}

          {/* o ralo */}
          <div
            style={{
              position: 'absolute',
              left: 450 - 90,
              top: 552,
              width: 200,
              height: 44,
              borderRadius: '50%',
              background: `radial-gradient(ellipse, ${cores.fundo} 35%, ${cores.vermelho}55 100%)`,
              border: `2px solid ${cores.vermelho}66`,
              transform: `scale(${1 + Math.sin(frame / 9) * 0.05})`
            }}
          />
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Entrou.|E sumiu.'} destaque={dados.destaque || ['sumiu.']} atraso={0} tamanho={dados.tamanho || 80} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ---------------------------------------------------------------- balança */

/**
 * A balança: entrou de um lado, saiu do outro.
 *
 * Os pratos pendem conforme os valores, e a diferença aparece como um bloco
 * que sobra em cima do prato mais leve. Mostra a subtração como equilíbrio
 * físico, não como número numa tabela.
 */
export function CenaBalanca({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const ENTROU = dados.entrou ?? 8400
  const SAIU = dados.saiu ?? 6423.5
  const SOBRA = ENTROU - SAIU

  const p = faixa(frame, 3, 26, 0, 1, curvas.enfase)
  /* inclinação proporcional à diferença, com teto para não virar gangorra */
  const desnivel = Math.max(-1, Math.min(1, (SAIU - ENTROU) / ENTROU)) * p
  const angulo = desnivel * 9

  const conta = (v) => (v * p).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 190 }}>
        <div style={{ position: 'relative', width: 900, height: 520 }}>
          {/* travessa */}
          <div
            style={{
              position: 'absolute',
              left: 130,
              top: 210,
              width: 640,
              height: 8,
              borderRadius: 4,
              backgroundColor: cores.superficieAlta,
              transform: `rotate(${angulo}deg)`,
              transformOrigin: '50% 50%'
            }}
          />
          {/* coluna */}
          <div style={{ position: 'absolute', left: 444, top: 214, width: 12, height: 250, borderRadius: 6, backgroundColor: cores.superficieAlta }} />

          {[
            { lado: -1, rotulo: 'Entrou', valor: ENTROU, cor: cores.verde },
            { lado: 1, rotulo: 'Saiu', valor: SAIU, cor: cores.vermelho }
          ].map((pr) => {
            const y = 210 + pr.lado * Math.tan((angulo * Math.PI) / 180) * 320 * -1
            return (
              <div
                key={pr.rotulo}
                style={{
                  position: 'absolute',
                  left: 450 + pr.lado * 320 - 130,
                  top: y + 30,
                  width: 260,
                  textAlign: 'center'
                }}
              >
                <div style={{ height: 3, backgroundColor: cores.borda, marginBottom: 18 }} />
                <div
                  style={{
                    padding: '26px 20px',
                    borderRadius: 22,
                    backgroundColor: cores.superficie,
                    border: `1px solid ${pr.cor}55`,
                    boxShadow: `0 0 40px ${pr.cor}22`
                  }}
                >
                  <div style={{ fontFamily: fontes.corpoLeve, fontSize: 26, color: cores.textoFraco }}>{pr.rotulo}</div>
                  <div style={{ fontFamily: fontes.titulo, fontSize: 40, color: pr.cor, letterSpacing: -1.5, marginTop: 4 }}>{conta(pr.valor)}</div>
                </div>
              </div>
            )
          })}
        </div>

        {/* a sobra */}
        <div
          style={{
            marginTop: 20,
            padding: '26px 56px',
            borderRadius: 26,
            backgroundColor: `${cores.azul}1e`,
            border: `1px solid ${cores.azul}77`,
            opacity: faixa(frame, 30, 30 + dur.entrada, 0, 1),
            transform: `translateY(${(1 - faixa(frame, 30, 30 + dur.entrada, 0, 1)) * 18}px)`,
            textAlign: 'center'
          }}
        >
          <div style={{ fontFamily: fontes.corpoLeve, fontSize: 28, color: cores.textoFraco }}>Sobrou</div>
          <div style={{ fontFamily: fontes.titulo, fontSize: 68, color: cores.texto, letterSpacing: -2.5 }}>
            {SOBRA.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
          </div>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Entrou menos saiu.'} destaque={dados.destaque || ['saiu.']} atraso={0} tamanho={dados.tamanho || 76} />
    </Palco>
  )
}

/* ------------------------------------------------------------------ cofre */

/**
 * O cofre enchendo até a taxa de poupança.
 *
 * Um cilindro que enche com líquido azul até a porcentagem, e o número conta
 * junto. A régua lateral dá a referência — sem ela, "23%" é abstrato; com ela,
 * vê-se o quanto falta para o topo.
 */
export function CenaCofre({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const TAXA = dados.taxa ?? 23.5
  const VALOR = dados.valor ?? 1976.5
  const p = faixa(frame, 3, 30, 0, 1, curvas.enfase)
  const nivel = (TAXA / 100) * p

  const ALT = 560
  const LARG = 300

  return (
    <Palco glow={0.8}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 200, gap: 40 }}>
        <div style={{ display: 'flex', alignItems: 'flex-end', gap: 34 }}>
          {/* régua */}
          <div style={{ height: ALT, display: 'flex', flexDirection: 'column', justifyContent: 'space-between', paddingBottom: 4 }}>
            {[100, 75, 50, 25, 0].map((v) => (
              <span key={v} style={{ fontFamily: fontes.corpoLeve, fontSize: 22, color: cores.textoFraco, opacity: faixa(frame, 2, 12, 0, 0.8) }}>
                {v}%
              </span>
            ))}
          </div>

          {/* o cilindro */}
          <div
            style={{
              position: 'relative',
              width: LARG,
              height: ALT,
              borderRadius: 34,
              border: `3px solid ${cores.borda}`,
              overflow: 'hidden',
              backgroundColor: cores.superficie
            }}
          >
            {/* líquido */}
            <div
              style={{
                position: 'absolute',
                left: 0,
                right: 0,
                bottom: 0,
                height: nivel * ALT,
                background: `linear-gradient(180deg, ${cores.azulClaro}, ${cores.azul})`,
                boxShadow: `0 0 50px ${cores.azul}88`
              }}
            />
            {/* superfície ondulando */}
            {nivel > 0.02 && (
              <div
                style={{
                  position: 'absolute',
                  left: -10,
                  right: -10,
                  bottom: nivel * ALT - 6,
                  height: 12,
                  borderRadius: '50%',
                  backgroundColor: cores.azulSuave,
                  opacity: 0.75,
                  transform: `translateX(${Math.sin(frame / 7) * 8}px)`
                }}
              />
            )}

            <div style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <span style={{ fontFamily: fontes.titulo, fontSize: 76, color: cores.texto, letterSpacing: -3, textShadow: '0 4px 24px rgba(0,0,0,0.6)' }}>
                {(TAXA * p).toFixed(1)}%
              </span>
            </div>
          </div>
        </div>

        <div style={{ textAlign: 'center', opacity: faixa(frame, 26, 26 + dur.entrada, 0, 1) }}>
          <div style={{ fontFamily: fontes.corpoLeve, fontSize: 28, color: cores.textoFraco }}>{dados.rotulo || 'sobrou este mês'}</div>
          <div style={{ fontFamily: fontes.titulo, fontSize: 56, color: cores.verde, letterSpacing: -2 }}>
            {VALOR.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
          </div>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Sua taxa|de poupança.'} destaque={dados.destaque || ['poupança.']} atraso={0} tamanho={dados.tamanho || 74} />
    </Palco>
  )
}

/* ----------------------------------------------------------------- filtro */

/**
 * O que NÃO conta como movimento.
 *
 * Duas colunas: o que entra na conta (fica, azul) e o que é ignorado
 * (atravessa e apaga). Diferente da peneira do vídeo de assinaturas: aqui não
 * há queda, há uma linha divisória e itens escolhendo lado.
 */
export function CenaFiltroRuido({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const ITENS = dados.itens || [
    { rotulo: 'Salário', icone: 'seta-tendencia-alta', conta: true },
    { rotulo: 'Mercado', icone: 'carrinho-compras', conta: true },
    { rotulo: 'Transferência entre suas contas', icone: 'transferencia', conta: false },
    { rotulo: 'Pagamento de fatura', icone: 'cartao', conta: false }
  ]

  return (
    <Palco glow={0.7}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 190, gap: 22 }}>
        {ITENS.map((it, i) => {
          const p = spring({ frame: frame - (2 + i * 5), fps, config: molas.suave })
          const desliza = it.conta ? -1 : 1
          return (
            <div
              key={i}
              style={{
                width: 880,
                display: 'flex',
                alignItems: 'center',
                gap: 26,
                padding: '30px 34px',
                borderRadius: 26,
                backgroundColor: it.conta ? `${cores.azul}16` : cores.superficie,
                border: `1px solid ${it.conta ? `${cores.azul}66` : cores.borda}`,
                opacity: p * (it.conta ? 1 : 0.42),
                transform: `translateX(${(1 - p) * desliza * 90}px)`,
                filter: it.conta ? 'none' : 'grayscale(1)'
              }}
            >
              <div
                style={{ width: 62, height: 62, flexShrink: 0, color: it.conta ? cores.azulClaro : cores.cinza }}
                dangerouslySetInnerHTML={{ __html: icones[it.icone] || icones['dinheiro'] }}
              />
              <span style={{ fontFamily: fontes.corpo, fontSize: 34, color: it.conta ? cores.texto : cores.textoFraco, flex: 1 }}>{it.rotulo}</span>
              <span
                style={{
                  fontFamily: fontes.corpo,
                  fontSize: 26,
                  color: it.conta ? cores.azulClaro : cores.cinza,
                  padding: '10px 20px',
                  borderRadius: 999,
                  border: `1px solid ${it.conta ? `${cores.azul}66` : cores.borda}`
                }}
              >
                {it.conta ? 'conta' : 'ignora'}
              </span>
            </div>
          )
        })}
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Só movimento|de verdade.'} destaque={dados.destaque || ['verdade.']} sub={dados.sub} atraso={0} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* -------------------------------------------------------------- histórico */

/**
 * A sobra de cada mês, em linha do tempo.
 *
 * Barras que saem de uma linha-base central: para cima quando sobrou, para
 * baixo quando faltou. O melhor mês acende. É diferente do gráfico de barras
 * comum porque o zero fica no MEIO — dá para ver o mês negativo.
 */
export function CenaHistoricoSobra({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const MESES = dados.meses || [
    ['Mar', 1240], ['Abr', 890], ['Mai', -310], ['Jun', 1520], ['Jul', 1976]
  ]
  const maximo = Math.max(...MESES.map((m) => Math.abs(m[1])))
  const melhor = Math.max(...MESES.map((m) => m[1]))

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 190 }}>
        <div style={{ position: 'relative', width: 880, height: 520 }}>
          {/* linha do zero */}
          <div style={{ position: 'absolute', left: 0, right: 0, top: 260, height: 2, backgroundColor: cores.borda }} />

          <div style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'space-around' }}>
            {MESES.map(([mes, valor], i) => {
              const p = faixa(frame, 3 + i * 5, 3 + i * 5 + 18, 0, 1, curvas.enfase)
              const h = (Math.abs(valor) / maximo) * 220 * p
              const positivo = valor >= 0
              const ehMelhor = valor === melhor
              const cor = positivo ? (ehMelhor ? cores.azulClaro : cores.azul) : cores.vermelho

              return (
                <div key={mes} style={{ width: 120, height: '100%', position: 'relative', display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
                  <div
                    style={{
                      position: 'absolute',
                      top: positivo ? 260 - h : 260,
                      width: 78,
                      height: h,
                      borderRadius: positivo ? '14px 14px 4px 4px' : '4px 4px 14px 14px',
                      background: positivo
                        ? `linear-gradient(180deg, ${cor}, ${cores.azul})`
                        : `linear-gradient(180deg, ${cores.vermelho}, ${cores.vermelho}99)`,
                      boxShadow: ehMelhor ? `0 0 40px ${cores.azul}aa` : 'none'
                    }}
                  />
                  <span
                    style={{
                      position: 'absolute',
                      top: positivo ? 260 - h - 42 : 260 + h + 12,
                      fontFamily: fontes.corpo,
                      fontSize: 24,
                      color: ehMelhor ? cores.azulClaro : cores.textoFraco,
                      opacity: p
                    }}
                  >
                    {valor > 0 ? '+' : ''}
                    {(valor / 1000).toFixed(1).replace('.', ',')}k
                  </span>
                  <span style={{ position: 'absolute', top: 290, fontFamily: fontes.corpoLeve, fontSize: 26, color: cores.textoFraco, opacity: p }}>{mes}</span>
                </div>
              )
            })}
          </div>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Mês a mês.'} destaque={dados.destaque || ['mês.']} sub={dados.sub} atraso={0} tamanho={dados.tamanho || 80} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}
