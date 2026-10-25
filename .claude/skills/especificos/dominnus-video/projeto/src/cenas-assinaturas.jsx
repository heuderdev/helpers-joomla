import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, interpolate, spring } from 'remotion'
import { cores, fontes, seguro } from './marca.js'
import { curvas, dur, ms, escalaEntrada, blurDeMovimento, movingHold } from './motion.js'
import { molas } from './movimento.jsx'
import { Palco, Legenda, Assinatura, faixa } from './cenas.jsx'
import icones from './icones.json'
import marcas from './marcas.json'

/*
 * CENAS DO VÍDEO "ASSINATURAS".
 *
 * Coreografia PRÓPRIA, não reaproveitada da abertura. A primeira tentativa
 * reusou `fluxo`/`categoriza`/`resposta` trocando só os dados, e o resultado
 * foi um vídeo que parecia o mesmo com outra legenda — trocar título e números
 * de uma coreografia não cria um vídeo novo.
 *
 * A forma visual sai do que cada frase PEDE:
 *   "some um dinheiro todo mês"  → calendário com cobranças pingando
 *   "acha o que se repete"       → radar varrendo lançamentos
 *   "confirma o que é de verdade"→ peneira: entra tudo, sai só assinatura
 *   "quanto dá no ano"           → 12 meses empilhando até o total
 */

/* --------------------------------------------------- calendário sangrando */

/**
 * O mês com as cobranças caindo nos dias.
 *
 * Grade de 5x7 (um mês), e as assinaturas pingam nos seus dias de cobrança.
 * Cada dia que recebe cobrança acende vermelho — o padrão espalhado é o ponto:
 * não é um gasto, são doze pequenos, o mês inteiro.
 */
