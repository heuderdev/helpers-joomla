import React from 'react'
import { useCurrentFrame, interpolate, staticFile, Img, spring } from 'remotion'
import { cores, fontes } from '../marca.js'
import { curvas, ms, atrasoStagger, escalaEntrada, movingHold } from '../motion.js'
import { Frase, Palco, Numero, useEntrada, Selo, LinhaAnimada, MoedaNoCaminho } from './primitivas.jsx'
import { Plataforma } from './personagem.jsx'
import { Icone, LogoGateway } from './icone.jsx'

/*
 * ATOS 4, 5 e 6 — o tempo (19–22), o que isso vale (23–28) e a saída (29–35).
 *
 * A curva emocional inverte no ato 6: até a cena 28 tudo é perda, em vermelho.
 * A partir da 29 tudo é ganho, em amarelo. Essa virada de cor é o que o
 * espectador sente antes de entender — e é de propósito.
 */

/** Cena 20 · o calendário da espera. Os dias passam, o dinheiro não chega. */
export function Calendario({ props }) {
  const frame = useCurrentFrame()
  const dias = 30

  return (
    <Palco centro>
      <span style={{ fontSize: 40, color: cores.textoFraco, marginBottom: 26 }}>
        Você se acostumou a esperar
      </span>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(15, 1fr)', gap: 12, width: 1180 }}>
        {Array.from({ length: dias }).map((_, i) => {
          const at = ms(300) + atrasoStagger(i, dias, ms(1500))
          const p = interpolate(frame - at, [0, ms(260)], [0, 1], {
            extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
          })
          const marco14 = i === 13
          const marco30 = i === 29
          const marcado = marco14 || marco30
          return (
            <div
              key={i}
              style={{
                aspectRatio: '1', borderRadius: 10,
                background: marcado ? `${cores.vermelho}22` : cores.superficie,
                border: `1.5px solid ${marcado ? cores.vermelho : cores.borda}`,
                display: 'flex', alignItems: 'center', justifyContent: 'center',
                opacity: p, transform: `scale(${escalaEntrada(p)})`
              }}
            >
              <span style={{ fontSize: 24, fontWeight: marcado ? 700 : 400, color: marcado ? cores.vermelho : cores.textoFraco }}>
                {i + 1}
              </span>
            </div>
          )
        })}
      </div>

      <div style={{ display: 'flex', gap: 60, marginTop: 40 }}>
        {props.marcos.map((m, i) => {
          const p = interpolate(frame - ms(1700) - i * ms(400), [0, ms(500)], [0, 1], {
            extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
          })
          return (
            <span key={i} style={{ fontSize: 76, fontWeight: 700, color: cores.vermelho, opacity: p, letterSpacing: -2 }}>
              {m}
            </span>
          )
        })}
      </div>
    </Palco>
  )
}

/**
 * Cena 22 · A REVIRAVOLTA DO PRAZO.
 *
 * D+30 riscado, D+2 assumindo o lugar em amarelo. É o primeiro respiro bom do
 * vídeo — o espectador vem de quatro atos de más notícias.
 */
