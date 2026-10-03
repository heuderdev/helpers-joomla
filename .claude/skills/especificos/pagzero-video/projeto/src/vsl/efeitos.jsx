import React from 'react'
import { useCurrentFrame, interpolate, spring, useVideoConfig } from 'remotion'
// Subset da @edusites/icons extraído para JSON: o pacote puxa Vue no import
// público e o bundler do Remotion não resolve. São os mesmos SVGs dos fronts.
import ICONES from './icones-lib/subset.json'
import { cores, fontes } from '../marca.js'
import { curvas, ms } from '../motion.js'

/*
 * efeitos.jsx — o vocabulário de impacto da VSL.
 *
 * O que faltava não era mais elemento na tela, era ENERGIA: número que
 * explode em vez de aparecer, ícone que chega com estilhaço, valor que se
 * desmonta quando some. Cada função aqui é um gesto com começo, pico e
 * assentamento — nunca um fade.
 *
 * Paleta: só os tons da marca (marca.js). Nada de cor inventada.
 */

// ---------------------------------------------------------------- ícones

/**
 * Ícone da biblioteca do produto (1.197 disponíveis, os mesmos dos fronts).
 * Vem como string SVG: injetamos e pintamos por currentColor.
 */
export function IconeLib({ nome, tamanho = 96, cor = cores.amarelo, opacidade = 1, style }) {
  const svg = ICONES[nome]
  if (!svg) return null
  /*
   * Os SVGs da lib NÃO trazem atributo fill — sem ele o path renderiza preto
   * (o padrão do SVG), e sobre fundo escuro o ícone some. Era a causa do
   * "ícone preto no fundo preto" que insistia em voltar.
   *
   * Não basta substituir fill existente: é preciso INJETAR no <svg> raiz e
   * limpar qualquer fill herdado dos paths.
   */
  const limpo = svg
    .replace(/fill="(?!none")[^"]*"/g, '')
    .replace(/<svg /, '<svg fill="currentColor" width="100%" height="100%" ')
  return (
    <div
      style={{ width: tamanho, height: tamanho, color: cor, opacity: opacidade, ...style }}
      dangerouslySetInnerHTML={{ __html: limpo }}
    />
  )
}

/**
 * Ícone que CHEGA: vem girando de longe, ultrapassa e assenta.
 * O overshoot da escala é o que separa "apareceu" de "chegou".
 */
export function IconeChega({ nome, em = 0, tamanho = 96, cor = cores.amarelo, giro = 26 }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const s = spring({ frame: frame - em, fps, config: { damping: 12, mass: 0.8 } })
  const rot = interpolate(s, [0, 1], [-giro, 0])
  return (
    <div style={{
      transform: `scale(${0.3 + s * 0.7}) rotate(${rot}deg)`,
      opacity: Math.min(s * 2, 1)
    }}>
      <IconeLib nome={nome} tamanho={tamanho} cor={cor} />
    </div>
  )
}

// ---------------------------------------------------------------- números

/**
 * Número que EXPLODE: entra grande demais, esmaga e assenta, com estilhaços
 * saindo do centro. É o gesto para o valor que carrega o argumento.
 *
 * A escala usa três marcos (1.5 → 0.92 → 1) porque dois só dariam um zoom;
 * o terceiro é o que lê como impacto.
 */
