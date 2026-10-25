import React from 'react'
import { AbsoluteFill, Audio, staticFile, Composition } from 'remotion'
import { TransitionSeries, linearTiming } from '@remotion/transitions'
import { fade } from '@remotion/transitions/fade'
import { iris, matchCut, whipPan, sobe, flash } from './transicoes.jsx'
import { Trilha, FaixaDeEfeitos } from './audio.jsx'
import { cssFontes, cores } from './marca.js'
import { Cena } from './base.jsx'
import { CENAS } from './cenas.jsx'
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

/*
 * TRANSIÇÃO POR CENA — declarada no próprio roteiro.
 *
 * Cada cena pode trazer `"transicao": { "tipo": "iris", "frames": 15 }`. Se não
 * trouxer, cai no padrão abaixo, que alterna por posição — assim um roteiro
 * novo já sai com corte variado sem precisar configurar nada, e só se ajusta o
 * que merece tratamento especial.
 *
 * Antes isso era um mapa fixo indexado por id de cena ('02', '03'), o que só
 * funcionava para um vídeo: os ids se repetem entre roteiros e as transições
 * de um vazariam para o outro.
 */
const PADRAO = [
  { tipo: 'whip', frames: 10, sentido: 1 },
  { tipo: 'sobe', frames: 12 },
  { tipo: 'matchCut', frames: 14 },
  { tipo: 'whip', frames: 10, sentido: -1 },
  { tipo: 'flash', frames: 8 },
  { tipo: 'sobe', frames: 12 }
]

function transicaoDe(cena, i) {
  if (i === 0) return null
  return cena.transicao || cena.conteudo?.transicao || PADRAO[(i - 1) % PADRAO.length]
}

/*
 * SOM POR EVENTO VISUAL — não por corte.
 *
 * Um whoosh em cada transição vira tique nervoso. O efeito entra onde há algo
 * acontecendo que o justifique: o logo que chega, o cadeado que trava, o card
 * que nasce da mensagem. Os frames são relativos ao início da cena e batem com
 * a coreografia definida em `cenas.jsx`.
 *
 * Indexado por TIPO de cena (não por id), então serve a todos os roteiros: uma
 * cena reaproveitada já vem com o som certo.
 */
const SONS = {
  planilha: [{ som: 'digita', frame: 4, volume: 0.5 }],
  planilhaColapso: [{ som: 'erro', frame: 6, volume: 0.7 }],
  caos: [
    { som: 'pop', frame: 2, volume: 0.5 },
    { som: 'pop', frame: 9, volume: 0.45 },
    { som: 'pop', frame: 15, volume: 0.4 }
  ],
  contaQuebrada: [{ som: 'erro', frame: 3, volume: 0.6 }],
  virada: [{ som: 'impacto', frame: 0, volume: 0.85 }],
  conecta: [{ som: 'chime', frame: 26, volume: 0.6 }],
  bancosChegam: [
    { som: 'pop', frame: 2, volume: 0.5 },
    { som: 'pop', frame: 8, volume: 0.45 },
    { som: 'pop', frame: 14, volume: 0.4 }
  ],
  bancosConectam: [{ som: 'chime', frame: 12, volume: 0.5 }],
  fluxo: [{ som: 'papel', frame: 2, volume: 0.45 }],
  categoriza: [
    { som: 'pop', frame: 7, volume: 0.45 },
    { som: 'pop', frame: 13, volume: 0.4 }
  ],
  whatsappDigita: [
    { som: 'digita', frame: 4, volume: 0.55 },
    { som: 'chime', frame: 32, volume: 0.5 }
  ],
  whatsappModos: [
    { som: 'pop', frame: 1, volume: 0.45 },
    { som: 'pop', frame: 5, volume: 0.42 },
    { som: 'pop', frame: 9, volume: 0.4 }
  ],
  importacao: [{ som: 'papel', frame: 2, volume: 0.5 }],
  pergunta: [{ som: 'pop', frame: 2, volume: 0.5 }],
  resposta: [{ som: 'whoosh', frame: 1, volume: 0.35 }],
  bancoCentral: [{ som: 'trava', frame: 19, volume: 0.9 }],
  somenteLeitura: [{ som: 'erro', frame: 9, volume: 0.5 }],

  /* --- whatsapp: o som segue a MENSAGEM, não o corte --- */
  formularioCansa: [
    { som: 'pop', frame: 4, volume: 0.35 },
    { som: 'pop', frame: 10, volume: 0.32 },
    { som: 'pop', frame: 16, volume: 0.3 }
  ],
  desistencia: [{ som: 'erro', frame: 12, volume: 0.6 }],
  conversaSolta: [
    { som: 'digita', frame: 2, volume: 0.5 },
    { som: 'chime', frame: 20, volume: 0.55 }   /* o cartão nascendo da bolha */
  ],
  audioVira: [{ som: 'pop', frame: 2, volume: 0.45 }],
  fotoLida: [
    { som: 'papel', frame: 3, volume: 0.5 },     /* o recibo entrando */
    { som: 'whoosh', frame: 14, volume: 0.3 },   /* a faixa varrendo */
    { som: 'chime', frame: 36, volume: 0.6 }     /* o valor saindo do papel */
  ],
  perguntaChat: [
    { som: 'digita', frame: 2, volume: 0.45 },
    { som: 'pop', frame: 22, volume: 0.5 }       /* a resposta chegando */
  ],
  respostaDetalha: [
    { som: 'pop', frame: 4, volume: 0.4 },
    { som: 'pop', frame: 11, volume: 0.36 },
    { som: 'pop', frame: 18, volume: 0.33 }
  ],
  confirmaAntes: [{ som: 'trava', frame: 4, volume: 0.7 }],

  /* --- open finance: o som marca o CICLO que roda sozinho --- */
  tempoPerdido: [{ som: 'erro', frame: 8, volume: 0.45 }],
  constanciaQuebra: [
    { som: 'pop', frame: 4, volume: 0.4 },
    { som: 'pop', frame: 12, volume: 0.36 },
    { som: 'erro', frame: 26, volume: 0.5 }      /* as semanas que falham */
  ],
  autorizaUmaVez: [
    { som: 'pop', frame: 18, volume: 0.6 },      /* o toque único */
    { som: 'trava', frame: 30, volume: 0.75 }    /* a autorização travando */
  ],
  sincronizaSozinho: [
    { som: 'chime', frame: 6, volume: 0.4 },
    { som: 'chime', frame: 24, volume: 0.34 },
    { som: 'chime', frame: 42, volume: 0.3 }     /* o pulso repetindo */
  ],
  todosOsBancos: [
    { som: 'pop', frame: 3, volume: 0.45 },
    { som: 'pop', frame: 9, volume: 0.4 },
    { som: 'pop', frame: 15, volume: 0.36 }
  ],
  chegaCategorizado: [
    { som: 'whoosh', frame: 5, volume: 0.32 },
    { som: 'whoosh', frame: 14, volume: 0.3 },
    { som: 'whoosh', frame: 23, volume: 0.28 }   /* cada transação deslizando */
  ],
  campoIntocado: [{ som: 'chime', frame: 14, volume: 0.5 }],
  leituraApenas: [{ som: 'trava', frame: 22, volume: 0.7 }],
  cta: [{ som: 'impacto', frame: 0, volume: 0.8 }]
}

