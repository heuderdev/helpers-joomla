import React from 'react'
import { AbsoluteFill, useCurrentFrame, interpolate, spring, useVideoConfig, Easing } from 'remotion'
import { cores, fontes } from './marca.js'
import { cameraZ } from './motion.js'

/** Fundo padrão: escuro com o brilho verde da marca, bem espalhado e sutil. */
export function Fundo({ children, intensidade = 1, cor, marca }) {
  const frame = useCurrentFrame()
  // Respiração lenta do brilho. Sem isso o fundo fica chapado e o vídeo parece
  // uma sequência de imagens estáticas em vez de motion.
  // Pulso mais rápido que o da versão de 50s: num vídeo de 30s com cenas de
  // ~3s, um ciclo lento demais deixa o fundo praticamente estático dentro de
  // cada cena, e o brilho para de contribuir com a sensação de movimento.
  const pulso = 1 + Math.sin(frame / 26) * 0.09

  return (
    <AbsoluteFill style={{ backgroundColor: cores.fundo, overflow: 'hidden' }}>
      {/*
       * Fundo SÓLIDO com marca d'água de ícone.
       *
       * O gradiente radial que existia aqui criava uma mancha esverdeada difusa
       * que não era nem fundo nem elemento — e num fundo escuro ela só sujava a
       * superfície sem construir profundidade.
       *
       * A troca: cor chapada + um ícone gigante em contorno, cortado pela borda
       * do quadro. Isso dá o que o gradiente tentava dar (profundidade, algo
       * atrás do conteúdo) mas com forma reconhecível, e ainda reforça o assunto
       * da cena. É o padrão de marca d'água que apps de produto usam.
       *
       * Também é mais barato: sem `filter: blur()` (que custava ~90ms/frame) e
       * sem gradiente recalculado — só um SVG estático com transform.
       */}
      {marca && (
        <div
          style={{
            position: 'absolute',
            width: 1500,
            height: 1500,
            right: -420,
            top: '20%',
            color: cor || cores.azul,
            // Bem apagado: é textura de fundo, não conteúdo. Acima de ~0.06 ele
            // começa a competir com os cards da frente.
            opacity: 0.05,
            transform: `rotate(-12deg) scale(${pulso})`,
            pointerEvents: 'none'
          }}
          dangerouslySetInnerHTML={{ __html: marca }}
        />
      )}

      {/*
       * Halo discreto atrás do conteúdo: separa os cards do fundo chapado sem
       * virar mancha. Uma única parada suave, opacidade baixa.
       */}
      <div
        style={{
          position: 'absolute',
          width: 1700,
          height: 1700,
          left: '50%',
          top: '44%',
          transform: `translate(-50%, -50%) scale(${pulso})`,
          background: `radial-gradient(circle, ${cor || cores.azul}${Math.round(intensidade * 10)
            .toString(16)
            .padStart(2, '0')} 0%, transparent 65%)`
        }}
      />

      {/*
       * Glow de canto — a assinatura visual dos criativos da Dominnus no Figma:
       * um halo azul nascendo atrás do topo esquerdo, onde ficam a logo e o
       * título. O halo central acima separa os cards do fundo; este dá a
       * PROFUNDIDADE de marca, e é o que faz o quadro ler como Dominnus em vez
       * de "card escuro genérico".
       *
       * Fica abaixo do conteúdo e acima da marca d'água, com opacidade fixa: se
       * respirasse junto com o pulso, o topo do quadro piscaria atrás do texto.
       */}
      <div
        style={{
          position: 'absolute',
          width: 1600,
          height: 1600,
          left: -560,
          top: -420,
          pointerEvents: 'none',
          background: `radial-gradient(circle, ${cor || cores.azul}2e 0%, ${cor || cores.azul}12 38%, transparent 68%)`
        }}
      />
      {children}
    </AbsoluteFill>
  )
}

/**
 * Entrada padrão dos elementos: sobe alguns pixels e ganha opacidade.
 *
 * `spring` em vez de interpolate linear porque o amortecimento dá peso físico
 * ao movimento; linear parece transição de PowerPoint.
 */
