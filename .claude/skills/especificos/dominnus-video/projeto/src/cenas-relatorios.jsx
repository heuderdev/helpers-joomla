import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, interpolate, spring } from 'remotion'
import { cores, fontes, seguro } from './marca.js'
import { curvas, dur, ms, escalaEntrada, movingHold } from './motion.js'
import { molas } from './movimento.jsx'
import { Palco, Legenda, Assinatura, faixa } from './cenas.jsx'
import icones from './icones.json'
import marcas from './marcas.json'

/*
 * CENAS DO VÍDEO "RELATÓRIOS".
 *
 * O tema é INICIATIVA: o app procura você, em horários certos. A forma visual
 * é sempre um APARELHO ou uma MENSAGEM chegando — nunca um gráfico solto.
 *
 *   "espera você abrir"    → app parado, notificações não lidas empilhando
 *   "às 20h chega"         → relógio marcando e a notificação entrando
 *   "resumo da semana"     → cartão de relatório se montando linha a linha
 *   "celular e e-mail"     → os três canais saindo do núcleo
 */

/* ------------------------------------------------------------ app parado */

/**
 * O ícone do app parado com o badge crescendo.
 *
 * Um app que você não abre acumula badge. O número sobe, o ícone fica cinza —
 * a imagem de "esperando você" sem precisar dizer.
 */
export function CenaAppParado({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const conta = Math.floor(faixa(frame, 6, 44, 0, dados.ate ?? 47))

  return (
    <Palco glow={0.4} cor={cores.cinza}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 180, paddingBottom: 140 }}>
        <div style={{ position: 'relative' }}>
          <div
            style={{
              width: 300,
              height: 300,
              borderRadius: 74,
              backgroundColor: cores.superficie,
              border: `1px solid ${cores.borda}`,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              filter: 'grayscale(1)',
              opacity: faixa(frame, 1, 14, 0, 0.75),
              transform: `scale(${escalaEntrada(faixa(frame, 1, 14, 0, 1), 0.88)})`
            }}
          >
            <div style={{ width: 150, height: 150, color: cores.cinza }} dangerouslySetInnerHTML={{ __html: icones['grafico-crescimento'] || icones['dinheiro'] }} />
          </div>

          {/* badge */}
          <div
            style={{
              position: 'absolute',
              top: -26,
              right: -26,
              minWidth: 104,
              height: 104,
              padding: '0 18px',
              borderRadius: 52,
              backgroundColor: cores.vermelho,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              boxShadow: `0 0 40px ${cores.vermelho}88`,
              transform: `scale(${1 + Math.sin(frame / 6) * 0.035})`,
              opacity: faixa(frame, 5, 12, 0, 1)
            }}
          >
            <span style={{ fontFamily: fontes.titulo, fontSize: 52, color: cores.texto }}>{conta}</span>
          </div>
        </div>

        <div style={{ marginTop: 44, fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco, opacity: faixa(frame, 24, 34, 0, 1) }}>
          {dados.rotulo || 'esperando você abrir'}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Todo app espera|você abrir.'} destaque={dados.destaque || ['espera']} atraso={0} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ------------------------------------------------------ notificação chega */

/**
 * A notificação chegando na hora marcada.
 *
 * Relógio digital grande marcando o horário, e o card da notificação desce
 * como no topo de um celular. É a cena que dá a sensação de "ele te procura".
 */
export function CenaNotificacaoChega({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const desce = spring({ frame: frame - 12, fps, config: molas.suave })
  const linhas = dados.linhas || [
    ['Entrou', 'R$ 0,00'],
    ['Saiu', 'R$ 257,60'],
    ['Lançamentos', '6']
  ]

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 260, paddingBottom: 120, gap: 54 }}>
        {/* relógio */}
        <div style={{ textAlign: 'center', opacity: faixa(frame, 1, 12, 0, 1) }}>
          <div
            style={{
              fontFamily: fontes.titulo,
              fontSize: 124,
              color: cores.texto,
              letterSpacing: -5,
              textShadow: `0 0 60px ${cores.azul}55`
            }}
          >
            {dados.hora || '20:00'}
          </div>
          <div style={{ fontFamily: fontes.corpoLeve, fontSize: 30, color: cores.textoFraco }}>{dados.dia || 'todo dia'}</div>
        </div>

        {/* card da notificação */}
        <div
          style={{
            width: 860,
            padding: '32px 36px',
            borderRadius: 34,
            backgroundColor: cores.superficie,
            border: `1px solid ${cores.azul}66`,
            boxShadow: `0 0 60px ${cores.azul}33`,
            opacity: desce,
            transform: `translateY(${(1 - desce) * -140}px) scale(${escalaEntrada(desce, 0.94)})`
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 18, marginBottom: 24 }}>
            <div style={{ width: 54, height: 54, borderRadius: 14, backgroundColor: cores.azul, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 12, color: cores.texto }}
              dangerouslySetInnerHTML={{ __html: icones['sino'] || icones['check-circulo'] }} />
            <span style={{ fontFamily: fontes.corpo, fontSize: 36, color: cores.texto, flex: 1 }}>{dados.tituloCard || 'Resumo do dia'}</span>
            <span style={{ fontFamily: fontes.corpoLeve, fontSize: 24, color: cores.textoFraco }}>agora</span>
          </div>

          {linhas.map(([k, v], i) => (
            <div
              key={k}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                padding: '14px 0',
                borderTop: i > 0 ? `1px solid ${cores.borda}` : 'none',
                opacity: faixa(frame, 22 + i * 4, 22 + i * 4 + 10, 0, 1)
              }}
            >
              <span style={{ fontFamily: fontes.corpoLeve, fontSize: 30, color: cores.textoFraco }}>{k}</span>
              <span style={{ fontFamily: fontes.corpo, fontSize: 30, color: cores.texto }}>{v}</span>
            </div>
          ))}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Às 20h,|ele te procura.'} destaque={dados.destaque || ['procura.']} atraso={0} tamanho={dados.tamanho || 74} />
    </Palco>
  )
}

