import React from 'react'
import { Img, staticFile, useCurrentFrame, interpolate, AbsoluteFill } from 'remotion'
import { cores, margem } from './marca.js'
import { curvas, ms, atrasoStagger } from './motion.js'
import { Etiqueta, Titulo, Progresso } from './base.jsx'
import { Print } from './print.jsx'

/** Abertura: a arte da Magnific + logo, com a câmera fechando lentamente. */
export function Abertura({ titulo, subtitulo, duracao }) {
  const frame = useCurrentFrame()

  const zoom = interpolate(frame, [0, duracao], [1.12, 1.0], { extrapolateRight: 'clamp', easing: curvas.movimento })
  const logo = interpolate(frame, [ms(120), ms(760)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  return (
    <AbsoluteFill style={{ background: cores.fundo }}>
      <AbsoluteFill style={{ transform: `scale(${zoom})`, transformOrigin: 'center' }}>
        <Img src={staticFile('fundo-abertura.png')} style={{ width: '100%', height: '100%', objectFit: 'cover' }} />
      </AbsoluteFill>

      <AbsoluteFill
        style={{ background: 'linear-gradient(100deg, rgba(11,10,13,0.94) 0%, rgba(11,10,13,0.72) 46%, rgba(11,10,13,0.18) 100%)' }}
      />

      <AbsoluteFill style={{ justifyContent: 'center', padding: `0 ${margem + 40}px` }}>
        <Img
          src={staticFile('logo-branco.svg')}
          style={{
            width: 310,
            marginBottom: 46,
            opacity: logo,
            transform: `translateY(${(1 - logo) * 18}px)`
          }}
        />
        <Titulo texto={titulo} tamanho={92} atraso={ms(340)} />
        <div style={{ height: 26 }} />
        <Sub texto={subtitulo} atraso={ms(680)} />
      </AbsoluteFill>
    </AbsoluteFill>
  )
}

function Sub({ texto, atraso = 0, cor = cores.textoFraco, tamanho = 33 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, ms(460)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.padrao
  })
  return (
    <div style={{ fontSize: tamanho, color: cor, opacity: p, transform: `translateY(${(1 - p) * 14}px)`, maxWidth: 900, lineHeight: 1.45 }}>
      {texto}
    </div>
  )
}

/**
 * Cena de print: texto à esquerda, tela à direita.
 * O olho lê o rótulo, depois a tela — a ordem do stagger respeita isso.
 */
export function CenaPrint({ conteudo, duracao, passo, total }) {
  const url = conteudo.marca === 'pagarme' ? 'dash.pagar.me' : 'app.pagzero.com'

  return (
    <AbsoluteFill style={{ background: cores.fundo }}>
      <Brilho cor={conteudo.marca === 'pagarme' ? cores.pagarme : cores.amarelo} />

      <AbsoluteFill style={{ flexDirection: 'row', alignItems: 'center', padding: `0 ${margem}px`, gap: 64 }}>
        <div style={{ width: 456, display: 'flex', flexDirection: 'column', gap: 26 }}>
          <div>
            <Etiqueta
              texto={conteudo.etiqueta}
              cor={conteudo.marca === 'pagarme' ? cores.pagarme : cores.amarelo}
            />
          </div>
          <Titulo texto={conteudo.titulo} tamanho={58} atraso={ms(160)} />
          <div style={{ marginTop: 10 }}>
            <Progresso passo={passo} total={total} />
          </div>
        </div>

        <Print
          arquivo={conteudo.arquivo}
          foco={conteudo.foco}
          url={url}
          marca={conteudo.marca}
          destaque={conteudo.destaque}
          duracao={duracao}
        />
      </AbsoluteFill>
    </AbsoluteFill>
  )
}

/** Halo de cor atrás da cena — profundidade sem custo de leitura. */
function Brilho({ cor = cores.amarelo }) {
  return (
    <AbsoluteFill
      style={{
        background: `radial-gradient(900px 620px at 82% 50%, ${cor}1F 0%, transparent 68%)`
      }}
    />
  )
}

/** Os eventos entrando como chips — cada um com seu tempo. */
export function CenaEventos({ conteudo, duracao, passo, total }) {
  const frame = useCurrentFrame()

  return (
    <AbsoluteFill style={{ background: cores.fundo }}>
      <Brilho />
      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center', padding: `0 ${margem}px`, gap: 44 }}>
        <Etiqueta texto={conteudo.etiqueta} />
        <div style={{ textAlign: 'center', display: 'flex', justifyContent: 'center' }}>
          <Titulo texto={conteudo.titulo} tamanho={68} atraso={ms(140)} />
        </div>

        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 16, justifyContent: 'center', maxWidth: 1240, marginTop: 8 }}>
          {conteudo.itens.map((ev, i) => {
            const at = ms(420) + atrasoStagger(i, conteudo.itens.length, ms(900))
            const p = interpolate(frame - at, [0, ms(420)], [0, 1], {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
              easing: curvas.enfase
            })
            const assinatura = ev.startsWith('invoice')
            const cor = assinatura ? cores.textoFraco : cores.amarelo

            return (
              <div
                key={ev}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 14,
                  padding: '18px 28px',
                  borderRadius: 16,
                  background: cores.superficie,
                  border: `1px solid ${p > 0.5 ? `${cor}55` : cores.borda}`,
                  opacity: p,
                  transform: `translateY(${(1 - p) * 20}px) scale(${0.95 + p * 0.05})`
                }}
              >
                <Check cor={cor} p={p} />
                <span style={{ fontSize: 29, color: assinatura ? cores.textoFraco : cores.texto, letterSpacing: -0.3 }}>{ev}</span>
              </div>
            )
          })}
        </div>

        <div style={{ marginTop: 12 }}>
          <Progresso passo={passo} total={total} />
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  )
}

