import React from 'react'
import { useCurrentFrame, interpolate, spring } from 'remotion'
import { cores, fontes } from '../marca.js'
import { curvas, ms, atrasoStagger, escalaEntrada } from '../motion.js'
import { Frase, Palco, Cartao, Numero, useEntrada, LinhaAnimada, MoedaNoCaminho } from './primitivas.jsx'
import { Plataforma } from './personagem.jsx'
import { Icone, VilaFogo, VilaFruta } from './icone.jsx'
import { NumeroExplode, NumeroDesmonta, Onda, Flash, IconeChega, IconeLib, PorcentoSobe, ChuvaDeIcones, MoedaPinga, Gateway } from './efeitos.jsx'

/*
 * ATO 1 — "O juro que ninguém viu" (cenas 1–9).
 *
 * É o ato que precisa funcionar: se o espectador não entender AQUI que a taxa
 * incide sobre o valor parcelado, o resto do vídeo não tem sobre o que se
 * apoiar. Por isso as cenas 4 e 7 são as mais trabalhadas — a primeira mostra
 * o valor inflando, a segunda mostra para onde ele vai.
 */

/** Cena 1 · frase de abertura. v7: o personagem saiu — desenho fraco puxava
 *  a cena para baixo; o ícone da moeda carrega a ideia melhor. */
