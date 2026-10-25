import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, interpolate, spring } from 'remotion'
import { cores, fontes, seguro } from './marca.js'
import { curvas, dur, ms, escalaEntrada, movingHold } from './motion.js'
import { molas } from './movimento.jsx'
import { Palco, Legenda, Assinatura, faixa } from './cenas.jsx'
import icones from './icones.json'
import marcas from './marcas.json'

/*
 * CENAS DO VÍDEO "PADRÕES DE GASTO".
 *
 * O tema é TEMPO e FREQUÊNCIA — quando, não quanto. Por isso nada de lista:
 * a forma é sempre uma superfície de tempo (semana, relógio) onde a
 * intensidade aparece como calor ou raio.
 *
 *   "em que dia"        → heatmap da semana, as células acendem
 *   "em que hora"       → relógio de 24h com raios saindo do centro
 *   "onde mais gastou"  → pódio de marcas
 *   "o que cresceu"     → setas comparando dois meses
 */

/* ---------------------------------------------------------------- heatmap */

/**
 * Heatmap da semana.
 *
 * Sete colunas (dias) por cinco linhas (semanas do mês). As células acendem
 * proporcionalmente ao gasto, e a coluna campeã ganha um realce. O padrão
 * emerge da mancha, não de um número.
 */
export function CenaHeatmapSemana({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const DIAS = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom']
  /* intensidade 0..1 por [semana][dia] — sexta e sábado concentram */
  const MAPA = dados.mapa || [
    [0.15, 0.25, 0.35, 0.3, 0.9, 0.7, 0.2],
    [0.2, 0.15, 0.4, 0.25, 0.95, 0.65, 0.3],
    [0.1, 0.3, 0.2, 0.35, 0.85, 0.75, 0.15],
    [0.25, 0.2, 0.3, 0.4, 1.0, 0.6, 0.25],
    [0.15, 0.35, 0.25, 0.2, 0.88, 0.7, 0.1]
  ]
  const destaqueCol = dados.colunaDestaque ?? 4

  return (
    <Palco glow={0.7}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 210, paddingBottom: 120, gap: 26 }}>
        <div style={{ display: 'flex', gap: 16 }}>
          {DIAS.map((d, i) => (
            <span
              key={d}
              style={{
                width: 116,
                textAlign: 'center',
                fontFamily: fontes.corpo,
                fontSize: 26,
                color: i === destaqueCol ? cores.azulClaro : cores.textoFraco,
                opacity: faixa(frame, 1 + i, 1 + i + 8, 0, 1)
              }}
            >
              {d}
            </span>
          ))}
        </div>

        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          {MAPA.map((linha, l) => (
            <div key={l} style={{ display: 'flex', gap: 16 }}>
              {linha.map((v, c) => {
                const p = faixa(frame, 4 + (l * 7 + c) * 0.9, 4 + (l * 7 + c) * 0.9 + 12, 0, 1, curvas.enfase)
                const intensidade = v * p
                const ehDestaque = c === destaqueCol
                return (
                  <div
                    key={c}
                    style={{
                      width: 116,
                      height: 92,
                      borderRadius: 16,
                      backgroundColor: `rgba(13, 46, 252, ${0.08 + intensidade * 0.85})`,
                      border: `1px solid ${ehDestaque && intensidade > 0.6 ? cores.azulClaro : cores.borda}`,
                      transform: `scale(${escalaEntrada(p, 0.85)})`,
                      boxShadow: intensidade > 0.7 ? `0 0 30px ${cores.azul}77` : 'none'
                    }}
                  />
                )
              })}
            </div>
          ))}
        </div>

        {dados.veredito && (
          <div
            style={{
              marginTop: 14,
              padding: '20px 34px',
              borderRadius: 999,
              backgroundColor: `${cores.azul}1e`,
              border: `1px solid ${cores.azul}66`,
              opacity: faixa(frame, 40, 40 + dur.entrada, 0, 1)
            }}
          >
            <span style={{ fontFamily: fontes.corpo, fontSize: 32, color: cores.texto }}>{dados.veredito}</span>
          </div>
        )}
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Dia por dia.'} destaque={dados.destaque || ['dia.']} atraso={0} tamanho={dados.tamanho || 78} />
    </Palco>
  )
}

/* --------------------------------------------------------------- relógio */

/**
 * Relógio de 24 horas.
 *
 * Cada hora é um raio saindo do centro; o comprimento é o gasto. A "coroa" que
 * se forma mostra o pico sem precisar de eixo. Um ponteiro varre e o horário
 * de pico acende quando ele passa.
 */
