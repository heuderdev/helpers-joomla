import React from 'react'
import { useCurrentFrame, useVideoConfig, spring, interpolate, Easing } from 'remotion'
import { progresso, curvas, dur, escalaEntrada, blurDeMovimento, duracaoPorDistancia, atrasoStagger, movingHold } from './motion.js'

/*
 * Vocabulário de movimento do vídeo.
 *
 * A versão anterior usava um único spring (damping 200) para tudo, o que dá um
 * deslize suave e sem personalidade — o movimento chegava no lugar e parava,
 * como uma transição de slide. Aqui os presets são mais rápidos e a maioria
 * ULTRAPASSA o alvo antes de assentar. É esse overshoot que o olho lê como
 * "pop": o elemento tem massa, chega com impulso e acomoda.
 *
 * Regra de ritmo: em 30s nenhuma entrada passa de ~0,5s (15 frames). O que
 * demora mais que isso rouba tempo da narração e o vídeo volta a arrastar.
 */

export const molas = {
  /*
   * ATENÇÃO: estes presets deixaram de ter overshoot.
   *
   * O `pop` original (damping 12) ultrapassava o alvo e voltava — bounce é
   * assinatura do registro *playful*, e é justamente o que desqualifica o look
   * premium. Os valores abaixo são criticamente amortecidos: chegam e param.
   *
   * Preferir os helpers de `motion.js` (progresso/curvas) para código novo;
   * estes ficam porque vários blocos ainda os consomem via spring().
   */
  pop: { damping: 200, stiffness: 120, mass: 0.5 },
  suave: { damping: 200, stiffness: 90, mass: 0.6 },
  seco: { damping: 200, stiffness: 170, mass: 0.4 },
  elastico: { damping: 200, stiffness: 100, mass: 0.55 }
}

/*
 * MOVIMENTO CONTÍNUO — o que faltava na v2.
 *
 * Lá os elementos entravam com pop e depois congelavam pelo resto da cena.
 * Numa cena de 3s isso passa; numa de 6s, a tela vira uma imagem estática com
 * uma narração por cima. Estes helpers dão vida DEPOIS da entrada: flutuação,
 * varredura de luz e destaque sequencial.
 */

/**
 * Flutuação suave e infinita. Cada item recebe uma fase diferente (via índice)
 * para que a lista respire em ondas, e não em bloco — em bloco parece que a
 * tela inteira treme.
 */
export function usarFlutuacao(indice = 0, amplitude = 5, periodo = 62) {
  const frame = useCurrentFrame()
  return Math.sin(frame / (periodo / (2 * Math.PI)) + indice * 1.15) * amplitude
}

/**
 * Foco que percorre a lista: cada item acende na sua vez.
 *
 * Retorna 0→1 indicando o quanto ESTE índice está em destaque agora. É o que
 * faz o olho caminhar pelos cards em vez de encarar quatro retângulos parados.
 */
export function usarDestaqueSequencial(indice, total, inicio, duracaoCada = 20) {
  const frame = useCurrentFrame()
  const t = frame - inicio - indice * duracaoCada

  // Rampa proporcional: com janelas curtas, duas rampas fixas de 6 frames se
  // sobrepunham e dois itens acendiam juntos.
  const rampa = Math.min(5, duracaoCada * 0.28)
  return interpolate(t, [-rampa, 0, duracaoCada - rampa, duracaoCada], [0, 1, 1, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.inOut(Easing.quad)
  })
}

/** Progresso 0→1 com a mola escolhida, já descontando o atraso. */
export function usarMola(atraso = 0, preset = 'pop') {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  return spring({ frame: frame - atraso, fps, config: molas[preset] })
}

/**
 * Entrada com pop: escala de baixo pra cima + leve subida.
 *
 * `transformOrigin` importa: escalar a partir do centro faz o elemento "inchar"
 * no lugar; a partir da base ele parece crescer da superfície.
 */
export function Pop({ children, atraso = 0, preset = 'pop', origem = 'center', desloca = 22, style, duracao }) {
  /*
   * Entrada padrão, registro premium: sem overshoot, escala partindo de 0.94
   * (nunca de 0 — elemento que nasce do nada parece barato) e blur curto que
   * cola os dois estados e some ao assentar.
   */
  const d = duracao || duracaoPorDistancia(desloca)
  const p = progresso(atraso, d, curvas.entrada)

  return (
    <div
      style={{
        opacity: Math.min(1, p * 1.4),
        transform: `translateY(${(1 - p) * desloca}px) scale(${escalaEntrada(p)})`,
        filter: blurDeMovimento(p, desloca > 30 ? 6 : 3),
        transformOrigin: origem,
        ...style
      }}
    >
      {children}
    </div>
  )
}