/* ------------------------------------------------------- relatório montando */

/**
 * O relatório se escrevendo sozinho.
 *
 * As linhas do relatório aparecem em sequência, como se estivessem sendo
 * digitadas, e a barra comparativa cresce no fim. Diferente do card de
 * notificação: aqui é uma FOLHA, com cabeçalho e blocos.
 */
export function CenaRelatorioMontando({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const BLOCOS = dados.blocos || [
    ['Entrou', 'R$ 8.400,00', cores.verde],
    ['Saiu', 'R$ 6.423,50', cores.vermelho],
    ['Sobrou', 'R$ 1.976,50', cores.azulClaro]
  ]
  const comparativo = dados.comparativo || 'Você gastou 9% a menos que na semana passada.'

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 200 }}>
        <div
          style={{
            width: 860,
            borderRadius: 34,
            backgroundColor: cores.superficie,
            border: `1px solid ${cores.borda}`,
            overflow: 'hidden',
            opacity: faixa(frame, 1, 12, 0, 1),
            transform: `scale(${escalaEntrada(faixa(frame, 1, 12, 0, 1), 0.95)})`
          }}
        >
          <div style={{ padding: '30px 36px', borderBottom: `1px solid ${cores.borda}`, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <span style={{ fontFamily: fontes.titulo, fontSize: 38, color: cores.texto, letterSpacing: -1 }}>{dados.cabecalho || 'Sua semana'}</span>
            <span style={{ fontFamily: fontes.corpoLeve, fontSize: 26, color: cores.textoFraco }}>{dados.periodo || '12 a 18 de agosto'}</span>
          </div>

          <div style={{ padding: '10px 36px 28px' }}>
            {BLOCOS.map(([k, v, cor], i) => {
              const p = faixa(frame, 8 + i * 6, 8 + i * 6 + 14, 0, 1, curvas.entrada)
              return (
                <div
                  key={k}
                  style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    alignItems: 'baseline',
                    padding: '22px 0',
                    borderBottom: i < BLOCOS.length - 1 ? `1px solid ${cores.borda}` : 'none',
                    opacity: p,
                    transform: `translateX(${(1 - p) * -26}px)`
                  }}
                >
                  <span style={{ fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco }}>{k}</span>
                  <span style={{ fontFamily: fontes.titulo, fontSize: 44, color: cor, letterSpacing: -1.5 }}>{v}</span>
                </div>
              )
            })}
          </div>

          <div
            style={{
              padding: '24px 36px',
              backgroundColor: `${cores.azul}14`,
              borderTop: `1px solid ${cores.azul}44`,
              opacity: faixa(frame, 32, 32 + dur.entrada, 0, 1)
            }}
          >
            <span style={{ fontFamily: fontes.corpo, fontSize: 30, color: cores.texto }}>{comparativo}</span>
          </div>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'A semana|comparada.'} destaque={dados.destaque || ['comparada.']} atraso={0} tamanho={dados.tamanho || 74} />
    </Palco>
  )
}

