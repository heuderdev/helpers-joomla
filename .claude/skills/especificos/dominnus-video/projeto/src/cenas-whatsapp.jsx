import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring } from 'remotion'
import { cores, fontes, seguro } from './marca.js'
import { dur, ms, escalaEntrada, movingHold } from './motion.js'
import { molas } from './movimento.jsx'
import { Palco, Legenda, Assinatura, faixa } from './cenas.jsx'
import icones from './icones.json'
import marcas from './marcas.json'

/*
 * CENAS DO VÍDEO "WHATSAPP".
 *
 * O tema é ATRITO ZERO: a interface some e sobra a conversa. Por isso a forma
 * visual é sempre uma BOLHA DE MENSAGEM — nunca um card de dashboard. Cada
 * beat mostra uma modalidade de entrada e o que ela vira do outro lado.
 *
 *   "formulário pra preencher"  → campos vazios empilhando, pedindo dado
 *   "desiste no terceiro"       → os campos apagando de baixo pra cima
 *   "escreve como fala"         → bolha enviada virando lançamento
 *   "manda um áudio"            → onda de voz se transcrevendo em texto
 *   "só a foto"                 → comprovante com o valor sendo lido
 *   "dá pra perguntar"          → bolha de pergunta e o número respondendo
 *   "de onde saiu"              → as marcas somando dentro da resposta
 *   "confirmar antes"           → a ação parada, esperando o sim
 *
 * A abertura já tinha `whatsappDigita` e `whatsappModos` — duas cenas para uma
 * frase. Aqui NENHUMA delas é reaproveitada: o tema é o vídeo inteiro, então a
 * mesma ideia precisa de forma nova e mais específica.
 */

/* A coluna da conversa é compartilhada entre os beats 04→09: as bolhas nascem
   sempre na mesma margem e sobem na mesma coluna, e é isso que faz parecer um
   único diálogo cortado, não seis telas diferentes. */
const MARGEM = 118
const LARGURA_BOLHA = 844
const VERDE_ZAP = '#25D366'

/* Coluna dos logos: largura fixa e gap fixo. A barra de valor usa a soma dos
   dois como recuo, então ela nasce alinhada ao nome — não a um chute. */
const LOGO = 74
const GAP_LOGO = 22

/** Bolha de mensagem. `minha` = enviada (direita, azul da marca). */
function Bolha({ children, minha = false, largura = LARGURA_BOLHA, style, p = 1 }) {
  return (
    <div
      style={{
        alignSelf: minha ? 'flex-end' : 'flex-start',
        maxWidth: largura,
        padding: '30px 38px',
        borderRadius: 40,
        borderBottomRightRadius: minha ? 12 : 40,
        borderBottomLeftRadius: minha ? 40 : 12,
        backgroundColor: minha ? cores.azul : cores.superficie,
        border: minha ? 'none' : `1px solid ${cores.borda}`,
        fontFamily: fontes.corpo,
        fontSize: 40,
        color: cores.texto,
        opacity: p,
        transform: `translateY(${(1 - p) * 26}px) scale(${escalaEntrada(p, 0.94)})`,
        boxShadow: minha ? `0 0 50px ${cores.azul}44` : 'none',
        ...style
      }}
    >
      {children}
    </div>
  )
}

/** Coluna onde as bolhas vivem. Mesma geometria em todos os beats do diálogo. */
function Conversa({ children, gap = 26 }) {
  return (
    <AbsoluteFill
      style={{
        paddingTop: seguro.topo + 250,
        paddingBottom: seguro.base - 40,
        paddingLeft: MARGEM,
        paddingRight: MARGEM,
        display: 'flex',
        flexDirection: 'column',
        justifyContent: 'center',
        gap
      }}
    >
      {children}
    </AbsoluteFill>
  )
}

/* --------------------------------------------------- 01 · formulário cansa */

/**
 * Os campos vazios empilhando.
 *
 * A forma do atrito é o campo em branco pedindo dado. Eles entram um a um, em
 * cascata, e o cursor pisca no primeiro: quanto mais entram, mais pesado fica —
 * é a sensação que a frase descreve.
 */
