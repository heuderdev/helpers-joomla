import React from 'react'
import { useCurrentFrame, interpolate } from 'remotion'
import { cores } from '../marca.js'
import { curvas, ms, movingHold } from '../motion.js'

/*
 * O PRODUTOR — personagem do VSL, desenhado em SVG puro.
 *
 * Existe por dois motivos:
 *
 * 1. O roteiro fala com "você" o tempo todo. Sem uma figura na tela, "você" é
 *    abstrato; com ela, o espectador tem em quem se projetar.
 * 2. As cenas de fluxo de dinheiro precisam de um destino. Uma seta que aponta
 *    para o nada não conta história; uma que aponta para uma pessoa, sim.
 *
 * É deliberadamente geométrico — círculos e retângulos arredondados, sem
 * rosto detalhado. Traço fino demais viraria clipart; detalhe demais exigiria
 * ilustração de verdade e destoaria do resto, que é tipografia e dado.
 *
 * `estado` controla a expressão pela POSTURA e por um único traço de boca,
 * porque é o que lê a 1920px sem virar caricatura:
 *   neutro    → em pé, boca reta
 *   pensativo → cabeça levemente inclinada (cenas de pergunta)
 *   preocupado→ ombros caídos, boca curvada pra baixo (quando perde dinheiro)
 *   feliz     → ombros erguidos, boca curvada pra cima (quando recupera)
 */

/*
 * ZONA SEGURA — leia antes de posicionar o personagem.
 *
 * `y` é o QUADRIL, não os pés: o desenho ocupa de -90*escala (topo da cabeça)
 * a +120*escala (base do corpo) em torno dele.
 *
 * A câmera de `cameraZ` escala até 1.055 no fim da cena, a partir do centro —
 * o que empurra as bordas para fora do quadro. A borda inferior visível cai
 * para y≈1052. Com folga, o limite prático é:
 *
 *     y ≤ 1012 - 120 * escala
 *
 * Para escala 0.95 isso dá y ≤ 898. Ignorar essa conta é o que cortava os pés
 * do personagem mesmo com y < 1080.
 */
export function Produtor({
  x = 0,
  y = 0,
  escala = 1,
  estado = 'neutro',
  atraso = 0,
  cor = cores.amarelo
}) {
  const frame = useCurrentFrame()

  const p = interpolate(frame - atraso, [0, ms(620)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  // Respiração: o que separa "boneco" de "personagem vivo". Amplitude mínima
  // de propósito — o que se nota é a ausência de imobilidade, não o movimento.
  const respira = movingHold(0, 3.2, 132)
  const balanco = movingHold(1, 1.4, 190)

  const postura = {
    neutro: { inclina: 0, ombro: 0, boca: 'M -13 13 L 13 13' },
    pensativo: { inclina: -7, ombro: -2, boca: 'M -12 13 Q 0 11 12 14' },
    preocupado: { inclina: 4, ombro: 8, boca: 'M -13 17 Q 0 8 13 17' },
    feliz: { inclina: -2, ombro: -6, boca: 'M -14 10 Q 0 21 14 10' }
  }[estado] || { inclina: 0, ombro: 0, boca: 'M -13 13 L 13 13' }

  return (
    <g
      transform={`translate(${x} ${y}) scale(${escala})`}
      opacity={p}
    >
      <g transform={`translate(0 ${respira + postura.ombro * p})`}>
        {/* Corpo */}
        <rect
          x={-46} y={16} width={92} height={104}
          rx={30}
          fill={cores.superficie}
          stroke={cores.textoFraco}
          strokeWidth={2.5}
        />

        {/* Braços — sobem quando está feliz (cena da virada) */}
        <rect
          x={-64} y={34} width={18} height={54} rx={9}
          fill={cores.superficie} stroke={cores.textoFraco} strokeWidth={2.5}
          transform={estado === 'feliz' ? `rotate(-24 -55 40)` : `rotate(${balanco} -55 40)`}
        />
        <rect
          x={46} y={34} width={18} height={54} rx={9}
          fill={cores.superficie} stroke={cores.textoFraco} strokeWidth={2.5}
          transform={estado === 'feliz' ? `rotate(24 55 40)` : `rotate(${-balanco} 55 40)`}
        />

        {/* Cabeça */}
        <g transform={`translate(0 -46) rotate(${postura.inclina * p})`}>
          <circle r={44} fill={cores.superficie} stroke={cor} strokeWidth={3} />
          {/* Olhos: pontos simples — piscam de leve pela escala vertical */}
          <ellipse cx={-15} cy={-4} rx={5} ry={5.6} fill={cores.texto} />
          <ellipse cx={15} cy={-4} rx={5} ry={5.6} fill={cores.texto} />
          <path d={postura.boca} stroke={cores.textoFraco} strokeWidth={3} strokeLinecap="round" fill="none" />
        </g>
      </g>
    </g>
  )
}

/**
 * A PLATAFORMA — o outro lado da história.
 *
 * Prédio/cofre anônimo, sem rosto: é o contraponto do personagem humano. A
 * escolha é intencional — o vídeo não ataca uma empresa específica, ataca um
 * MODELO. Dar rosto a ele viraria acusação nominal; deixá-lo como estrutura
 * mantém a crítica no mecanismo, que é o que o roteiro defende.
 */
export function Plataforma({ x = 0, y = 0, escala = 1, atraso = 0, tom = cores.textoFraco, engordando = 0 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, ms(620)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  // Cresce conforme recebe dinheiro: a ganância fica literal, sem precisar de texto.
  const gordura = 1 + engordando * 0.16

  return (
    <g transform={`translate(${x} ${y}) scale(${escala})`} opacity={p}>
      <g transform={`scale(${gordura})`} style={{ transformOrigin: 'center bottom' }}>
        <rect
          x={-72} y={-96} width={144} height={192} rx={16}
          fill={cores.superficieAlta} stroke={tom} strokeWidth={2.5}
        />
        {/* Janelas — algumas acesas, dá a leitura de "prédio" na hora */}
        {[0, 1, 2].map((linha) =>
          [0, 1, 2].map((col) => {
            const acesa = (linha + col) % 3 !== 0
            return (
              <rect
                key={`${linha}-${col}`}
                x={-48 + col * 34} y={-72 + linha * 46}
                width={22} height={26} rx={4}
                fill={acesa ? `${tom}55` : 'transparent'}
                stroke={`${tom}44`} strokeWidth={1.5}
              />
            )
          })
        )}
      </g>
    </g>
  )
}