/* ------------------------------------------------------------- três canais */

/**
 * Os canais saindo do núcleo.
 *
 * WhatsApp, push e e-mail partem do centro em três direções, ligados por
 * linhas. É o inverso da cena de bancos da abertura: lá tudo converge para o
 * núcleo, aqui tudo SAI dele.
 */
export function CenaCanais({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const CANAIS = dados.canais || [
    { icone: 'whatsapp', rotulo: 'WhatsApp', ang: -150 },
    { icone: 'celular', rotulo: 'Push', ang: -90 },
    { icone: 'envelope-1', rotulo: 'E-mail', ang: -30 }
  ]
  const CX = 540
  const CY = 1080
  const R = 300

  return (
    <Palco glow={0.85}>
      <AbsoluteFill>
        <svg width="1080" height="1920" style={{ position: 'absolute' }}>
          {CANAIS.map((c, i) => {
            const rad = (c.ang * Math.PI) / 180
            const x = CX + Math.cos(rad) * R
            const y = CY + Math.sin(rad) * R
            const traco = faixa(frame, 8 + i * 4, 8 + i * 4 + 14, 0, 1, curvas.movimento)
            const t = ((frame - 8 - i * 4 - 14) / 20) % 1
            const px = CX + (x - CX) * t
            const py = CY + (y - CY) * t
            return (
              <g key={c.rotulo}>
                <line x1={CX} y1={CY} x2={CX + (x - CX) * traco} y2={CY + (y - CY) * traco} stroke={cores.azul} strokeWidth="3" opacity="0.5" />
                {frame > 8 + i * 4 + 14 && <circle cx={px} cy={py} r="8" fill={cores.azulClaro} opacity={(1 - t) * 0.9} />}
              </g>
            )
          })}
        </svg>

        {/* núcleo emissor */}
        <div style={{ position: 'absolute', left: CX - 82, top: CY - 82 }}>
          <div
            style={{
              width: 164,
              height: 164,
              borderRadius: 44,
              backgroundColor: cores.azul,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              boxShadow: `0 0 70px ${cores.azul}aa`,
              transform: `scale(${1 + Math.sin(frame / 12) * 0.03})`
            }}
          >
            <div style={{ width: 76, height: 76, color: cores.texto }} dangerouslySetInnerHTML={{ __html: icones['enviar'] || icones['sino'] }} />
          </div>
        </div>

        {CANAIS.map((c, i) => {
          const rad = (c.ang * Math.PI) / 180
          const x = CX + Math.cos(rad) * R
          const y = CY + Math.sin(rad) * R
          const p = spring({ frame: frame - (14 + i * 4), fps, config: molas.pop })
          return (
            <div
              key={c.rotulo}
              style={{
                position: 'absolute',
                left: x - 78,
                top: y - 78 + movingHold(i, 5, 100),
                width: 156,
                display: 'flex',
                flexDirection: 'column',
                alignItems: 'center',
                gap: 12,
                opacity: p,
                transform: `scale(${escalaEntrada(p, 0.55)})`
              }}
            >
              <div
                style={{
                  width: 104,
                  height: 104,
                  borderRadius: 26,
                  backgroundColor: cores.superficie,
                  border: `1px solid ${cores.azul}66`,
                  color: cores.azulClaro,
                  padding: 24,
                  boxShadow: `0 0 34px ${cores.azul}44`
                }}
                dangerouslySetInnerHTML={{ __html: marcas[c.icone]?.svg || icones[c.icone] || '' }}
              />
              <span style={{ fontFamily: fontes.corpo, fontSize: 27, color: cores.textoFraco }}>{c.rotulo}</span>
            </div>
          )
        })}
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'No celular|e no e-mail.'} destaque={dados.destaque || ['e-mail.']} atraso={0} tamanho={dados.tamanho || 74} />
    </Palco>
  )
}
