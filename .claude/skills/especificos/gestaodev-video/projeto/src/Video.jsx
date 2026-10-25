import React from 'react'
import { AbsoluteFill, Sequence, Audio, staticFile, Composition } from 'remotion'
import { cssFontes, cores } from './marca.js'
import { Cena } from './base.jsx'
import { montarCena } from './montador.jsx'
import indice from './indice.json'

/*
 * Uma composição por vídeo da série.
 *
 * Cada roteiro em `roteiros/*.json` gera seu próprio `tempos-<id>.json` (com os
 * cortes derivados dos timestamps da narração) e sua faixa de áudio. O
 * `indice.json` lista o que já foi gerado, então renderizar é
 * `remotion render src/index.js <Id>`.
 *
 * A narração é sempre uma fala CONTÍNUA: um mp3 por vídeo, nunca um por cena.
 * Frases geradas isoladamente são entoadas como sentença fechada e não
 * encadeiam — foi o que deixou a primeira versão com cara de leitor de tela.
 */
/**
 * Grain — o acabamento que separa 2D flat de "camadas empilhadas".
 *
 * O argumento não é estético, é funcional: o grain age como agente coesivo que
 * cola as superfícies, impedindo que o design flat leia como recortes soltos
 * um sobre o outro. Referência de dosagem: 15–20% em blend Overlay.
 *
 * Gerado por SVG `feTurbulence` em vez de vídeo de grain: não precisa de asset
 * externo e o ruído é resolvido no render, sem repetição perceptível.
 */
function Grain({ opacidade = 0.17 }) {
  return (
    <AbsoluteFill style={{ pointerEvents: 'none', mixBlendMode: 'overlay', opacity: opacidade }}>
      <svg width="100%" height="100%">
        <filter id="grao">
          {/* baseFrequency alto = grão fino. Abaixo de ~0.6 vira mancha. */}
          <feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="3" stitchTiles="stitch" />
          <feColorMatrix type="saturate" values="0" />
        </filter>
        <rect width="100%" height="100%" filter="url(#grao)" />
      </svg>
    </AbsoluteFill>
  )
}

/**
 * Vinheta sutil: escurece as bordas e empurra o olho para o centro.
 * Junto com o grain, é o que faz o quadro parecer "gradeado" em vez de chapado.
 */
function Vinheta() {
  return (
    <AbsoluteFill
      style={{
        pointerEvents: 'none',
        background: 'radial-gradient(ellipse at 50% 45%, transparent 45%, rgba(0,0,0,0.38) 100%)'
      }}
    />
  )
}

function Filme({ tempos, tema }) {
  return (
    <AbsoluteFill style={{ backgroundColor: cores.fundo }}>
      <style>{cssFontes}</style>

      <Audio src={staticFile(tempos.audioUnico)} />

      {tempos.cenas.map((cena, i) => (
        <Sequence key={cena.id} from={cena.inicio} durationInFrames={cena.frames} name={cena.id}>
          <Cena duracao={cena.frames} indice={i}>{montarCena(cena.conteudo, cena.frames, tema)}</Cena>
        </Sequence>
      ))}

      {/* Camadas de acabamento ficam ACIMA de tudo e fora das Sequence: são
          propriedade do filme inteiro, não de uma cena. */}
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
          width={1080}
          height={1920}
          defaultProps={{ tempos: v.tempos, tema: v.id }}
        />
      ))}
    </>
  )
}