/**
 * Moldagem: o elemento cresce de uma fatia fina até a altura cheia.
 *
 * `scaleY` a partir do topo, com a opacidade subindo mais rápido que a escala,
 * dá a leitura de algo sendo "extrudado" — é o efeito de cartão se formando,
 * bem mais interessante que aparecer pronto.
 */
export function Molda({ children, atraso = 0, style }) {
  const p = usarMola(atraso, 'seco')

  return (
    <div
      style={{
        opacity: Math.min(1, p * 2.2),
        transform: `scaleY(${0.12 + p * 0.88})`,
        transformOrigin: 'top',
        ...style
      }}
    >
      {/* Contra-escala no filho: sem isso o conteúdo nasce achatado junto com
          o container, e o texto fica ilegível durante a animação. */}
      <div style={{ transform: `scaleY(${1 / (0.12 + p * 0.88)})`, transformOrigin: 'top' }}>{children}</div>
    </div>
  )
}

/** Entrada lateral com overshoot — usada em listas para dar direção à leitura. */
export function Desliza({ children, atraso = 0, de = -60, style }) {
  const p = usarMola(atraso, 'pop')

  return (
    <div
      style={{
        opacity: Math.min(1, p * 1.8),
        transform: `translateX(${(1 - p) * de}px)`,
        ...style
      }}
    >
      {children}
    </div>
  )
}

/**
 * Revela palavra a palavra, cada uma com seu próprio pop.
 *
 * Num título, revelar o bloco inteiro de uma vez desperdiça o único momento em
 * que o olho está travado ali. Escalonar por palavra guia a leitura no mesmo
 * ritmo da fala.
 */
export function TituloAnimado({ texto, atraso = 0, intervalo = 2.5, style, cor }) {
  const palavras = texto.split(' ')

  return (
    <span style={{ display: 'inline', ...style }}>
      {palavras.map((palavra, i) => (
        <PalavraPop key={`${palavra}-${i}`} atraso={atraso + i * intervalo} cor={cor && cor(palavra, i)}>
          {palavra}
        </PalavraPop>
      ))}
    </span>
  )
}

function PalavraPop({ children, atraso, cor }) {
  const p = usarMola(atraso, 'pop')

  return (
    <span
      style={{
        display: 'inline-block',
        opacity: Math.min(1, p * 1.7),
        transform: `translateY(${(1 - p) * 26}px) scale(${0.9 + p * 0.1})`,
        marginRight: '0.26em',
        color: cor || undefined,
        whiteSpace: 'pre'
      }}
    >
      {children}
    </span>
  )
}

/**
 * Contagem numérica animada — para valores que "sobem" até o total.
 *
 * Easing `out` porque acelerar no fim faria o número parecer instável; freando,
 * ele assenta no valor final e o olho confia no resultado.
 */
export function Contador({ ate, atraso = 0, duracao = 22, formato = (n) => n }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.out(Easing.cubic)
  })
  return <>{formato(Math.round(ate * p))}</>
}

/**
 * Varredura de luz sobre uma superfície (efeito "shimmer").
 *
 * Uma faixa diagonal clara atravessa o card uma vez. Custa quase nada e é o
 * que separa um cartão desenhado de um cartão que parece material — a luz
 * revela que existe uma superfície ali.
 *
 * Precisa de `position: relative` e `overflow: hidden` no pai.
 */
export function Varredura({ atraso = 0, duracao = 26, cor = '#FFFFFF', forca = 0.07 }) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.inOut(Easing.cubic)
  })

  // Fora da janela de animação o elemento nem é montado: evita pintar 4 camadas
  // extras em todo frame de uma cena que já tem lista, brilho e flutuação.
  if (p <= 0 || p >= 1) return null

  return (
    <div
      style={{
        position: 'absolute',
        inset: 0,
        pointerEvents: 'none',
        background: `linear-gradient(105deg, transparent 38%, ${cor}${Math.round(forca * 255).toString(16).padStart(2, '0')} 50%, transparent 62%)`,
        transform: `translateX(${-120 + p * 240}%)`
      }}
    />
  )
}

/**
 * Ícone do @edusites/icons com entrada animada.
 *
 * Os ícones da lib são de PREENCHIMENTO (path com fill, sem stroke), então o
 * truque de `stroke-dasharray` para "desenhar o traço" não funciona neles —
 * a animação aqui é escala com giro, que lê bem em ícone sólido.
 *
 * `fill: currentColor` no wrapper: os SVGs da lib não declaram cor, herdam do
 * contexto. É assim que o `<SvgIcone>` do produto também os pinta.
 */
