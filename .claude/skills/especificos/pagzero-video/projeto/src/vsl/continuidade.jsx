import React from 'react'
import { AbsoluteFill, useCurrentFrame, interpolate, Easing } from 'remotion'
import { cores } from '../marca.js'
import { curvas, ms } from '../motion.js'

/*
 * CAMADA DE CONTINUIDADE — o que dá "cara de After Effects".
 *
 * O problema que ela resolve: até aqui cada cena entrava e saía por conta
 * própria. Mesmo com gesto, o olho lia 35 peças separadas em sequência. O que
 * falta num motion de verdade é algo que ATRAVESSE o corte — um elemento que
 * pertence ao vídeo, não à cena, e que costura uma na outra.
 *
 * Esta camada vive ACIMA de todas as <Sequence> e enxerga o frame global. Por
 * isso ela pode desenhar um traço que sai de uma cena e chega na próxima.
 *
 * São quatro elementos, do mais estrutural ao mais decorativo:
 *
 *   Fio         — a linha contínua que percorre o vídeo inteiro
 *   Riscos      — traços rápidos que cruzam o quadro NO corte entre cenas
 *   Blobs       — massas fora de foco que derivam (o "líquido" do fundo)
 *   Grade       — malha sutil que dá profundidade e reage à passagem do fio
 */

/**
 * O FIO.
 *
 * Uma linha amarela que atravessa o vídeo do começo ao fim, mudando de rota a
 * cada ato. Ela não pertence a nenhuma cena: passa por trás do conteúdo,
 * costurando as cenas numa peça só.
 *
 * A rota é uma curva Bézier redesenhada por ato — nas trocas, o fio "vira" e
 * puxa o olho para o próximo bloco. É o mesmo truque de continuidade que
 * abertura de série usa: o elemento que persiste diz que ainda é o mesmo filme.
 */
export function Fio({ totalFrames }) {
  const frame = useCurrentFrame()
  const t = frame / totalFrames

  // Rotas por trecho do vídeo. Cada uma entra na anterior — o ponto final de
  // uma é o inicial da seguinte, senão o fio "salta" na virada.
  const rotas = [
    'M -200 700 C 300 700, 500 380, 900 380 S 1500 620, 2120 620',
    'M -200 620 C 400 620, 600 800, 1000 800 S 1600 300, 2120 300',
    'M -200 300 C 300 300, 700 560, 1100 560 S 1700 760, 2120 760',
    'M -200 760 C 400 760, 800 420, 1200 420 S 1800 540, 2120 540'
  ]
  const trecho = Math.min(rotas.length - 1, Math.floor(t * rotas.length))

  // O traço se desenha e se apaga continuamente: a "cabeça" corre à frente e a
  // "cauda" persegue, como um pulso viajando pelo fio.
  const ciclo = (frame % 260) / 260
  const cabeca = interpolate(ciclo, [0, 0.62], [0, 1], {
    extrapolateRight: 'clamp',
    easing: curvas.movimento
  })
  const cauda = interpolate(ciclo, [0.22, 0.92], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.movimento
  })

  const COMPRIMENTO = 3400
  const visivel = Math.max(0, cabeca - cauda) * COMPRIMENTO

  return (
    <AbsoluteFill style={{ pointerEvents: 'none', opacity: 0.5 }}>
      <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0 }}>
        <defs>
          <linearGradient id="fio-grad" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" stopColor={cores.amarelo} stopOpacity="0" />
            <stop offset="45%" stopColor={cores.amarelo} stopOpacity="0.9" />
            <stop offset="100%" stopColor={cores.amarelo} stopOpacity="0" />
          </linearGradient>
        </defs>

        {/* Rastro apagado: mostra a rota inteira, bem fraca */}
        <path d={rotas[trecho]} fill="none" stroke={cores.amarelo} strokeWidth={1.5} opacity={0.1} />

        {/* Pulso que percorre a rota */}
        <path
          d={rotas[trecho]}
          fill="none"
          stroke="url(#fio-grad)"
          strokeWidth={3}
          strokeLinecap="round"
          strokeDasharray={`${visivel} ${COMPRIMENTO}`}
          strokeDashoffset={-cauda * COMPRIMENTO}
          style={{ filter: `drop-shadow(0 0 12px ${cores.amarelo}66)` }}
        />
      </svg>
    </AbsoluteFill>
  )
}

