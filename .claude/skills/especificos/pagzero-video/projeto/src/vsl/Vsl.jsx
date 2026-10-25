import React from 'react'
import { AbsoluteFill, Audio, Sequence, staticFile, useCurrentFrame, interpolate } from 'remotion'
import { TransitionSeries, linearTiming } from '@remotion/transitions'
import { cssFontes, cores, fontes } from '../marca.js'
import { curvas, ms, cameraZ } from '../motion.js'
import { cenas as roteiro, FPS } from './roteiro.js'

import { Frase1, Pergunta, Negacao, Parcelamento, Martelo, Impacto, Fluxo, TaxaReal } from './blocos-ato1.jsx'
import { Capitulo, Cadeia, Revenda, ListaFuncoes, CustoPix, TaxaCheia, ComparaPix } from './blocos-ato23.jsx'
import { Calendario, Reviravolta, Somatorio, ContaMes, ReceitaLucro, Margem, ViradaMarca, Zeros, Gateways, VendaInteira, Cta } from './blocos-ato456.jsx'
import { Frase, Palco } from './primitivas.jsx'
import { useTransicao, Particulas } from './transicoes.jsx'
import { iris, matchCut, whipPan, empurra, estouro } from './passagens.jsx'
import { Trilha, FaixaDeEfeitos } from './audio.jsx'
import { FundoClaro } from './tema.jsx'
import { Fio, Blobs, Grade } from './continuidade.jsx'
import { Icone } from './icone.jsx'

/*
 * O VSL montado. Cada cena do roteiro vira uma <Sequence> com o bloco que a
 * desenha; o áudio da narração toca por cima de tudo, contínuo.
 *
 * As durações vêm de `tempos.json` quando ele existe (gerado a partir dos
 * timestamps reais da ElevenLabs). Sem ele, caem no `dur` do roteiro — o que
 * permite ver o vídeo no Studio ANTES de gastar crédito de narração.
 */

/** Frase genérica com realce — cobre as cenas que são só texto. */
function BlocoFrase({ props, icone }) {
  const frame = useCurrentFrame()

  /*
   * A frase solta ficava parada no quadro depois de entrar. Agora ela tem
   * companhia: moedas SUBINDO ao fundo quando a frase é de recuperação
   * ("o juro volta"), e um brilho que percorre a palavra realçada.
   *
   * O sentido do movimento importa: nas cenas de perda o dinheiro desce; aqui
   * ele sobe. É o mesmo elemento contando o oposto.
   */
  const ehVolta = /volta/i.test(props.realce || '')

  const moedas = ehVolta
    ? Array.from({ length: 9 }).map((_, i) => {
        const nasce = ms(300) + i * ms(180)
        const p = interpolate(frame, [nasce, nasce + ms(1600)], [0, 1], {
          extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
        })
        if (p <= 0) return null
        return {
          i,
          x: 1180 + (i * 137) % 620,
          y: 1140 - p * 1260,
          op: Math.sin(p * Math.PI) * 0.85,
          escala: 0.5 + Math.sin(p * Math.PI) * 0.5,
          giro: p * 220
        }
      }).filter(Boolean)
    : []

  return (
    <Palco>
      {ehVolta && (
        <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
          {moedas.map((m) => (
            <g key={m.i} transform={`translate(${m.x} ${m.y}) scale(${m.escala}) rotate(${m.giro})`}
               opacity={m.op}>
              <circle r={26} fill={cores.amarelo} stroke="#8A6800" strokeWidth={3} />
              <text y={9} textAnchor="middle" fontSize={26} fontWeight={700} fill="#8A6800">$</text>
            </g>
          ))}
        </svg>
      )}
      <Frase texto={props.texto} realce={props.realce} tamanho={92} />
    </Palco>
  )
}