export function CenaFormularioCansa({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const campos = dados.campos || ['Valor', 'Categoria', 'Data', 'Conta', 'Descrição']

  return (
    <Palco glow={0.4} cor={cores.cinza}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 240, paddingBottom: 120 }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 22, width: 820 }}>
          {campos.map((campo, i) => {
            const p = faixa(frame, 4 + i * 6, 16 + i * 6, 0, 1)
            const piscando = i === 0 && frame > 20 && Math.floor(frame / 12) % 2 === 0
            return (
              <div
                key={campo}
                style={{
                  height: 104,
                  borderRadius: 20,
                  backgroundColor: cores.superficie,
                  border: `1px solid ${piscando ? cores.cinza : cores.borda}`,
                  display: 'flex',
                  alignItems: 'center',
                  padding: '0 34px',
                  gap: 14,
                  opacity: p * (1 - i * 0.1),
                  transform: `translateY(${(1 - p) * 30}px)`
                }}
              >
                <span style={{ fontFamily: fontes.corpoLeve, fontSize: 36, color: cores.cinza }}>{campo}</span>
                {piscando && <div style={{ width: 3, height: 44, backgroundColor: cores.cinza }} />}
              </div>
            )
          })}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Um formulário|pra preencher.'} destaque={dados.destaque || ['formulário']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ------------------------------------------------------- 02 · desistência */

/**
 * Os campos apagando de baixo para cima.
 *
 * Continuidade com o beat 01: a MESMA pilha de campos, na mesma posição. Só que
 * agora eles se apagam e o contador para em três — o ponto exato onde a
 * narração diz que a pessoa desistiu. O gesto é de abandono, não de erro.
 */
