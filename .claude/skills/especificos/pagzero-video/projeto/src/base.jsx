import React from 'react'
import { AbsoluteFill, useCurrentFrame, interpolate } from 'remotion'
import { cores } from './marca.js'
import { cameraZ, curvas, ms } from './motion.js'

/**
 * Envelope de cena: câmera Z + blur de movimento.
 * Transição de cena é CÂMERA, não fade — é o que separa motion de slideshow.
 */
export function Cena({ duracao, indice = 0, children }) {
  const frame = useCurrentFrame()
  const cam = cameraZ(frame, duracao, { indice })

  return (
    <AbsoluteFill
      style={{
        opacity: cam.opacidade,
        transform: `scale(${cam.escala})`,
        filter: cam.blur,
        willChange: 'transform, opacity, filter'
      }}
    >
      {children}
    </AbsoluteFill>
  )
}

/** Etiqueta de passo — a âncora que diz onde a pessoa está no tutorial. */
export function Etiqueta({ texto, cor = cores.amarelo, atraso = 0 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, ms(360)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  return (
    <div
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 14,
        padding: '12px 24px 12px 20px',
        borderRadius: 999,
        background: `${cor}1A`,
        border: `1.5px solid ${cor}44`,
        opacity: p,
        transform: `translateY(${(1 - p) * 14}px)`
      }}
    >
      <span style={{ width: 9, height: 9, borderRadius: 999, background: cor }} />
      <span style={{ fontSize: 25, fontWeight: 600, letterSpacing: 0.4, color: cor }}>{texto}</span>
    </div>
  )
}

/** Título de cena com stagger por palavra — leitura guiada, sem overshoot. */
export function Titulo({ texto, tamanho = 68, atraso = 0, cor = cores.texto }) {
  const frame = useCurrentFrame()
  const palavras = texto.split(' ')
  const orcamento = ms(340)

  return (
    <div style={{ display: 'flex', flexWrap: 'wrap', gap: '0 18px', maxWidth: 1100 }}>
      {palavras.map((palavra, i) => {
        const at = atraso + i * Math.max(ms(28), orcamento / palavras.length)
        const p = interpolate(frame - at, [0, ms(420)], [0, 1], {
          extrapolateLeft: 'clamp',
          extrapolateRight: 'clamp',
          easing: curvas.enfase
        })
        return (
          <span
            key={i}
            style={{
              fontSize: tamanho,
              fontWeight: 600,
              lineHeight: 1.1,
              letterSpacing: -1.6,
              color: cor,
              opacity: p,
              transform: `translateY(${(1 - p) * 22}px)`,
              display: 'inline-block'
            }}
          >
            {palavra}
          </span>
        )
      })}
    </div>
  )
}

/** Barra de progresso do tutorial — orientação sem custo de atenção. */
export function Progresso({ passo, total }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame, [0, ms(600)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.padrao
  })

  return (
    <div style={{ display: 'flex', gap: 8 }}>
      {Array.from({ length: total }).map((_, i) => {
        const ativo = i < passo
        const atual = i === passo - 1
        return (
          <div
            key={i}
            style={{
              width: atual ? 46 : 22,
              height: 5,
              borderRadius: 999,
              background: ativo ? cores.amarelo : cores.borda,
              opacity: atual ? p : ativo ? 0.55 : 0.4,
              transition: 'none'
            }}
          />
        )
      })}
    </div>
  )
}
