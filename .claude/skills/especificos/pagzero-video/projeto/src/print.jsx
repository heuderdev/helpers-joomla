import React from 'react'
import { Img, staticFile, useCurrentFrame, interpolate } from 'remotion'
import { cores } from './marca.js'
import { curvas, ms } from './motion.js'

/*
 * Os prints nasceram do viewport de 1568x767 e foram upscalados 2x. As
 * coordenadas de `foco` no roteiro estão na escala ORIGINAL — foi assim que
 * eu li a tela.
 */
const BASE = { w: 1568, h: 767 }

/*
 * Alguns prints saíram de um viewport diferente (a área do aluno veio 1512x796).
 * Uma proporção errada aqui desalinha o realce — foi o bug que custou uma
 * rodada inteira no vídeo do Pagar.me. Por isso a exceção é declarada, não
 * adivinhada.
 */
const BASES = { 'am-aluno-login@2x.png': { w: 1512, h: 796 } }

/*
 * A janela respeita a PROPORÇÃO do print (1568:767). Essa é a correção que
 * fez os realces baterem: antes a janela era 1240x640 com `objectFit: cover`,
 * e o cover aplicava um recorte/escala próprios que o retângulo de realce não
 * conhecia. Com a proporção casada e `width/height` explícitos, imagem e
 * realce compartilham UMA geometria só — `k` abaixo vale para os dois.
 */
const LARGURA = 1240

/** Moldura do navegador — sem ela o print flutua e perde o contexto de tela real. */
function Chrome({ url, marca }) {
  const cor = marca === 'pagarme' ? cores.pagarme : cores.amarelo
  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: 12,
        height: 46,
        padding: '0 18px',
        background: '#26252B',
        borderBottom: '1px solid rgba(255,255,255,0.06)'
      }}
    >
      {['#FF5F57', '#FEBC2E', '#28C840'].map((c) => (
        <span key={c} style={{ width: 12, height: 12, borderRadius: 999, background: c }} />
      ))}
      <div
        style={{
          marginLeft: 14,
          flex: 1,
          height: 28,
          borderRadius: 8,
          background: 'rgba(0,0,0,0.28)',
          display: 'flex',
          alignItems: 'center',
          padding: '0 14px',
          gap: 10
        }}
      >
        <span style={{ width: 7, height: 7, borderRadius: 999, background: cor }} />
        <span style={{ fontSize: 16, color: 'rgba(255,255,255,0.55)', letterSpacing: 0.2 }}>{url}</span>
      </div>
    </div>
  )
}

/**
 * O print com câmera dirigida: começa no enquadramento inteiro e fecha na
 * região que a narração descreve. O movimento carrega a informação.
 */
export function Print({ arquivo, foco, url, marca, destaque, duracao }) {
  const frame = useCurrentFrame()
  const base = BASES[arquivo] || BASE
  const JAN = { w: LARGURA, h: Math.round((LARGURA * base.h) / base.w) }
  const k = JAN.w / base.w
  const temFoco = Boolean(foco)

  const z = interpolate(frame, [ms(260), ms(1500)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.movimento
  })

  /*
   * Transformação escrita como: escala em torno do CENTRO da janela, mais um
   * deslocamento que traz o centro do foco até esse centro.
   *
   * Com transformOrigin no centro, um ponto p (em px de janela) vai parar em
   *   p' = centro + (p - centro) * escala + t
   * Igualando p' ao centro para p = centroDoFoco, sai
   *   t = (centro - centroDoFoco) * escala
   * — o `* escala` é o que faltava antes, e é a razão de o realce parecer
   * "jogado pra um canto" justamente quando o zoom era maior.
   */
  let escala = 1
  let tx = 0
  let ty = 0

  if (temFoco) {
    const fw = foco.w * k
    const fh = foco.h * k
    const alvo = Math.min(JAN.w / fw, JAN.h / fh) * 0.8
    const zoom = Math.max(1, Math.min(2.6, alvo))
    escala = 1 + (zoom - 1) * z

    const cxFoco = (foco.x + foco.w / 2) * k
    const cyFoco = (foco.y + foco.h / 2) * k

    tx = (JAN.w / 2 - cxFoco) * escala * z
    ty = (JAN.h / 2 - cyFoco) * escala * z
  } else {
    escala = interpolate(frame, [0, duracao], [1.015, 1.055], { extrapolateRight: 'clamp' })
  }

  const entrada = interpolate(frame, [0, ms(480)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  // Imagem e realce usam EXATAMENTE esta string — é o que garante o alinhamento.
  const camera = `translate(${tx}px, ${ty}px) scale(${escala})`

  return (
    <div
      style={{
        width: JAN.w,
        borderRadius: 18,
        overflow: 'hidden',
        background: '#26252B',
        border: '1px solid rgba(255,255,255,0.08)',
        boxShadow: '0 40px 90px rgba(0,0,0,0.6), 0 0 0 1px rgba(255,255,255,0.03)',
        opacity: entrada,
        transform: `translateY(${(1 - entrada) * 26}px) scale(${0.97 + entrada * 0.03})`
      }}
    >
      <Chrome url={url} marca={marca} />

      <div style={{ position: 'relative', width: JAN.w, height: JAN.h, overflow: 'hidden', background: '#fff' }}>
        <div
          style={{
            position: 'absolute',
            left: 0,
            top: 0,
            width: JAN.w,
            height: JAN.h,
            transformOrigin: 'center center',
            transform: camera,
            willChange: 'transform'
          }}
        >
          {/* width/height explícitos e proporção casada: nada de objectFit aqui. */}
          <Img src={staticFile(`prints/${arquivo}`)} style={{ display: 'block', width: JAN.w, height: JAN.h }} />
          {temFoco && <Realce foco={foco} escala={escala} destaque={destaque} k={k} />}
        </div>
      </div>
    </div>
  )
}

/**
 * O retângulo em volta do campo. Vive DENTRO da camada transformada, então
 * acompanha a câmera de graça — não há segunda matemática para divergir.
 */
function Realce({ foco, escala, destaque, k }) {
  const frame = useCurrentFrame()

  const p = interpolate(frame, [ms(900), ms(1320)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  if (p <= 0) return null

  const l = foco.x * k
  const t = foco.y * k
  const w = foco.w * k
  const h = foco.h * k

  const pulso = destaque ? 1 + Math.sin(frame / 9) * 0.015 : 1
  const cor = cores.amarelo

  // Traço e sombra dividem pela escala para manter espessura constante na tela.
  return (
    <div
      style={{
        position: 'absolute',
        left: l - 6,
        top: t - 6,
        width: w + 12,
        height: h + 12,
        borderRadius: 12,
        border: `${2.5 / escala}px solid ${cor}`,
        boxShadow: `0 0 0 9999px rgba(11,10,13,${0.42 * p}), 0 0 ${26 / escala}px ${cor}66`,
        opacity: p,
        transform: `scale(${pulso})`,
        transformOrigin: 'center center',
        pointerEvents: 'none'
      }}
    />
  )
}