export function CenaDesistencia({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const campos = dados.campos || ['Valor', 'Categoria', 'Data', 'Conta', 'Descrição']
  const ate = dados.ate ?? 3

  return (
    <Palco glow={0.35} cor={cores.cinza}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 240, paddingBottom: 120 }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 22, width: 820, position: 'relative' }}>
          {campos.map((campo, i) => {
            /* preenchidos até `ate`; os demais somem, de baixo pra cima */
            const preenchido = i < ate
            const morte = faixa(frame, 10 + (campos.length - 1 - i) * 5, 24 + (campos.length - 1 - i) * 5, 1, 0)
            const opacidade = preenchido ? 1 : morte
            return (
              <div
                key={campo}
                style={{
                  height: 104,
                  borderRadius: 20,
                  backgroundColor: cores.superficie,
                  border: `1px solid ${cores.borda}`,
                  display: 'flex',
                  alignItems: 'center',
                  padding: '0 34px',
                  opacity: opacidade,
                  transform: `translateX(${(1 - opacidade) * 40}px)`,
                  filter: preenchido ? 'none' : 'grayscale(1)'
                }}
              >
                <span style={{ fontFamily: fontes.corpoLeve, fontSize: 36, color: preenchido ? cores.texto : cores.cinza }}>
                  {campo}
                </span>
              </div>
            )
          })}

          {/* o contador de desistência */}
          <div
            style={{
              position: 'absolute',
              right: -30,
              top: ate * 126 - 40,
              fontFamily: fontes.titulo,
              fontSize: 96,
              color: cores.vermelho,
              opacity: faixa(frame, 26, 38, 0, 1),
              transform: `scale(${escalaEntrada(faixa(frame, 26, 38, 0, 1), 0.7)})`,
              textShadow: `0 0 50px ${cores.vermelho}66`
            }}
          >
            {ate}
          </div>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'E você desiste|no terceiro campo.'} destaque={dados.destaque || ['desiste']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ----------------------------------------------------- 04 · conversa solta */

/**
 * A mensagem escrita virando lançamento.
 *
 * A bolha sai da direita como qualquer mensagem, e a resposta do app NASCE dela
 * — não aparece ao lado. É a tradução visual de "escreveu, virou despesa".
 */
export function CenaConversaSolta({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const pMinha = faixa(frame, 2, 14, 0, 1)
  const nasce = spring({ frame: frame - 20, fps, config: molas.suave })
  const cartao = dados.cartao || { titulo: 'Despesa registrada', linhas: [['Mercado', 'R$ 90,00']] }

  return (
    <Palco glow={0.8}>
      <Conversa>
        <Bolha minha p={pMinha}>{dados.mensagem || 'mercado 90'}</Bolha>

        {/* o cartão nasce da bolha: escala a partir do topo direito */}
        <div
          style={{
            alignSelf: 'flex-start',
            width: LARGURA_BOLHA,
            padding: 38,
            borderRadius: 40,
            borderBottomLeftRadius: 12,
            backgroundColor: cores.superficie,
            border: `1px solid ${cores.azul}55`,
            opacity: nasce,
            transformOrigin: 'top right',
            transform: `scale(${0.82 + nasce * 0.18}) translateY(${(1 - nasce) * -20}px)`,
            boxShadow: `0 0 60px ${cores.azul}22`
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 16, marginBottom: 24 }}>
            <div style={{ width: 44, height: 44, color: cores.verde }} dangerouslySetInnerHTML={{ __html: icones['check-circulo'] }} />
            <span style={{ fontFamily: fontes.corpo, fontSize: 34, color: cores.verde }}>{cartao.titulo}</span>
          </div>
          {(cartao.linhas || []).map(([rot, val], i) => (
            <div
              key={rot}
              style={{
                display: 'flex',
                justifyContent: 'space-between',
                alignItems: 'baseline',
                paddingTop: i ? 18 : 0,
                opacity: faixa(frame, 28 + i * 5, 38 + i * 5, 0, 1)
              }}
            >
              <span style={{ fontFamily: fontes.corpoLeve, fontSize: 36, color: cores.textoFraco }}>{rot}</span>
              <span style={{ fontFamily: fontes.titulo, fontSize: i ? 34 : 48, color: i ? cores.textoFraco : cores.texto }}>{val}</span>
            </div>
          ))}
        </div>
      </Conversa>

      <Legenda texto={dados.titulo || 'Escreve|como você fala.'} destaque={dados.destaque || ['como você fala.']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* -------------------------------------------------------- 05 · áudio vira */

/**
 * A onda de voz virando texto.
 *
 * As barras da onda existem primeiro; depois o texto transcrito revela por
 * baixo, palavra a palavra, no ritmo da própria onda. O áudio não "vira card":
 * ele vira LETRA — que é o que o produto faz de fato.
 */
export function CenaAudioVira({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const texto = dados.transcrito || 'paguei 42 no uber agora'
  const palavras = texto.split(' ')

  /* a onda "toca": cada barra tem fase própria, e a cabeça de leitura corre */
  const cabeca = faixa(frame, 6, 46, 0, 1)

  return (
    <Palco glow={0.8}>
      <Conversa gap={20}>
        <Bolha minha p={faixa(frame, 2, 12, 0, 1)} style={{ display: 'flex', alignItems: 'center', gap: 22, padding: '34px 40px' }}>
          <div style={{ width: 46, height: 46, color: cores.texto, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: icones['microfone'] }} />
          <div style={{ display: 'flex', alignItems: 'center', gap: 6, height: 60 }}>
            {Array.from({ length: 26 }).map((_, i) => {
              const lida = i / 26 < cabeca
              const altura = 14 + Math.abs(Math.sin(i * 1.7)) * 42
              return (
                <div
                  key={i}
                  style={{
                    width: 6,
                    height: altura * (lida ? 1 : 0.55),
                    borderRadius: 3,
                    backgroundColor: lida ? cores.texto : '#FFFFFF66',
                    transition: 'none'
                  }}
                />
              )
            })}
          </div>
          <span style={{ fontFamily: fontes.corpoLeve, fontSize: 30, color: '#FFFFFFAA', flexShrink: 0 }}>0:04</span>
        </Bolha>

        {/* a transcrição saindo palavra a palavra */}
        <div
          style={{
            alignSelf: 'flex-end',
            maxWidth: LARGURA_BOLHA,
            display: 'flex',
            flexWrap: 'wrap',
            gap: '0 14px',
            justifyContent: 'flex-end',
            paddingRight: 12,
            opacity: faixa(frame, 18, 28, 0, 1)
          }}
        >
          {palavras.map((palavra, i) => (
            <span
              key={i}
              style={{
                fontFamily: fontes.corpoLeve,
                fontSize: 40,
                color: cores.azulSuave,
                opacity: faixa(frame, 22 + i * 4, 30 + i * 4, 0, 1),
                transform: `translateY(${(1 - faixa(frame, 22 + i * 4, 30 + i * 4, 0, 1)) * 10}px)`
              }}
            >
              {palavra}
            </span>
          ))}
        </div>
      </Conversa>

      <Legenda texto={dados.titulo || 'Ou manda|um áudio.'} destaque={dados.destaque || ['um áudio.']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* --------------------------------------------------------- 06 · foto lida */

/**
 * O comprovante com o valor sendo lido.
 *
 * Um recibo entra inclinado, e uma faixa de leitura desce por ele. Quando a
 * faixa passa pelo valor, o valor ACENDE e sobe para fora do papel. É o gesto
 * de "ele lê o valor sozinho" sem precisar de um rótulo dizendo OCR.
 */
export function CenaFotoLida({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const entra = spring({ frame: frame - 3, fps, config: molas.suave })
  const varre = faixa(frame, 14, 40, 0, 1)
  const achou = faixa(frame, 36, 50, 0, 1)

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 300, paddingBottom: 120 }}>
        <div style={{ position: 'relative', ...movingHold(1, 2, 120) }}>
          {/* o papel */}
          <div
            style={{
              width: 520,
              height: 620,
              borderRadius: 18,
              backgroundColor: '#F4F6FB',
              padding: '46px 40px',
              opacity: entra,
              transform: `rotate(${-4 + (1 - entra) * 6}deg) scale(${escalaEntrada(entra, 0.88)})`,
              boxShadow: '0 40px 90px #00000066',
              overflow: 'hidden',
              position: 'relative'
            }}
          >
            <div style={{ fontFamily: fontes.titulo, fontSize: 34, color: '#0A0E1C' }}>
              {dados.estabelecimento || 'Posto Ipiranga'}
            </div>
            <div style={{ fontFamily: fontes.corpoLeve, fontSize: 24, color: '#556680', marginTop: 8 }}>
              CUPOM FISCAL
            </div>

            {/* linhas de texto fingidas */}
            <div style={{ marginTop: 40, display: 'flex', flexDirection: 'column', gap: 16 }}>
              {[0.9, 0.72, 0.84, 0.6].map((w, i) => (
                <div key={i} style={{ height: 12, width: `${w * 100}%`, borderRadius: 6, backgroundColor: '#E4E8F2' }} />
              ))}
            </div>

            {/* o valor no papel — apaga quando "sai" dele */}
            <div
              style={{
                position: 'absolute',
                left: 40,
                bottom: 64,
                fontFamily: fontes.titulo,
                fontSize: 62,
                color: '#0A0E1C',
                opacity: 1 - achou
              }}
            >
              {dados.valor || 'R$ 128,40'}
            </div>

            {/* faixa de leitura */}
            <div
              style={{
                position: 'absolute',
                left: 0,
                right: 0,
                top: varre * 620 - 60,
                height: 120,
                background: `linear-gradient(180deg, transparent, ${cores.azul}55, transparent)`,
                opacity: varre > 0 && varre < 1 ? 1 : 0
              }}
            />
          </div>

          {/* o valor extraído, flutuando acima do papel */}
          <div
            style={{
              position: 'absolute',
              left: '50%',
              top: -30,
              transform: `translateX(-50%) translateY(${(1 - achou) * 60}px) scale(${escalaEntrada(achou, 0.8)})`,
              opacity: achou,
              padding: '22px 44px',
              borderRadius: 26,
              backgroundColor: cores.azul,
              fontFamily: fontes.titulo,
              fontSize: 64,
              color: cores.texto,
              whiteSpace: 'nowrap',
              boxShadow: `0 0 70px ${cores.azul}88`
            }}
          >
            {dados.valor || 'R$ 128,40'}
          </div>
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Ou só a foto|do comprovante.'} destaque={dados.destaque || ['a foto']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* ----------------------------------------------------- 07 · pergunta chat */

/**
 * A pergunta e o número que responde.
 *
 * Volta para a coluna da conversa (continuidade com 04): a bolha da pergunta
 * sobe e a resposta chega como NÚMERO grande, não como frase. O que a pessoa
 * quer é o valor; a frase seria ruído.
 */
export function CenaPerguntaChat({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const responde = spring({ frame: frame - 22, fps, config: molas.suave })

  return (
    <Palco glow={0.8}>
      <Conversa>
        <Bolha minha p={faixa(frame, 2, 14, 0, 1)}>{dados.pergunta || 'quanto gastei com comida esse mês?'}</Bolha>

        {/* "digitando" some quando a resposta chega */}
        <div
          style={{
            alignSelf: 'flex-start',
            display: 'flex',
            gap: 12,
            padding: '26px 34px',
            borderRadius: 40,
            borderBottomLeftRadius: 12,
            backgroundColor: cores.superficie,
            border: `1px solid ${cores.borda}`,
            opacity: faixa(frame, 14, 20, 0, 1) * (1 - responde)
          }}
        >
          {[0, 1, 2].map((i) => (
            <div
              key={i}
              style={{
                width: 16,
                height: 16,
                borderRadius: 8,
                backgroundColor: cores.cinza,
                opacity: 0.4 + Math.abs(Math.sin((frame - i * 4) / 7)) * 0.6
              }}
            />
          ))}
        </div>

        <div
          style={{
            alignSelf: 'flex-start',
            maxWidth: LARGURA_BOLHA,
            padding: '36px 44px',
            borderRadius: 40,
            borderBottomLeftRadius: 12,
            backgroundColor: cores.superficie,
            border: `1px solid ${cores.azul}55`,
            opacity: responde,
            transform: `translateY(${(1 - responde) * 24}px)`,
            boxShadow: `0 0 60px ${cores.azul}22`
          }}
        >
          <div style={{ fontFamily: fontes.titulo, fontSize: 92, color: cores.texto, letterSpacing: -3 }}>
            {dados.resposta || 'R$ 847,30'}
          </div>
          <div style={{ fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco, marginTop: 10, opacity: faixa(frame, 34, 44, 0, 1) }}>
            {dados.detalhe || '23 lançamentos · maior: iFood'}
          </div>
        </div>
      </Conversa>

      <Legenda texto={dados.titulo || 'E dá pra|perguntar.'} destaque={dados.destaque || ['perguntar.']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* -------------------------------------------------- 08 · resposta detalha */

/**
 * De onde o número saiu.
 *
 * Continuidade com 07: o total continua no topo, e agora as marcas entram por
 * baixo somando até ele. As barras crescem proporcionalmente ao valor — a
 * resposta se abre, não é substituída.
 */
export function CenaRespostaDetalha({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const itens = dados.itens || [
    ['ifood', 'iFood', 'R$ 312,00'],
    ['mcdonalds', "McDonald's", 'R$ 96,50'],
    ['rappi', 'Rappi', 'R$ 128,80']
  ]

  const valores = itens.map(([, , v]) => parseFloat(v.replace(/[^\d,]/g, '').replace(',', '.')) || 0)
  const maior = Math.max(...valores, 1)

  return (
    <Palco glow={0.8}>
      <AbsoluteFill style={{ paddingTop: seguro.topo + 240, paddingBottom: seguro.base - 20, paddingLeft: MARGEM, paddingRight: MARGEM, justifyContent: 'center' }}>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 28 }}>
          {itens.map(([chave, nome, valor], i) => {
            const p = faixa(frame, 4 + i * 7, 20 + i * 7, 0, 1)
            const largura = (valores[i] / maior) * 100
            return (
              <div key={chave} style={{ opacity: p, transform: `translateX(${(1 - p) * -40}px)` }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: GAP_LOGO, marginBottom: 14 }}>
                  {/* O SVG da marca já vem com fundo e raio próprios; a caixa só
                      fixa o tamanho. `flexShrink: 0` + largura fixa é o que trava
                      a coluna — sem isso o SVG define a própria largura e empurra
                      o nome, desalinhando as três linhas entre si. */}
                  <div style={{ width: LOGO, height: LOGO, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: marcas[chave]?.svg || icones['carrinho-compras'] }} />
                  <span style={{ fontFamily: fontes.corpo, fontSize: 40, color: cores.texto, flex: 1 }}>{nome}</span>
                  <span style={{ fontFamily: fontes.titulo, fontSize: 44, color: cores.texto }}>{valor}</span>
                </div>
                {/* a barra começa exatamente onde o NOME começa */}
                <div style={{ height: 10, borderRadius: 5, backgroundColor: cores.superficieAlta, marginLeft: LOGO + GAP_LOGO, overflow: 'hidden' }}>
                  <div
                    style={{
                      height: '100%',
                      width: `${largura * faixa(frame, 12 + i * 7, 34 + i * 7, 0, 1)}%`,
                      borderRadius: 5,
                      backgroundColor: cores.azul,
                      boxShadow: `0 0 24px ${cores.azul}88`
                    }}
                  />
                </div>
              </div>
            )
          })}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Responde|na hora.'} destaque={dados.destaque || ['na hora.']} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* --------------------------------------------------- 09 · confirma antes */

/**
 * A ação parada, esperando o sim.
 *
 * O ponto de confiança do vídeo. A ação destrutiva aparece PRONTA mas inerte,
 * e os dois botões pulsam de leve — nada avança sozinho. O beat termina sem
 * ninguém apertar: a promessa é o controle, não o resultado.
 */
export function CenaConfirmaAntes({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const entra = spring({ frame: frame - 4, fps, config: molas.suave })

  return (
    <Palco glow={0.7}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 280, paddingBottom: 120 }}>
        <div
          style={{
            width: 820,
            padding: 48,
            borderRadius: 44,
            backgroundColor: cores.superficie,
            border: `1px solid ${cores.borda}`,
            opacity: entra,
            transform: `translateY(${(1 - entra) * 30}px) scale(${escalaEntrada(entra, 0.92)})`,
            boxShadow: '0 30px 80px #00000055'
          }}
        >
          <div style={{ display: 'flex', alignItems: 'center', gap: 18, marginBottom: 30 }}>
            <div style={{ width: 52, height: 52, color: cores.laranja }} dangerouslySetInnerHTML={{ __html: icones['atencao'] }} />
            <span style={{ fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco }}>Confirmação necessária</span>
          </div>

          <div style={{ fontFamily: fontes.titulo, fontSize: 52, color: cores.texto, lineHeight: 1.16, letterSpacing: -1.5 }}>
            {dados.acao || 'Apagar despesa de R$ 90,00?'}
          </div>

          <div style={{ display: 'flex', gap: 20, marginTop: 44, opacity: faixa(frame, 20, 32, 0, 1) }}>
            {[
              { texto: 'Não', cor: cores.superficieAlta, fonte: cores.textoFraco },
              { texto: 'Sim, apagar', cor: cores.azul, fonte: cores.texto }
            ].map((b, i) => (
              <div
                key={b.texto}
                style={{
                  flex: 1,
                  height: 104,
                  borderRadius: 24,
                  backgroundColor: b.cor,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  fontFamily: fontes.corpo,
                  fontSize: 38,
                  color: b.fonte,
                  /* pulsa de leve: está VIVO e esperando, não travado */
                  transform: `scale(${1 + Math.sin((frame - i * 8) / 14) * 0.012})`
                }}
              >
                {b.texto}
              </div>
            ))}
          </div>
        </div>

        <div style={{ marginTop: 40, fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco, opacity: faixa(frame, 36, 48, 0, 1) }}>
          {dados.rotulo || 'ele espera você'}
        </div>
      </AbsoluteFill>

      <Legenda texto={dados.titulo || 'Nada entra|sem você confirmar.'} destaque={dados.destaque || ['confirmar.']} tamanho={dados.tamanho || 70} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

export const CENAS_WHATSAPP = {
  formularioCansa: CenaFormularioCansa,
  desistencia: CenaDesistencia,
  conversaSolta: CenaConversaSolta,
  audioVira: CenaAudioVira,
  fotoLida: CenaFotoLida,
  perguntaChat: CenaPerguntaChat,
  respostaDetalha: CenaRespostaDetalha,
  confirmaAntes: CenaConfirmaAntes
}