export function Entra({ children, atraso = 0, deslocamento = 40, style }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const progresso = spring({
    frame: frame - atraso,
    fps,
    config: { damping: 200, stiffness: 90, mass: 0.7 }
  })

  return (
    <div
      style={{
        opacity: progresso,
        transform: `translateY(${(1 - progresso) * deslocamento}px)`,
        ...style
      }}
    >
      {children}
    </div>
  )
}

/**
 * Transição entre cenas — corte COM MOVIMENTO, não fade.
 *
 * Com a narração contínua, o fade virou o elo fraco: apagar e reacender a tela
 * enquanto a voz segue falando cria um descompasso — o ouvido não para, mas o
 * olho sim. É parte do que soava travado.
 *
 * A troca agora é: a cena que sai continua acelerando para cima e some rápido
 * (2 frames), e a que entra já chega em movimento, vindo de baixo. Como as duas
 * se movem na MESMA direção, o olho lê continuidade em vez de dois eventos
 * separados — é o corte casado de montagem.
 */
/**
 * Transição de cena — CÂMERA CONTÍNUA, não fade.
 *
 * A análise de 7 demos de SaaS (Figma, Stripe, Notion, Miro, Slack, Airtable,
 * Zendesk) mostrou que 100% delas usam câmera como transição e NENHUMA usa
 * cross-fade estrutural: 71% fazem Z-punch-in, o resto faz pan sobre um canvas
 * persistente. Era exatamente o que faltava aqui.
 *
 * O que muda na prática: em vez de a cena apagar e a próxima acender, a câmera
 * avança para dentro da que entra. O movimento atravessa o corte, então o olho
 * lê uma peça só. A escala nunca fica parada — há uma deriva de 1,5% ao longo
 * da cena que impede o quadro morto.
 *
 * `blur` só existe durante o deslocamento e cai a zero ao assentar: é a cola
 * perceptual entre os dois estados, não um efeito permanente.
 */
export function Cena({ children, duracao, indice = 0 }) {
  const frame = useCurrentFrame()
  const cam = cameraZ(frame, duracao, { indice })

  return (
    <AbsoluteFill
      style={{
        opacity: cam.opacidade,
        // O deslocamento vem antes da escala: translate depois de scale seria
        // multiplicado por ela, e a distância variaria com o zoom da transição.
        transform: `translate(${cam.x || 0}px, ${cam.y || 0}px) scale(${cam.escala})`,
        filter: cam.blur,
        // A origem alterna com o sentido do punch: entrar sempre pelo centro
        // vira padrão perceptível depois de três cenas.
        transformOrigin: indice % 2 === 0 ? '50% 42%' : '50% 58%'
      }}
    >
      {children}
    </AbsoluteFill>
  )
}

/** Etiqueta pequena com bolinha — mesmo componente visual dos carrosséis. */
export function Etiqueta({ texto, cor = cores.verde }) {
  return (
    <div
      style={{
        display: 'inline-flex',
        alignItems: 'center',
        gap: 14,
        padding: '14px 30px',
        borderRadius: 999,
        backgroundColor: cores.superficie,
        border: `1px solid ${cores.borda}`
      }}
    >
      <div style={{ width: 14, height: 14, borderRadius: 999, backgroundColor: cor }} />
      <span style={{ fontFamily: fontes.corpo, fontSize: 30, color: cores.texto, letterSpacing: -0.2 }}>{texto}</span>
    </div>
  )
}

export function Titulo({ children, tamanho = 92, style }) {
  return (
    <h1
      style={{
        fontFamily: fontes.titulo,
        fontSize: tamanho,
        lineHeight: 1.04,
        letterSpacing: -1.5,
        color: cores.texto,
        margin: 0,
        textWrap: 'balance',
        ...style
      }}
    >
      {children}
    </h1>
  )
}

export function Paragrafo({ children, style }) {
  return (
    <p
      style={{
        fontFamily: fontes.corpoLeve,
        fontSize: 36,
        lineHeight: 1.45,
        color: cores.textoFraco,
        margin: 0,
        ...style
      }}
    >
      {children}
    </p>
  )
}

/** Cartão escuro com borda — a superfície base de toda a UI do produto. */
export function Cartao({ children, style, destaque = false }) {
  return (
    <div
      style={{
        backgroundColor: cores.superficie,
        border: `1px solid ${destaque ? cores.verde : cores.borda}`,
        borderRadius: 28,
        padding: 36,
        ...style
      }}
    >
      {children}
    </div>
  )
}