export function Frase1({ props }) {
  const frame = useCurrentFrame()

  /*
   * DUAS COLUNAS: texto à esquerda (3 linhas), cartão à direita.
   * Antes o texto era corrido e encostava no cartão — os dois disputavam o
   * mesmo espaço. Agora cada um tem sua metade.
   *
   * O cartão CHEGA voando: entra girando de fora do quadro, ultrapassa o
   * ângulo de repouso e assenta. Depois flutua e emite moedas.
   */
  const chega = spring({ frame: frame - ms(180), fps: 30, config: { damping: 11, mass: 1.1 } })
  const desenha = interpolate(frame, [ms(400), ms(1200)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  // Vem de fora, à direita, girando. O overshoot do spring dá o "assentar".
  const entradaX = interpolate(chega, [0, 1], [520, 0])
  const gira = interpolate(chega, [0, 1], [-38, -7])
  const flutua = Math.sin(frame / 28) * 7
  const balanca = Math.sin(frame / 34) * 1.6

  const moedas = [0, 1, 2, 3, 4, 5, 6].map((i) => {
    const nasce = ms(1000) + i * ms(210)
    const p = interpolate(frame, [nasce, nasce + ms(1400)], [0, 1], {
      extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
    })
    const desvio = (i % 2 === 0 ? 1 : -1) * (18 + (i % 3) * 14)
    return {
      i,
      x: desvio * p,
      y: -60 - p * 300,
      escala: p === 0 ? 0 : 0.45 + Math.sin(p * Math.PI) * 0.55,
      op: p === 0 ? 0 : Math.sin(p * Math.PI) * 0.95,
      giro: p * 260
    }
  })

  // O chip pisca no frame em que cada moeda nasce: liga causa e efeito.
  const pulso = moedas.reduce((acc, m) => {
    const nasce = ms(1000) + m.i * ms(210)
    const d = interpolate(frame, [nasce - 3, nasce, nasce + 9], [0, 1, 0], {
      extrapolateLeft: 'clamp', extrapolateRight: 'clamp'
    })
    return Math.max(acc, d)
  }, 0)

  return (
    <Palco>
      <div style={{
        display: 'flex', alignItems: 'center', justifyContent: 'space-between',
        width: '100%', paddingLeft: 120, paddingRight: 120, gap: 80
      }}>
        <div style={{ maxWidth: 880, flexShrink: 0 }}>
          <Frase texto={props.texto} realce={props.realce} tamanho={94} />
        </div>

        <div style={{ position: 'relative', width: 700, height: 700, flexShrink: 0 }}>
          <svg width={700} height={700} viewBox="-350 -350 700 700"
               style={{ position: 'absolute', inset: 0 }}>
            <defs>
              <linearGradient id="c1-face" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stopColor={cores.superficieAlta} />
                <stop offset="1" stopColor={cores.superficie} />
              </linearGradient>
            </defs>

            <g transform={`translate(${entradaX} ${flutua}) rotate(${gira + balanca}) scale(${0.6 + chega * 0.4})`}
               opacity={Math.min(chega * 2.2, 1)}>
              <rect x={-230} y={-146} width={460} height={292} rx={30}
                    fill="url(#c1-face)" stroke={cores.borda} strokeWidth={3} />
              <rect x={-230} y={-76} width={460 * desenha} height={50} fill={cores.amarelo} opacity={0.92} />
              <g opacity={desenha}>
                <rect x={-182} y={22} width={88} height={64} rx={11}
                      fill={cores.amareloEscuro} opacity={0.5 + pulso * 0.5} />
                <rect x={-182} y={22} width={88} height={64} rx={11} fill="none"
                      stroke={cores.amarelo} strokeWidth={2.4} opacity={0.6 + pulso * 0.4} />
                <path d="M -153 22 v 64 M -124 22 v 64 M -182 43 h 88 M -182 65 h 88"
                      stroke={cores.amarelo} strokeWidth={1.7} opacity={0.45} />
              </g>
              <rect x={-182} y={110} width={240 * desenha} height={11} rx={5.5}
                    fill={cores.textoFraco} opacity={0.4} />
              <g opacity={desenha}>
                <circle cx={116} cy={66} r={36} fill={cores.amarelo} opacity={0.9} />
                <circle cx={168} cy={66} r={36} fill={cores.amarelo} opacity={0.45} />
              </g>
            </g>

            {moedas.map((m) => (
              <g key={m.i} transform={`translate(${m.x} ${m.y}) scale(${m.escala}) rotate(${m.giro})`}
                 opacity={m.op}>
                <circle r={30} fill={cores.amarelo} stroke={cores.amareloEscuro} strokeWidth={3.5} />
                <text y={12} textAnchor="middle" fontSize={34} fontWeight={700}
                      fill={cores.amareloEscuro} style={{ fontFamily: fontes.corpo }}>$</text>
              </g>
            ))}
          </svg>
        </div>
      </div>
    </Palco>
  )
}


/** Cena 2 · a pergunta, sozinha no quadro. */
export function Pergunta({ props, icone }) {
  const frame = useCurrentFrame()
  const tom = props.tom === 'amarelo' ? cores.amarelo : cores.texto

  /*
   * A moeda que escapou do cartão na cena 1 CAI aqui, quica duas vezes e
   * evapora — o dinheiro chega, encosta e some antes de você alcançar.
   *
   * O gesto responde à pergunta antes do texto: para onde foi? Sumiu.
   * As interrogações sobem no rastro dela, defasadas, e também se dissolvem.
   */
  const interrogacoes = [0, 1, 2].map((i) => {
    const nasce = ms(1500) + i * ms(210)
    const p = interpolate(frame, [nasce, nasce + ms(1000)], [0, 1], {
      extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.entrada
    })
    const lado = i === 1 ? 0 : (i === 0 ? -1 : 1)
    return {
      i,
      x: 960 + lado * (128 + i * 22),
      y: 600 - p * 280,
      op: p === 0 ? 0 : Math.sin(p * Math.PI) * 0.45,
      escala: 0.5 + p * 0.65
    }
  })

  return (
    <Palco centro>
      <MoedaPinga em={ms(120)} x={960} chao={660} tamanho={118} explode />

      <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
        {interrogacoes.map((q) => (
          <text key={q.i} x={q.x} y={q.y} textAnchor="middle"
                fontSize={92 * q.escala} fontWeight={700} fill={cores.amarelo}
                opacity={q.op} style={{ fontFamily: fontes.corpo }}>?</text>
        ))}
      </svg>

      <Frase texto={props.texto} tamanho={104} cor={tom} align="center" />
    </Palco>
  )
}


/** Cena 3 · duas negações que entram e SAEM riscadas — elimina hipóteses. */
export function Negacao({ props, duracao }) {
  const frame = useCurrentFrame()

  /*
   * O texto entra BRANCO e legível; só depois que o risco atravessa ele
   * esmaece. Antes nascia cinza e o espectador tinha que ler algo já apagado.
   *
   * O risco é traçado da esquerda para a direita — o gesto de "cortar da
   * lista" — e o item recua um pouco quando é riscado.
   */
  const itens = props.itens || []

  return (
    <Palco>
      <div style={{ display: 'flex', flexDirection: 'column', gap: 54, paddingLeft: 40 }}>
        {itens.map((item, i) => {
          const entra = ms(200) + i * ms(560)
          const risca = entra + ms(420)

          const ent = spring({ frame: frame - entra, fps: 30, config: { damping: 14, mass: 0.8 } })
          const corte = interpolate(frame - risca, [0, ms(380)], [0, 1], {
            extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.movimento
          })
          // Esmaece DEPOIS do risco chegar ao fim, não junto.
          const apaga = interpolate(frame - risca - ms(260), [0, ms(340)], [0, 1], {
            extrapolateLeft: 'clamp', extrapolateRight: 'clamp'
          })

          return (
            <div key={i} style={{
              position: 'relative', display: 'inline-block', alignSelf: 'flex-start',
              opacity: Math.min(ent * 2, 1),
              transform: `translateX(${(1 - ent) * -40 + apaga * 16}px)`
            }}>
              <span style={{
                fontSize: 82, fontWeight: 700,
                // branco -> cinza, só depois do corte
                color: apaga > 0.5 ? cores.textoFraco : cores.texto,
                opacity: 1 - apaga * 0.45
              }}>
                {item}
              </span>
              {/* o risco */}
              <div style={{
                position: 'absolute', left: 0, top: '52%',
                width: `${corte * 100}%`, height: 5,
                background: cores.vermelho, borderRadius: 3,
                boxShadow: `0 0 16px ${cores.vermelho}66`
              }} />
            </div>
          )
        })}
      </div>
    </Palco>
  )
}


/**
 * Cena 4 · O PARCELAMENTO INFLANDO.
 *
 * A cena mais importante do ato. O valor de R$ 1.000 vira R$ 1.200 na frente do
 * espectador, e as 12 parcelas aparecem embaixo somando. É a prova visual do
 * mecanismo — sem ela, "mil vira mil e duzentos" é só uma afirmação.
 */
export function Parcelamento({ props, duracao}) {
  const frame = useCurrentFrame()
  const { style } = useEntrada(0)

  const inflando = interpolate(frame, [ms(900), ms(2100)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  const parcela = props.para / props.parcelas

  return (
    <Palco centro>
      <div style={{ ...style, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 30 }}>
        <span style={{ fontSize: 34, fontWeight: 500, color: cores.textoFraco, letterSpacing: 0.4 }}>
          Sua venda
        </span>

        <div style={{ display: 'flex', alignItems: 'baseline', gap: 34 }}>
          <Numero limite={duracao}
            de={props.de} para={props.de} atraso={0}
            style={{
              fontSize: 150, fontWeight: 700, letterSpacing: -5,
              color: inflando > 0.02 ? cores.textoFraco : cores.texto,
              textDecoration: inflando > 0.5 ? 'line-through' : 'none',
              opacity: inflando > 0.5 ? 0.45 : 1
            }}
          />
          {inflando > 0.04 && (
            <>
              <span style={{ fontSize: 78, color: cores.textoFraco, opacity: inflando }}>→</span>
              <Numero limite={duracao}
                de={props.de} para={props.para} atraso={ms(900)} duracao={ms(1200)}
                style={{
                  fontSize: 168, fontWeight: 700, letterSpacing: -5,
                  color: cores.vermelho,
                  opacity: inflando,
                  transform: `scale(${escalaEntrada(inflando, 0.9)})`,
                  display: 'inline-block'
                }}
              />
            </>
          )}
        </div>

        {/* As 12 parcelas somando — dá materialidade ao "em doze vezes" */}
        <div style={{ display: 'flex', gap: 11, marginTop: 18 }}>
          {Array.from({ length: props.parcelas }).map((_, i) => {
            const at = ms(1300) + atrasoStagger(i, props.parcelas, ms(760))
            const p = interpolate(frame - at, [0, ms(340)], [0, 1], {
              extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
            })
            return (
              <div
                key={i}
                style={{
                  width: 92, padding: '16px 0', borderRadius: 12,
                  background: `${cores.vermelho}18`,
                  border: `1.5px solid ${cores.vermelho}44`,
                  textAlign: 'center', opacity: p,
                  transform: `translateY(${(1 - p) * 18}px) scale(${escalaEntrada(p)})`
                }}
              >
                <div style={{ fontSize: 19, color: cores.textoFraco, marginBottom: 3 }}>{i + 1}x</div>
                <div style={{ fontSize: 25, fontWeight: 600, color: cores.texto }}>
                  {parcela.toLocaleString('pt-BR', { maximumFractionDigits: 0 })}
                </div>
              </div>
            )
          })}
        </div>

        <span style={{ fontSize: 32, color: cores.textoFraco, opacity: interpolate(frame, [ms(2300), ms(2800)], [0, 1], { extrapolateLeft: 'clamp', extrapolateRight: 'clamp' }) }}>
          no cartão do seu cliente
        </span>
      </div>
    </Palco>
  )
}

/** Cena 5 e 8 e 24 · o martelo: um número grande e a frase que o explica. */
export function Martelo({ props, icone }) {
  const tom = props.tom === 'vermelho' ? cores.vermelho : cores.amarelo
  const frame = useCurrentFrame()

  /*
   * O martelo é o momento em que o número BATE. Antes ele só aparecia com
   * fade e escala — o mesmo gesto de qualquer outro texto, e o argumento mais
   * forte da cena entrava sem peso.
   *
   * Agora: onda de choque + flash da marca + o número explodindo com
   * estilhaços, e as linhas de apoio entrando depois do impacto assentar.
   */
  return (
    <Palco centro>
      <Onda em={ms(60)} cor={tom} raio={520} />
      <Flash em={ms(60)} cor={tom} forca={0.14} />

      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 30 }}>
        {icone && (
          <div style={{ marginBottom: 4 }}>
            <IconeChega nome={icone} em={0} tamanho={92} cor={tom} />
          </div>
        )}

        {/* O tamanho cede ao comprimento: "R$ 200 a mais" em 176px estourava a
            tela. Textos longos encolhem em vez de vazar. */}
        <NumeroExplode
          texto={props.destaque}
          em={ms(60)}
          tamanho={String(props.destaque).length > 9 ? 128 : 176}
          cor={tom}
          estilhacos={14}
        />

        <div style={{ display: 'flex', flexDirection: 'column', gap: 12, alignItems: 'center' }}>
          {props.linhas.map((l, i) => {
            // As linhas entram DEPOIS do impacto: a ordem é o que dá causa
            // e consequência em vez de tudo chegando junto.
            const p = interpolate(frame - ms(520) - i * ms(260), [0, ms(420)], [0, 1], {
              extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
            })
            return (
              <span key={i} style={{
                fontSize: 62, fontWeight: 500, color: cores.texto,
                opacity: p, transform: `translateY(${(1 - p) * 22}px)`
              }}>
                {l}
              </span>
            )
          })}
        </div>
      </div>
    </Palco>
  )
}


/** Cenas 6, 28, 34 · impacto: frase sozinha, tela quase vazia. */
export function Impacto({ props, icone }) {
  const frame = useCurrentFrame()
  const ehPerda = props.tom === 'vermelho'
  const tom = ehPerda ? cores.vermelho : (props.tom === 'amarelo' ? cores.amarelo : cores.texto)

  /*
   * O fundo REAGE ao que a frase diz. Numa cena de perda ele sangra vermelho
   * e as partículas CAEM em vez de subir — o quadro fica pesado junto com o
   * argumento. Antes era o mesmo fundo neutro de todas as outras, e a cena
   * mais dura do ato tinha o mesmo clima da mais leve.
   */
  const tinge = interpolate(frame, [0, ms(700)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  // Pulsação lenta: o vermelho respira como algo vivo e incômodo.
  const respira = 1 + Math.sin(frame / 32) * 0.22

  // Gotas caindo: o dinheiro escorrendo para fora do quadro.
  const gotas = Array.from({ length: 16 }).map((_, i) => {
    const nasce = i * 7
    const p = ((frame - nasce) % 150) / 150
    if (frame < nasce) return null
    return {
      i,
      x: 90 + (i * 127) % 1760,
      y: -40 + p * 1180,
      op: Math.sin(p * Math.PI) * 0.5,
      r: 2.5 + (i % 3)
    }
  }).filter(Boolean)

  return (
    <Palco centro>
      {ehPerda && (
        <>
          {/* sangria vermelha nas bordas */}
          <div style={{
            position: 'absolute', inset: 0, pointerEvents: 'none',
            background: `radial-gradient(ellipse at 50% 50%, transparent 34%, ${cores.vermelho}22 100%)`,
            opacity: tinge * respira
          }} />
          <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
            {gotas.map((g) => (
              <circle key={g.i} cx={g.x} cy={g.y} r={g.r}
                      fill={cores.vermelho} opacity={g.op * tinge} />
            ))}
          </svg>
        </>
      )}

      <div style={{ position: 'relative', zIndex: 2 }}>
        {icone && (
          <div style={{ display: 'flex', justifyContent: 'center', marginBottom: 28 }}>
            <IconeChega nome={icone} em={ms(60)} tamanho={96} cor={tom} />
          </div>
        )}
        <Frase texto={props.texto} tamanho={props.gigante ? 132 : 104} cor={tom} align="center" />
      </div>
    </Palco>
  )
}


/**
 * Cena 7 · O FLUXO DO DINHEIRO.
 *
 * Cliente → plataforma → produtor. A moeda percorre o caminho e a plataforma
 * ENGORDA ao recebê-la, enquanto o que chega no produtor é visivelmente menor.
 * É a cena que traduz o argumento inteiro em uma imagem só.
 */
export function Fluxo({ props, duracao }) {
  const frame = useCurrentFrame()

  /*
   * TRÊS EIXOS, um por etapa: cliente → plataforma → você.
   * Antes cada elemento tinha a sua própria coordenada (moeda em 300, ícone em
   * 735, rótulos em 300/960/1540) e nada se alinhava. Agora tudo — círculo,
   * linha, moeda em trânsito e rótulo — deriva do mesmo X.
   */
  const EIXO = [340, 960, 1580]
  const LINHA_Y = 470          // altura do percurso
  const ROTULO_Y = 660         // base comum dos três rótulos

  const chegouNaPlataforma = interpolate(frame, [ms(900), ms(1250)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  const chegouNoDestino = interpolate(frame, [ms(2100), ms(2450)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })
  const entra = interpolate(frame, [0, ms(400)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  /** Nó do percurso: círculo com um glifo dentro. */
  const No = ({ x, cor, glifo, escala = 1, opacidade = 1, brilho = 0 }) => (
    <g transform={`translate(${x} ${LINHA_Y}) scale(${escala})`} opacity={opacidade}>
      {brilho > 0 && (
        <circle r={72} fill={cor} opacity={brilho * 0.16} />
      )}
      <circle r={58} fill={cores.superficie} stroke={cor} strokeWidth={3.5} />
      <text y={20} textAnchor="middle" fontSize={50} fontWeight={700} fill={cor}
            style={{ fontFamily: fontes.corpo }}>{glifo}</text>
    </g>
  )

  return (
    <Palco>
      <svg width={1920} height={1080} style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
        {/* trilhos entre os nós, traçados na ordem do percurso */}
        <LinhaAnimada d={`M ${EIXO[0] + 70} ${LINHA_Y} L ${EIXO[1] - 70} ${LINHA_Y}`}
                      atraso={ms(400)} cor={cores.borda} />
        <LinhaAnimada d={`M ${EIXO[1] + 70} ${LINHA_Y} L ${EIXO[2] - 70} ${LINHA_Y}`}
                      atraso={ms(1600)} cor={cores.borda} />

        {/* moeda cheia sai do cliente; moeda MENOR chega em você — a diferença
            ficou no meio, e o tamanho conta isso sem precisar de texto */}
        <MoedaNoCaminho pontos={[[EIXO[0] + 70, LINHA_Y], [EIXO[1] - 70, LINHA_Y]]}
                        atraso={ms(560)} duracao={ms(900)} tamanho={24} />
        <MoedaNoCaminho pontos={[[EIXO[1] + 70, LINHA_Y], [EIXO[2] - 70, LINHA_Y]]}
                        atraso={ms(1760)} duracao={ms(900)} tamanho={15} />

        <No x={EIXO[0]} cor={cores.amarelo} glifo="$" opacidade={entra} escala={0.9 + entra * 0.1} />
        <No x={EIXO[1]} cor={cores.vermelho} glifo="%" opacidade={chegouNaPlataforma}
            escala={0.86 + chegouNaPlataforma * 0.14} brilho={chegouNaPlataforma} />
        <No x={EIXO[2]} cor={cores.texto} glifo="→" opacidade={chegouNoDestino}
            escala={0.86 + chegouNoDestino * 0.14} />
      </svg>

      {/* rótulos: mesma base, mesma largura, centrados no eixo do nó */}
      {[
        { x: EIXO[0], rot: 'Cliente pagou', valor: props.pagou, cor: cores.texto, op: entra, atraso: ms(300) },
        { x: EIXO[1], rot: 'Ficou aqui', valor: props.pagou - props.recebeu, cor: cores.vermelho, op: chegouNaPlataforma, atraso: ms(1000) },
        { x: EIXO[2], rot: 'Você recebeu', valor: props.recebeu, cor: cores.texto, op: chegouNoDestino, atraso: ms(2200) }
      ].map((c, i) => (
        <div key={i} style={{
          position: 'absolute', left: c.x - 190, top: ROTULO_Y,
          width: 380, textAlign: 'center', opacity: c.op
        }}>
          <div style={{ fontSize: 26, color: cores.textoFraco, marginBottom: 14 }}>{c.rot}</div>
          <Numero limite={duracao} de={0} para={c.valor} atraso={c.atraso}
                  style={{ fontSize: 64, fontWeight: 700, color: c.cor }} />
        </div>
      ))}
    </Palco>
  )
}

/**
 * Cena 9 · A TAXA ANUNCIADA vs A REAL.
 *
 * Duas barras lado a lado. A anunciada assenta rápido e pequena; a real cresce
 * muito além dela. O contraste de altura é o argumento — nenhum texto precisa
 * dizer "é quatro vezes maior".
 */
export function TaxaReal({ props }) {
  const frame = useCurrentFrame()

  /*
   * LAYOUT: uma coluna por barra, e TUDO dentro dela.
   *
   * O erro anterior: as vilãs iam num <svg> com coordenada absoluta enquanto
   * as barras eram flexbox centralizado — dois sistemas de posicionamento
   * diferentes na mesma cena, então nunca alinhariam. Agora cada coluna é um
   * flex vertical e os ícones são filhos dela: alinham por construção.
   *
   * Ordem de leitura, de cima para baixo:
   *   ícones de quem cobra · valor · barra · rótulo
   */
  const ALTURA_MAX = 380
  const LARGURA_BARRA = 200

  const cresce = (valor, atraso) => {
    const p = interpolate(frame - atraso, [0, ms(900)], [0, 1], {
      extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
    })
    return { altura: (valor / props.real) * ALTURA_MAX * p, p }
  }

  const anunciada = cresce(props.anunciada, ms(400))
  const real = cresce(props.real, ms(1300))
  const vilas = interpolate(frame, [ms(2100), ms(2600)], [0, 1], {
    extrapolateLeft: 'clamp', extrapolateRight: 'clamp', easing: curvas.enfase
  })

  const colunas = [
    { rotulo: 'A que ela anuncia', valor: props.anunciada, est: anunciada, cor: cores.textoFraco, vila: false },
    { rotulo: 'A que ela cobrou', valor: props.real, est: real, cor: cores.vermelho, vila: true }
  ]

  return (
    <Palco centro>
      <div style={{ display: 'flex', alignItems: 'flex-end', gap: 180 }}>
        {colunas.map((c, i) => (
          <div key={i} style={{
            display: 'flex', flexDirection: 'column', alignItems: 'center',
            width: 340
          }}>
            {/* faixa dos ícones: altura fixa nas DUAS colunas, para as barras
                começarem na mesma linha mesmo com só uma tendo ícone */}
            <div style={{
              height: 132, display: 'flex', alignItems: 'center', justifyContent: 'center',
              gap: 18, opacity: c.vila ? vilas : 0,
              transform: `translateY(${c.vila ? (1 - vilas) * 16 : 0}px)`
            }}>
              {c.vila && (
                <svg width={280} height={130} viewBox="-140 -65 280 130">
                  {/* flutuam em fases diferentes, sem caixa: são elas que
                      cobram, não um selo institucional */}
                  <g transform={`translate(-64 ${Math.sin(frame / 24) * 8})`}>
                    <VilaFogo x={0} y={0} escala={0.62} />
                  </g>
                  <g transform={`translate(64 ${Math.sin(frame / 29 + 1.6) * 8})`}>
                    <VilaFruta x={0} y={0} escala={0.62} />
                  </g>
                </svg>
              )}
            </div>

            <span style={{
              fontSize: 92, fontWeight: 700, color: c.cor, opacity: c.est.p,
              letterSpacing: -2, marginBottom: 20, lineHeight: 1
            }}>
              {c.valor}%
            </span>

            <div style={{
              width: LARGURA_BARRA, height: c.est.altura,
              borderRadius: '18px 18px 0 0',
              background: `linear-gradient(180deg, ${c.cor} 0%, ${c.cor}55 100%)`
            }} />

            {/* linha de base comum: é ela que ancora as duas colunas */}
            <div style={{ width: 300, height: 2, background: cores.borda, marginTop: 0 }} />

            <span style={{
              fontSize: 30, color: cores.textoFraco, marginTop: 22, opacity: c.est.p
            }}>
              {c.rotulo}
            </span>
          </div>
        ))}
      </div>
    </Palco>
  )
}