export function Icone({ svg, atraso = 0, tamanho = 64, cor, preset = 'pop', giro = 0 }) {
  const p = usarMola(atraso, preset)

  /*
   * Sem SVG não renderiza nada — nunca uma caixa vazia.
   *
   * Um nome de ícone que não existe no JSON produzia um `<div>` com fundo mas
   * sem conteúdo, que na tela parecia um quadrado preto. O defeito passava
   * despercebido porque não gera erro: `icones['gd-inexistente']` é undefined,
   * e `dangerouslySetInnerHTML` com undefined só deixa o elemento vazio.
   */
  if (!svg) return null

  return (
    <div
      style={{
        width: tamanho,
        height: tamanho,
        color: cor,
        fill: 'currentColor',
        flexShrink: 0,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        opacity: Math.min(1, p * 2),
        transform: `scale(${0.4 + p * 0.6}) rotate(${(1 - p) * giro}deg)`
      }}
      dangerouslySetInnerHTML={{ __html: svg }}
    />
  )
}

/**
 * Ícone que PULSA quando o foco chega nele.
 *
 * Além de acender a cor, ele cresce e volta. É o gesto que mais lê como "vivo"
 * numa lista: o olho detecta mudança de tamanho antes de mudança de cor.
 */
export function IconePulsante({ svg, atraso = 0, tamanho = 40, cor, corAtiva, foco = 0, giro = 0 }) {
  const p = usarMola(atraso, 'pop')

  // Ver comentário em `Icone`: sem SVG, nada — não uma caixa vazia.
  if (!svg) return null

  // O pulso é uma senóide curta disparada pelo foco: sobe rápido e assenta.
  const pulso = 1 + foco * 0.28

  return (
    <div
      style={{
        width: tamanho,
        height: tamanho,
        color: foco > 0.15 ? corAtiva || cor : cor,
        fill: 'currentColor',
        flexShrink: 0,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        opacity: Math.min(1, p * 2),
        transform: `scale(${(0.4 + p * 0.6) * pulso}) rotate(${(1 - p) * giro + foco * 6}deg)`,
        filter: foco > 0.15 ? `drop-shadow(0 0 ${foco * 14}px currentColor)` : 'none'
      }}
      dangerouslySetInnerHTML={{ __html: svg }}
    />
  )
}

/**
 * Troca de cartão no lugar: um sai, o próximo entra, mesma posição.
 *
 * Serve para mostrar VÁRIAS coisas no tempo de uma — em vez de empilhar quatro
 * cards que ficam parados, o mesmo espaço apresenta um de cada vez, com corte
 * seco. Muito mais denso por segundo, que é o que dá sensação de ritmo.
 *
 * Retorna o índice ativo e o progresso da transição para quem chama animar.
 */
export function usarCarrossel(total, inicio, duracaoCada = 18) {
  const frame = useCurrentFrame()
  const t = frame - inicio

  if (t < 0) return { indice: 0, entrada: 0, saindo: false }

  const indice = Math.min(total - 1, Math.floor(t / duracaoCada))
  const dentro = t % duracaoCada

  // Entrada rápida (4 frames) e aviso de saída nos 3 últimos.
  const entrada = interpolate(dentro, [0, 4], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: Easing.out(Easing.cubic) })
  const saindo = dentro > duracaoCada - 3 && indice < total - 1

  return { indice, entrada, saindo, dentro }
}

/**
 * Contagem regressiva de tempo — "40 min" caindo para "1 min".
 *
 * É o argumento do vídeo em forma de número. Animar a queda comunica a
 * economia sem gastar uma frase da narração com isso.
 */
export function usarContagem(de, para, atraso, duracao) {
  const frame = useCurrentFrame()
  const p = interpolate(frame - atraso, [0, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    // `Easing.exp` (não `expo` — esse nome é do React Native e quebra aqui).
    // Freia forte no fim: o número assenta no valor final em vez de parar seco.
    easing: Easing.out(Easing.exp)
  })
  return Math.round(de + (para - de) * p)
}

/**
 * Saída da cena: encolhe de leve e some.
 *
 * Corte seco em 8 cenas curtas fica agressivo; um fade de ~4 frames com uma
 * pitada de escala dá continuidade sem custar tempo perceptível.
 */
export function usarSaida(duracao, frames = 5) {
  const frame = useCurrentFrame()
  const p = interpolate(frame, [duracao - frames, duracao], [1, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: Easing.in(Easing.quad)
  })
  return { opacity: p, transform: `scale(${0.985 + p * 0.015})` }
}