export function CenaRelogio24h({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  /* 24 valores 0..1 — pico às 20h, secundário no almoço */
  const HORAS = dados.horas || [
    0.05, 0.03, 0.02, 0.02, 0.03, 0.06, 0.12, 0.22, 0.35, 0.3, 0.28, 0.4,
    0.62, 0.55, 0.3, 0.28, 0.35, 0.45, 0.6, 0.85, 1.0, 0.75, 0.4, 0.15
  ]
  const CX = 540
  const CY = 1020
  const R0 = 92
  const RMAX = 250

  const varredura = faixa(frame, 4, 42, 0, 24)
  const pico = HORAS.indexOf(Math.max(...HORAS))

  return (
    <Palco glow={0.75}>
      <AbsoluteFill>
        <svg width="1080" height="1920" style={{ position: 'absolute' }}>
          {/* miolo */}
          <circle cx={CX} cy={CY} r={R0 - 12} fill="none" stroke={cores.borda} strokeWidth="2" opacity={faixa(frame, 1, 10, 0, 1)} />

          {HORAS.map((v, h) => {
            const ang = (h / 24) * Math.PI * 2 - Math.PI / 2
            const revelado = varredura > h
            const p = revelado ? faixa(frame, 4 + h * 1.6, 4 + h * 1.6 + 12, 0, 1, curvas.enfase) : 0
            const comp = R0 + v * RMAX * p
            const x1 = CX + Math.cos(ang) * R0
            const y1 = CY + Math.sin(ang) * R0
            const x2 = CX + Math.cos(ang) * comp
            const y2 = CY + Math.sin(ang) * comp
            const ehPico = h === pico && p > 0.8

            return (
              <g key={h}>
                <line
                  x1={x1}
                  y1={y1}
                  x2={x2}
                  y2={y2}
                  stroke={ehPico ? cores.azulClaro : cores.azul}
                  strokeWidth={ehPico ? 16 : 11}
                  strokeLinecap="round"
                  opacity={ehPico ? 1 : 0.55 + v * 0.35}
                />
                {h % 6 === 0 && (
                  <text
                    x={CX + Math.cos(ang) * (RMAX + R0 + 46)}
                    y={CY + Math.sin(ang) * (RMAX + R0 + 46) + 10}
                    fill={cores.textoFraco}
                    fontSize="28"
                    fontFamily="Figtree"
                    textAnchor="middle"
                    opacity={faixa(frame, 2, 12, 0, 0.9)}
                  >
                    {String(h).padStart(2, '0')}h
                  </text>
                )}
              </g>
            )
          })}

          {/* ponteiro de varredura */}
          {varredura < 24 && (
            <line
              x1={CX}
              y1={CY}
              x2={CX + Math.cos((varredura / 24) * Math.PI * 2 - Math.PI / 2) * (R0 + RMAX)}
              y2={CY + Math.sin((varredura / 24) * Math.PI * 2 - Math.PI / 2) * (R0 + RMAX)}
              stroke={cores.azulSuave}
              strokeWidth="3"
              opacity="0.8"
            />
          )}
        </svg>

        {dados.veredito && (
          <div
            style={{
              position: 'absolute',
              left: seguro.lateral,
              right: seguro.lateral,
              bottom: seguro.base - 60,
              textAlign: 'center',
              opacity: faixa(frame, 46, 46 + dur.entrada, 0, 1)
            }}
          >
            <span
              style={{
                display: 'inline-block',
                padding: '20px 34px',
                borderRadius: 999,
                backgroundColor: `${cores.azul}1e`,
                border: `1px solid ${cores.azul}66`,
                fontFamily: fontes.corpo,
                fontSize: 32,
                color: cores.texto
              }}
            >
              {dados.veredito}
            </span>
          </div>
        )}
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Hora por hora.'} destaque={dados.destaque || ['hora.']} atraso={0} tamanho={dados.tamanho || 78} />
    </Palco>
  )
}

/* ------------------------------------------------------------------ pódio */

/**
 * Pódio de marcas.
 *
 * As três primeiras sobem em degraus, com o logo real em cima. Ranking lê
 * instantaneamente como competição — é a forma certa para "onde você mais
 * gastou", e não se parece com nenhuma outra cena da série.
 */
export function CenaPodio({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const TOP = dados.top || [
    { marca: 'uber', valor: 'R$ 312,50', pos: 2 },
    { marca: 'ifood', valor: 'R$ 684,20', pos: 1 },
    { marca: 'rappi', valor: 'R$ 248,90', pos: 3 }
  ]
  const ALTURA = { 1: 300, 2: 210, 3: 155 }

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 210, paddingBottom: 130 }}>
        <div style={{ display: 'flex', alignItems: 'flex-end', gap: 24 }}>
          {TOP.map((it, i) => {
            const atraso = 4 + (3 - it.pos) * 6
            const sobe = spring({ frame: frame - atraso, fps, config: molas.suave })
            const h = ALTURA[it.pos] * sobe
            const primeiro = it.pos === 1

            return (
              <div key={it.marca} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 18 }}>
                <div
                  style={{
                    width: primeiro ? 132 : 104,
                    height: primeiro ? 132 : 104,
                    opacity: sobe,
                    transform: `translateY(${(1 - sobe) * 40}px) scale(${escalaEntrada(sobe, 0.6)})`,
                    filter: primeiro ? `drop-shadow(0 0 30px ${cores.azul}aa)` : 'drop-shadow(0 10px 24px rgba(0,0,0,0.5))'
                  }}
                  dangerouslySetInnerHTML={{ __html: marcas[it.marca]?.svg || '' }}
                />
                <span style={{ fontFamily: fontes.titulo, fontSize: primeiro ? 40 : 32, color: primeiro ? cores.texto : cores.textoFraco, letterSpacing: -1, opacity: sobe }}>
                  {it.valor}
                </span>
                <div
                  style={{
                    width: primeiro ? 230 : 190,
                    height: h,
                    borderRadius: '20px 20px 0 0',
                    background: primeiro
                      ? `linear-gradient(180deg, ${cores.azulClaro}, ${cores.azul})`
                      : `linear-gradient(180deg, ${cores.superficieAlta}, ${cores.superficie})`,
                    border: `1px solid ${primeiro ? cores.azulClaro : cores.borda}`,
                    borderBottom: 'none',
                    display: 'flex',
                    alignItems: 'flex-start',
                    justifyContent: 'center',
                    paddingTop: 20,
                    boxShadow: primeiro ? `0 0 50px ${cores.azul}55` : 'none'
                  }}
                >
                  <span style={{ fontFamily: fontes.titulo, fontSize: 46, color: primeiro ? cores.texto : cores.cinza, opacity: sobe }}>{it.pos}º</span>
                </div>
              </div>
            )
          })}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Onde foi parar.'} destaque={dados.destaque || ['parar.']} sub={dados.sub} atraso={0} tamanho={dados.tamanho || 78} />
    </Palco>
  )
}

