import React from 'react'
import { AbsoluteFill, interpolate, Easing } from 'remotion'
import { cores } from './marca.js'

/*
 * TRANSIÇÕES ELEMENT-DRIVEN.
 *
 * A versão anterior trocava as cenas por opacidade + escala: o corte era limpo,
 * mas nada CONDUZIA a troca. Aqui a transição é feita por um elemento — um
 * círculo que se abre a partir do ícone, um empurrão lateral com rastro, uma
 * câmera que entra dentro da marca.
 *
 * As duas cenas COEXISTEM durante a transição (via TransitionSeries), que é o
 * que torna possível uma revelar a outra. Sem sobreposição só dá para
 * fade/scale, que é onde estávamos.
 *
 * Todos os números vêm de prática documentada, não de gosto:
 *   - match cut / scale-through: 400–600ms, cubic-bezier(0.65, 0, 0.35, 1)
 *   - circle wipe (adaptado de UI 1000ms para vídeo): 450–550ms, (0.77, 0, 0.175, 1)
 *   - whip pan: 270–400ms, (0.9, 0, 0.1, 1) — quase step, é o que dá o snap
 *   - shared element: 350–500ms, M3 emphasized-decelerate (0.05, 0.7, 0.1, 1)
 *   - saída ≈ 75% da entrada
 */

export const CURVAS = {
  expoOut: Easing.bezier(0.16, 1, 0.3, 1),
  quartInOut: Easing.bezier(0.77, 0, 0.175, 1),
  matchCut: Easing.bezier(0.65, 0, 0.35, 1),
  quaseStep: Easing.bezier(0.9, 0, 0.1, 1),
  m3Decelera: Easing.bezier(0.05, 0.7, 0.1, 1),
  m3Acelera: Easing.bezier(0.3, 0, 0.8, 0.15)
}

/**
 * Raio necessário para um círculo em (cx, cy) cobrir o quadro inteiro.
 *
 * Precisa ser a distância até o canto MAIS DISTANTE. Usar `circle(100%)` deixa
 * canto descoberto quando a origem não é o centro — e num 9:16 isso aparece
 * como uma sobra de um frame.
 */
function raioParaCobrir(cx, cy, L = 1080, A = 1920) {
  return Math.max(
    Math.hypot(cx, cy),
    Math.hypot(L - cx, cy),
    Math.hypot(cx, A - cy),
    Math.hypot(L - cx, A - cy)
  )
}

/**
 * IRIS — a cena nova nasce de um ponto e se abre até cobrir o quadro.
 *
 * O raio começa no tamanho do elemento que originou a transição (`raioInicial`),
 * nunca em zero: começando em zero o círculo "nasce do nada"; começando no raio
 * do ícone, o círculo É o ícone se expandindo. É a diferença entre um wipe
 * genérico e uma transição conduzida pelo elemento.
 */