export function Reviravolta({ props }) {
  const frame = useCurrentFrame()

  const troca = interpolate(frame, [ms(1100), ms(1900)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  return (
    <Palco centro>
      <div style={{ display: 'flex', alignItems: 'center', gap: 60 }}>
        <span style={{
          fontSize: 130, fontWeight: 700, letterSpacing: -4,
          color: cores.textoFraco, opacity: 1 - troca * 0.55,
          textDecoration: troca > 0.4 ? 'line-through' : 'none'
        }}>
          {props.de}
        </span>

        <span style={{ fontSize: 70, color: cores.amarelo, opacity: troca }}>→</span>

        <span style={{
          fontSize: 176, fontWeight: 700, letterSpacing: -6, color: cores.amarelo,
          opacity: troca, transform: `scale(${escalaEntrada(troca, 0.88)})`, display: 'inline-block'
        }}>
          {props.para}
        </span>
      </div>

      <div style={{ display: 'flex', gap: 22, marginTop: 46 }}>
        {props.selos.map((s, i) => {
          const p = interpolate(frame - ms(2100) - i * ms(360), [0, ms(520)], [0, 1], {
            extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
          })
          return (
            <div
              key={i}
              style={{
                padding: '20px 40px', borderRadius: 999,
                background: `${cores.verde}14`, border: `1.5px solid ${cores.verde}44`,
                opacity: p, transform: `translateY(${(1 - p) * 18}px)`,
                display: 'flex', alignItems: 'center', gap: 14
              }}
            >
              <span style={{ fontSize: 30, color: cores.verde }}>✓</span>
              <span style={{ fontSize: 36, fontWeight: 500, color: cores.texto }}>{s}</span>
            </div>
          )
        })}
      </div>
    </Palco>
  )
}

/** Cena 23 · os quatro vazamentos empilhando. Cada um entra e FICA. */
export function Somatorio({ props, duracao }) {
  const frame = useCurrentFrame()

  /*
   * Os quatro vilões entram um a um E PERMANECEM PESANDO: cada item ganha uma
   * barra vermelha que cresce à direita, e a soma acumula. A metade direita
   * da tela estava vazia enquanto a lista ocupava só a esquerda.
   *
   * O peso visual acompanha o argumento: a cada item o total sobe, e no fim
   * o bloco inteiro treme de leve — é o acúmulo que dói.
   */
  const itens = props.itens || []
  const ultimoEm = ms(400) + (itens.length - 1) * ms(520)

  // O conjunto treme quando o último entra: o peso somado.
  const tremeTudo = frame > ultimoEm + ms(400)
    ? Math.sin(frame * 1.6) * 1.6
    : 0

  return (
    <Palco>
      <div style={{
        display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        width: '100%', paddingLeft: 110, paddingRight: 120, gap: 70,
        transform: `translateX(${tremeTudo}px)`
      }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 34, flexShrink: 0 }}>
          <span style={{
            alignSelf: 'flex-start', padding: '10px 22px', borderRadius: 999,
            background: `${cores.vermelho}1A`, border: `1px solid ${cores.vermelho}44`,
            color: cores.vermelho, fontSize: 28, fontWeight: 600
          }}>
            {props.etiqueta || 'Agora junta tudo'}
          </span>

          {itens.map((item, i) => {
            const em = ms(400) + i * ms(520)
            const s = spring({ frame: frame - em, fps: 30, config: { damping: 14, mass: 0.8 } })
            return (
              <div key={i} style={{
                display: 'flex', alignItems: 'center', gap: 22,
                opacity: Math.min(s * 2, 1),
                transform: `translateX(${(1 - s) * -50}px)`
              }}>
                <span style={{
                  width: 14, height: 14, borderRadius: 999,
                  background: cores.vermelho, flexShrink: 0,
                  boxShadow: `0 0 ${12 + Math.sin(frame / 18 + i) * 8}px ${cores.vermelho}88`
                }} />
                <span style={{ fontSize: 62, fontWeight: 600, color: cores.texto }}>{item}</span>
              </div>
            )
          })}
        </div>

        {/* barras acumulando à direita: o peso que cada vilão adiciona */}
        <svg width={620} height={560} viewBox="0 0 620 560" style={{ flexShrink: 0 }}>
          {itens.map((_, i) => {
            const em = ms(400) + i * ms(520)
            const p = interpolate(frame - em - ms(200), [0, ms(600)], [0, 1], {
              extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
            })
            const largura = (200 + i * 96) * p
            const y = 70 + i * 118
            return (
              <g key={i}>
                <rect x={0} y={y} width={largura} height={62} rx={14}
                      fill={cores.vermelho} opacity={0.22 + i * 0.14} />
                <rect x={0} y={y} width={largura} height={62} rx={14}
                      fill="none" stroke={cores.vermelho} strokeWidth={1.5} opacity={p * 0.5} />
              </g>
            )
          })}
          {/* linha de acúmulo */}
          <line x1={0} y1={44} x2={0} y2={530} stroke={cores.borda} strokeWidth={2} />
        </svg>
      </div>
    </Palco>
  )
}


/** Cena 25 · a conta do mês, no concreto. */
export function ContaMes({ props, duracao}) {
  const frame = useCurrentFrame()
  const { style } = useEntrada(0)

  const revela = interpolate(frame, [ms(1200), ms(2000)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  return (
    <Palco centro>
      <div style={{ ...style, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 18 }}>
        <span style={{ fontSize: 36, color: cores.textoFraco }}>Se você fatura</span>
        <Numero limite={duracao} de={0} para={props.fatura} atraso={ms(200)} duracao={ms(1000)}
          style={{ fontSize: 128, fontWeight: 700, color: cores.texto, letterSpacing: -4 }} />
        <span style={{ fontSize: 34, color: cores.textoFraco }}>por mês</span>

        <div style={{ opacity: revela, marginTop: 36, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 14 }}>
          <span style={{ fontSize: 36, color: cores.textoFraco }}>ficam no caminho</span>
          <div style={{ display: 'flex', alignItems: 'baseline', gap: 22 }}>
            <Numero limite={duracao} de={0} para={props.perdeDe} atraso={ms(1400)}
              style={{ fontSize: 108, fontWeight: 700, color: cores.vermelho, letterSpacing: -3 }} />
            <span style={{ fontSize: 52, color: cores.textoFraco }}>a</span>
            <Numero limite={duracao} de={0} para={props.perdeAte} atraso={ms(1700)}
              style={{ fontSize: 108, fontWeight: 700, color: cores.vermelho, letterSpacing: -3 }} />
          </div>
        </div>
      </div>
    </Palco>
  )
}

/**
 * Cena 26 · RECEITA vs LUCRO.
 *
 * Duas barras: a receita é grande, o lucro é a fatia fina no topo. A perda é
 * então recortada DO LUCRO, não da receita — é a cena que explica por que 10%
 * de faturamento dói tanto.
 */
export function ReceitaLucro() {
  const frame = useCurrentFrame()

  const p1 = interpolate(frame, [ms(300), ms(1100)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  const corte = interpolate(frame, [ms(1800), ms(2800)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  return (
    <Palco centro>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 120 }}>
        {/* Receita */}
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 20 }}>
          <div style={{
            width: 210, height: 420 * p1, borderRadius: '16px 16px 0 0',
            background: `linear-gradient(180deg, ${cores.superficieAlta} 0%, ${cores.superficie} 100%)`,
            border: `1.5px solid ${cores.borda}`, borderBottom: 'none'
          }} />
          <span style={{ fontSize: 34, color: cores.textoFraco, opacity: p1 }}>Sua receita</span>
        </div>

        {/* Lucro — fatia fina, e é dela que sai a perda */}
        <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 20 }}>
          <div style={{ position: 'relative', width: 210, height: 420 }}>
            <div style={{
              position: 'absolute', bottom: 0, width: '100%', height: 126 * p1,
              borderRadius: '16px 16px 0 0',
              background: `linear-gradient(180deg, ${cores.amarelo} 0%, ${cores.amareloEscuro} 100%)`
            }} />
            {/* O pedaço que some */}
            <div style={{
              position: 'absolute', bottom: 126 * p1 - 126 * p1 * corte * 0.42,
              width: '100%', height: 126 * p1 * corte * 0.42,
              background: `repeating-linear-gradient(45deg, ${cores.vermelho}dd 0 12px, ${cores.vermelho}77 12px 24px)`,
              borderRadius: 4
            }} />
          </div>
          <span style={{ fontSize: 34, color: cores.amarelo, opacity: p1 }}>Seu lucro</span>
        </div>
      </div>

      <span style={{
        fontSize: 54, fontWeight: 600, color: cores.texto, marginTop: 50,
        opacity: corte, textAlign: 'center'
      }}>
        Não sai da receita. Sai <span style={{ color: cores.vermelho }}>inteiro do lucro</span>.
      </span>
    </Palco>
  )
}

/** Cena 27 · a margem que volta. Primeiro número BOM em amarelo. */
export function Margem({ props }) {
  const frame = useCurrentFrame()
  const { style } = useEntrada(0)

  const ganho = interpolate(frame, [ms(1400), ms(2200)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  return (
    <Palco centro>
      <div style={{ ...style, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 22 }}>
        <span style={{ fontSize: 36, color: cores.textoFraco }}>Se a sua margem é de {props.margem}%</span>
        <span style={{ fontSize: 40, color: cores.texto, opacity: ganho, marginTop: 10 }}>
          trocar de plataforma coloca
        </span>
        <div style={{ display: 'flex', alignItems: 'baseline', gap: 24, opacity: ganho }}>
          <span style={{ fontSize: 168, fontWeight: 700, color: cores.amarelo, letterSpacing: -6 }}>
            {props.ganhoDe} a {props.ganhoAte}%
          </span>
        </div>
        <span style={{ fontSize: 46, fontWeight: 500, color: cores.texto, opacity: ganho }}>
          de lucro a mais no seu bolso
        </span>
      </div>
    </Palco>
  )
}

/**
 * Cena 29 · A VIRADA DA MARCA.
 *
 * A logo da PagZero entra pela primeira vez. Tudo antes foi problema; daqui em
 * diante é solução. A entrada é sóbria de propósito — a marca não precisa
 * gritar depois de quatro minutos de argumento.
 */
export function ViradaMarca({ duracao }) {
  const frame = useCurrentFrame()

  /*
   * A cena da marca ficava PARADA por segundos depois do logo entrar — e é
   * justamente o momento mais importante do vídeo. Agora ela tem três tempos
   * encadeados que ocupam a cena inteira:
   *
   *   1. raios de luz explodem do centro e o logo nasce deles
   *   2. um anel se expande e some, como uma onda de chegada
   *   3. moedas ORBITAM o logo — o dinheiro que agora circula em volta de
   *      você, não some. É o fecho visual do arco da moeda que caiu na cena 2.
   */
  const s = spring({ frame: frame - ms(120), fps: 30, config: { damping: 13, mass: 0.9 } })
  const brilho = interpolate(frame, [ms(100), ms(500)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  const linha = interpolate(frame, [ms(700), ms(1300)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.movimento
  })
  const texto = interpolate(frame, [ms(900), ms(1400)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  // Onda que abre e some no instante da chegada.
  const onda = interpolate(frame, [ms(140), ms(900)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
  })

  // As moedas orbitam continuamente até o fim da cena.
  const orbita = Array.from({ length: 7 }).map((_, i) => {
    const nasce = ms(1100) + i * ms(150)
    const p = interpolate(frame, [nasce, nasce + ms(600)], [0, 1], {
      extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
    })
    const a = (i / 7) * Math.PI * 2 + (frame - nasce) / 78
    const raio = 340 + Math.sin((frame + i * 20) / 40) * 22
    return {
      i, p,
      x: Math.cos(a) * raio,
      y: Math.sin(a) * raio * 0.42,
      // a que está "atrás" do logo fica menor e mais apagada
      atras: Math.sin(a) < 0,
      escala: p * (Math.sin(a) < 0 ? 0.6 : 0.95)
    }
  })

  return (
    <Palco centro>
      <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
        {/* raios de luz saindo do centro */}
        {Array.from({ length: 18 }).map((_, i) => {
          const a = (i / 18) * Math.PI * 2
          const comp = 200 + brilho * (300 + (i % 3) * 90)
          return (
            <line key={i}
                  x1={960 + Math.cos(a) * 130} y1={540 + Math.sin(a) * 130}
                  x2={960 + Math.cos(a) * comp} y2={540 + Math.sin(a) * comp}
                  stroke={cores.amarelo} strokeWidth={2}
                  opacity={(1 - brilho) * 0.45} />
          )
        })}

        {/* onda de chegada */}
        {onda > 0 && onda < 1 && (
          <circle cx={960} cy={540} r={140 + onda * 620} fill="none"
                  stroke={cores.amarelo} strokeWidth={(1 - onda) * 5}
                  opacity={(1 - onda) * 0.4} />
        )}

        {/* moedas atrás do logo */}
        {orbita.filter((o) => o.atras).map((o) => (
          <g key={`b${o.i}`} transform={`translate(${960 + o.x} ${470 + o.y}) scale(${o.escala})`}
             opacity={o.p * 0.5}>
            <circle r={26} fill={cores.amarelo} stroke={cores.amareloEscuro} strokeWidth={3} />
          </g>
        ))}
      </svg>

      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 40, zIndex: 2 }}>
        <img src="/logo-branco.svg" width={620} alt=""
             style={{
               opacity: Math.min(s * 2, 1),
               transform: `scale(${0.72 + s * 0.28})`,
               filter: `drop-shadow(0 0 ${40 + brilho * 60}px ${cores.amarelo}55)`
             }} />

        <div style={{ width: 420 * linha, height: 3, background: cores.amarelo, borderRadius: 999, opacity: 0.7 }} />

        <span style={{
          fontSize: 52, fontWeight: 500, color: cores.texto, textAlign: 'center', lineHeight: 1.3,
          opacity: texto, transform: `translateY(${(1 - texto) * 20}px)`
        }}>
          Sem taxa por venda.<br />
          <span style={{ color: cores.amarelo, fontWeight: 600 }}>Só uma mensalidade fixa.</span>
        </span>
      </div>

      {/* moedas na frente do logo */}
      <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none', zIndex: 3 }}>
        {orbita.filter((o) => !o.atras).map((o) => (
          <g key={`f${o.i}`} transform={`translate(${960 + o.x} ${470 + o.y}) scale(${o.escala})`}
             opacity={o.p}>
            <circle r={26} fill={cores.amarelo} stroke={cores.amareloEscuro} strokeWidth={3} />
            <text y={9} textAnchor="middle" fontSize={26} fontWeight={700}
                  fill={cores.amareloEscuro} style={{ fontFamily: fontes.corpo }}>$</text>
          </g>
        ))}
      </svg>
    </Palco>
  )
}


/** Cena 30 · os dois zeros. Tipografia grande, nada mais. */
export function Zeros({ props }) {
  const frame = useCurrentFrame()
  return (
    <Palco centro>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 30, alignItems: 'center' }}>
        {props.itens.map((item, i) => {
          const at = i * ms(700)
          const p = interpolate(frame - at, [0, ms(600)], [0, 1], {
            extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
          })
          const [zero, ...resto] = item.split(' ')
          return (
            <div key={i} style={{ display: 'flex', alignItems: 'baseline', gap: 24, opacity: p, transform: `translateY(${(1 - p) * 26}px)` }}>
              <span style={{ fontSize: 118, fontWeight: 700, color: cores.amarelo, letterSpacing: -4 }}>{zero}</span>
              <span style={{ fontSize: 68, fontWeight: 500, color: cores.texto }}>{resto.join(' ')}</span>
            </div>
          )
        })}
      </div>
    </Palco>
  )
}

/** Cena 31 · os gateways conectando à PagZero. */
// Ordem da fala da cena 31: Pagar.me, Asaas, Mercado Pago, Stripe, PayPal.
const ARQUIVOS = ['pagarme.svg', 'asaas.svg', 'mercadopago.svg', 'stripe.svg', 'paypal.svg']

export function Gateways({ props }) {
  const frame = useCurrentFrame()

  return (
    <Palco centro>
      <span style={{ fontSize: 44, color: cores.textoFraco, marginBottom: 40 }}>
        Você conecta o seu próprio gateway
      </span>
      {/*
        Os LOGOS REAIS dos gateways, copiados do nuxt-checkout — os mesmos que
        o comprador vê no rodapé do checkout. Nome em texto seria mais fácil,
        mas o logo é reconhecido em milissegundos e o texto não.
      */}
      <div style={{ display: 'flex', flexWrap: 'wrap', justifyContent: 'center', gap: 24, maxWidth: 1560 }}>
        {ARQUIVOS.map((arq, i) => (
          <LogoGateway
            key={arq}
            arquivo={arq}
            largura={i === 2 ? 210 : 170}
            atraso={ms(300) + atrasoStagger(i, ARQUIVOS.length, ms(900))}
            indice={i}
          />
        ))}
      </div>
    </Palco>
  )
}

/**
 * Cena 32 · A VENDA CAINDO INTEIRA.
 *
 * Espelha a cena 7 de propósito: mesmo layout, mas agora SEM a plataforma no
 * meio. A moeda vai direto do cliente ao produtor, do mesmo tamanho. Quem viu
 * a cena 7 sente a diferença sem precisar de explicação.
 */
export function VendaInteira({ props, duracao }) {
  const frame = useCurrentFrame()

  /*
   * A linha terminava no VAZIO: a moeda percorria o caminho e sumia sem
   * chegar em lugar nenhum. Agora há um destino — o seu gateway — e ele
   * CONFIRMA o recebimento com um check que se desenha.
   *
   * É o oposto exato da cena 7, onde a moeda chegava menor do outro lado.
   * Aqui ela chega inteira, e o anel fecha em verde.
   */
  const chegou = interpolate(frame, [ms(1900), ms(2300)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  // O check se desenha depois da moeda encostar.
  const confirma = interpolate(frame, [ms(2150), ms(2550)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.movimento
  })
  const pulsoDestino = confirma >= 1 ? 1 + Math.sin(frame / 20) * 0.035 : 1

  return (
    <Palco centro style={{ padding: 0 }}>
      <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0 }}>
        <LinhaAnimada d="M 420 470 L 1400 470" atraso={ms(400)} cor={cores.amarelo} largura={4} />
        <MoedaNoCaminho pontos={[[420, 470], [1400, 470]]} atraso={ms(700)} duracao={ms(1500)} tamanho={24} />

        {/* origem: a venda */}
        <g opacity={interpolate(frame, [0, ms(500)], [0, 1], { extrapolateRight: 'clamp' })}>
          <circle cx={340} cy={470} r={66} fill={cores.superficie} stroke={cores.amarelo} strokeWidth={3} />
          <text x={340} y={490} textAnchor="middle" fontSize={56} fill={cores.amarelo} fontWeight={700}
                style={{ fontFamily: fontes.corpo }}>$</text>
        </g>

        {/* DESTINO: o seu gateway, confirmando */}
        <g transform={`translate(1480 470) scale(${(0.7 + chegou * 0.3) * pulsoDestino})`}
           opacity={chegou}>
          {/* halo de confirmação */}
          {confirma > 0 && (
            <circle r={94} fill={cores.verde} opacity={(1 - confirma) * 0.25} />
          )}
          <circle r={70} fill={cores.superficie} stroke={cores.verde} strokeWidth={4} />
          {/* o check se DESENHA: stroke-dasharray animado */}
          <path d="M -30 2 L -10 24 L 32 -22"
                fill="none" stroke={cores.verde} strokeWidth={9}
                strokeLinecap="round" strokeLinejoin="round"
                strokeDasharray={110}
                strokeDashoffset={110 * (1 - confirma)} />
        </g>

        <text x={1480} y={600} textAnchor="middle" fontSize={30} fill={cores.textoFraco}
              opacity={confirma} style={{ fontFamily: fontes.corpo }}>seu gateway</text>
      </svg>

      <div style={{ position: 'absolute', left: 0, right: 0, bottom: 170, textAlign: 'center', opacity: chegou }}>
        <Numero limite={duracao} de={0} para={props.valor} atraso={ms(2100)}
          style={{ fontSize: 132, fontWeight: 700, color: cores.amarelo, letterSpacing: -4 }} />
        <div style={{ fontSize: 40, color: cores.texto, marginTop: 14 }}>direto no seu gateway</div>
      </div>
    </Palco>
  )
}


/** Cena 35 · o CTA. Botão pulsando, sem pressa. */
export function Cta({ props }) {
  const frame = useCurrentFrame()
  const { style } = useEntrada(0)

  const pulso = 1 + Math.sin(frame / 14) * 0.018

  return (
    <Palco centro>
      <div style={{ ...style, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 46 }}>
        <Img src={staticFile('logo-branco.svg')} style={{ width: 400, opacity: 0.95 }} />

        <div
          style={{
            padding: '34px 84px', borderRadius: 999,
            background: cores.amarelo,
            transform: `scale(${pulso})`,
            boxShadow: `0 24px 80px ${cores.amarelo}44`
          }}
        >
          <span style={{ fontSize: 54, fontWeight: 700, color: cores.fundo, letterSpacing: -0.5 }}>
            {props.texto}
          </span>
        </div>

        <span style={{ fontSize: 40, color: cores.textoFraco, letterSpacing: 1 }}>{props.site}</span>
      </div>
    </Palco>
  )
}
