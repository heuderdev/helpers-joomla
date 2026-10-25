import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring } from 'remotion'
import { cores, fontes, seguro } from './marca.js'
import { dur, ms, escalaEntrada, movingHold } from './motion.js'
import { molas } from './movimento.jsx'
import { Palco, Legenda, Assinatura, Nucleo, faixa } from './cenas.jsx'
import icones from './icones.json'
import marcas from './marcas.json'
import bancos from './bancos.json'

/*
 * CENAS DO VÍDEO "OPEN FINANCE".
 *
 * O tema é AUTOMAÇÃO: o trabalho acontece sem você. A forma visual é sempre
 * TEMPO ou CICLO — ampulheta, calendário, órbita, pulso — porque o que se está
 * vendendo é o que roda enquanto a pessoa não está olhando.
 *
 *   "quanto tempo você perde"  → minutos escoando de uma ampulheta
 *   "uma semana sim, três não" → quatro semanas, duas acendem e duas apagam
 *   "autoriza uma vez"         → um único toque, e o chaveiro trava
 *   "busca sozinho, todo dia"  → pulso diário, sete vezes, sem intervenção
 *   "todos os bancos"          → os logos orbitando em cadência
 *   "já chega organizado"      → a transação entra COM a etiqueta colada
 *   "sem encostar num campo"   → uma mão que se afasta do teclado
 *   "só leitura"               → seta de mão dupla com a volta bloqueada
 *
 * A abertura tem `bancosChegam`/`bancosConectam`/`somenteLeitura`. Aqui a
 * geometria é OUTRA de propósito: lá os bancos entravam em órbita estática;
 * aqui eles pulsam em cadência de sincronização diária, que é o assunto.
 */

const CX = 540

/* --------------------------------------------------- 01 · tempo perdido */

/**
 * Os minutos escoando.
 *
 * Ampulheta feita de dois trapézios, com a areia migrando de cima para baixo e
 * o contador subindo junto. O número não aparece pronto: ele se acumula na
 * mesma velocidade em que a areia cai, então o dado e a imagem contam a mesma
 * coisa ao mesmo tempo.
 */
