import React from 'react'
import { useCurrentFrame, interpolate, spring } from 'remotion'
import { cores, fontes } from '../marca.js'
import { curvas, ms, atrasoStagger, escalaEntrada } from '../motion.js'
import { Frase, Palco, Numero, useEntrada, Selo, LinhaAnimada, MoedaNoCaminho } from './primitivas.jsx'
import { Plataforma } from './personagem.jsx'
import { Icone, VilaFogo, VilaFruta } from './icone.jsx'

/*
 * ATOS 2 e 3 — "Por que isso é possível" (10–14) e "O Pix" (15–18).
 *
 * O ato 2 é explicação: precisa ser LIMPO, porque o espectador está aprendendo
 * um mecanismo novo (a plataforma revende processamento). O ato 3 é indignação:
 * pode ser mais agressivo, porque o espectador já entendeu a mecânica.
 */

/** Cenas 10, 15, 19 · cartela de capítulo. Respiro entre os atos. */
export function Capitulo({ props, icone }) {
  const { style } = useEntrada(0)
  const frame = useCurrentFrame()

  /*
   * A régua amarela acima do título saiu: o destaque da marca não é uma linha,
   * é a PÍLULA AMARELA envolvendo a palavra-chave — é assim no hero do
   * nuxt-web, e é o gesto mais reconhecível da identidade.
   *
   * A pílula se DESENHA da esquerda para a direita, revelando a palavra que
   * carrega o assunto do capítulo.
   */
  const ehPlataformas = /plataforma/i.test(props.titulo || '')

  // Separa a última palavra: é ela que ganha a pílula.
  const palavras = (props.titulo || '').replace(/\n/g, ' ').split(' ')
  const destaque = palavras.pop()
  const resto = palavras.join(' ')

  const pilula = interpolate(frame, [ms(420), ms(1000)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.movimento
  })

  const vila = (i) => {
    const s = spring({ frame: frame - ms(200) - i * ms(160), fps: 30, config: { damping: 12, mass: 0.9 } })
    return {
      s,
      flutua: Math.sin(frame / (24 + i * 5) + i * 2) * 12,
      gira: Math.sin(frame / (38 + i * 7) + i) * 4
    }
  }
  const v1 = vila(0)
  const v2 = vila(1)
  const pulso = 1 + Math.sin(frame / 26) * 0.05

  return (
    <Palco>
      <div style={{
        display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        width: '100%', paddingLeft: 120, paddingRight: 120, gap: 60
      }}>
        <div style={{ ...style, maxWidth: 900 }}>
          <div style={{ fontSize: 82, fontWeight: 700, lineHeight: 1.24, color: cores.texto }}>
            {resto}{' '}
            <span style={{ position: 'relative', display: 'inline-block', padding: '2px 18px 8px 18px' }}>
              {/* a pílula cresce por trás da palavra */}
              <span style={{
                position: 'absolute', left: 0, top: 0, bottom: 0,
                width: `${pilula * 100}%`,
                background: cores.amarelo, borderRadius: 18
              }} />
              <span style={{
                position: 'relative',
                color: pilula > 0.55 ? '#000000' : cores.texto,
                transition: 'none'
              }}>{destaque}</span>
            </span>
          </div>
        </div>

        <div style={{ position: 'relative', width: 620, height: 620, flexShrink: 0 }}>
          {ehPlataformas ? (
            <svg width={620} height={620} viewBox="-310 -310 620 620">
              <circle r={250 * pulso} fill="none" stroke={cores.borda} strokeWidth={1.5} opacity={0.5} />
              <circle r={180 * pulso} fill="none" stroke={cores.borda} strokeWidth={1.5} opacity={0.35} />

              <g transform={`translate(-108 ${-30 + v1.flutua}) rotate(${v1.gira}) scale(${(0.4 + v1.s * 0.6) * 1.9})`}
                 opacity={Math.min(v1.s * 2, 1)}>
                <VilaFogo x={0} y={0} escala={1} />
              </g>
              <g transform={`translate(108 ${40 + v2.flutua}) rotate(${-v2.gira}) scale(${(0.4 + v2.s * 0.6) * 1.9})`}
                 opacity={Math.min(v2.s * 2, 1)}>
                <VilaFruta x={0} y={0} escala={1} />
              </g>

              {/* os -% saem em LOOP até o fim da cena: a sangria é contínua,
                  não um evento único */}
              {[{ x: -108, y: -30, d: '−5%', fase: 0 }, { x: 108, y: 40, d: '−10%', fase: 34 }].map((p, i) => {
                const ciclo = 78
                const t = (frame - ms(700) - p.fase + ciclo * 10) % ciclo / ciclo
                if (frame < ms(700) + p.fase) return null
                return (
                  <text key={i} x={p.x} y={p.y - 118 - t * 96} textAnchor="middle"
                        fontSize={44} fontWeight={700} fill={cores.vermelho}
                        opacity={Math.sin(t * Math.PI) * 0.9}
                        style={{ fontFamily: fontes.corpo }}>{p.d}</text>
                )
              })}
            </svg>
          ) : (
            icone && (
              <div style={{
                position: 'absolute', left: '50%', top: '50%',
                transform: `translate(-50%,-50%) scale(${pulso})`
              }}>
                <Icone nome={icone} tamanho={300} cor={cores.amarelo} atraso={ms(180)} respira />
              </div>
            )
          )}
        </div>
      </div>
    </Palco>
  )
}




