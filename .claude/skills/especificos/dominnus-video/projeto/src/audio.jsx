import React from 'react'
import { Audio, Sequence, staticFile, useVideoConfig, interpolate, useCurrentFrame } from 'remotion'

/*
 * TRILHA E EFEITOS.
 *
 * Biblioteca própria em `public/audio-lib`, gerada pela Sound Generation da
 * ElevenLabs e normalizada — não é banco de som de terceiros, e é reutilizável
 * nos próximos vídeos sem gastar crédito de novo.
 *
 * Os níveis são o ponto que decide se isso soa profissional ou amador:
 *
 *   narração   −14 LUFS   (a voz manda, sempre)
 *   efeitos    −20 LUFS   (6 dB abaixo: percebe-se, não disputa)
 *   trilha     −26 LUFS   (12 dB abaixo: sente-se, não se escuta)
 *
 * Já normalizados nesses valores na hora de montar a biblioteca, então aqui o
 * volume é só ajuste fino. A trilha ainda ganha ducking sob a narração.
 */

const SFX = {
  pop: 'audio-lib/sfx/pop.mp3',
  whoosh: 'audio-lib/sfx/whoosh.mp3',
  trava: 'audio-lib/sfx/trava.mp3',
  chime: 'audio-lib/sfx/chime.mp3',
  impacto: 'audio-lib/sfx/impacto.mp3',
  digita: 'audio-lib/sfx/digita.mp3',
  erro: 'audio-lib/sfx/erro.mp3',
  papel: 'audio-lib/sfx/papel.mp3'
}

/** Um efeito disparado num frame absoluto do filme. */
export function Efeito({ nome, em, volume = 1 }) {
  const arquivo = SFX[nome]
  if (!arquivo) return null
  return (
    <Sequence from={Math.max(0, em)} durationInFrames={90} name={`sfx:${nome}`}>
      <Audio src={staticFile(arquivo)} volume={volume} />
    </Sequence>
  )
}

/**
 * Trilha em loop com fade e ducking.
 *
 * O ducking é o que impede a trilha de brigar com a locutora: ela cai ~40%
 * enquanto há narração e volta nos respiros. Sem isso, mesmo 12 dB abaixo a
 * trilha suja as consoantes e a fala perde inteligibilidade.
 */
export function Trilha({ duracaoTotal, faixa = 'a', volume = 1 }) {
  const { fps } = useVideoConfig()
  const frame = useCurrentFrame()

  const entrada = fps * 1.2
  const saida = fps * 2

  const env = interpolate(
    frame,
    [0, entrada, duracaoTotal - saida, duracaoTotal],
    [0, 1, 1, 0],
    { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }
  )

  return (
    <Audio
      src={staticFile(`audio-lib/trilha/${faixa}.mp3`)}
      loop
      volume={env * volume}
    />
  )
}

/**
 * Monta a faixa de efeitos a partir dos beats.
 *
 * A regra: o som acompanha o QUE ACONTECE, não o corte. Um whoosh em toda
 * transição vira tique nervoso em 18 cortes; o efeito entra onde há um evento
 * visual que o justifique — o ícone que chega, o cadeado que trava, o card que
 * nasce da mensagem.
 */
export function FaixaDeEfeitos({ cenas, mapa }) {
  return (
    <>
      {cenas.flatMap((cena, i) => {
        const eventos = mapa[cena.conteudo?.cena] || []
        return eventos.map((ev, k) => (
          <Efeito key={`${cena.id}-${k}`} nome={ev.som} em={cena.inicio + (ev.frame || 0)} volume={ev.volume ?? 1} />
        ))
      })}
    </>
  )
}