export function CenaTempoPerdido({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const escoa = faixa(frame, 8, 52, 0, 1)
  const minutos = Math.floor(escoa * (dados.minutos ?? 40))
  const entra = faixa(frame, 1, 14, 0, 1)

  /*
   * A ampulheta é UM path só, não dois blocos com borda arredondada.
   *
   * A primeira versão montava dois divs com `border-radius` em porcentagem, e
   * eles nunca se encontravam no meio: o resultado eram duas cúpulas soltas,
   * sem o estrangulamento que faz a forma ser lida como ampulheta. Com um
   * contorno em SVG o gargalo existe de verdade, e a areia usa o MESMO desenho
   * como máscara — então o líquido nunca escapa do vidro.
   */
  const VIDRO = 'M40 20 H260 L165 210 L260 400 H40 L135 210 Z'

  return (
    <Palco glow={0.45} cor={cores.cinza}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 260, paddingBottom: 120, gap: 46 }}>
        <svg width="300" height="420" viewBox="0 0 300 420" style={{ opacity: entra, overflow: 'visible' }}>
          <defs>
            {/* a máscara é o próprio vidro: a areia só existe dentro dele */}
            <clipPath id="vidro">
              <path d={VIDRO} />
            </clipPath>
            <linearGradient id="areia" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={cores.superficieAlta} />
              <stop offset="100%" stopColor={cores.cinza} />
            </linearGradient>
          </defs>

          <g clipPath="url(#vidro)">
            {/* areia de cima: a superfície DESCE conforme escoa */}
            <rect x="0" y={20 + escoa * 190} width="300" height={200 - escoa * 190} fill="url(#areia)" opacity="0.92" />
            {/* areia de baixo: acumula a partir da base */}
            <rect x="0" y={400 - escoa * 190} width="300" height={escoa * 190} fill="url(#areia)" opacity="0.92" />
          </g>

          {/* o fio caindo pelo gargalo, só enquanto está escoando */}
          {escoa > 0.02 && escoa < 0.98 && (
            <rect x="147" y="205" width="6" height="90" fill={cores.cinza} opacity="0.85" />
          )}

          {/* o contorno por cima da areia, para o vidro ficar sempre legível */}
          <path d={VIDRO} fill="none" stroke={cores.superficieAlta} strokeWidth="5" strokeLinejoin="round" />
          {/* tampas */}
          <rect x="30" y="10" width="240" height="14" rx="7" fill={cores.superficieAlta} />
          <rect x="30" y="396" width="240" height="14" rx="7" fill={cores.superficieAlta} />
        </svg>

        <div style={{ textAlign: 'center' }}>
          <div style={{ fontFamily: fontes.titulo, fontSize: 130, color: cores.texto, letterSpacing: -5, lineHeight: 1 }}>
            {minutos}
          </div>
          <div style={{ fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco, marginTop: 12 }}>
            {dados.rotulo || 'minutos por mês, digitando'}
          </div>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Quanto tempo|você perde?'} destaque={dados.destaque || ['tempo']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ----------------------------------------------- 02 · constância quebra */

/**
 * As semanas que acendem e apagam.
 *
 * Quatro colunas de dias. As duas primeiras se preenchem; as duas últimas
 * ficam ocas e a linha de constância despenca. A forma é a de um gráfico de
 * hábito falhando — que é literalmente o que a frase descreve.
 */
export function CenaConstanciaQuebra({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const semanas = dados.semanas || [1, 1, 0, 0]

  return (
    <Palco glow={0.4} cor={cores.cinza}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 260, paddingBottom: 140 }}>
        <div style={{ display: 'flex', gap: 30 }}>
          {semanas.map((ativa, s) => (
            <div key={s} style={{ display: 'flex', flexDirection: 'column', gap: 14, alignItems: 'center' }}>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
                {Array.from({ length: 7 }).map((_, d) => {
                  const p = faixa(frame, 4 + s * 8 + d * 2, 16 + s * 8 + d * 2, 0, 1)
                  const preenchido = ativa === 1
                  return (
                    <div
                      key={d}
                      style={{
                        width: 62,
                        height: 62,
                        borderRadius: 16,
                        backgroundColor: preenchido ? cores.azul : 'transparent',
                        border: preenchido ? 'none' : `2px dashed ${cores.superficieAlta}`,
                        opacity: p * (preenchido ? 1 : 0.7),
                        transform: `scale(${escalaEntrada(p, 0.6)})`,
                        boxShadow: preenchido ? `0 0 22px ${cores.azul}55` : 'none'
                      }}
                    />
                  )
                })}
              </div>
              <span
                style={{
                  fontFamily: fontes.corpoLeve,
                  fontSize: 26,
                  color: ativa ? cores.textoFraco : cores.cinza,
                  opacity: faixa(frame, 20 + s * 6, 32 + s * 6, 0, 1)
                }}
              >
                sem {s + 1}
              </span>
            </div>
          ))}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Uma semana sim,|três não.'} destaque={dados.destaque || ['três não.']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* --------------------------------------------- 04 · autoriza uma vez */

/**
 * O toque único que trava.
 *
 * Um botão de autorização recebe UM toque (o anel de ripple sai do ponto), e
 * logo em seguida um cadeado se fecha sobre ele. A ideia de "uma vez só" é o
 * ripple que não se repete — nenhum segundo toque acontece na cena inteira.
 */
export function CenaAutorizaUmaVez({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const entra = spring({ frame: frame - 3, fps, config: molas.suave })
  const toque = faixa(frame, 18, 44, 0, 1)
  const confirma = faixa(frame, 30, 44, 0, 1)
  const chave = dados.banco || 'nubank'

  return (
    <Palco glow={0.85}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 280, paddingBottom: 120, gap: 52 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 30, opacity: entra, transform: `scale(${escalaEntrada(entra, 0.9)})` }}>
          <div style={{ width: 130, height: 130, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: bancos[chave]?.svg || '' }} />
          <div style={{ width: 60, height: 4, borderRadius: 2, backgroundColor: cores.azul, opacity: confirma }} />
          <Nucleo tamanho={130} brilho={0.6 + confirma * 0.6} escala={escalaEntrada(entra, 0.86)} />
        </div>

        {/* o botão com o toque único */}
        <div style={{ position: 'relative' }}>
          <div
            style={{
              width: 560,
              height: 116,
              borderRadius: 28,
              backgroundColor: confirma > 0.5 ? cores.verde : cores.azul,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              gap: 16,
              fontFamily: fontes.corpo,
              fontSize: 40,
              color: cores.texto,
              opacity: faixa(frame, 10, 22, 0, 1),
              transform: `scale(${1 - toque * 0.02})`,
              boxShadow: `0 0 60px ${(confirma > 0.5 ? cores.verde : cores.azul)}66`
            }}
          >
            {confirma > 0.5 && (
              <div style={{ width: 44, height: 44, color: cores.texto }} dangerouslySetInnerHTML={{ __html: icones['check'] }} />
            )}
            {confirma > 0.5 ? 'Autorizado' : 'Autorizar acesso'}
          </div>

          {/* ripple: UM só, e ele não se repete */}
          <div
            style={{
              position: 'absolute',
              left: '50%',
              top: '50%',
              width: 120,
              height: 120,
              marginLeft: -60,
              marginTop: -60,
              borderRadius: '50%',
              border: `3px solid ${cores.texto}`,
              opacity: (1 - toque) * 0.7,
              transform: `scale(${1 + toque * 4.2})`
            }}
          />
        </div>

        <div style={{ fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco, opacity: faixa(frame, 40, 52, 0, 1) }}>
          {dados.rotulo || 'e pronto, nunca mais'}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Uma autorização.|Uma vez só.'} destaque={dados.destaque || ['Uma vez só.']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ------------------------------------------- 05 · sincroniza sozinho */

/**
 * O pulso diário.
 *
 * Sete dias numa trilha; um pulso azul percorre a trilha e, em cada dia,
 * dispara um anel de sincronização. Ninguém aperta nada — a repetição É o
 * argumento. O ícone de sincronizar gira continuamente no centro.
 */
export function CenaSincronizaSozinho({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const total = dados.dias ?? 7
  const avanco = faixa(frame, 6, 56, 0, total)

  return (
    <Palco glow={0.85}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 280, paddingBottom: 120, gap: 70 }}>
        {/* o disco girando: a máquina que não para */}
        <div style={{ position: 'relative', width: 200, height: 200, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div
            style={{
              position: 'absolute',
              inset: 0,
              borderRadius: '50%',
              border: `3px solid ${cores.superficieAlta}`
            }}
          />
          <div
            style={{
              position: 'absolute',
              inset: 0,
              borderRadius: '50%',
              border: `3px solid transparent`,
              borderTopColor: cores.azul,
              borderRightColor: cores.azul,
              transform: `rotate(${frame * 6}deg)`,
              filter: `drop-shadow(0 0 12px ${cores.azul}88)`
            }}
          />
          <div style={{ width: 84, height: 84, color: cores.azulClaro }} dangerouslySetInnerHTML={{ __html: icones['nuvem-sincronizar'] || icones['sincronizar'] }} />
        </div>

        {/* a trilha de dias */}
        <div style={{ display: 'flex', alignItems: 'center', gap: 0 }}>
          {Array.from({ length: total }).map((_, i) => {
            const passou = avanco > i
            const agora = avanco > i && avanco < i + 0.6
            return (
              <React.Fragment key={i}>
                {i > 0 && (
                  <div
                    style={{
                      width: 46,
                      height: 4,
                      backgroundColor: passou ? cores.azul : cores.superficieAlta,
                      opacity: passou ? 1 : 0.6
                    }}
                  />
                )}
                <div style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                  <div
                    style={{
                      width: 54,
                      height: 54,
                      borderRadius: '50%',
                      backgroundColor: passou ? cores.azul : cores.superficie,
                      border: `2px solid ${passou ? cores.azul : cores.superficieAlta}`,
                      boxShadow: passou ? `0 0 26px ${cores.azul}77` : 'none'
                    }}
                  />
                  {/* anel de sincronização no instante em que o pulso chega */}
                  {agora && (
                    <div
                      style={{
                        position: 'absolute',
                        width: 54,
                        height: 54,
                        borderRadius: '50%',
                        border: `3px solid ${cores.azulClaro}`,
                        transform: `scale(${1 + (avanco - i) * 3})`,
                        opacity: 1 - (avanco - i) / 0.6
                      }}
                    />
                  )}
                </div>
              </React.Fragment>
            )
          })}
        </div>

        <div style={{ fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco, opacity: faixa(frame, 30, 42, 0, 1) }}>
          {dados.rotulo || 'todo dia, sem você pedir'}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Ele busca|sozinho.'} destaque={dados.destaque || ['sozinho.']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* --------------------------------------------- 06 · todos os bancos */

/**
 * Os bancos em cadência.
 *
 * Diferente da órbita da abertura: aqui eles ficam em GRADE e pulsam em
 * sequência, um após o outro, como uma fila de sincronização rodando. A leitura
 * é "todos, sempre", não "todos, uma vez".
 */
export function CenaTodosOsBancos({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const lista = dados.bancos || ['nubank', 'itau', 'inter', 'c6', 'bradesco', 'santander']

  return (
    <Palco glow={0.9}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 280, paddingBottom: 130, gap: 54 }}>
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(3, 200px)', gap: 30, justifyContent: 'center' }}>
          {lista.map((chave, i) => {
            const p = faixa(frame, 3 + i * 5, 15 + i * 5, 0, 1)
            /* pulso em cadência: cada um acende na sua vez, em loop */
            const ciclo = (frame / 8 - i) % lista.length
            const aceso = ciclo > 0 && ciclo < 1 ? 1 - ciclo : 0
            return (
              /* Sem quadro por baixo: o SVG do banco já traz o próprio fundo
                 e o raio da marca. Um card cinza atrás só criaria uma moldura
                 dupla, e o padding encolheria o logo dentro dela. O brilho da
                 cadência vai no drop-shadow, que respeita o contorno do SVG. */
              <div
                key={chave}
                style={{
                  width: 200,
                  height: 200,
                  opacity: p,
                  transform: `scale(${escalaEntrada(p, 0.86) * (1 + aceso * 0.04)})`,
                  filter: aceso > 0.05 ? `drop-shadow(0 0 ${34 * aceso}px ${cores.azul})` : 'drop-shadow(0 12px 30px rgba(0,0,0,0.5))'
                }}
                dangerouslySetInnerHTML={{ __html: bancos[chave]?.svg || '' }}
              />
            )
          })}
        </div>

        <div style={{ display: 'flex', gap: 40, opacity: faixa(frame, 34, 46, 0, 1) }}>
          {['conta', 'cartão', 'saldo', 'fatura'].map((rot, i) => (
            <span key={rot} style={{ fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.azulSuave }}>
              {rot}
            </span>
          ))}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'De todos|os seus bancos.'} destaque={dados.destaque || ['todos']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ------------------------------------------ 07 · chega categorizado */

/**
 * A transação que entra COM a etiqueta.
 *
 * O detalhe que carrega a frase: a categoria não é aplicada depois, num segundo
 * movimento — ela entra grudada na linha, no mesmo gesto. Por isso a etiqueta
 * compartilha a mesma animação de entrada da transação, sem atraso próprio.
 */
export function CenaChegaCategorizado({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const itens = dados.itens || [
    ['ifood', 'iFood', 'Alimentação'],
    ['uber', 'Uber', 'Transporte'],
    ['netflix', 'Netflix', 'Assinaturas']
  ]

  return (
    <Palco glow={0.85}>
      <AbsoluteFill style={{ paddingTop: seguro.topo + 260, paddingBottom: seguro.base - 20, paddingLeft: 110, paddingRight: 110, justifyContent: 'center' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 24 }}>
          {itens.map(([chave, nome, categoria], i) => {
            const p = faixa(frame, 5 + i * 9, 22 + i * 9, 0, 1)
            return (
              <div
                key={chave}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 24,
                  padding: '28px 32px',
                  borderRadius: 30,
                  backgroundColor: cores.superficie,
                  border: `1px solid ${cores.borda}`,
                  opacity: p,
                  /* entra deslizando da direita, como algo que CHEGOU */
                  transform: `translateX(${(1 - p) * 120}px)`
                }}
              >
                {/* O SVG da lib JÁ traz o próprio fundo e o `rx` da marca —
                    embrulhar em quadro com padding só encolhe o logo dentro
                    dele. Aqui a caixa apenas define o tamanho. */}
                <div style={{ width: 78, height: 78, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: marcas[chave]?.svg || '' }} />
                <span style={{ fontFamily: fontes.corpo, fontSize: 40, color: cores.texto, flex: 1 }}>{nome}</span>
                {/* a etiqueta NÃO tem atraso próprio: ela chega junto */}
                <span
                  style={{
                    fontFamily: fontes.corpo,
                    fontSize: 28,
                    color: cores.azulClaro,
                    padding: '12px 24px',
                    borderRadius: 999,
                    backgroundColor: `${cores.azul}22`,
                    border: `1px solid ${cores.azul}55`,
                    whiteSpace: 'nowrap'
                  }}
                >
                  {categoria}
                </span>
              </div>
            )
          })}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Já chega|organizado.'} destaque={dados.destaque || ['organizado.']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* -------------------------------------------- 08 · campo intocado */

/**
 * O teclado que ninguém encosta.
 *
 * As teclas aparecem e ficam apagadas; nenhuma é pressionada. O campo acima se
 * preenche sozinho, e o cursor nunca pisca nele. A ausência de interação é o
 * conteúdo do plano.
 */
export function CenaCampoIntocado({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const preenche = spring({ frame: frame - 14, fps, config: molas.suave })

  return (
    <Palco glow={0.8}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 290, paddingBottom: 120, gap: 60 }}>
        {/* o campo se preenchendo sozinho */}
        <div
          style={{
            width: 760,
            height: 128,
            borderRadius: 26,
            backgroundColor: cores.superficie,
            border: `1px solid ${cores.azul}66`,
            display: 'flex',
            alignItems: 'center',
            padding: '0 36px',
            gap: 18,
            opacity: faixa(frame, 2, 14, 0, 1),
            boxShadow: `0 0 50px ${cores.azul}22`
          }}
        >
          <div style={{ width: 46, height: 46, color: cores.azulClaro, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: icones['check-circulo'] }} />
          <span
            style={{
              fontFamily: fontes.corpo,
              fontSize: 40,
              color: cores.texto,
              /* revela por clip, como se fosse escrito por outra pessoa */
              clipPath: `inset(0 ${(1 - preenche) * 100}% 0 0)`
            }}
          >
            {dados.campo || 'iFood · R$ 48,90 · Alimentação'}
          </span>
        </div>

        {/* teclado apagado */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14, opacity: faixa(frame, 18, 30, 0, 0.45) }}>
          {[10, 9, 7].map((qtd, linha) => (
            <div key={linha} style={{ display: 'flex', gap: 12, justifyContent: 'center' }}>
              {Array.from({ length: qtd }).map((_, i) => (
                <div
                  key={i}
                  style={{
                    width: 74,
                    height: 74,
                    borderRadius: 14,
                    backgroundColor: cores.superficie,
                    border: `1px solid ${cores.borda}`,
                    filter: 'grayscale(1)'
                  }}
                />
              ))}
            </div>
          ))}
        </div>

        <div style={{ fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco, opacity: faixa(frame, 36, 48, 0, 1) }}>
          {dados.rotulo || 'ninguém digitou isso'}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Sem encostar|num campo.'} destaque={dados.destaque || ['num campo.']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ------------------------------------------- 09 · leitura apenas */

/**
 * A via de mão dupla com a volta bloqueada.
 *
 * Duas setas entre banco e app: a de ida (dados) pulsa e vive; a de volta
 * (dinheiro) está barrada, com um traço vermelho atravessando. A segurança fica
 * evidente pela assimetria, sem precisar do cadeado — que a skill reserva para
 * o vídeo de segurança.
 */
export function CenaLeituraApenas({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const entra = spring({ frame: frame - 3, fps, config: molas.suave })
  const fluxo = (frame / 26) % 1
  const barra = faixa(frame, 22, 36, 0, 1)

  return (
    <Palco glow={0.8}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 280, paddingBottom: 130, gap: 44 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 34, opacity: entra }}>
          <div style={{ width: 150, height: 150, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: bancos[dados.banco || 'itau']?.svg || '' }} />

          <div style={{ display: 'flex', flexDirection: 'column', gap: 26, width: 250 }}>
            {/* ida: dados, viva */}
            <div style={{ position: 'relative', height: 56 }}>
              <div style={{ position: 'absolute', top: 26, left: 0, right: 0, height: 4, backgroundColor: `${cores.azul}44` }} />
              <div
                style={{
                  position: 'absolute',
                  top: 20,
                  left: `${fluxo * 100}%`,
                  width: 16,
                  height: 16,
                  borderRadius: 8,
                  backgroundColor: cores.azul,
                  boxShadow: `0 0 20px ${cores.azul}`,
                  opacity: entra
                }}
              />
              <span style={{ position: 'absolute', top: -10, left: 0, fontFamily: fontes.corpoLeve, fontSize: 26, color: cores.azulSuave }}>
                dados
              </span>
            </div>

            {/* volta: dinheiro, barrada */}
            <div style={{ position: 'relative', height: 56 }}>
              <div style={{ position: 'absolute', top: 26, left: 0, right: 0, height: 4, backgroundColor: cores.superficieAlta }} />
              <span style={{ position: 'absolute', top: -10, left: 0, fontFamily: fontes.corpoLeve, fontSize: 26, color: cores.cinza }}>
                dinheiro
              </span>
              {/* o corte vermelho */}
              <div
                style={{
                  position: 'absolute',
                  top: 6,
                  left: '50%',
                  width: 4,
                  height: 44,
                  marginLeft: -2,
                  backgroundColor: cores.vermelho,
                  transform: `rotate(45deg) scaleY(${barra})`,
                  boxShadow: `0 0 18px ${cores.vermelho}aa`
                }}
              />
              <div
                style={{
                  position: 'absolute',
                  top: 6,
                  left: '50%',
                  width: 4,
                  height: 44,
                  marginLeft: -2,
                  backgroundColor: cores.vermelho,
                  transform: `rotate(-45deg) scaleY(${barra})`,
                  boxShadow: `0 0 18px ${cores.vermelho}aa`
                }}
              />
            </div>
          </div>

          <Nucleo tamanho={150} brilho={0.9} escala={escalaEntrada(entra, 0.86)} />
        </div>

        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 16,
            padding: '20px 34px',
            borderRadius: 999,
            backgroundColor: cores.superficie,
            border: `1px solid ${cores.borda}`,
            opacity: faixa(frame, 34, 46, 0, 1)
          }}
        >
          <div style={{ width: 40, height: 40, color: cores.verde }} dangerouslySetInnerHTML={{ __html: icones['escudo-check'] }} />
          <span style={{ fontFamily: fontes.corpo, fontSize: 30, color: cores.textoFraco }}>
            {dados.selo || 'Regulado pelo Banco Central'}
          </span>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Só leitura.|Nada se move.'} destaque={dados.destaque || ['Só leitura.']} tamanho={dados.tamanho || 72} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

export const CENAS_OPENFINANCE = {
  tempoPerdido: CenaTempoPerdido,
  constanciaQuebra: CenaConstanciaQuebra,
  autorizaUmaVez: CenaAutorizaUmaVez,
  sincronizaSozinho: CenaSincronizaSozinho,
  todosOsBancos: CenaTodosOsBancos,
  chegaCategorizado: CenaChegaCategorizado,
  campoIntocado: CenaCampoIntocado,
  leituraApenas: CenaLeituraApenas
}