const BLOCOS = {
  frase: BlocoFrase,
  pergunta: Pergunta,
  negacao: Negacao,
  parcelamento: Parcelamento,
  martelo: Martelo,
  impacto: Impacto,
  fluxo: Fluxo,
  taxaReal: TaxaReal,
  capitulo: Capitulo,
  cadeia: Cadeia,
  revenda: Revenda,
  listaFuncoes: ListaFuncoes,
  custoPix: CustoPix,
  taxaCheia: TaxaCheia,
  comparaPix: ComparaPix,
  calendario: Calendario,
  reviravolta: Reviravolta,
  somatorio: Somatorio,
  contaMes: ContaMes,
  receitaLucro: ReceitaLucro,
  margem: Margem,
  viradaMarca: ViradaMarca,
  zeros: Zeros,
  gateways: Gateways,
  vendaInteira: VendaInteira,
  cta: Cta
}

/*
 * A cena 1 tem tratamento próprio: leva o personagem, e o bloco `frase` genérico
 * não o desenha. Mapeada aqui para não poluir o roteiro com detalhe de layout.
 */
const ESPECIAIS = { 1: Frase1 }

/**
 * Envelope da cena: gesto de transição + deriva de câmera.
 *
 * O gesto (`entrada`) é o que dá dinamismo — cada cena atravessa o quadro na
 * direção que faz sentido para o conteúdo dela. A deriva da câmera continua
 * por baixo, sustentando a sensação de shot único.
 */
function Cena({ duracao, indice, gesto, claro, children }) {
  const t = useTransicao(duracao, gesto, indice)
  return (
    <AbsoluteFill style={t} className={claro ? 'vsl-claro' : undefined}>
      {/* Dentro da cena, não da composição: assim a passagem revela o fundo
          claro junto com o conteúdo, e o iris abre uma tela branca de dentro
          da escura. A classe `vsl-claro` deixa o tema reescrever as cores de
          texto que os blocos declaram fixas (ver tema.jsx). */}
      <FundoClaro ativo={claro} />
      {children}
    </AbsoluteFill>
  )
}

/*
 * Traduz o `passagem` do roteiro na apresentação do @remotion/transitions.
 *
 * Cada tipo tem a duração que a prática de motion prescreve: whip é o mais
 * curto (é um snap), matchCut o mais longo (a câmera precisa de curso para o
 * avanço ser lido).
 */
// v7: passagens ~35% mais curtas. Com a locução a 164 palavras/min, transição
// de 17 frames (0.57s) segurava o corte e o vídeo perdia o embalo da fala.
const DURACAO_PASSAGEM = { whip: 6, estouro: 8, iris: 10, matchCut: 11, empurra: 9 }

function apresentacaoDe(passagem) {
  if (!passagem) return null
  switch (passagem.tipo) {
    case 'iris':
      return iris({ cx: passagem.cx, cy: passagem.cy, raioInicial: passagem.raio })
    case 'matchCut':
      return matchCut({ escalaSaida: passagem.escalaSaida })
    case 'whip':
      return whipPan({ sentido: passagem.sentido })
    case 'estouro':
      return estouro({})
    default:
      return empurra()
  }
}

/** Grão sutil — tira o aspecto "vetor plano" e aproxima de vídeo. */
/**
 * Descobre em que ato o vídeo está e passa isso ao fundo. É o que faz o clima
 * acompanhar o argumento em vez de ficar igual os 3 minutos inteiros.
 */
/**
 * Troca a faixa da trilha quando o argumento vira. O corte acontece no
 * primeiro frame do ato 6, junto com a entrada da marca.
 */
function TrilhaPorAto({ lista, duracaoTotal, temNarracao }) {
  let inicioAto6 = null
  let acumulado = 0
  for (const t of lista) {
    const cena = roteiro.find((c) => c.id === t.id)
    if (cena?.ato === 6 && inicioAto6 === null) inicioAto6 = acumulado
    acumulado += t.frames
  }
  const vol = temNarracao ? 0.75 : 1
  if (inicioAto6 === null) {
    return <Trilha duracaoTotal={duracaoTotal} faixa="a" volume={vol} />
  }
  return (
    <>
      <Sequence durationInFrames={inicioAto6} name="trilha:problema">
        <Trilha duracaoTotal={inicioAto6} faixa="a" volume={vol} />
      </Sequence>
      <Sequence from={inicioAto6} name="trilha:solucao">
        <Trilha duracaoTotal={duracaoTotal - inicioAto6} faixa="b" volume={vol * 1.15} />
      </Sequence>
    </>
  )
}

