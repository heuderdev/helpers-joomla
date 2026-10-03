import React from 'react'
import { Audio, Sequence, staticFile, useVideoConfig, useCurrentFrame, interpolate } from 'remotion'

/*
 * TRILHA E EFEITOS do VSL.
 *
 * Os arquivos vêm da biblioteca da Dominnus (gerada pela Sound Generation da
 * ElevenLabs e já normalizada), copiada para `public/vsl/audio/`. Reaproveitar
 * evita gastar crédito de novo e mantém o mesmo padrão de mixagem.
 *
 * Os níveis são o que separa profissional de amador:
 *
 *   narração   −14 LUFS   a voz manda, sempre
 *   efeitos    −20 LUFS   6 dB abaixo: percebe-se, não disputa
 *   trilha     −26 LUFS   12 dB abaixo: sente-se, não se escuta
 *
 * Já normalizados nesses valores na biblioteca — aqui o volume é ajuste fino.
 *
 * REGRA QUE MAIS IMPORTA: o som acompanha o EVENTO, não o corte. Um whoosh em
 * cada uma das 34 passagens vira tique nervoso. O efeito entra onde há algo que
 * o justifique — a moeda que chega, o número que assenta, a logo que aparece.
 */

const SFX = {
  pop: 'vsl/audio/sfx/pop.mp3',
  whoosh: 'vsl/audio/sfx/whoosh.mp3',
  trava: 'vsl/audio/sfx/trava.mp3',
  chime: 'vsl/audio/sfx/chime.mp3',
  impacto: 'vsl/audio/sfx/impacto.mp3',
  digita: 'vsl/audio/sfx/digita.mp3',
  erro: 'vsl/audio/sfx/erro.mp3',
  papel: 'vsl/audio/sfx/papel.mp3',
  // Atmosféricos: carregam EMOÇÃO, não são UI sounds. Trovão na perda,
  // ascensão na virada, tensão no acúmulo.
  trovao: 'vsl/audio/sfx/trovao.mp3',
  tensao: 'vsl/audio/sfx/tensao.mp3',
  ascensao: 'vsl/audio/sfx/ascensao.mp3',
  brilho: 'vsl/audio/sfx/brilho.mp3',
  queda: 'vsl/audio/sfx/queda.mp3',
  caixa: 'vsl/audio/sfx/caixa.mp3'
}

/** Um efeito disparado num frame ABSOLUTO do filme. */
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
 * Trilha em loop, com fade de entrada e saída.
 *
 * As faixas têm 22s; o VSL tem ~164s, então são ~8 repetições. O loop é
 * montado por <Sequence> em vez de `loop` nativo para o fade final cair no
 * frame certo do filme, não no da faixa.
 */
export function Trilha({ duracaoTotal, faixa = 'a', volume = 0.9 }) {
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

  // 22s por faixa (a biblioteca foi gerada nessa duração).
  const duracaoFaixa = Math.round(fps * 22)
  const repeticoes = Math.ceil(duracaoTotal / duracaoFaixa)

  return (
    <>
      {Array.from({ length: repeticoes }).map((_, i) => (
        <Sequence key={i} from={i * duracaoFaixa} durationInFrames={duracaoFaixa} name={`trilha:${i}`}>
          <Audio src={staticFile(`vsl/audio/trilha/${faixa}.mp3`)} volume={env * volume} />
        </Sequence>
      ))}
    </>
  )
}

/**
 * Faixa de efeitos do filme.
 *
 * `eventos` é uma lista de `{ som, em, volume }` com `em` em frames ABSOLUTOS.
 * Quem calcula essas posições é o Vsl.jsx, porque só ele sabe onde cada cena
 * realmente começa depois que a TransitionSeries sobrepõe as passagens.
 */
export function FaixaDeEfeitos({ eventos }) {
  return (
    <>
      {eventos.map((e, i) => (
        <Efeito key={`${e.som}-${e.em}-${i}`} nome={e.som} em={e.em} volume={e.volume ?? 1} />
      ))}
    </>
  )
}