export function CenaCalendarioSangra({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  /* dia do mês → marca que cobra nele */
  const COBRANCAS = dados.cobrancas || [
    [3, 'netflix'], [7, 'spotify'], [11, 'disneyplus'],
    [15, 'hbomax'], [19, 'youtube'], [24, 'amazon']
  ]
  const DIAS = 31
  const COLS = 7

  return (
    <Palco glow={0.5} cor={cores.vermelho}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 230, paddingBottom: 120 }}>
        <div style={{ display: 'grid', gridTemplateColumns: `repeat(${COLS}, 116px)`, gap: 14 }}>
          {Array.from({ length: DIAS }).map((_, i) => {
            const dia = i + 1
            const cobranca = COBRANCAS.find((c) => c[0] === dia)
            const entra = faixa(frame, 2 + i * 0.6, 2 + i * 0.6 + 8, 0, 1)

            /* a cobrança pinga depois de a grade toda estar montada */
            const ordem = COBRANCAS.findIndex((c) => c[0] === dia)
            const pinga = cobranca ? spring({ frame: frame - (24 + ordem * 6), fps, config: molas.pop }) : 0

            return (
              <div
                key={dia}
                style={{
                  width: 116,
                  height: 116,
                  borderRadius: 20,
                  backgroundColor: pinga > 0.15 ? `${cores.vermelho}1e` : cores.superficie,
                  border: `1px solid ${pinga > 0.15 ? `${cores.vermelho}77` : cores.borda}`,
                  opacity: entra,
                  transform: `scale(${escalaEntrada(entra, 0.9)})`,
                  position: 'relative',
                  display: 'flex',
                  alignItems: 'flex-start',
                  justifyContent: 'flex-start',
                  padding: 12,
                  boxShadow: pinga > 0.15 ? `0 0 34px ${cores.vermelho}33` : 'none'
                }}
              >
                <span style={{ fontFamily: fontes.corpoLeve, fontSize: 22, color: cores.textoFraco }}>{dia}</span>

                {cobranca && pinga > 0 && (
                  <div
                    style={{
                      position: 'absolute',
                      right: 10,
                      bottom: 10,
                      width: 52,
                      height: 52,
                      opacity: pinga,
                      transform: `scale(${escalaEntrada(pinga, 0.3)})`
                    }}
                    dangerouslySetInnerHTML={{ __html: marcas[cobranca[1]]?.svg || '' }}
                  />
                )}
              </div>
            )
          })}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Todo mês|some um valor.'} destaque={dados.destaque || ['some']} atraso={0} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ------------------------------------------------------------------ radar */

/**
 * O radar varrendo os lançamentos.
 *
 * Uma varredura circular gira sobre pontos espalhados; quando o feixe passa por
 * um ponto que se repete, ele ACENDE e fica. É a leitura visual de "acha o que
 * se repete" — o achado é o que permanece depois da passagem.
 */
export function CenaRadar({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const CX = 540
  const CY = 1030
  const R = 330

  /* pontos em ângulos fixos; os `repete: true` acendem quando varridos */
  const PONTOS = dados.pontos || [
    { ang: -80, d: 0.55, repete: true, marca: 'netflix' },
    { ang: -20, d: 0.8, repete: false },
    { ang: 30, d: 0.5, repete: true, marca: 'spotify' },
    { ang: 80, d: 0.85, repete: false },
    { ang: 130, d: 0.6, repete: true, marca: 'disneyplus' },
    { ang: 175, d: 0.9, repete: false },
    { ang: 215, d: 0.55, repete: true, marca: 'youtube' },
    { ang: 260, d: 0.82, repete: false },
    { ang: 305, d: 0.62, repete: true, marca: 'amazon' }
  ]

  /* uma volta a cada ~50 frames */
  const giro = (frame * 7.2) % 360

  return (
    <Palco glow={0.7}>
      <AbsoluteFill>
        <svg width="1080" height="1920" style={{ position: 'absolute' }}>
          <defs>
            <radialGradient id="feixe">
              <stop offset="0%" stopColor={cores.azul} stopOpacity="0.5" />
              <stop offset="100%" stopColor={cores.azul} stopOpacity="0" />
            </radialGradient>
          </defs>

          {/* anéis de referência */}
          {[0.35, 0.6, 0.85, 1].map((k, i) => (
            <circle
              key={i}
              cx={CX}
              cy={CY}
              r={R * k}
              fill="none"
              stroke={cores.borda}
              strokeWidth="1.5"
              opacity={faixa(frame, 1 + i * 2, 1 + i * 2 + 8, 0, 0.8)}
            />
          ))}

          {/* o feixe girando */}
          <g transform={`rotate(${giro} ${CX} ${CY})`} opacity={faixa(frame, 6, 14, 0, 1)}>
            <path d={`M ${CX} ${CY} L ${CX + R} ${CY - R * 0.42} A ${R} ${R} 0 0 1 ${CX + R} ${CY + R * 0.05} Z`} fill="url(#feixe)" />
            <line x1={CX} y1={CY} x2={CX + R} y2={CY} stroke={cores.azulClaro} strokeWidth="3" opacity="0.85" />
          </g>
        </svg>

        {PONTOS.map((pt, i) => {
          const rad = (pt.ang * Math.PI) / 180
          const x = CX + Math.cos(rad) * R * pt.d
          const y = CY + Math.sin(rad) * R * pt.d

          /* acende na primeira vez que o feixe passa pelo ângulo do ponto */
          const anguloNormalizado = (pt.ang + 360) % 360
          const voltas = Math.floor((frame * 7.2) / 360)
          const jaPassou = voltas > 0 || giro >= anguloNormalizado
          const aceso = pt.repete && jaPassou
          const brilho = aceso ? faixa(frame, 8, 20, 0.4, 1) : 0.35

          return (
            <div
              key={i}
              style={{
                position: 'absolute',
                left: x - (aceso ? 42 : 9),
                top: y - (aceso ? 42 : 9) + movingHold(i, 3, 100),
                width: aceso ? 84 : 18,
                height: aceso ? 84 : 18,
                borderRadius: aceso ? 20 : '50%',
                backgroundColor: aceso ? 'transparent' : cores.superficieAlta,
                opacity: faixa(frame, 4 + i, 4 + i + 8, 0, brilho),
                transition: 'none',
                filter: aceso ? `drop-shadow(0 0 22px ${cores.azul}aa)` : 'none'
              }}
              dangerouslySetInnerHTML={aceso && pt.marca ? { __html: marcas[pt.marca]?.svg || '' } : undefined}
            />
          )
        })}
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Ele acha|o que se repete.'} destaque={dados.destaque || ['repete.']} sub={dados.sub} atraso={0} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ----------------------------------------------------------------- peneira */

/**
 * A peneira da IA.
 *
 * Tudo entra por cima; a peneira retém o que É assinatura (fica preso, azul) e
 * deixa cair o que não é (atravessa e some, apagado). O julgamento vira física.
 */
export function CenaPeneira({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const ITENS = dados.itens || [
    { marca: 'netflix', passa: false, x: -230 },
    { marca: 'ifood', passa: true, x: -80 },
    { marca: 'spotify', passa: false, x: 70 },
    { marca: 'uber', passa: true, x: 220 },
    { marca: 'disneyplus', passa: false, x: -155 },
    { marca: 'rappi', passa: true, x: 145 }
  ]

  const YPENEIRA = 250

  return (
    <Palco glow={0.7}>
      <AbsoluteFill style={{ alignItems: 'center', paddingTop: 340 }}>
        <div style={{ position: 'relative', width: 900, height: 760 }}>
          {ITENS.map((it, i) => {
            const inicio = 2 + i * 4
            const cai = faixa(frame, inicio, inicio + 26, 0, 1, curvas.movimento)
            const y = cai * (it.passa ? 620 : YPENEIRA)

            /* quem passa some depois da peneira; quem fica assenta nela */
            const some = it.passa ? faixa(frame, inicio + 16, inicio + 26, 1, 0) : 1
            const preso = !it.passa && cai > 0.95

            return (
              <div
                key={i}
                style={{
                  position: 'absolute',
                  left: 450 + it.x - 46,
                  top: y,
                  width: 92,
                  height: 92,
                  opacity: faixa(frame, inicio, inicio + 5, 0, 1) * some * (it.passa ? 0.5 : 1),
                  transform: `scale(${preso ? 1 : 0.92}) rotate(${(1 - cai) * (i % 2 ? 12 : -12)}deg)`,
                  filter: preso ? `drop-shadow(0 0 26px ${cores.azul}88)` : 'none'
                }}
                dangerouslySetInnerHTML={{ __html: marcas[it.marca]?.svg || '' }}
              />
            )
          })}

          {/* a peneira */}
          <svg width="900" height="40" style={{ position: 'absolute', top: YPENEIRA + 96, left: 0 }}>
            {Array.from({ length: 22 }).map((_, i) => (
              <rect
                key={i}
                x={40 + i * 37}
                y={14}
                width={22}
                height={7}
                rx={3.5}
                fill={cores.azul}
                opacity={faixa(frame, 1 + i * 0.4, 1 + i * 0.4 + 6, 0, 0.85)}
              />
            ))}
          </svg>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'A IA separa|o que é de verdade.'} destaque={dados.destaque || ['verdade.']} sub={dados.sub} atraso={0} tamanho={dados.tamanho || 70} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ------------------------------------------------------------- ano empilha */

/**
 * Os 12 meses empilhando.
 *
 * Doze colunas iguais sobem uma a uma e o total do ano conta junto. O impacto
 * não vem do valor mensal — vem de ver doze vezes o mesmo valor virarem um
 * número que ninguém tinha somado.
 */
export function CenaAnoEmpilha({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const MES = dados.valorMes ?? 436.4
  const MESES = ['J', 'F', 'M', 'A', 'M', 'J', 'J', 'A', 'S', 'O', 'N', 'D']
  const contados = MESES.filter((_, i) => frame > 4 + i * 3.4).length
  const total = MES * contados

  return (
    <Palco glow={0.8}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 210, paddingBottom: 120, gap: 48 }}>
        <div style={{ display: 'flex', alignItems: 'flex-end', gap: 16, height: 420 }}>
          {MESES.map((m, i) => {
            const p = faixa(frame, 4 + i * 3.4, 4 + i * 3.4 + 14, 0, 1, curvas.enfase)
            return (
              <div key={i} style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12 }}>
                <div
                  style={{
                    width: 58,
                    height: p * 360,
                    borderRadius: 12,
                    background: `linear-gradient(180deg, ${cores.azulClaro}, ${cores.azul})`,
                    boxShadow: `0 0 24px ${cores.azul}44`
                  }}
                />
                <span style={{ fontFamily: fontes.corpoLeve, fontSize: 24, color: cores.textoFraco, opacity: p }}>{m}</span>
              </div>
            )
          })}
        </div>

        <div style={{ textAlign: 'center' }}>
          <div style={{ fontFamily: fontes.corpoLeve, fontSize: 30, color: cores.textoFraco }}>{dados.rotulo || 'no ano'}</div>
          <div
            style={{
              fontFamily: fontes.titulo,
              fontSize: 108,
              fontWeight: 700,
              color: cores.texto,
              letterSpacing: -4,
              textShadow: `0 0 60px ${cores.azul}66`
            }}
          >
            {total.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
          </div>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Por mês.|E por ano.'} destaque={dados.destaque || ['ano.']} atraso={0} tamanho={dados.tamanho || 74} />
    </Palco>
  )
}