function AtoAtual({ lista }) {
  const frame = useCurrentFrame()
  let acumulado = 0
  let ato = 1
  for (const t of lista) {
    if (frame < acumulado + t.frames) {
      const cena = roteiro.find((c) => c.id === t.id)
      ato = cena?.ato || 1
      break
    }
    acumulado += t.frames
  }
  return (
    <>
      <Blobs ato={ato} />
      <Grade />
    </>
  )
}

function Grao() {
  return (
    <AbsoluteFill style={{ pointerEvents: 'none', mixBlendMode: 'overlay', opacity: 0.1 }}>
      <svg width="100%" height="100%">
        <filter id="grao-vsl">
          <feTurbulence type="fractalNoise" baseFrequency="0.85" numOctaves="3" stitchTiles="stitch" />
          <feColorMatrix type="saturate" values="0" />
        </filter>
        <rect width="100%" height="100%" filter="url(#grao-vsl)" />
      </svg>
    </AbsoluteFill>
  )
}

function Vinheta() {
  return (
    <AbsoluteFill
      style={{
        pointerEvents: 'none',
        background: 'radial-gradient(ellipse at 50% 46%, transparent 62%, rgba(0,0,0,0.26) 100%)'
      }}
    />
  )
}

/** Barra de progresso do vídeo — orienta em peça de 4 minutos. */
function Progresso({ total }) {
  const frame = useCurrentFrame()
  const p = Math.min(1, frame / total)
  return (
    <div style={{ position: 'absolute', left: 0, right: 0, bottom: 0, height: 5, background: `${cores.borda}88` }}>
      <div style={{ width: `${p * 100}%`, height: '100%', background: cores.amarelo, opacity: 0.7 }} />
    </div>
  )
}

/*
 * Onde cada cena REALMENTE começa dentro da TransitionSeries.
 *
 * Não é a soma das durações anteriores: cada transição sobrepõe as duas cenas
 * que une, então toda cena começa `duracaoDaPassagem` frames antes do que a
 * soma simples diria. Usar o valor cru atrasaria todos os efeitos, e o erro
 * ACUMULA — o vídeo pareceria certo no começo e dessincronizado no fim.
 *
 * Este cálculo é o espelho do que o render monta: mudou um, muda o outro.
 */
function posicoesReais(lista) {
  const pos = {}
  let cursor = 0
  for (const t of lista) {
    const cena = roteiro.find((c) => c.id === t.id)
    const passagem = cena?.passagem ? DURACAO_PASSAGEM[cena.passagem.tipo] || 13 : 0
    cursor -= passagem
    pos[t.id] = cursor
    cursor += t.frames
  }
  return pos
}