/**
 * Posição real de cada cena dentro da TransitionSeries.
 *
 * A transição consome frames das duas vizinhas: cada cena é ampliada pelo
 * consumo e o conjunto recua pela duração da transição seguinte. Este cálculo
 * é o espelho exato do que o `Filme` monta — se um mudar, o outro muda junto,
 * senão o som descola da imagem.
 */
function posicoesReais(cenas) {
  let pos = 0
  return cenas.map((cena, i) => {
    const antes = i > 0 ? Math.ceil((transicaoDe(cena, i)?.frames || 0) / 2) : 0
    const proxima = cenas[i + 1]
    const framesProx = proxima ? transicaoDe(proxima, i + 1)?.frames || 0 : 0
    const depois = Math.floor(framesProx / 2)
    const inicio = pos
    pos += cena.frames + antes + depois - framesProx
    return { ...cena, inicio: inicio + antes }
  })
}

function apresentacaoDe(cfg) {
  if (cfg.tipo === 'iris') return iris({ cx: cfg.cx, cy: cfg.cy, raioInicial: cfg.raioInicial })
  if (cfg.tipo === 'matchCut') return matchCut({ escalaSaida: cfg.escalaSaida || 14 })
  if (cfg.tipo === 'whip') return whipPan({ sentido: cfg.sentido })
  if (cfg.tipo === 'sobe') return sobe()
  if (cfg.tipo === 'flash') return flash()
  /* continuidade: quase um corte seco — o elemento é que carrega a troca */
  return fade()
}

function Filme({ tempos, tema }) {
  return (
    <AbsoluteFill style={{ backgroundColor: cores.fundo }}>
      <style>{cssFontes}</style>

      {/* Narração — a camada que manda. */}
      <Audio src={staticFile(tempos.audioUnico)} />

      {/* Trilha bem abaixo, com fade de entrada e saída. */}
      <Trilha duracaoTotal={tempos.duracaoTotal} faixa="a" volume={0.5} />

      {/*
        * Efeitos ancorados nos eventos visuais de cada cena.
        *
        * As posições NÃO são as do índice: a TransitionSeries sobrepõe as
        * cenas, então cada uma começa antes do que o corte da narração diz.
        * Usar `cena.inicio` cru colocaria os sons cada vez mais atrasados ao
        * longo do vídeo — o mesmo tipo de erro acumulado que já apareceu nos
        * timestamps.
        */}
      <FaixaDeEfeitos cenas={posicoesReais(tempos.cenas)} mapa={SONS} />

      <TransitionSeries>
        {tempos.cenas.map((cena, i) => {
          const Comp = CENAS[cena.conteudo.cena] || CENAS.virada
          const cfg = transicaoDe(cena, i)
          /*
           * A transição CONSOME frames das duas cenas vizinhas (elas se
           * sobrepõem). Sem descontar, cada transição empurraria o vídeo para
           * frente e a fala sairia de sincronia com a imagem — o erro acumula
           * ao longo de 18 cortes.
           */
          const consumoAntes = i > 0 ? Math.ceil((cfg?.frames || 0) / 2) : 0
          const proxima = tempos.cenas[i + 1]
          const consumoDepois = proxima ? Math.floor((transicaoDe(proxima, i + 1)?.frames || 0) / 2) : 0
          const duracao = cena.frames + consumoAntes + consumoDepois

          return (
            <React.Fragment key={cena.id}>
              {i > 0 && cfg && <TransitionSeries.Transition presentation={apresentacaoDe(cfg)} timing={linearTiming({ durationInFrames: cfg.frames })} />}
              <TransitionSeries.Sequence durationInFrames={duracao}>
                {/* Sem <Cena> aqui: ela aplicava o punch/desloc de cameraZ, e
                    com a TransitionSeries no comando isso empilharia duas
                    transições no mesmo corte. */}
                <Comp duracao={duracao} dados={cena.conteudo} />
              </TransitionSeries.Sequence>
            </React.Fragment>
          )
        })}
      </TransitionSeries>

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
