import React from 'react'
import { AbsoluteFill, interpolate, Easing } from 'remotion'
import { cores } from '../marca.js'

/*
 * PASSAGENS — transições conduzidas por ELEMENTO.
 *
 * O que havia antes: cada cena entrava com um gesto próprio e, por cima,
 * traços cruzavam o quadro no corte. O problema é que os traços não tinham
 * relação com o conteúdo — passavam "no meio do nada", como decoração. E como
 * as cenas nunca se sobrepunham, nenhuma podia revelar a outra.
 *
 * Aqui a troca é feita por um elemento que PERTENCE à cena: um círculo que se
 * abre a partir do ponto onde o dado estava, uma câmera que entra dentro do
 * número, um empurrão lateral com rastro na direção em que o dinheiro anda.
 *
 * Isso só é possível com `TransitionSeries`, onde as duas cenas COEXISTEM
 * durante a passagem. Sem sobreposição só dá para fade e escala.
 *
 * Os tempos seguem prática estabelecida de motion:
 *   match cut / scale-through .. 400–600ms · bezier(0.65, 0, 0.35, 1)
 *   circle wipe ............... 450–550ms · bezier(0.77, 0, 0.175, 1)
 *   whip pan .................. 270–400ms · bezier(0.9, 0, 0.1, 1) (quase step)
 *   entrada que assenta ....... 350–500ms · M3 emphasized-decelerate
 *   saída ..................... ~75% da entrada
 */

export const CURVAS = {
  expoOut: Easing.bezier(0.16, 1, 0.3, 1),
  quartInOut: Easing.bezier(0.77, 0, 0.175, 1),
  matchCut: Easing.bezier(0.65, 0, 0.35, 1),
  quaseStep: Easing.bezier(0.9, 0, 0.1, 1),
  m3Decelera: Easing.bezier(0.05, 0.7, 0.1, 1),
  m3Acelera: Easing.bezier(0.3, 0, 0.8, 0.15)
}

/*
 * Raio para um círculo em (cx, cy) cobrir os 1920x1080 inteiros.
 *
 * Tem de ser a distância até o canto MAIS DISTANTE. `circle(100%)` deixa canto
 * descoberto quando a origem não é o centro — e isso aparece como uma sobra de
 * um frame na virada.
 */
function raioParaCobrir(cx, cy) {
  const cantos = [[0, 0], [1920, 0], [0, 1080], [1920, 1080]]
  return Math.max(...cantos.map(([x, y]) => Math.hypot(x - cx, y - cy)))
}

/**
 * IRIS — a cena nova nasce de um PONTO da cena anterior.
 *
 * Use quando a cena que sai tem um elemento âncora (um número, um ícone, a
 * moeda): passe as coordenadas dele em `cx`/`cy` e a próxima cena parece
 * brotar exatamente dali. É a passagem que mais "amarra" duas cenas.
 */
export function iris({ cx = 960, cy = 540, raioInicial = 90 } = {}) {
  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      if (presentationDirection === 'exiting') {
        /*
         * A cena que sai recua E SOME.
         *
         * Antes ela só perdia 28% de opacidade, porque a ideia era "quem
         * conduz é quem entra". Com texto grande nas duas cenas, o resultado
         * era leitura empilhada: o número da anterior aparecia por dentro do
         * círculo do iris junto com o novo. Some rápido (o quadrado do
         * progresso) para liberar a leitura ainda no meio da passagem.
         */
        const some = interpolate(presentationProgress, [0, 0.45], [1, 0], {
          extrapolateRight: 'clamp',
          easing: CURVAS.m3Acelera
        })
        return (
          <AbsoluteFill
            style={{ transform: `scale(${1 - presentationProgress * 0.08})`, opacity: some }}
          >
            {children}
          </AbsoluteFill>
        )
      }

      const r = interpolate(presentationProgress, [0, 1], [raioInicial, raioParaCobrir(cx, cy)], {
        easing: CURVAS.quartInOut,
        extrapolateRight: 'clamp'
      })

      return (
        <AbsoluteFill style={{ clipPath: `circle(${r}px at ${cx}px ${cy}px)`, willChange: 'clip-path' }}>
          {children}
        </AbsoluteFill>
      )
    },
    props: {}
  }
}

/**
 * MATCH CUT — a câmera avança para DENTRO da cena anterior.
 *
 * Duas coisas separam isto de um zoom comum:
 *
 * 1. A escala é EXPONENCIAL. Interpolar 1→16 linearmente faz o crescimento
 *    parecer travar no fim; o olho lê escala em log, então a taxa aparente só
 *    fica constante interpolando no espaço logarítmico.
 * 2. As duas cenas escalam JUNTAS, em fatores diferentes — a que sai vai muito
 *    mais longe. Esse paralaxe é o que lê como câmera avançando, em vez de
 *    "elemento crescendo sobre um fundo parado".
 */