export function Vsl({ tempos }) {
  // Sem tempos.json (antes da narração), usa a duração alvo do roteiro.
  const lista = tempos?.cenas?.length
    ? tempos.cenas
    : (() => {
        let cursor = 0
        return roteiro.map((c) => {
          const frames = Math.round(c.dur * FPS)
          const item = { id: c.id, inicio: cursor, frames }
          cursor += frames
          return item
        })
      })()

  const totalFrames = lista.reduce((s, c) => s + c.frames, 0)

  // Efeitos convertidos para frames absolutos do filme.
  const somaPassagens = roteiro.filter((c) => c.passagem).reduce((a, c) => a + (DURACAO_PASSAGEM[c.passagem.tipo] || 13), 0)
  const duracaoReal = totalFrames - somaPassagens

  const posicoes = posicoesReais(lista)
  const eventos = roteiro
    .filter((c) => c.sons)
    .flatMap((c) => c.sons.map((s) => ({ ...s, em: (posicoes[c.id] ?? 0) + s.em })))

  return (
    <AbsoluteFill style={{ backgroundColor: cores.fundo, fontFamily: fontes.corpo }}>
      <style>{cssFontes}</style>

      {tempos?.audio && <Audio src={staticFile(tempos.audio)} />}

      {/* Trilha e efeitos. A narração entra depois, por cima — os níveis da
          biblioteca já reservam espaço para ela (voz −14, sfx −20, trilha −26). */}
      {/*
        A trilha VIRA quando a PagZero entra (ato 6). A faixa "a" é contida e
        serve ao diagnóstico do problema; a "b" é mais aberta e acompanha a
        solução. Manter a mesma música nos 3 minutos deixa o desfecho com o
        mesmo peso do problema.
      */}
      <TrilhaPorAto lista={lista} duracaoTotal={duracaoReal} temNarracao={!!tempos?.audio} />
      <FaixaDeEfeitos eventos={eventos} />

      {/* FUNDO — vive abaixo das cenas. É o que faz o quadro respirar em vez
          de ler como cor sólida. */}
      <AtoAtual lista={lista} />

      {/*
        TransitionSeries em vez de <Sequence> soltas: aqui as duas cenas
        COEXISTEM durante a passagem, que é o que permite uma revelar a outra
        (o iris nascendo de um ponto, a câmera entrando no número). Com
        sequências isoladas só dá para fade e escala.
      */}
      <TransitionSeries>
        {lista.map((t, i) => {
          const cena = roteiro.find((c) => c.id === t.id)
          if (!cena) return null
          const Bloco = ESPECIAIS[cena.id] || BLOCOS[cena.bloco]
          if (!Bloco) return null

          const apresentacao = i > 0 ? apresentacaoDe(cena.passagem) : null
          const framesPassagem = DURACAO_PASSAGEM[cena.passagem?.tipo] || 13

          return (
            <React.Fragment key={cena.id}>
              {apresentacao && (
                <TransitionSeries.Transition
                  presentation={apresentacao}
                  timing={linearTiming({ durationInFrames: framesPassagem })}
                />
              )}
              <TransitionSeries.Sequence durationInFrames={t.frames}>
                {/*
                  O gesto interno continua, mas SEM fade de saída: com a
                  TransitionSeries no comando, os dois se somariam e a cena
                  sumiria cedo demais.
                */}
                <Cena duracao={t.frames} indice={i} gesto={cena.entrada} claro={cena.claro}>
                  <Bloco props={cena.props} duracao={t.frames} icone={cena.icone} />
                </Cena>
              </TransitionSeries.Sequence>
            </React.Fragment>
          )
        })}
      </TransitionSeries>

      {/* CONTINUIDADE — acima das cenas, enxerga o frame GLOBAL.
          Os riscos de corte foram REMOVIDOS: com as passagens conduzindo a
          troca (iris, match cut, whip), eles passavam no meio do nada a cada
          corte e viraram tique. O fio fica, porque costura o filme inteiro. */}

      <Particulas />
      <Vinheta />
      <Grao />
      {/* Vinheta e grão são desenhados por cima de TUDO, inclusive das cenas
          claras, onde sujam em vez de dar textura. Mantidos porque o vídeo é
          majoritariamente escuro; o FundoClaro compensa com sombra própria. */}
    </AbsoluteFill>
  )
}

/*
 * Duração real do filme.
 *
 * A TransitionSeries SOBREPÕE as cenas durante cada passagem: os frames da
 * transição são descontados do total, não somados. Declarar só a soma das
 * cenas deixava 462 frames (15s) de tela preta no fim.
 */
export const duracaoTotal = (tempos) => {
  const lista = tempos?.cenas?.length ? tempos.cenas : roteiro.map((c) => ({ frames: Math.round(c.dur * FPS) }))
  const somaCenas = lista.reduce((s, c) => s + c.frames, 0)

  /*
   * Cada transição consome frames das DUAS cenas que ela une — não do total.
   * Verificado em teste isolado: cena 20 + transição 15 + cena 20 = 25 frames
   * de filme, não 40. A cena 1 não tem passagem (é a primeira).
   */
  const somaPassagens = roteiro
    .filter((c) => c.passagem)
    .reduce((s, c) => s + (DURACAO_PASSAGEM[c.passagem.tipo] || 13), 0)

  return somaCenas - somaPassagens
}