export function iris({ cx = 540, cy = 900, raioInicial = 95 } = {}) {
  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      if (presentationDirection === 'exiting') {
        /* A cena que sai só recua de leve — quem conduz é a que entra. */
        return (
          <AbsoluteFill style={{ transform: `scale(${1 - presentationProgress * 0.06})`, opacity: 1 - presentationProgress * 0.25 }}>
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
 * MATCH CUT — a câmera entra "dentro" da cena anterior.
 *
 * Duas coisas separam isto de um zoom comum:
 *
 * 1. A escala é EXPONENCIAL. Interpolar 1→18 linearmente faz o crescimento
 *    parecer travar no fim; o olho lê escala em log, então a taxa aparente só
 *    fica constante interpolando no espaço logarítmico.
 * 2. As duas cenas escalam JUNTAS, em fatores diferentes (a que sai vai muito
 *    mais longe). Esse paralaxe é o que lê como câmera avançando, em vez de
 *    "elemento crescendo sobre um fundo parado".
 */
export function matchCut({ escalaSaida = 16, origem = '50% 50%' } = {}) {
  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      if (presentationDirection === 'exiting') {
        const escala = Math.exp(interpolate(presentationProgress, [0, 1], [0, Math.log(escalaSaida)], { easing: CURVAS.matchCut }))
        return (
          <AbsoluteFill style={{ transform: `scale(${escala})`, transformOrigin: origem, opacity: 1 - presentationProgress * 0.9, filter: `blur(${presentationProgress * 14}px)` }}>
            {children}
          </AbsoluteFill>
        )
      }

      /* A que entra vem de dentro: começa pequena e assenta. */
      const escala = interpolate(presentationProgress, [0, 1], [0.55, 1], { easing: CURVAS.matchCut })
      return (
        <AbsoluteFill style={{ transform: `scale(${escala})`, transformOrigin: origem, opacity: Math.min(1, presentationProgress * 2.2) }}>
          {children}
        </AbsoluteFill>
      )
    },
    props: {}
  }
}

/**
 * WHIP PAN — varre lateralmente com rastro direcional.
 *
 * O `filter: blur()` do CSS é ISOTRÓPICO: borra igual nos dois eixos e não
 * serve para rastro de movimento. O rastro direcional exige `feGaussianBlur`
 * com `stdDeviation` de dois valores ("40 0" borra só em X).
 *
 * O blur acompanha a VELOCIDADE, não o progresso: é a distância percorrida no
 * frame, que é o que fisicamente causa rastro. Por isso a derivada é calculada
 * a cada frame em vez de interpolar o blur direto.
 */
export function whipPan({ sentido = 1 } = {}) {
  const idBase = `whip${sentido > 0 ? 'D' : 'E'}`

  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      const desloca = (p) => interpolate(p, [0, 1], [0, -1080 * sentido], { easing: CURVAS.quaseStep, extrapolateRight: 'clamp' })

      const passo = 1 / 12
      const velocidade = Math.abs(desloca(presentationProgress) - desloca(Math.max(0, presentationProgress - passo)))
      /* multiplicador 0.4–0.6: o valor "fisicamente real" (1.0) borra demais */
      const borrao = Math.min(velocidade * 0.5, 55)

      const id = `${idBase}-${presentationDirection}`
      const x = presentationDirection === 'exiting' ? desloca(presentationProgress) : desloca(presentationProgress) + 1080 * sentido

      return (
        <AbsoluteFill>
          <svg style={{ position: 'absolute', width: 0, height: 0 }}>
            <filter id={id} x="-60%" y="-10%" width="220%" height="120%" colorInterpolationFilters="sRGB">
              {/* stdDeviation só em X: o eixo perpendicular fica em zero, senão
                  vira desfoque comum em vez de rastro. */}
              <feGaussianBlur in="SourceGraphic" stdDeviation={`${borrao} 0`} />
            </filter>
          </svg>
          <AbsoluteFill style={{ transform: `translateX(${x}px)`, filter: borrao > 1 ? `url(#${id})` : 'none' }}>{children}</AbsoluteFill>
        </AbsoluteFill>
      )
    },
    props: {}
  }
}

/**
 * SOBE — a cena nova empurra a anterior para cima.
 *
 * Curva M3 emphasized-decelerate: entra na velocidade máxima e desacelera até
 * parar. É a curva prescrita para elementos que entram e assentam no quadro.
 */
export function sobe() {
  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      if (presentationDirection === 'exiting') {
        const y = interpolate(presentationProgress, [0, 1], [0, -260], { easing: CURVAS.m3Acelera })
        return <AbsoluteFill style={{ transform: `translateY(${y}px) scale(${1 - presentationProgress * 0.05})`, opacity: 1 - presentationProgress * 0.8 }}>{children}</AbsoluteFill>
      }
      const y = interpolate(presentationProgress, [0, 1], [420, 0], { easing: CURVAS.m3Decelera })
      return <AbsoluteFill style={{ transform: `translateY(${y}px)`, opacity: Math.min(1, presentationProgress * 2.4) }}>{children}</AbsoluteFill>
    },
    props: {}
  }
}

/**
 * FLASH — corte seco com um estouro de luz da marca.
 *
 * Para as viradas de tom, onde o corte deve ser sentido. Curto de propósito:
 * um flash longo vira efeito de vídeo caseiro.
 */
export function flash() {
  return {
    component: ({ children, presentationProgress, presentationDirection }) => {
      const brilho = Math.sin(presentationProgress * Math.PI)

      if (presentationDirection === 'exiting') {
        return (
          <AbsoluteFill style={{ transform: `scale(${1 + presentationProgress * 0.1})`, opacity: 1 - presentationProgress }}>
            {children}
          </AbsoluteFill>
        )
      }

      return (
        <AbsoluteFill>
          <AbsoluteFill style={{ transform: `scale(${interpolate(presentationProgress, [0, 1], [1.14, 1], { easing: CURVAS.expoOut })})`, opacity: Math.min(1, presentationProgress * 2.6) }}>
            {children}
          </AbsoluteFill>
          <AbsoluteFill style={{ backgroundColor: cores.azul, opacity: brilho * 0.5, mixBlendMode: 'screen', pointerEvents: 'none' }} />
        </AbsoluteFill>
      )
    },
    props: {}
  }
}
