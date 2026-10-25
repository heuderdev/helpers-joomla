import React from 'react'
import { useCurrentFrame, interpolate, staticFile, Img } from 'remotion'
import { cores } from '../marca.js'
import { curvas, ms, escalaEntrada, movingHold } from '../motion.js'

/*
 * ÍCONES — os mesmos da PagZero (@edusites/icons), usados no nuxt-web,
 * nuxt-app e nuxt-checkout.
 *
 * POR QUE ARQUIVO, E NÃO A STRING DA LIB:
 *
 * O pacote devolve o SVG como string, e a rota óbvia seria injetá-la com
 * `dangerouslySetInnerHTML`. Isso NÃO desenha — nem no Remotion, nem no
 * Chrome puro. Verificado com um teste lado a lado: o mesmo SVG servido como
 * `src="arquivo.svg"` renderiza o ícone; injetado como string, sai vazio.
 * Montar um data URI em JS falha igual.
 *
 * Então os ícones são PRÉ-GERADOS como arquivos em `public/vsl/icones/`
 * (script em `gerar-icones.mjs`) e consumidos via <Img>, que é a mesma rota
 * que o LogoGateway já usava com sucesso.
 *
 * A COR vai no nome do arquivo (`dinheiro--amarelo.svg`) porque o `fill` é
 * assado dentro do SVG na geração — um <img> é um documento isolado e não
 * herda cor do CSS de fora.
 */

// Cores disponíveis, espelhando o que `gerar-icones.mjs` produz.
const PALETA = {
  [cores.amarelo]: 'amarelo',
  [cores.vermelho]: 'vermelho',
  [cores.verde]: 'verde',
  [cores.texto]: 'branco'
}

function arquivoDoIcone(nome, cor) {
  const sufixo = PALETA[cor] || 'amarelo'
  return `vsl/icones/${nome}--${sufixo}.svg`
}

/**
 * Ícone da marca, com entrada animada.
 *
 * `pop` faz o ícone nascer um pouco maior e assentar — é o gesto que dá
 * "vida" sem virar bounce (o motion.js do projeto proíbe overshoot marcado).
 */
export function Icone({
  nome,
  tamanho = 64,
  cor = cores.amarelo,
  atraso = 0,
  respira = false,
  indice = 0,
  style
}) {
  const frame = useCurrentFrame()

  const p = interpolate(frame - atraso, [0, ms(480)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  const flutua = respira ? movingHold(indice, 3, 150) : 0

  if (!nome) return null

  return (
    <Img
      src={staticFile(arquivoDoIcone(nome, cor))}
      width={tamanho}
      height={tamanho}
      alt=""
      style={{
        display: 'inline-block',
        opacity: p,
        transform: `translateY(${(1 - p) * 16 + flutua}px) scale(${escalaEntrada(p, 0.82)})`,
        willChange: 'transform, opacity',
        ...style
      }}
    />
  )
}

/**
 * Ícone dentro de uma "pastilha" — superfície + borda na cor do ícone.
 * É o formato que lê melhor a 1920px quando o ícone acompanha um rótulo.
 */
export function Pastilha({ nome, rotulo, cor = cores.amarelo, tamanho = 52, atraso = 0, indice = 0 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, ms(520)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })
  const flutua = movingHold(indice, 3.5, 165)

  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        gap: 22,
        padding: rotulo ? '24px 40px 24px 30px' : 26,
        borderRadius: 22,
        background: cores.superficie,
        border: `1.5px solid ${cor}33`,
        opacity: p,
        transform: `translateY(${(1 - p) * 26 + flutua}px) scale(${escalaEntrada(p)})`,
        boxShadow: `0 18px 60px rgba(0,0,0,0.35)`
      }}
    >
      <Icone nome={nome} tamanho={tamanho} cor={cor} atraso={atraso + ms(80)} />
      {rotulo && (
        <span style={{ fontSize: 42, fontWeight: 600, color: cores.texto, letterSpacing: -0.6 }}>
          {rotulo}
        </span>
      )}
    </div>
  )
}