export function matchCut({ escalaSaida = 14, origem = '50% 50%' } = {}) {
  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      if (presentationDirection === 'exiting') {
        const escala = Math.exp(
          interpolate(presentationProgress, [0, 1], [0, Math.log(escalaSaida)], { easing: CURVAS.matchCut })
        )
        return (
          <AbsoluteFill
            style={{
              transform: `scale(${escala})`,
              transformOrigin: origem,
              opacity: interpolate(presentationProgress, [0, 0.5], [1, 0], { extrapolateRight: 'clamp' }),
              // v7: era *7. Com as passagens mais curtas o blur não tem tempo de
              // dissipar e o frame do corte sai borrado. 2.5px sugere o movimento
              // sem comer a legibilidade.
              filter: `blur(${presentationProgress * 2.5}px)`
            }}
          >
            {children}
          </AbsoluteFill>
        )
      }

      const escala = interpolate(presentationProgress, [0, 1], [0.58, 1], { easing: CURVAS.matchCut })
      return (
        <AbsoluteFill
          style={{
            transform: `scale(${escala})`,
            transformOrigin: origem,
            opacity: Math.min(1, presentationProgress * 2.2)
          }}
        >
          {children}
        </AbsoluteFill>
      )
    },
    props: {}
  }
}

/**
 * WHIP PAN — varredura lateral com rastro direcional.
 *
 * É a passagem das cenas de FLUXO: varre no mesmo sentido em que o dinheiro
 * anda na tela, então o movimento da câmera concorda com o argumento.
 *
 * O `filter: blur()` do CSS é ISOTRÓPICO — borra igual nos dois eixos e não
 * serve como rastro. Rastro direcional exige `feGaussianBlur` com
 * `stdDeviation` de dois valores: "40 0" borra só em X.
 *
 * E o blur acompanha a VELOCIDADE, não o progresso: é a distância percorrida
 * no frame que fisicamente causa rastro. Por isso a derivada é calculada a
 * cada frame, em vez de interpolar o blur direto.
 */
export function whipPan({ sentido = 1 } = {}) {
  const idBase = `whip${sentido > 0 ? 'D' : 'E'}`

  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      const desloca = (p) =>
        interpolate(p, [0, 1], [0, -1920 * sentido], { easing: CURVAS.quaseStep, extrapolateRight: 'clamp' })

      const passo = 1 / 12
      const velocidade = Math.abs(desloca(presentationProgress) - desloca(Math.max(0, presentationProgress - passo)))
      // v7.1: era *0.5 com teto de 55px. Ao encurtar a passagem de 9 para 6
      // frames a velocidade por frame subiu e o rastro virou mancha — a cena
      // aos 27s chegava ilegível. O teto de 14px mantém o rastro sugerido.
      const borrao = Math.min(velocidade * 0.16, 14)

      const id = `${idBase}-${presentationDirection}`
      const x =
        presentationDirection === 'exiting'
          ? desloca(presentationProgress)
          : desloca(presentationProgress) + 1920 * sentido

      return (
        <AbsoluteFill>
          <svg style={{ position: 'absolute', width: 0, height: 0 }}>
            <filter id={id} x="-60%" y="-10%" width="220%" height="120%" colorInterpolationFilters="sRGB">
              <feGaussianBlur in="SourceGraphic" stdDeviation={`${borrao} 0`} />
            </filter>
          </svg>
          <AbsoluteFill
            style={{ transform: `translateX(${x}px)`, filter: borrao > 1 ? `url(#${id})` : 'none' }}
          >
            {children}
          </AbsoluteFill>
        </AbsoluteFill>
      )
    },
    props: {}
  }
}

/**
 * EMPURRA — a cena nova empurra a anterior para cima.
 *
 * A passagem "neutra" do vídeo, para quando não há âncora nem direção de
 * fluxo. Curva M3 emphasized-decelerate: entra na velocidade máxima e
 * desacelera até parar, que é a prescrita para elemento que entra e assenta.
 */
export function empurra() {
  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      if (presentationDirection === 'exiting') {
        const y = interpolate(presentationProgress, [0, 1], [0, -300], { easing: CURVAS.m3Acelera })
        return (
          <AbsoluteFill
            style={{
              transform: `translateY(${y}px) scale(${1 - presentationProgress * 0.05})`,
              opacity: 1 - presentationProgress * 0.8
            }}
          >
            {children}
          </AbsoluteFill>
        )
      }
      const y = interpolate(presentationProgress, [0, 1], [480, 0], { easing: CURVAS.m3Decelera })
      return (
        <AbsoluteFill
          style={{ transform: `translateY(${y}px)`, opacity: Math.min(1, presentationProgress * 2.4) }}
        >
          {children}
        </AbsoluteFill>
      )
    },
    props: {}
  }
}

/**
 * ESTOURO — corte com um pulso de luz da marca.
 *
 * Reservado para as viradas de ATO, onde o corte deve ser sentido. Curto de
 * propósito: flash longo vira efeito de vídeo caseiro.
 */
export function estouro({ cor = cores.amarelo } = {}) {
  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      const brilho = Math.sin(presentationProgress * Math.PI)

      if (presentationDirection === 'exiting') {
        return (
          <AbsoluteFill
            style={{ transform: `scale(${1 + presentationProgress * 0.1})`, opacity: 1 - presentationProgress }}
          >
            {children}
          </AbsoluteFill>
        )
      }

      return (
        <AbsoluteFill>
          <AbsoluteFill
            style={{
              transform: `scale(${interpolate(presentationProgress, [0, 1], [1.13, 1], { easing: CURVAS.expoOut })})`,
              opacity: Math.min(1, presentationProgress * 2.6)
            }}
          >
            {children}
          </AbsoluteFill>
          <AbsoluteFill
            style={{
              backgroundColor: cor,
              opacity: brilho * 0.42,
              mixBlendMode: 'screen',
              pointerEvents: 'none'
            }}
          />
        </AbsoluteFill>
      )
    },
    props: {}
  }
}