/**
 * Cena 11 · A CADEIA REAL.
 *
 * Você → plataforma → adquirente. O terceiro elo aparece DEPOIS, deslizando por
 * trás da plataforma: é literalmente a revelação de que existe alguém atrás
 * dela. A ordem de entrada é o argumento.
 */
export function Cadeia({ props }) {
  const frame = useCurrentFrame()

  /*
   * Mesmos três eixos da cena 7: você → plataforma → adquirente.
   * Antes o "Você" não tinha nó nenhum (só o rótulo solto) e os rótulos
   * centravam em 382/960/1560 contra elementos em 960/1560 — nada batia.
   */
  const EIXO = [340, 960, 1580]
  const LINHA_Y = 470
  const ROTULO_Y = 640

  const entra = interpolate(frame, [0, ms(400)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  const plataforma = interpolate(frame, [ms(400), ms(800)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  const revela = interpolate(frame, [ms(1500), ms(2200)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  return (
    <Palco centro style={{ padding: 0 }}>
      <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0 }}>
        <LinhaAnimada d={`M ${EIXO[0] + 78} ${LINHA_Y} L ${EIXO[1] - 78} ${LINHA_Y}`}
                      atraso={ms(500)} cor={cores.borda} />
        <LinhaAnimada d={`M ${EIXO[1] + 78} ${LINHA_Y} L ${EIXO[2] - 78} ${LINHA_Y}`}
                      atraso={ms(1500)} cor={cores.borda} tracejada />

        {/* dúvidas saindo do Você: ele não sabe para onde foi */}
        {[0, 1, 2].map((k) => {
          const ciclo = 90
          const fase = ((frame - ms(500) - k * 26) % ciclo) / ciclo
          if (frame < ms(500) + k * 26) return null
          const lado = k === 1 ? 0 : (k === 0 ? -1 : 1)
          return (
            <text key={`q${k}`}
                  x={EIXO[0] + 46 + lado * 26} y={LINHA_Y - 78 - fase * 76}
                  textAnchor="middle" fontSize={30 + k * 6} fontWeight={700}
                  fill={cores.amarelo} opacity={Math.sin(fase * Math.PI) * 0.55}
                  style={{ fontFamily: fontes.corpo }}>?</text>
          )
        })}

        {/* VOCÊ — o nó que faltava: um selo com a inicial */}
        <g transform={`translate(${EIXO[0]} ${LINHA_Y}) scale(${0.88 + entra * 0.12})`} opacity={entra}>
          <circle r={64} fill={cores.superficie} stroke={cores.textoFraco} strokeWidth={3} />
          <circle cx={0} cy={-14} r={19} fill="none" stroke={cores.textoFraco} strokeWidth={3} />
          <path d="M -30 34 Q -30 4 0 4 Q 30 4 30 34" fill="none"
                stroke={cores.textoFraco} strokeWidth={3} strokeLinecap="round" />
        </g>

        {/* A PLATAFORMA */}
        <g opacity={plataforma}>
          <Plataforma x={EIXO[1]} y={LINHA_Y} escala={0.74} atraso={ms(400)} />
        </g>

        {/* A ADQUIRENTE — entra deslizando na revelação */}
        <g opacity={revela}
           transform={`translate(${EIXO[2] - (1 - revela) * 70} ${LINHA_Y}) scale(${0.74 + revela * 0.12})`}>
          <rect x={-72} y={-94} width={144} height={188} rx={18}
                fill={cores.superficieAlta} stroke={cores.amarelo} strokeWidth={2.5} />
          <circle cx={0} cy={-16} r={32} fill="none" stroke={cores.amarelo} strokeWidth={3} />
          <path d="M -14 -16 L 14 -16 M 0 -30 L 0 -2" stroke={cores.amarelo}
                strokeWidth={3} strokeLinecap="round" />
          <rect x={-44} y={44} width={88} height={9} rx={4.5} fill={cores.amarelo} opacity={0.5} />
        </g>
      </svg>

      <div style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
        {[
          { x: EIXO[0], rot: 'Você', op: entra },
          { x: EIXO[1], rot: 'A plataforma', op: plataforma },
          { x: EIXO[2], rot: 'A adquirente', op: revela, cor: cores.amarelo }
        ].map((r, i) => (
          <div key={i} style={{
            position: 'absolute', left: r.x - 170, top: ROTULO_Y,
            width: 340, textAlign: 'center', opacity: r.op
          }}>
            <span style={{ fontSize: 34, fontWeight: 600, color: r.cor || cores.textoFraco }}>{r.rot}</span>
          </div>
        ))}

        <div style={{ position: 'absolute', left: 0, right: 0, top: 800, textAlign: 'center', opacity: revela }}>
          <span style={{ fontSize: 44, fontWeight: 500, color: cores.texto }}>
            Quem processa de verdade está <span style={{ color: cores.amarelo }}>atrás dela</span>
          </span>
        </div>
      </div>
    </Palco>
  )
}

export function Revenda({ props, duracao }) {
  const frame = useCurrentFrame()

  const etiqueta = (rotulo, valor, atraso, cor, tamanho, pulsa = false) => {
    const p = interpolate(frame - atraso, [0, ms(700)], [0, 1], {
      extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
    })
    // A borda do valor revendido PULSA: é o número que carrega o argumento,
    // e borda estática não segura o olho pelos segundos que a cena dura.
    const pulso = pulsa && p > 0.9 ? (Math.sin((frame - atraso) / 11) + 1) / 2 : 0
    return (
      <div style={{
        display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 16,
        opacity: p, transform: `translateY(${(1 - p) * 30}px) scale(${escalaEntrada(p)})`
      }}>
        <span style={{ fontSize: 30, color: cores.textoFraco, fontWeight: 500 }}>{rotulo}</span>
        <div style={{
          padding: '26px 54px', borderRadius: 20,
          background: `${cor}${pulsa ? (14 + Math.round(pulso * 12)) : 14}`,
          border: `${2 + pulso * 2}px solid ${cor}${pulsa ? 'AA' : '55'}`,
          boxShadow: pulsa ? `0 0 ${20 + pulso * 46}px ${cor}${Math.round(30 + pulso * 50).toString(16)}` : 'none'
        }}>
          <span style={{ fontSize: tamanho, fontWeight: 700, color: cor, letterSpacing: -2 }}>{valor}</span>
        </div>
      </div>
    )
  }

  const seta = interpolate(frame, [ms(900), ms(1500)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.movimento
  })

  return (
    <Palco centro>
      <div style={{ display: 'flex', alignItems: 'center', gap: 70 }}>
        {etiqueta('Ela compra por', props.compra, ms(300), cores.texto, 76)}
        <span style={{ fontSize: 68, color: cores.amarelo, opacity: seta, transform: `translateX(${(1 - seta) * -20}px)` }}>→</span>
        {etiqueta('E revende por', props.revende, ms(1400), cores.vermelho, 104, true)}
      </div>
    </Palco>
  )
}

/** Cena 13 · o que a plataforma de fato entrega. Sem ironia: é serviço real. */
export function ListaFuncoes({ props }) {
  const frame = useCurrentFrame()

  /*
   * QUATRO CARDS LADO A LADO, cada um com ícone grande em cima e rótulo
   * abaixo — o formato de card de produto, não de bullet point.
   *
   * Entram um a um. Depois que os quatro assentam, o conjunto ENCOLHE e
   * cede espaço para a pergunta que fecha a cena: "mas isso deveria custar
   * um % do seu faturamento?". É a virada do argumento acontecendo dentro
   * da mesma cena, sem precisar de corte.
   */
  const ICONES_FUNCAO = {
    Checkout: 'cartao',
    Dashboard: 'grafico',
    'Área de membros': 'foguete',
    Integrações: 'porcentagem'
  }

  const itens = props.itens || []
  const ultimoEm = ms(300) + (itens.length - 1) * ms(340)

  // Depois dos 4 entrarem, o bloco recua para a pergunta caber.
  const recua = interpolate(frame - ultimoEm - ms(700), [0, ms(520)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  return (
    <Palco centro>
      <div style={{
        display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 40,
        transform: `translateY(${-recua * 60}px) scale(${1 - recua * 0.14})`
      }}>
        <span style={{
          padding: '10px 24px', borderRadius: 999,
          background: `${cores.amarelo}1A`, border: `1px solid ${cores.amarelo}44`,
          color: cores.amarelo, fontSize: 30, fontWeight: 600
        }}>
          {props.etiqueta || 'O que ela entrega'}
        </span>

        <div style={{ display: 'flex', gap: 34, justifyContent: 'center' }}>
          {itens.map((item, i) => {
            const em = ms(300) + i * ms(340)
            const s = spring({ frame: frame - em, fps: 30, config: { damping: 13, mass: 0.85 } })
            const respira = Math.sin((frame - em) / (32 + i * 5)) * 5

            return (
              <div key={i} style={{
                display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 22,
                width: 320, padding: '44px 24px', borderRadius: 28,
                background: cores.superficie, border: `1px solid ${cores.borda}`,
                opacity: Math.min(s * 2, 1),
                transform: `translateY(${(1 - s) * 40 + respira}px) scale(${0.86 + s * 0.14})`
              }}>
                <div style={{
                  width: 116, height: 116, borderRadius: 30,
                  background: `${cores.amarelo}14`,
                  display: 'flex', alignItems: 'center', justifyContent: 'center'
                }}>
                  <Icone nome={ICONES_FUNCAO[item] || 'porcentagem'} tamanho={68}
                         cor={cores.amarelo} atraso={em + ms(140)} />
                </div>
                <span style={{
                  fontSize: 38, fontWeight: 600, color: cores.texto,
                  textAlign: 'center', lineHeight: 1.2
                }}>
                  {item}
                </span>
              </div>
            )
          })}
        </div>
      </div>

      {/* a pergunta que vira o argumento, em vermelho */}
      <div style={{
        position: 'absolute', left: 0, right: 0, bottom: 150, textAlign: 'center',
        opacity: recua, transform: `translateY(${(1 - recua) * 30}px)`
      }}>
        <span style={{ fontSize: 56, fontWeight: 700, color: cores.vermelho }}>
          Mas isso vale um % do seu faturamento?
        </span>
      </div>
    </Palco>
  )
}



/** Cena 16 · o custo real de um Pix. Número pequeno, sozinho, quase inofensivo. */
export function CustoPix({ props }) {
  const { style } = useEntrada(0)
  return (
    <Palco centro>
      <div style={{ ...style, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 22 }}>
        <span style={{ fontSize: 34, color: cores.textoFraco }}>Processar um Pix custa</span>
        <span style={{ fontSize: 210, fontWeight: 700, color: cores.verde, letterSpacing: -8, lineHeight: 1 }}>
          {props.custo}
        </span>
      </div>
    </Palco>
  )
}

/**
 * Cena 17 · A TAXA CHEIA NO PIX.
 *
 * As três faixas entram uma sobre a outra, empilhando — e cada uma é maior que
 * a anterior. O empilhamento é o ponto: não é UM valor abusivo, é uma escala.
 */
export function TaxaCheia({ props, duracao }) {
  const frame = useCurrentFrame()

  /*
   * Os três percentuais entram em escada, e o ÚLTIMO — o pior — não assenta:
   * ele treme e solta faíscas até o fim da cena. É o número que dói, e o
   * movimento contínuo é o que impede o olho de se acomodar nele.
   */
  const faixas = props.faixas || ['3%', '5%', '10%']

  return (
    <Palco centro>
      <span style={{ fontSize: 42, color: cores.textoFraco, marginBottom: 40 }}>
        {props.acima || 'Mas a plataforma cobra'}
      </span>

      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 70 }}>
        {faixas.map((f, i) => {
          const ultimo = i === faixas.length - 1
          const em = ms(300) + i * ms(420)
          const s = spring({ frame: frame - em, fps: 30, config: { damping: 12, mass: 0.8 } })

          // O último treme para sempre; os outros assentam.
          const treme = ultimo && frame > em + ms(500)
            ? Math.sin(frame * 1.7) * 3.4 + Math.sin(frame * 2.9) * 1.8
            : 0
          const tremeY = ultimo && frame > em + ms(500) ? Math.cos(frame * 2.2) * 2.6 : 0

          const tamanho = 96 + i * 46
          const cor = ultimo ? cores.vermelho : cores.texto

          // Estoura nos últimos 22 frames da cena.
          const estoura = ultimo
            ? interpolate(frame, [duracao - 22, duracao - 4], [0, 1], {
                extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
              })
            : 0

          return (
            <div key={i} style={{ position: 'relative' }}>
              {/* faíscas: só no último, saindo continuamente */}
              {ultimo && frame > em + ms(400) && (
                <svg width={420} height={420} viewBox="-210 -210 420 420"
                     style={{ position: 'absolute', left: '50%', top: '50%',
                              marginLeft: -210, marginTop: -210, pointerEvents: 'none' }}>
                  {Array.from({ length: 10 }).map((_, k) => {
                    const ciclo = 42
                    const fase = (frame - em - ms(400) + k * 9) % ciclo / ciclo
                    const a = (k * 2.6) + fase * 0.8
                    const d = 62 + fase * 96
                    return (
                      <circle key={k}
                        cx={Math.cos(a) * d} cy={Math.sin(a) * d * 0.75 - fase * 26}
                        r={(2.5 + (k % 3)) * (1 - fase)}
                        fill={k % 3 === 0 ? cores.amarelo : cores.vermelho}
                        opacity={(1 - fase) * 0.85} />
                    )
                  })}
                </svg>
              )}

              <span style={{
                display: 'inline-block',
                fontSize: tamanho, fontWeight: 700, lineHeight: 1,
                letterSpacing: -3, color: cor,
                opacity: Math.min(s * 2, 1) * (ultimo ? 1 - estoura * 0.9 : 1),
                // No fim da cena o último INFLA e estoura, levando o olho para
                // a próxima. Antes ele só tremia e a cena morria parada.
                transform: `translate(${treme}px, ${tremeY}px) scale(${(0.5 + s * 0.5) * (ultimo ? 1 + estoura * 0.9 : 1)})`,
                textShadow: ultimo ? `0 0 ${28 + Math.abs(treme) * 5 + estoura * 90}px ${cores.vermelho}` : 'none'
              }}>
                {f}
              </span>
            </div>
          )
        })}
      </div>

      {/* estilhaços do estouro do último percentual */}
      <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
        {Array.from({ length: 16 }).map((_, k) => {
          const e = interpolate(frame, [duracao - 20, duracao], [0, 1], {
            extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
          })
          if (e <= 0) return null
          const a = k * 2.4
          const d = e * (180 + (k % 4) * 90)
          return (
            <circle key={k} cx={1240 + Math.cos(a) * d} cy={520 + Math.sin(a) * d * 0.8}
                    r={(4 + (k % 3) * 3) * (1 - e)}
                    fill={k % 3 === 0 ? cores.amarelo : cores.vermelho}
                    opacity={(1 - e) * 0.9} />
          )
        })}
      </svg>

      <span style={{
        fontSize: 40, color: cores.textoFraco, marginTop: 44,
        opacity: interpolate(frame, [ms(1600), ms(2100)], [0, 1], {
          extrapolateLeft: 'clamp', extrapolateRight: 'clamp'
        })
      }}>
        {props.abaixo || 'pra processar um simples Pix'}
      </span>
    </Palco>
  )
}


/**
 * Cena 18 · O ABSURDO EM NÚMEROS.
 *
 * Custo real (R$ 50) contra o cobrado (R$ 300 a R$ 1.000), na mesma escala
 * visual. A barra do custo real é quase invisível ao lado — e é essa
 * desproporção que fecha o ato.
 */
export function ComparaPix({ props, duracao}) {
  const frame = useCurrentFrame()
  const larguraMax = 900

  const barra = (valor, atraso) => {
    const p = interpolate(frame - atraso, [0, ms(900)], [0, 1], {
      extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
    })
    return { largura: (valor / props.cobrado[1]) * larguraMax * p, p }
  }

  const real = barra(props.custoReal, ms(400))
  const min = barra(props.cobrado[0], ms(1300))
  const max = barra(props.cobrado[1], ms(2100))

  const linha = (rotulo, valor, est, cor) => (
    <div style={{ display: 'flex', alignItems: 'center', gap: 30, opacity: est.p }}>
      <span style={{ width: 300, fontSize: 32, color: cores.textoFraco, textAlign: 'right' }}>{rotulo}</span>
      <div style={{
        width: Math.max(est.largura, 6), height: 58, borderRadius: 10,
        background: `linear-gradient(90deg, ${cor} 0%, ${cor}77 100%)`,
        boxShadow: `0 0 46px ${cor}33`
      }} />
      <Numero limite={duracao} de={0} para={valor} atraso={0} style={{ fontSize: 46, fontWeight: 700, color: cor }} />
    </div>
  )

  return (
    <Palco>
      <Selo texto="Uma venda de R$ 10.000 no Pix" />
      <div style={{ display: 'flex', flexDirection: 'column', gap: 34, marginTop: 20 }}>
        {linha('Custa de verdade', props.custoReal, real, cores.verde)}
        {linha('Você paga de', props.cobrado[0], min, cores.vermelho)}
        {linha('até', props.cobrado[1], max, cores.vermelho)}
      </div>
    </Palco>
  )
}