/**
 * Logo de gateway (SVG real do checkout da PagZero).
 *
 * Vem de `public/vsl/gateways/`, copiado do nuxt-checkout — são os mesmos
 * arquivos que o comprador vê no rodapé do checkout, então a peça mostra a
 * marca certa, não uma reconstrução.
 */
export function LogoGateway({ arquivo, largura = 190, atraso = 0, indice = 0 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, ms(560)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })
  const flutua = movingHold(indice, 4, 170)

  return (
    <div
      style={{
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        padding: '30px 44px',
        borderRadius: 22,
        background: cores.superficie,
        border: `1.5px solid ${cores.borda}`,
        opacity: p,
        transform: `translateY(${(1 - p) * 28 + flutua}px) scale(${escalaEntrada(p)})`,
        boxShadow: '0 18px 60px rgba(0,0,0,0.4)'
      }}
    >
      {/*
        `filter: brightness(0) invert(1)` normaliza logos coloridos para branco:
        os SVGs originais têm cores de marca variadas (azul do PayPal, verde do
        Asaas) que brigariam entre si sobre o fundo escuro. Branco uniforme
        deixa a leitura "lista de opções", que é o papel deles na cena.
      */}
      <Img
        src={staticFile(`vsl/gateways/${arquivo}`)}
        style={{ width: largura, filter: 'brightness(0) invert(1)', opacity: 0.92 }}
      />
    </div>
  )
}

/*
 * PLATAFORMAS CONCORRENTES — desenhadas de forma genérica, sem citar marca.
 * A chama e a fruta são reconhecíveis para quem é do mercado sem nomear
 * ninguém: evita problema jurídico e não vira propaganda do concorrente.
 */

/** Chama. */
export function VilaFogo({ x = 0, y = 0, escala = 1, opacidade = 1, cor, comFundo = false }) {
  const c = cor || cores.vermelho
  return (
    <g transform={`translate(${x} ${y}) scale(${escala})`} opacity={opacidade}>
      {/* O quadrado de fundo é opcional: sobre um card ele vira caixa dentro
          de caixa e suja a composição. */}
      {comFundo && (
        <rect x={-58} y={-58} width={116} height={116} rx={28}
              fill={cores.superficieAlta} stroke={cores.borda} strokeWidth={2} />
      )}
      <path d="M 0 -34 C 16 -14 30 -6 30 12 C 30 30 16 42 0 42 C -16 42 -30 30 -30 12
               C -30 -2 -20 -8 -14 -20 C -10 -8 -4 -6 0 -12 C 4 -20 2 -28 0 -34 Z"
            fill={c} opacity={0.9} />
      <path d="M 0 4 C 7 12 11 16 11 23 C 11 30 6 34 0 34 C -6 34 -11 30 -11 23
               C -11 17 -6 13 0 4 Z" fill={cores.amarelo} opacity={0.85} />
    </g>
  )
}

/** Fruta cortada. */
export function VilaFruta({ x = 0, y = 0, escala = 1, opacidade = 1, cor, comFundo = false }) {
  const c = cor || '#7BB53F'
  return (
    <g transform={`translate(${x} ${y}) scale(${escala})`} opacity={opacidade}>
      {comFundo && (
        <rect x={-58} y={-58} width={116} height={116} rx={28}
              fill={cores.superficieAlta} stroke={cores.borda} strokeWidth={2} />
      )}
      <ellipse rx={38} ry={34} fill={c} opacity={0.35} />
      <ellipse rx={30} ry={26} fill={c} opacity={0.55} />
      <ellipse rx={11} ry={9} fill="#F2F5E8" opacity={0.9} />
      {[0, 1, 2, 3, 4, 5, 6, 7].map((i) => {
        const a = (i / 8) * Math.PI * 2
        return (
          <ellipse key={i} cx={Math.cos(a) * 20} cy={Math.sin(a) * 17}
                   rx={2.6} ry={4} fill="#1A2410" opacity={0.85}
                   transform={`rotate(${(a * 180) / Math.PI} ${Math.cos(a) * 20} ${Math.sin(a) * 17})`} />
        )
      })}
    </g>
  )
}
