import React from 'react'
import { useCurrentFrame, interpolate } from 'remotion'
import { cores } from '../marca.js'
import { curvas, ms, escalaEntrada, blurDeMovimento, movingHold, atrasoStagger } from '../motion.js'

/*
 * Peças reaproveitadas pelos blocos do VSL.
 *
 * Tudo aqui obedece às regras do motion.js do projeto: sem overshoot, escala
 * nunca nasce de 0, stagger por orçamento e a sequência termina no elemento
 * mais importante. Manter essa disciplina é o que faz o VSL parecer da mesma
 * família dos outros vídeos da marca.
 */

/** Envelope de entrada padrão: sobe, escala e desembaça. */
export function useEntrada(atraso = 0, duracao = ms(520)) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })
  return {
    p,
    style: {
      opacity: p,
      transform: `translateY(${(1 - p) * 26}px) scale(${escalaEntrada(p)})`,
      // Sem blur: soma com o da passagem e deixa o conteúdo ilegível na entrada.
      // (ver a nota em transicoes.jsx)
      willChange: 'transform, opacity, filter'
    }
  }
}

/**
 * Contador de dinheiro. Anima o VALOR, não só a opacidade — o número "roda".
 *
 * v7: `limite` é a duração da cena. Com as durações vindo da fala real (e não
 * mais do alvo do roteiro), cenas encurtaram e contadores com atraso longo
 * ficavam congelados no meio — o "R$ 236" no lugar de "R$ 250". Quando a cena
 * é curta demais, o atraso e a duração são comprimidos para caber, garantindo
 * que o número SEMPRE chegue ao valor final antes do corte.
 */
export function Numero({ de = 0, para, atraso = 0, duracao = ms(900), prefixo = 'R$ ', sufixo = '', decimais = 0, limite, style }) {
  const frame = useCurrentFrame()

  let ini = atraso
  let dur = duracao
  if (limite) {
    // GARANTIA: o número tem que estar no valor final ANTES do corte, com
    // folga de leitura. O ajuste proporcional sozinho não bastava — quando a
    // cena tinha vários contadores em série, o último ainda estourava
    // (o "R$ 943" no lugar de 950). Aqui o fim é fixado no limite e o atraso
    // cede o espaço que for preciso.
    const FOLGA = 8            // ~0.27s parado no valor final
    const fimDesejado = ini + dur + FOLGA
    if (fimDesejado > limite) {
      dur = Math.max(6, Math.min(dur, limite - FOLGA))
      ini = Math.max(0, limite - FOLGA - dur)
    }
  }

  const p = interpolate(frame - ini, [0, dur], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })
  const v = de + (para - de) * p
  const texto = v.toLocaleString('pt-BR', { minimumFractionDigits: decimais, maximumFractionDigits: decimais })
  return (
    <span style={{ fontVariantNumeric: 'tabular-nums', ...style }}>
      {prefixo}{texto}{sufixo}
    </span>
  )
}

/**
 * Texto que entra palavra a palavra. `realce` pinta de amarelo a palavra que
 * carrega a ideia — é o equivalente visual da ênfase da locução.
 * Aceita \n para quebra manual, porque a quebra de linha é decisão de ritmo.
 */