/* -------------------------------------------------------------- comparativo */

/**
 * Dois meses lado a lado, com a variação em seta.
 *
 * Não é barra crescendo: são PARES. Cada categoria mostra o mês passado
 * apagado e o atual aceso, com a seta e o percentual entre eles. A forma diz
 * "comparação", que é exatamente o que a narração promete.
 */
export function CenaComparaMeses({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const LINHAS = dados.linhas || [
    { nome: 'Delivery', antes: 518, agora: 684, marca: 'ifood' },
    { nome: 'Transporte', antes: 344, agora: 312, marca: 'uber' },
    { nome: 'Streaming', antes: 160, agora: 186, marca: 'netflix' }
  ]
  const maximo = Math.max(...LINHAS.flatMap((l) => [l.antes, l.agora]))

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 200, gap: 34 }}>
        {LINHAS.map((l, i) => {
          const p = faixa(frame, 3 + i * 6, 3 + i * 6 + 20, 0, 1, curvas.enfase)
          const varia = ((l.agora - l.antes) / l.antes) * 100
          const subiu = varia >= 0
          const corVar = subiu ? cores.vermelho : cores.verde

          return (
            <div key={l.nome} style={{ width: 880, opacity: faixa(frame, 3 + i * 6, 3 + i * 6 + 8, 0, 1) }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 14 }}>
                <div style={{ width: 46, height: 46 }} dangerouslySetInnerHTML={{ __html: marcas[l.marca]?.svg || '' }} />
                <span style={{ fontFamily: fontes.corpo, fontSize: 32, color: cores.texto, flex: 1 }}>{l.nome}</span>
                <span
                  style={{
                    fontFamily: fontes.corpo,
                    fontSize: 30,
                    color: corVar,
                    opacity: faixa(frame, 12 + i * 6, 12 + i * 6 + 10, 0, 1)
                  }}
                >
                  {subiu ? '↑' : '↓'} {Math.abs(varia).toFixed(0)}%
                </span>
              </div>

              {/* barra do mês passado, apagada */}
              <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 8 }}>
                <span style={{ width: 70, fontFamily: fontes.corpoLeve, fontSize: 22, color: cores.cinza }}>antes</span>
                <div style={{ flex: 1, height: 16, borderRadius: 8, backgroundColor: cores.superficie, overflow: 'hidden' }}>
                  <div style={{ height: '100%', width: `${(l.antes / maximo) * 100 * p}%`, borderRadius: 8, backgroundColor: cores.superficieAlta }} />
                </div>
              </div>

              {/* barra do mês atual, acesa */}
              <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                <span style={{ width: 70, fontFamily: fontes.corpoLeve, fontSize: 22, color: cores.textoFraco }}>agora</span>
                <div style={{ flex: 1, height: 22, borderRadius: 11, backgroundColor: cores.superficie, overflow: 'hidden' }}>
                  <div
                    style={{
                      height: '100%',
                      width: `${(l.agora / maximo) * 100 * p}%`,
                      borderRadius: 11,
                      background: `linear-gradient(90deg, ${cores.azul}, ${subiu ? cores.vermelho : cores.azulClaro})`,
                      boxShadow: `0 0 24px ${subiu ? cores.vermelho : cores.azul}55`
                    }}
                  />
                </div>
              </div>
            </div>
          )
        })}
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'O que cresceu.'} destaque={dados.destaque || ['cresceu.']} atraso={0} tamanho={dados.tamanho || 78} />
    </Palco>
  )
}
