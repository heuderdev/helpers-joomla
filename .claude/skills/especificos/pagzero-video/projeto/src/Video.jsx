import React from 'react'
import { AbsoluteFill, Sequence, Audio, staticFile, Composition } from 'remotion'
import { cssFontes, cores, fontes, T } from './marca.js'
import { Cena } from './base.jsx'
import { montarCena } from './montador.jsx'
import indice from './indice.json'
import { Vsl, duracaoTotal as duracaoVsl } from './vsl/Vsl.jsx'
import temposVsl from './vsl/tempos.json'

function Grain({ opacidade = 0.13 }) {
  return (
    <AbsoluteFill style={{ pointerEvents: 'none', mixBlendMode: 'overlay', opacity: opacidade }}>
      <svg width="100%" height="100%">
        <filter id="grao">
          <feTurbulence type="fractalNoise" baseFrequency="0.8" numOctaves="3" stitchTiles="stitch" />
          <feColorMatrix type="saturate" values="0" />
        </filter>
        <rect width="100%" height="100%" filter="url(#grao)" />
      </svg>
    </AbsoluteFill>
  )
}

function Vinheta() {
  return (
    <AbsoluteFill
      style={{
        pointerEvents: 'none',
        background: 'radial-gradient(ellipse at 50% 48%, transparent 52%, rgba(0,0,0,0.34) 100%)'
      }}
    />
  )
}

function Filme({ tempos }) {
  // Os passos numerados são só as cenas de tutorial — abertura e CTA ficam fora.
  const passos = tempos.cenas.filter((c) => c.conteudo.etiqueta)
  const total = passos.length

  return (
    <AbsoluteFill style={{ backgroundColor: cores.fundo, fontFamily: fontes.corpo }}>
      <style>{cssFontes}</style>

      <Audio src={staticFile(tempos.audioUnico)} />

      {tempos.cenas.map((cena, i) => {
        const passo = passos.findIndex((c) => c.id === cena.id) + 1
        return (
          <Sequence key={cena.id} from={cena.inicio} durationInFrames={cena.frames} name={cena.id}>
            <Cena duracao={cena.frames} indice={i}>
              {montarCena(cena.conteudo, cena.frames, passo, total)}
            </Cena>
          </Sequence>
        )
      })}

      <Vinheta />
      <Grain />
    </AbsoluteFill>
  )
}

export function RemotionRoot() {
  return (
    <>
      {indice.videos.map((v) => (
        <Composition
          key={v.id}
          id={v.id}
          component={Filme}
          durationInFrames={v.tempos.duracaoTotal}
          fps={v.tempos.fps}
          width={T.w}
          height={T.h}
          defaultProps={{ tempos: v.tempos }}
        />
      ))}

      {/* VSL "Quem está pagando a conta" — 35 cenas. Composição própria: usa
          blocos e roteiro de src/vsl/, sem tocar nos vídeos curtos acima. */}
      <Composition
        id="vsl-quem-paga-a-conta"
        component={Vsl}
        durationInFrames={duracaoVsl(temposVsl)}
        fps={30}
        width={T.w}
        height={T.h}
        defaultProps={{ tempos: temposVsl }}
      />
    </>
  )
}
