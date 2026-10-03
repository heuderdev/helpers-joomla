import React from 'react'
import { AbsoluteFill, useCurrentFrame, interpolate } from 'remotion'
import { cores } from '../marca.js'
import { curvas, ms } from '../motion.js'

/*
 * TEMA CLARO — as "telas brancas" que quebram o ritmo.
 *
 * Um VSL inteiro sobre fundo escuro cansa: depois de dois minutos o olho
 * satura e todas as cenas passam a parecer a mesma. Alternar para claro em
 * momentos escolhidos faz três coisas de uma vez:
 *
 *   1. Reseta a atenção — a mudança de luminância é percebida antes do
 *      conteúdo, então funciona como um respiro forçado.
 *   2. Marca hierarquia — o claro fica reservado para os dados que o vídeo
 *      quer que sejam lembrados, não distribuído a esmo.
 *   3. Dá o contraste que o fecho precisa — voltar ao escuro na marca faz o
 *      amarelo saltar muito mais do que se nunca tivesse saído dele.
 *
 * A troca NÃO é um corte de cor: seria agressivo demais a cada mudança. É uma
 * transição de ~500ms na qual o fundo e as cores do texto migram juntos, e a
 * vinheta/grão diminuem (num fundo claro eles sujam em vez de dar textura).
 *
 * Cada cena declara `claro: true` no roteiro. O `paleta()` devolve as cores
 * daquele frame, já interpoladas.
 */

// Paleta clara — não é branco puro: #FFFFFF vibra em vídeo comprimido e cansa.
export const CLARO = {
  fundo: '#F4F6FB',
  superficie: '#FFFFFF',
  superficieAlta: '#EDF0F7',
  borda: '#D8DDE9',
  texto: '#14161F',
  textoFraco: '#6B7184',
  amarelo: '#E0A800', // o amarelo da marca escurecido: #FEBE00 some no claro
  vermelho: '#D32F2F',
  verde: '#1B873F'
}

const ESCURO = {
  fundo: cores.fundo,
  superficie: cores.superficie,
  superficieAlta: cores.superficieAlta,
  borda: cores.borda,
  texto: cores.texto,
  textoFraco: cores.textoFraco,
  amarelo: cores.amarelo,
  vermelho: cores.vermelho,
  verde: cores.verde
}

/** Interpola dois hex. Usado para o fundo migrar em vez de piscar. */
function misturar(a, b, t) {
  const n = (h) => [1, 3, 5].map((i) => parseInt(h.slice(i, i + 2), 16))
  const [r1, g1, b1] = n(a)
  const [r2, g2, b2] = n(b)
  const c = (x, y) => Math.round(x + (y - x) * t)
  return `rgb(${c(r1, r2)}, ${c(g1, g2)}, ${c(b1, b2)})`
}

/**
 * Cores do frame atual.
 *
 * `claro` vem do roteiro. A migração dura ~500ms a partir do início da cena,
 * então a troca acontece ENQUANTO o conteúdo entra — o olho lê como uma peça
 * só, não como "mudou o slide e mudou a cor".
 */
export function usarPaleta(claro = false) {
  const frame = useCurrentFrame()
  const t = claro
    ? interpolate(frame, [0, ms(500)], [0, 1], {
        extrapolateLeft: 'clamp',
        extrapolateRight: 'clamp',
        easing: curvas.enfase
      })
    : 0

  if (t === 0) return { ...ESCURO, claro: false, t: 0 }
  if (t === 1) return { ...CLARO, claro: true, t: 1 }

  const p = {}
  for (const k of Object.keys(ESCURO)) p[k] = misturar(ESCURO[k], CLARO[k], t)
  return { ...p, claro: true, t }
}

/**
 * Fundo da cena clara.
 *
 * Fica DENTRO da cena (não na composição) para a TransitionSeries poder
 * revelá-lo junto com o conteúdo — é isso que faz o iris "abrir" uma tela
 * branca de dentro de uma escura, que é o efeito que dá o respiro.
 */
export function FundoClaro({ ativo }) {
  const paleta = usarPaleta(ativo)
  if (!ativo) return null

  return (
    <AbsoluteFill style={{ backgroundColor: paleta.fundo, pointerEvents: 'none' }}>
      {/*
        Reescreve as cores de TEXTO da paleta escura.

        Os blocos usam `cores.texto` (#FFFFFF) e `cores.textoFraco` (#8E8C97)
        fixos — 20 usos em 7 blocos. No tema claro isso vira branco sobre
        branco (aconteceu com o "R$ 30.000" da cena 25). Trocar bloco a bloco
        seria frágil: qualquer cena nova esqueceria.

        Um seletor de atributo pega exatamente os elementos com essas cores,
        sem tocar no vermelho e no amarelo, que devem continuar como são.
      */}
      <style>{`
        .vsl-claro [style*="rgb(255, 255, 255)"],
        .vsl-claro [style*="#FFFFFF"],
        .vsl-claro [style*="#ffffff"] { color: ${CLARO.texto} !important; }
        .vsl-claro [style*="rgb(142, 140, 151)"],
        .vsl-claro [style*="#8E8C97"] { color: ${CLARO.textoFraco} !important; }
      `}</style>
      {/* Grade sutil, escura sobre claro — mantém a textura sem sujar. */}
      <AbsoluteFill
        style={{
          opacity: 0.05 * paleta.t,
          backgroundImage: `
            linear-gradient(${CLARO.texto} 1px, transparent 1px),
            linear-gradient(90deg, ${CLARO.texto} 1px, transparent 1px)
          `,
          backgroundSize: '90px 90px',
          maskImage: 'radial-gradient(ellipse at 50% 50%, black 25%, transparent 75%)',
          WebkitMaskImage: 'radial-gradient(ellipse at 50% 50%, black 25%, transparent 75%)'
        }}
      />
      {/* Sombra suave nas bordas: dá volume ao branco, que senão fica chapado. */}
      <AbsoluteFill
        style={{
          background: 'radial-gradient(ellipse at 50% 45%, transparent 55%, rgba(20,22,31,0.10) 100%)',
          opacity: paleta.t
        }}
      />
    </AbsoluteFill>
  )
}
