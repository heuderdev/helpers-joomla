import React from 'react'
import { useCurrentFrame, interpolate } from 'remotion'
import { cores } from '../marca.js'
import { curvas, ms, escalaEntrada } from '../motion.js'

/*
 * VIDA — o que faz o elemento parecer vivo depois que assenta.
 *
 * O problema que resolve: com entrada bem animada e nada depois, todo elemento
 * congela assim que chega. Em peça de quase três minutos isso lê como uma
 * sequência de slides bem animados, não como motion.
 *
 * A regra que evita virar circo: o movimento pós-entrada é SEMPRE de amplitude
 * baixa e período longo. O que se deve perceber é a ausência de imobilidade,
 * não o movimento em si. Amplitude alta aqui é o que separa motion caro de
 * PowerPoint com animação.
 */

/**
 * PULSO DE CHEGADA — o número "bate" quando assenta.
 *
 * Uma escala que passa de 1 e volta, curtíssima (~260ms). É o acento que marca
 * o instante em que o dado chega, e é o gesto que mais barato entrega
 * "produzido". Só nos números que importam: em tudo, vira tique.
 */
export function usarPulso(quando, forca = 0.07) {
  const frame = useCurrentFrame()
  const d = frame - quando
  if (d < 0 || d > ms(320)) return 1
  const p = d / ms(320)
  // sin(πp) sobe e volta a zero: entra e sai sem overshoot residual.
  return 1 + Math.sin(p * Math.PI) * forca
}

/**
 * RESPIRAÇÃO — escala que oscila devagar, indefinidamente.
 *
 * Para elementos que ficam muito tempo na tela (a marca, o CTA). Período longo
 * (4–6s) e amplitude de 1–2%: some na percepção consciente e mantém o quadro
 * vivo.
 */
export function usarRespiro(periodo = 150, amplitude = 0.015, fase = 0) {
  const frame = useCurrentFrame()
  return 1 + Math.sin((frame / periodo) * Math.PI * 2 + fase) * amplitude
}

/**
 * DERIVA — deslocamento lento em dois eixos.
 *
 * Diferente do respiro por mover em vez de escalar. Usado em conjuntos (os
 * cards de gateway, as pastilhas): cada item com fase própria, então o grupo
 * inteiro nunca fica estático nem se move em bloco.
 */
export function usarDeriva(indice = 0, amplitude = 4, periodo = 170) {
  const frame = useCurrentFrame()
  return {
    x: Math.sin((frame / periodo) * Math.PI * 2 + indice * 1.7) * amplitude,
    y: Math.cos((frame / (periodo * 1.3)) * Math.PI * 2 + indice * 1.1) * amplitude * 0.7
  }
}

/**
 * ANEL DE IMPACTO — onda que expande e some a partir de um ponto.
 *
 * Marca o instante em que algo importante acontece: o número que assenta, a
 * moeda que chega. Dura ~700ms e desaparece — é acento, não decoração fixa.
 */
export function Anel({ x, y, quando = 0, cor = cores.amarelo, tamanho = 240, espessura = 3 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - quando, [0, ms(700)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  if (p <= 0.001 || p >= 0.999) return null

  return (
    <div
      style={{
        position: 'absolute',
        left: x - tamanho / 2,
        top: y - tamanho / 2,
        width: tamanho,
        height: tamanho,
        borderRadius: 999,
        border: `${espessura}px solid ${cor}`,
        opacity: (1 - p) * 0.65,
        transform: `scale(${0.5 + p * 1.6})`,
        pointerEvents: 'none'
      }}
    />
  )
}

/**
 * BRILHO QUE VARRE — reflexo que atravessa um elemento.
 *
 * O gesto de "objeto premium": uma faixa de luz cruzando a superfície. Usado
 * na marca e no botão do CTA, onde há uma superfície grande o bastante para o
 * reflexo ser lido.
 */
export function Varredura({ quando = 0, duracao = ms(900), cor = '#FFFFFF' }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - quando, [0, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.movimento
  })

  if (p <= 0.001 || p >= 0.999) return null

  return (
    <div
      style={{
        position: 'absolute',
        inset: 0,
        overflow: 'hidden',
        pointerEvents: 'none',
        borderRadius: 'inherit'
      }}
    >
      <div
        style={{
          position: 'absolute',
          top: '-50%',
          bottom: '-50%',
          width: '45%',
          left: `${-45 + p * 145}%`,
          background: `linear-gradient(100deg, transparent, ${cor}44, transparent)`,
          transform: 'skewX(-18deg)'
        }}
      />
    </div>
  )
}

/**
 * CONTADOR COM BATIDA — número que sobe e pulsa ao chegar no valor.
 *
 * Junta as duas coisas que dão vida a um dado: o valor rodando (o olho
 * acompanha a subida) e o pulso no instante em que trava.
 */
export function NumeroVivo({
  de = 0,
  para,
  atraso = 0,
  duracao = ms(900),
  prefixo = 'R$ ',
  sufixo = '',
  decimais = 0,
  style
}) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  const v = de + (para - de) * p
  const texto = v.toLocaleString('pt-BR', { minimumFractionDigits: decimais, maximumFractionDigits: decimais })

  // A batida acontece quando o contador TRAVA, não quando começa.
  const pulso = usarPulso(atraso + duracao, 0.08)

  return (
    <span
      style={{
        fontVariantNumeric: 'tabular-nums',
        display: 'inline-block',
        transform: `scale(${pulso})`,
        ...style
      }}
    >
      {prefixo}
      {texto}
      {sufixo}
    </span>
  )
}