/**
 * RISCOS DE CORTE.
 *
 * Traços finos que cruzam o quadro exatamente NO corte entre duas cenas. É o
 * gesto que "leva" o olho de uma para a outra — sem ele o corte é um salto,
 * com ele é um movimento.
 *
 * `cortes` são os frames globais onde cada cena começa. O risco dura ~380ms:
 * o suficiente para ser sentido, curto demais para ser lido como elemento.
 */
export function RiscosDeCorte({ cortes }) {
  const frame = useCurrentFrame()

  // Acha o corte mais recente e há quantos frames ele aconteceu.
  let inicio = -1
  let indice = 0
  for (let i = 0; i < cortes.length; i++) {
    if (cortes[i] <= frame) {
      inicio = cortes[i]
      indice = i
    } else break
  }
  if (inicio < 0) return null

  const decorrido = frame - inicio
  const DURACAO = ms(560)
  if (decorrido > DURACAO) return null

  /*
   * Curva quase linear DE PROPÓSITO. Com um easing agressivo (o bezier
   * 0.16,1,0.3,1 que eu usava antes), o traço percorria a tela inteira nos
   * dois primeiros frames e saía do quadro — existia no papel e era invisível
   * na prática. O movimento aqui já é rápido pela distância percorrida; não
   * precisa de aceleração por cima.
   */
  const p = interpolate(decorrido, [0, DURACAO], [0, 1], {
    extrapolateRight: 'clamp',
    easing: Easing.bezier(0.4, 0.1, 0.6, 0.9)
  })

  // Alterna a direção por corte: sempre igual vira padrão perceptível.
  const paraDireita = indice % 2 === 0
  const x = paraDireita ? -55 + p * 165 : 145 - p * 165

  // Três traços em alturas diferentes, com pequeno atraso entre si —
  // é o desencontro que dá sensação de velocidade.
  const alturas = [0.24, 0.52, 0.78]

  return (
    <AbsoluteFill style={{ pointerEvents: 'none', overflow: 'hidden' }}>
      {alturas.map((h, i) => {
        const atraso = i * 0.14
        const pl = Math.max(0, Math.min(1, (p - atraso) / (1 - atraso)))
        const opac = Math.sin(pl * Math.PI)
        return (
          <div
            key={i}
            style={{
              position: 'absolute',
              top: `${h * 100}%`,
              left: `${x + i * (paraDireita ? -14 : 14)}%`,
              width: '52%',
              height: i === 1 ? 6 : 3.5,
              background: `linear-gradient(90deg, transparent, ${cores.amarelo}, ${cores.texto}, ${cores.amarelo}, transparent)`,
              opacity: opac,
              transform: 'skewX(-18deg)',
              filter: `blur(0.5px) drop-shadow(0 0 14px ${cores.amarelo})`
            }}
          />
        )
      })}
    </AbsoluteFill>
  )
}

/**
 * BLOBS — o "líquido" do fundo.
 *
 * Massas grandes e muito desfocadas que derivam devagar. É o que tira o fundo
 * do estado "chapado" e dá a sensação orgânica que se espera de motion.
 *
 * Opacidade baixíssima de propósito: se der para identificar as formas, elas
 * estão competindo com o conteúdo. O que se deve perceber é que o fundo
 * *respira*, não que existem manchas nele.
 */
/*
 * O fundo MUDA de caráter por ato. Antes eram sempre as mesmas três massas
 * derivando, e 3 minutos com a mesma atmosfera achatam o argumento: a cena
 * que denuncia uma perda tinha o mesmo clima da que apresenta a solução.
 *
 * Cada ato recebe uma paleta e um comportamento:
 *   1 · o juro escondido     amarelo baixo, deriva lenta — algo espreitando
 *   2 · como funciona        azul frio, massas afastadas — explicação, distância
 *   3 · o Pix                amarelo pulsante e rápido — o custo que dispara
 *   4 · o tempo              massas quase paradas — o dinheiro que não anda
 *   5 · o que isso vale      vermelho entrando — a perda somada
 *   6 · a saída              amarelo alto e aberto — a marca respira
 */
