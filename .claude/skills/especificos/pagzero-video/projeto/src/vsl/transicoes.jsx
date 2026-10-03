import React from 'react'
import { AbsoluteFill, useCurrentFrame, interpolate } from 'remotion'
import { cores } from '../marca.js'
import { curvas, ms } from '../motion.js'

/*
 * TRANSIÇÕES ENTRE CENAS.
 *
 * O corte seco entre cenas (que o VSL tinha antes) é limpo, mas fica inerte
 * numa peça de 35 cenas: o olho entende que mudou, e não sente o movimento.
 *
 * Aqui cada cena entra com um GESTO próprio, escolhido pelo papel dela:
 *
 *   deslizeX  → cenas de fluxo/comparação: o conteúdo entra na direção em que
 *               o dinheiro anda, reforçando a leitura de percurso.
 *   subir     → cenas de dado e martelo: entram de baixo, como quem "assenta"
 *               um número na mesa.
 *   zoom      → cenas de impacto e virada: a câmera avança para dentro, é o
 *               gesto mais forte e por isso o mais raro.
 *   cortina   → troca de ATO: uma faixa amarela varre a tela. É o único gesto
 *               que interrompe o fluxo, e existe para marcar capítulo.
 *
 * A regra que evita o efeito "PowerPoint": o gesto de ENTRADA e o de SAÍDA são
 * o mesmo, na mesma direção. Cena que entra subindo, sai subindo — o conteúdo
 * atravessa o quadro em vez de quicar dentro dele.
 */

const ENTRADA = ms(420)
const SAIDA = ms(260)

/**
 * Envelope de transição. Devolve o estilo a aplicar no wrapper da cena.
 *
 * `gesto` vem do roteiro (campo `entrada`); sem ele, cai em 'subir', que é o
 * neutro seguro.
 */
export function useTransicao(duracao, gesto = 'subir', indice = 0) {
  const frame = useCurrentFrame()

  /*
   * SÓ ENTRADA — a saída pertence à passagem.
   *
   * Quem tira a cena de cena é a TransitionSeries (ver passagens.jsx). Quando
   * este hook também aplicava fade e deslocamento de saída, os dois se somavam:
   * a cena antiga continuava opaca enquanto a nova já estava por cima, e o
   * resultado era texto empilhado — o iris parecia não recortar nada.
   *
   * Este gesto agora faz UMA coisa: dar personalidade à entrada do conteúdo,
   * por dentro do quadro que a passagem revela.
   */
  const entra = interpolate(frame, [0, ENTRADA], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  // Alterna o lado por cena: entrar sempre do mesmo lado vira padrão
  // perceptível depois de três repetições seguidas.
  const lado = indice % 2 === 0 ? 1 : -1

  const opacidade = entra

  const gestos = {
    subir: {
      x: 0,
      y: (1 - entra) * 70,
      escala: 0.97 + entra * 0.03
    },
    deslizeX: {
      x: (1 - entra) * 130 * lado,
      y: 0,
      escala: 1
    },
    zoom: {
      x: 0,
      y: 0,
      // Avança para dentro: começa maior e assenta. Sai avançando mais,
      // nunca recuando — recuar leria como "desfazer".
      escala: 1.1 - entra * 0.1
    },
    cortina: {
      x: 0,
      y: (1 - entra) * 40,
      escala: 1
    }
  }

  const g = gestos[gesto] || gestos.subir

  return {
    opacity: opacidade,
    transform: `translate(${g.x}px, ${g.y}px) scale(${g.escala})`,
    /*
     * SEM BLUR AQUI.
     *
     * Este é um de três wrappers aninhados que borravam ao mesmo tempo
     * (useEntrada 10px + este 9px + a passagem 13px). Como filtros de
     * elementos aninhados se acumulam, o texto entrava com ~12px de desfoque
     * e ficava ilegível. O blur de movimento pertence à passagem, que é quem
     * desloca a cena inteira.
     */
    willChange: 'transform, opacity, filter'
  }
}

/**
 * CORTINA DE ATO — faixa amarela que varre a tela.
 *
 * Só nas trocas de ato (cenas 10, 15, 19, 23, 29). É o gesto mais chamativo do
 * vídeo e por isso o mais racionado: usado em toda cena, viraria ruído.
 *
 * Varre e sai pelo outro lado dentro de ~500ms — tempo suficiente para marcar
 * a virada sem custar conteúdo numa peça de 3 minutos.
 */
export function CortinaAto({ cor = cores.amarelo }) {
  const frame = useCurrentFrame()

  const varre = interpolate(frame, [0, ms(500)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.movimento
  })

  // -100% → 100%: entra pela esquerda, sai pela direita.
  const x = -100 + varre * 200

  if (varre >= 1) return null

  return (
    <AbsoluteFill style={{ pointerEvents: 'none', overflow: 'hidden' }}>
      <div
        style={{
          position: 'absolute',
          inset: '-10% -50%',
          background: `linear-gradient(100deg, transparent 0%, ${cor} 42%, ${cor} 58%, transparent 100%)`,
          transform: `translateX(${x}%) skewX(-12deg)`,
          opacity: 0.96
        }}
      />
    </AbsoluteFill>
  )
}

/**
 * PULSO DE DESTAQUE — anel que expande e some.
 *
 * Marca o instante em que um número importante assenta. Dura ~700ms e sai;
 * é acento, não decoração permanente.
 */
export function Pulso({ x, y, cor = cores.amarelo, atraso = 0, tamanho = 200 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, ms(700)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  if (p <= 0 || p >= 1) return null

  return (
    <div
      style={{
        position: 'absolute',
        left: x - tamanho / 2,
        top: y - tamanho / 2,
        width: tamanho,
        height: tamanho,
        borderRadius: 999,
        border: `3px solid ${cor}`,
        opacity: (1 - p) * 0.7,
        transform: `scale(${0.6 + p * 1.5})`,
        pointerEvents: 'none'
      }}
    />
  )
}

/**
 * PARTÍCULAS DE FUNDO — pontos que derivam lentamente.
 *
 * Existe para o fundo nunca ficar morto entre uma cena e outra. Amplitude e
 * opacidade baixas de propósito: se der para notar as partículas, elas estão
 * competindo com o conteúdo.
 */
export function Particulas({ quantidade = 18, cor = cores.amarelo }) {
  const frame = useCurrentFrame()

  return (
    <AbsoluteFill style={{ pointerEvents: 'none', opacity: 0.14 }}>
      {Array.from({ length: quantidade }).map((_, i) => {
        // Distribuição determinística (sem random: quebraria o cache de frames
        // do Remotion e faria as partículas tremerem entre renders).
        const bx = ((i * 137) % 100) / 100
        const by = ((i * 71) % 100) / 100
        const vel = 0.25 + ((i % 5) * 0.12)
        const y = (by * 1080 + frame * vel) % 1180 - 50

        return (
          <span
            key={i}
            style={{
              position: 'absolute',
              left: bx * 1920,
              top: y,
              width: 4 + (i % 3),
              height: 4 + (i % 3),
              borderRadius: 999,
              background: cor,
              filter: 'blur(1px)'   // partículas de fundo: o blur aqui dá profundidade
            }}
          />
        )
      })}
    </AbsoluteFill>
  )
}