/** O check desenhando — o traço faz a marcação parecer uma ação, não um estado. */
function Check({ cor, p }) {
  const risco = Math.max(0, Math.min(1, (p - 0.35) / 0.5))
  return (
    <svg width="26" height="26" viewBox="0 0 26 26" style={{ flexShrink: 0 }}>
      <rect x="1.5" y="1.5" width="23" height="23" rx="7" fill="none" stroke={`${cor}66`} strokeWidth="1.6" />
      <path
        d="M7 13.4 L11.2 17.4 L19 8.8"
        fill="none"
        stroke={cor}
        strokeWidth="2.6"
        strokeLinecap="round"
        strokeLinejoin="round"
        strokeDasharray="20"
        strokeDashoffset={20 * (1 - risco)}
      />
    </svg>
  )
}

/** Encerramento. */
export function CenaCta({ conteudo, duracao }) {
  const frame = useCurrentFrame()

  const anel = interpolate(frame, [0, ms(900)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  return (
    <AbsoluteFill style={{ background: cores.fundo }}>
      <AbsoluteFill
        style={{ background: `radial-gradient(760px 560px at 50% 46%, ${cores.amarelo}22 0%, transparent 70%)` }}
      />

      <AbsoluteFill style={{ justifyContent: 'center', alignItems: 'center', gap: 30 }}>
        <Img
          src={staticFile('simbolo.svg')}
          style={{
            width: 128,
            opacity: anel,
            transform: `scale(${0.9 + anel * 0.1}) rotate(${(1 - anel) * -22}deg)`
          }}
        />
        <div style={{ display: 'flex', justifyContent: 'center', marginTop: 6 }}>
          <Titulo texto={conteudo.titulo} tamanho={96} atraso={ms(260)} />
        </div>
        <Sub texto={conteudo.subtitulo} atraso={ms(560)} tamanho={36} />

        <div style={{ marginTop: 30 }}>
          <Etiqueta texto={conteudo.cta} atraso={ms(820)} />
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  )
}

/**
 * Cena de conceito: título à esquerda, cards à direita entrando em cascata.
 * Serve para explicar o que uma coisa É, quando não há print que ajude.
 */
export function CenaConceito({ conteudo, duracao, passo, total }) {
  const frame = useCurrentFrame()
  const itens = conteudo.itens || []

  return (
    <AbsoluteFill style={{ background: cores.fundo }}>
      <Brilho />
      <AbsoluteFill style={{ flexDirection: 'row', alignItems: 'center', padding: `0 ${margem}px`, gap: 72 }}>
        <div style={{ width: 470, display: 'flex', flexDirection: 'column', gap: 26 }}>
          {conteudo.etiqueta && (
            <div>
              <Etiqueta texto={conteudo.etiqueta} />
            </div>
          )}
          <Titulo texto={conteudo.titulo} tamanho={58} atraso={ms(160)} />
          {conteudo.subtitulo && <Sub texto={conteudo.subtitulo} atraso={ms(420)} tamanho={27} />}
          {passo ? (
            <div style={{ marginTop: 10 }}>
              <Progresso passo={passo} total={total} />
            </div>
          ) : null}
        </div>

        <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 18 }}>
          {itens.map((item, i) => {
            const at = ms(380) + atrasoStagger(i, itens.length, ms(760))
            const p = interpolate(frame - at, [0, ms(440)], [0, 1], {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
              easing: curvas.enfase
            })
            const titulo = typeof item === 'string' ? item : item.titulo
            const detalhe = typeof item === 'string' ? null : item.detalhe

            return (
              <div
                key={i}
                style={{
                  display: 'flex',
                  alignItems: 'flex-start',
                  gap: 22,
                  padding: '26px 30px',
                  borderRadius: 20,
                  background: cores.superficie,
                  border: `1px solid ${cores.borda}`,
                  opacity: p,
                  transform: `translateX(${(1 - p) * 34}px)`
                }}
              >
                <div
                  style={{
                    width: 42,
                    height: 42,
                    borderRadius: 12,
                    flexShrink: 0,
                    background: `${cores.amarelo}1F`,
                    border: `1px solid ${cores.amarelo}44`,
                    display: 'flex',
                    alignItems: 'center',
                    justifyContent: 'center',
                    fontSize: 21,
                    fontWeight: 600,
                    color: cores.amarelo
                  }}
                >
                  {i + 1}
                </div>
                <div style={{ display: 'flex', flexDirection: 'column', gap: 6 }}>
                  <span style={{ fontSize: 31, fontWeight: 600, color: cores.texto, letterSpacing: -0.5 }}>{titulo}</span>
                  {detalhe && <span style={{ fontSize: 24, color: cores.textoFraco, lineHeight: 1.4 }}>{detalhe}</span>}
                </div>
              </div>
            )
          })}
        </div>
      </AbsoluteFill>
    </AbsoluteFill>
  )
}