const CLIMA = {
  //                                     linhas  vel  inclina  horiz   força
  1: { cores: [cores.amareloEscuro],        linhas: 9,  vel: 1.0, inclina: 140,  horizontal: true,  forca: 0.30 },
  2: { cores: ['#3D5AFE', cores.borda],     linhas: 14, vel: 0.6, inclina: -90,  horizontal: false, forca: 0.26 },
  3: { cores: [cores.amarelo],              linhas: 20, vel: 2.4, inclina: 220,  horizontal: true,  forca: 0.34, grossa: true },
  4: { cores: [cores.borda, '#3D5AFE'],     linhas: 7,  vel: 0.18, inclina: 0,   horizontal: true,  forca: 0.22 },
  5: { cores: [cores.vermelho, cores.borda],linhas: 16, vel: 1.5, inclina: -260, horizontal: false, forca: 0.32 },
  6: { cores: [cores.amarelo],              linhas: 11, vel: 0.8, inclina: 180,  horizontal: false, forca: 0.40, grossa: true }
}

export function Blobs({ ato = 1 }) {
  const frame = useCurrentFrame()
  const c = CLIMA[ato] || CLIMA[1]

  /*
   * Sem blush. As manchas radiais borradas apareciam em TODA cena e viravam
   * ruído: o olho registra "aquela bolinha de novo" em vez do conteúdo.
   *
   * No lugar, uma malha de linhas que responde ao ato. Ela é geométrica (a
   * linguagem do produto é de painel, não de aquarela) e muda de densidade e
   * inclinação conforme o argumento — o fundo passa a ter estado.
   */
  const linhas = []
  const qtd = c.linhas
  for (let i = 0; i < qtd; i++) {
    const p = i / (qtd - 1 || 1)
    // Deriva lenta e contínua: a malha nunca fica parada, nunca chama atenção.
    const desliza = (frame * c.vel * 0.22 + i * 37) % 1240 - 80
    linhas.push({ i, p, y: c.horizontal ? desliza : 0, x: c.horizontal ? 0 : desliza })
  }

  return (
    <AbsoluteFill style={{ pointerEvents: 'none', overflow: 'hidden' }}>
      <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0 }}>
        {linhas.map((l) => {
          const cor = c.cores[l.i % c.cores.length]
          const op = (0.05 + Math.sin(frame * 0.01 * c.vel + l.i) * 0.03) * c.forca * 2
          return c.horizontal ? (
            <line key={l.i} x1={-100} y1={l.y} x2={2020} y2={l.y + c.inclina}
                  stroke={cor} strokeWidth={c.grossa ? 2 : 1} opacity={Math.max(0, op)} />
          ) : (
            <line key={l.i} x1={l.x} y1={-100} x2={l.x + c.inclina} y2={1180}
                  stroke={cor} strokeWidth={c.grossa ? 2 : 1} opacity={Math.max(0, op)} />
          )
        })}
      </svg>
    </AbsoluteFill>
  )
}

export function Grade() {
  const frame = useCurrentFrame()
  const desloca = (frame * 0.18) % 90

  return (
    <AbsoluteFill
      style={{
        pointerEvents: 'none',
        opacity: 0.04,
        backgroundImage: `
          linear-gradient(${cores.amarelo} 1px, transparent 1px),
          linear-gradient(90deg, ${cores.amarelo} 1px, transparent 1px)
        `,
        backgroundSize: '90px 90px',
        backgroundPosition: `${desloca}px ${desloca}px`,
        maskImage: 'radial-gradient(ellipse at 50% 50%, black 20%, transparent 72%)',
        WebkitMaskImage: 'radial-gradient(ellipse at 50% 50%, black 20%, transparent 72%)'
      }}
    />
  )
}