export function Frase({ texto, realce, tamanho = 92, atraso = 0, cor = cores.texto, peso = 600, align = 'left' }) {
  const frame = useCurrentFrame()
  const linhas = texto.split('\n')
  const todas = linhas.flatMap((l) => l.split(' '))
  const orcamento = ms(520)

  let indiceGlobal = -1

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: tamanho * 0.16, alignItems: align === 'center' ? 'center' : 'flex-start' }}>
      {linhas.map((linha, li) => (
        <div key={li} style={{ display: 'flex', flexWrap: 'wrap', gap: `0 ${tamanho * 0.26}px`, justifyContent: align === 'center' ? 'center' : 'flex-start' }}>
          {linha.split(' ').map((palavra, pi) => {
            indiceGlobal++
            const at = atraso + atrasoStagger(indiceGlobal, todas.length, orcamento)
            const p = interpolate(frame - at, [0, ms(560)], [0, 1], {
              extrapolateLeft: 'clamp',
              extrapolateRight: 'clamp',
              easing: curvas.enfase
            })
            // Compara sem pontuação: "juros." precisa casar com realce "juros".
            const limpa = palavra.replace(/[.,!?]/g, '').toLowerCase()
            const destacada = realce && realce.toLowerCase().split(' ').includes(limpa)
            return (
              <span
                key={pi}
                style={{
                  fontSize: tamanho,
                  fontWeight: peso,
                  lineHeight: 1.08,
                  letterSpacing: tamanho * -0.022,
                  color: destacada ? cores.amarelo : cor,
                  opacity: p,
                  transform: `translateY(${(1 - p) * 30}px)`,
                  display: 'inline-block',
                  willChange: 'transform, opacity'
                }}
              >
                {palavra}
              </span>
            )
          })}
        </div>
      ))}
    </div>
  )
}

/** Cartão de superfície — a "folha" onde os dados pousam. */
export function Cartao({ children, atraso = 0, largura, padding = 56, cor = cores.superficie, borda = cores.borda, style }) {
  const { style: ent } = useEntrada(atraso)
  const flutua = movingHold(0, 2.5, 150)
  return (
    <div
      style={{
        background: cor,
        border: `1.5px solid ${borda}`,
        borderRadius: 28,
        padding,
        width: largura,
        boxShadow: '0 30px 90px rgba(0,0,0,0.42)',
        ...ent,
        transform: `${ent.transform} translateY(${flutua}px)`,
        ...style
      }}
    >
      {children}
    </div>
  )
}

/** Etiqueta de ato, no topo — dá orientação sem competir com o conteúdo. */
export function Selo({ texto, cor = cores.amarelo, atraso = 0 }) {
  const { style } = useEntrada(atraso, ms(420))
  return (
    <div
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 14,
        padding: '13px 26px 13px 22px',
        borderRadius: 999,
        background: `${cor}14`,
        border: `1.5px solid ${cor}3A`,
        ...style
      }}
    >
      <span style={{ width: 10, height: 10, borderRadius: 999, background: cor }} />
      <span style={{ fontSize: 26, fontWeight: 600, letterSpacing: 0.5, color: cor }}>{texto}</span>
    </div>
  )
}

/** Palco: respiro lateral consistente em todas as cenas. */
export function Palco({ children, centro = false, style }) {
  return (
    <div
      style={{
        position: 'absolute',
        inset: 0,
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        alignItems: centro ? 'center' : 'flex-start',
        textAlign: centro ? 'center' : 'left',
        padding: '0 132px',
        gap: 44,
        ...style
      }}
    >
      {children}
    </div>
  )
}

/** Linha de conexão que se desenha sozinha (usada nos fluxos de dinheiro). */
export function LinhaAnimada({ d, atraso = 0, duracao = ms(700), cor = cores.borda, largura = 3, tracejada = false }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })
  return (
    <path
      d={d}
      fill="none"
      stroke={cor}
      strokeWidth={largura}
      strokeLinecap="round"
      strokeDasharray={tracejada ? '10 14' : '1200'}
      strokeDashoffset={tracejada ? 0 : 1200 * (1 - p)}
      opacity={tracejada ? p : 1}
    />
  )
}

/** Moeda viajando por um caminho — a metáfora central do vídeo. */
export function MoedaNoCaminho({ pontos, atraso = 0, duracao = ms(1100), cor = cores.amarelo, tamanho = 18 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.movimento
  })
  const i = Math.min(pontos.length - 2, Math.floor(p * (pontos.length - 1)))
  const local = p * (pontos.length - 1) - i
  const x = pontos[i][0] + (pontos[i + 1][0] - pontos[i][0]) * local
  const y = pontos[i][1] + (pontos[i + 1][1] - pontos[i][1]) * local
  const vis = p > 0.001 && p < 0.999 ? 1 : 0
  return <circle cx={x} cy={y} r={tamanho} fill={cor} opacity={vis} style={{ filter: `drop-shadow(0 0 22px ${cor}AA)` }} />
}