export function NumeroExplode({
  texto, em = 0, tamanho = 140, cor = cores.texto, estilhacos = 12, style
}) {
  const frame = useCurrentFrame()
  const t = frame - em

  const escala = interpolate(t, [0, ms(90), ms(200), ms(330)], [1.55, 0.9, 1.06, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  const op = interpolate(t, [0, ms(70)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp'
  })
  // Tremor que decai: o quadro "sente" o impacto e se acalma.
  const tremor = t > 0 && t < ms(400)
    ? Math.sin(t * 1.4) * interpolate(t, [ms(60), ms(400)], [7, 0], { extrapolateRight: 'clamp' })
    : 0

  return (
    <div style={{ position: 'relative', display: 'inline-block', ...style }}>
      {/* estilhaços: saem do centro no frame do impacto */}
      <svg width={520} height={320} viewBox="-260 -160 520 320"
           style={{ position: 'absolute', left: '50%', top: '50%', marginLeft: -260, marginTop: -160, pointerEvents: 'none' }}>
        {Array.from({ length: estilhacos }).map((_, i) => {
          const a = (i / estilhacos) * Math.PI * 2
          const p = interpolate(t, [ms(60), ms(520)], [0, 1], {
            extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
          })
          const d = 70 + p * (140 + (i % 3) * 44)
          return (
            <circle key={i}
              cx={Math.cos(a) * d} cy={Math.sin(a) * d * 0.62}
              r={(3 + (i % 3)) * (1 - p)}
              fill={cor} opacity={(1 - p) * 0.75} />
          )
        })}
      </svg>

      <span style={{
        display: 'inline-block',
        fontSize: tamanho, fontWeight: 700, color: cor, lineHeight: 1,
        letterSpacing: -2, opacity: op,
        transform: `scale(${escala}) translateX(${tremor}px)`,
        fontFamily: fontes.corpo
      }}>
        {texto}
      </span>
    </div>
  )
}

/**
 * Número que SE DESMONTA ao sair — para o valor que se perde.
 * Cada caractere cai e gira por conta própria; o conjunto se desfaz em vez de
 * dar fade, que leria como "acabou a cena" e não como "o dinheiro sumiu".
 */
export function NumeroDesmonta({ texto, em = 0, tamanho = 130, cor = cores.vermelho, style }) {
  const frame = useCurrentFrame()
  const chars = String(texto).split('')
  return (
    <div style={{ display: 'flex', ...style }}>
      {chars.map((c, i) => {
        const t = frame - em - i * 2
        const p = interpolate(t, [0, ms(620)], [0, 1], {
          extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
        })
        const lado = i % 2 === 0 ? 1 : -1
        return (
          <span key={i} style={{
            display: 'inline-block',
            fontSize: tamanho, fontWeight: 700, color: cor, lineHeight: 1,
            fontFamily: fontes.corpo,
            opacity: 1 - p,
            transform: `translateY(${p * 90}px) translateX(${p * 26 * lado}px) rotate(${p * 34 * lado}deg)`
          }}>
            {c === ' ' ? '\\u00A0' : c}
          </span>
        )
      })}
    </div>
  )
}

/** Percentual que sobe girando o dígito — leitura de "está subindo". */
export function PorcentoSobe({ ate, em = 0, tamanho = 150, cor = cores.vermelho, limite }) {
  const frame = useCurrentFrame()
  let ini = em, dur = ms(1000)
  if (limite && ini + dur + 8 > limite) {
    dur = Math.max(6, limite - 8 - Math.min(ini, limite / 2))
    ini = Math.max(0, limite - 8 - dur)
  }
  const p = interpolate(frame - ini, [0, dur], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  const v = Math.round(ate * p)
  // Pulsa a cada vez que o valor muda: o número "conta" em vez de deslizar.
  const bate = 1 + (p < 1 ? Math.abs(Math.sin(frame * 0.9)) * 0.03 : 0)
  return (
    <span style={{
      fontSize: tamanho, fontWeight: 700, color: cor, lineHeight: 1,
      letterSpacing: -2, display: 'inline-block',
      transform: `scale(${bate})`, fontFamily: fontes.corpo
    }}>
      {v}%
    </span>
  )
}

// ---------------------------------------------------------------- impacto

/** Onda de choque circular que abre e some. Ancora o momento do impacto. */
export function Onda({ em = 0, x = 960, y = 540, cor = cores.amarelo, raio = 420 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - em, [0, ms(700)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
  })
  if (p <= 0 || p >= 1) return null
  return (
    <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
      <circle cx={x} cy={y} r={raio * p} fill="none" stroke={cor}
              strokeWidth={(1 - p) * 6} opacity={(1 - p) * 0.5} />
      <circle cx={x} cy={y} r={raio * p * 0.72} fill="none" stroke={cor}
              strokeWidth={(1 - p) * 3} opacity={(1 - p) * 0.3} />
    </svg>
  )
}

/** Flash da marca — um pulso curto de luz. Longo demais vira vídeo caseiro. */
export function Flash({ em = 0, cor = cores.amarelo, forca = 0.22 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - em, [0, ms(160)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp'
  })
  const op = p > 0 && p < 1 ? Math.sin(p * Math.PI) * forca : 0
  if (op <= 0) return null
  return <div style={{ position: 'absolute', inset: 0, background: cor, opacity: op, pointerEvents: 'none' }} />
}

/**
 * Chuva de ícones passando: bancos, gateways, meios de pagamento.
 * Usado onde a fala cita "o mercado" — dá volume sem precisar nomear ninguém.
 */
export function ChuvaDeIcones({ nomes, em = 0, quantidade = 10, cor = cores.textoFraco }) {
  const frame = useCurrentFrame()
  return (
    <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
      {Array.from({ length: quantidade }).map((_, i) => {
        const nome = nomes[i % nomes.length]
        const t = frame - em - i * 4
        const p = interpolate(t, [0, ms(1500)], [0, 1], {
          extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
        })
        if (p <= 0) return null
        const x = 140 + (i * 173) % 1640
        const y = 1120 - p * 1240
        const op = Math.sin(p * Math.PI) * 0.28
        return (
          <foreignObject key={i} x={x} y={y} width={68} height={68} opacity={op}>
            <IconeLib nome={nome} tamanho={68} cor={cor} />
          </foreignObject>
        )
      })}
    </svg>
  )
}

/** Selo de gateway (SVG real do repo), entrando com pop. */
export function Gateway({ nome, em = 0, largura = 180, x = 0, y = 0 }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const s = spring({ frame: frame - em, fps, config: { damping: 13, mass: 0.7 } })
  return (
    <div style={{
      position: 'absolute', left: x, top: y,
      opacity: Math.min(s * 2, 1),
      transform: `scale(${0.6 + s * 0.4})`
    }}>
      <img src={`/vsl/gateways/${nome}.svg`} width={largura}
           style={{ filter: 'brightness(0) invert(1)', opacity: 0.92 }} alt="" />
    </div>
  )
}


/**
 * MOEDA QUE PINGA e some.
 *
 * O pedido era literal: "poderia pingar a moeda e ela sumir". O gesto tem
 * três tempos, e é a ORDEM deles que faz parecer física em vez de animação:
 *
 *   1. cai acelerando (gravidade)
 *   2. quica duas vezes, cada uma mais baixa e mais rápida
 *   3. gira no eixo e evapora encolhendo
 *
 * Sem os quiques a moeda "desce e some", que lê como fade disfarçado.
 */
export function MoedaPinga({
  em = 0, x = 960, chao = 620, cor = cores.amarelo, tamanho = 54,
  some = true, explode = true
}) {
  const frame = useCurrentFrame()
  const t = frame - em
  if (t < 0) return null

  const QUEDA = ms(420)
  const Q1 = ms(200)
  const Q2 = ms(130)
  const REPOUSO = ms(300)
  const fim = QUEDA + Q1 + Q2 + REPOUSO

  let y = chao
  let squash = 1
  let girando = t * 5

  if (t < QUEDA) {
    const p = t / QUEDA
    y = chao - 420 * (1 - p * p)
    squash = 1 + p * 0.14
  } else if (t < QUEDA + Q1) {
    const p = (t - QUEDA) / Q1
    y = chao - 150 * Math.sin(p * Math.PI)
    squash = p < 0.12 ? 0.7 : 1
  } else if (t < QUEDA + Q1 + Q2) {
    const p = (t - QUEDA - Q1) / Q2
    y = chao - 54 * Math.sin(p * Math.PI)
    squash = p < 0.16 ? 0.82 : 1
  } else {
    y = chao
    girando = (QUEDA + Q1 + Q2) * 5
  }

  /*
   * A moeda EXPLODE ao sumir, em vez de evaporar.
   *
   * Antes ela encolhia subindo, o que lia como fade disfarçado. Agora ela
   * infla, estoura e vira estilhaços que voam — o dinheiro não "desaparece",
   * ele se desfaz. Os cacos saem em ângulos ímpares (o passo 2.4 evita o
   * padrão de relógio) e giram enquanto caem.
   */
  const exp = explode
    ? interpolate(t, [fim, fim + ms(90)], [0, 1], {
        extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
      })
    : 0
  const cacos = explode
    ? interpolate(t, [fim + ms(40), fim + ms(760)], [0, 1], {
        extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
      })
    : 0

  // Infla um pouco antes de estourar: a antecipação é o que faz o estouro
  // ser lido como estouro, e não como corte.
  const infla = 1 + exp * 0.42
  const opMoeda = exp >= 1 ? 0 : (1 - exp * 0.15)
  const escalaBase = (tamanho / 54)

  return (
    <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
      <ellipse cx={x} cy={chao + 46 * escalaBase}
               rx={32 * (1 - Math.min(Math.abs(y - chao) / 420, 0.7)) * escalaBase}
               ry={8 * escalaBase}
               fill="#000" opacity={(1 - exp) * 0.35} />

      {/* clarão no instante do estouro */}
      {exp > 0 && exp < 1 && (
        <circle cx={x} cy={y} r={90 * escalaBase * (0.5 + exp * 1.6)}
                fill={cor} opacity={(1 - exp) * 0.35} />
      )}

      {opMoeda > 0 && (
        <g transform={`translate(${x} ${y}) scale(${escalaBase * infla * (2 - squash)} ${escalaBase * infla * squash}) rotate(${girando})`}
           opacity={opMoeda}>
          <circle r={54} fill={cor} stroke={cores.amareloEscuro} strokeWidth={5} />
          <circle r={40} fill="none" stroke={cores.amareloEscuro} strokeWidth={3} opacity={0.5} />
          <text y={20} textAnchor="middle" fontSize={58} fontWeight={700}
                fill={cores.amareloEscuro} style={{ fontFamily: fontes.corpo }}>$</text>
        </g>
      )}

      {/* estilhaços */}
      {cacos > 0 && Array.from({ length: 14 }).map((_, i) => {
        const a = i * 2.4          // passo ímpar: evita o padrão de relógio
        const d = cacos * (150 + (i % 4) * 70) * escalaBase
        const cai = cacos * cacos * 130 * escalaBase
        return (
          <g key={i}
             transform={`translate(${x + Math.cos(a) * d} ${y + Math.sin(a) * d * 0.7 + cai}) rotate(${cacos * 320 * (i % 2 ? 1 : -1)})`}
             opacity={(1 - cacos) * 0.9}>
            <circle r={(5 + (i % 3) * 3) * escalaBase * (1 - cacos * 0.5)}
                    fill={i % 3 === 0 ? cores.amareloEscuro : cor} />
          </g>
        )
      })}
    </svg>
  )
}

