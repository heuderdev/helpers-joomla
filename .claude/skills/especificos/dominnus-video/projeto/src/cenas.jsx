import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, interpolate, spring, Easing } from 'remotion'
import { cores, fontes, seguro } from './marca.js'
import { curvas, dur, ms, escalaEntrada, blurDeMovimento, movingHold } from './motion.js'
import { molas, TituloAnimado } from './movimento.jsx'
import icones from './icones.json'
import bancos from './bancos.json'
import marcas from './marcas.json'
import logo from './logo.json'
import * as A from './cenas-assinaturas.jsx'
import * as E from './cenas-economia.jsx'
import * as P from './cenas-padroes.jsx'
import * as C from './cenas-cartoes.jsx'
import * as R from './cenas-relatorios.jsx'
import * as X from './cenas-extras.jsx'
import * as W from './cenas-whatsapp.jsx'
import * as O from './cenas-openfinance.jsx'

/*
 * CENAS — beats curtos com continuidade líquida.
 *
 * Duas revisões antes disto:
 *   1ª: cards com stagger (herdado do Gestão Dev) — rejeitado, é template de slide
 *   2ª: cenas coreografadas, mas 8 planos para 63s = ~8s cada — ainda lento
 *
 * O problema da 2ª não era velocidade de animação (essa eu já tinha acelerado),
 * era ESTRUTURA: um plano de 8s fica parado 5-6s por mais que o conteúdo se
 * mexa. Motion de verdade troca de beat a cada 2-3s.
 *
 * Agora são 18 beats de ~2,7s. Uma frase da narração vira duas ou três cenas, e
 * o que liga uma na outra é CONTINUIDADE: o núcleo da marca fica na mesma
 * posição atravessando o corte, os bancos que chegaram em um beat permanecem no
 * seguinte, o extrato que se formou continua rolando. O corte deixa de ser
 * "acabou, começou outra" e vira movimento de câmera dentro de uma cena só.
 *
 * Por isso várias cenas compartilham helpers de layout (`ORBITA`, `Nucleo` na
 * mesma coordenada): a posição precisa BATER entre os beats, senão o elemento
 * salta no corte e a ilusão quebra.
 */

/* ---------------------------------------------------------------- utilitários */

export function faixa(frame, de, para, saidaDe, saidaPara, curva = curvas.padrao) {
  return interpolate(frame, [de, para], [saidaDe, saidaPara], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curva
  })
}

/* Coordenadas COMPARTILHADAS entre beats. Mudar aqui move em todas as cenas que
   participam da continuidade — que é justamente o ponto. */
const CX = 540
const CY = 1010
const RAIO = 330
const ORDEM_BANCOS = ['nubank', 'itau', 'inter', 'c6', 'bradesco', 'santander', 'picpay', 'btg']

const anguloDe = (i, total) => (i / total) * Math.PI * 2 - Math.PI / 2

/* Travamento: acelera e para seco. É a curva de uma peça mecânica encaixando,
   não de um objeto flutuando até a posição. */
const CURVA_TRAVA = Easing.bezier(0.7, 0, 0.2, 1)

/** Legenda curta, ancorada no topo. Em beat de 2,7s o texto precisa entrar já. */
export function Legenda({ texto, destaque, sub, atraso = 0, tamanho = 74 }) {
  const frame = useCurrentFrame()
  const p = faixa(frame - atraso, 0, dur.entrada, 0, 1, curvas.entrada)

  /*
   * Tudo centralizado num eixo só.
   *
   * A versão anterior alinhava o título à esquerda enquanto o palco animado era
   * centrado — dois eixos concorrentes no mesmo quadro. Em vídeo vertical, onde
   * o olho desce pelo meio, isso lê como desalinho.
   *
   * As quebras de linha vêm marcadas com `|` no roteiro, não do navegador:
   * quebra automática produz linha órfã e viúva de uma palavra só.
   */
  return (
    <div
      style={{
        position: 'absolute',
        top: seguro.topo,
        left: seguro.lateral,
        right: seguro.lateral,
        opacity: p,
        textAlign: 'center'
      }}
    >
      <div
        style={{
          fontFamily: fontes.titulo,
          fontSize: tamanho,
          fontWeight: 700,
          color: cores.texto,
          letterSpacing: -2.5,
          lineHeight: 1.08,
          transform: `translateY(${(1 - p) * 16}px)`,
          filter: blurDeMovimento(p, 6),
          textWrap: 'balance'
        }}
      >
        <TituloAnimado
          texto={texto}
          atraso={atraso}
          intervalo={0.9}
          cor={(palavra) => (destaque && destaque.includes(palavra.replace(/[.,?!“”"]/g, '')) ? cores.azulClaro : undefined)}
        />
      </div>
      {sub && (
        <div
          style={{
            marginTop: 18,
            fontFamily: fontes.corpoLeve,
            fontSize: 32,
            color: cores.textoFraco,
            opacity: faixa(frame - atraso - ms(120), 0, dur.entrada, 0, 1)
          }}
        >
          {sub}
        </div>
      )}
    </div>
  )
}

export function Assinatura({ atraso = 0 }) {
  const frame = useCurrentFrame()
  const p = faixa(frame - atraso, 0, dur.entrada, 0, 1)
  return (
    <div
      style={{
        position: 'absolute',
        bottom: seguro.base - 130,
        left: '50%',
        transform: `translateX(-50%)`,
        width: 210,
        height: 210 * (299.09 / 1079.68),
        opacity: p * 0.85
      }}
      dangerouslySetInnerHTML={{ __html: logo.principal }}
    />
  )
}

export function Nucleo({ tamanho = 190, brilho = 1, escala = 1 }) {
  return (
    <div
      style={{
        width: tamanho,
        height: tamanho,
        borderRadius: tamanho * 0.26,
        backgroundColor: cores.azul,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        transform: `scale(${escala})`,
        boxShadow: `0 0 ${70 * brilho}px ${cores.azul}aa, 0 0 ${190 * brilho}px ${cores.azul}44`
      }}
    >
      <div style={{ width: tamanho * 0.46, height: tamanho * 0.46, display: 'flex' }} dangerouslySetInnerHTML={{ __html: logo.simboloBranco }} />
    </div>
  )
}

/**
 * Palco com "gota" líquida — o fundo nunca fica chapado.
 *
 * Dois blobs de gradiente radial se movendo em fase diferente, com blur alto.
 * É o que dá a leitura de líquido pedida: superfície que respira e escorre em
 * vez de um degradê fixo.
 */
export function Palco({ children, glow = 1, cor }) {
  const frame = useCurrentFrame()
  const c = cor || cores.azul

  return (
    <AbsoluteFill style={{ backgroundColor: cores.fundo, overflow: 'hidden' }}>
      <div
        style={{
          position: 'absolute',
          width: 1500,
          height: 1500,
          left: -420 + Math.sin(frame / 44) * 90,
          top: -400 + Math.cos(frame / 52) * 70,
          background: `radial-gradient(circle, ${c}34 0%, ${c}12 42%, transparent 70%)`,
          opacity: glow,
          filter: 'blur(30px)'
        }}
      />
      <div
        style={{
          position: 'absolute',
          width: 1150,
          height: 1150,
          right: -380 + Math.cos(frame / 38) * 80,
          bottom: -320 + Math.sin(frame / 46) * 90,
          background: `radial-gradient(circle, ${c}22 0%, transparent 66%)`,
          opacity: glow * 0.9,
          filter: 'blur(40px)'
        }}
      />
      {children}
    </AbsoluteFill>
  )
}

/** Grade da planilha — compartilhada entre os beats 01 e 02. */
function Grade({ preenchimento = 1, colapso = 0, erroDe = 5 }) {
  const COLS = 4
  const LINHAS = 7
  return (
    <div
      style={{
        display: 'flex',
        flexDirection: 'column',
        gap: 12,
        transform: `perspective(1400px) rotateX(${14 + colapso * 20}deg) translateY(${colapso * 210}px)`,
        opacity: 1 - colapso * 0.8
      }}
    >
      {Array.from({ length: LINHAS }).map((_, l) => {
        const cai = colapso > 0 ? Math.min(1, Math.max(0, (colapso - l * 0.04) * 2.2)) : 0
        return (
          <div
            key={l}
            style={{
              display: 'flex',
              gap: 12,
              opacity: (1 - cai) * Math.min(1, Math.max(0, preenchimento * LINHAS - l)),
              transform: `translateY(${cai * 170}px) rotate(${cai * (l % 2 ? 4 : -4)}deg)`
            }}
          >
            {Array.from({ length: COLS }).map((_, c) => {
              const i = l * COLS + c
              const p = Math.min(1, Math.max(0, preenchimento * (LINHAS * COLS) - i))
              const erro = l >= erroDe
              return (
                <div
                  key={c}
                  style={{
                    width: c === 0 ? 250 : 145,
                    height: 60,
                    borderRadius: 10,
                    backgroundColor: erro ? `${cores.vermelho}14` : cores.superficie,
                    border: `1px solid ${erro ? `${cores.vermelho}55` : cores.borda}`,
                    display: 'flex',
                    alignItems: 'center',
                    padding: '0 16px'
                  }}
                >
                  <div style={{ height: 11, width: `${p * (c === 0 ? 78 : 60)}%`, borderRadius: 6, backgroundColor: erro ? `${cores.vermelho}88` : cores.superficieAlta }} />
                </div>
              )
            })}
          </div>
        )
      })}
    </div>
  )
}

/* ============================================================ BEATS 01 · 02 */

/** A grade se preenchendo, célula a célula. */
export function CenaPlanilha({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const preenche = faixa(frame, 2, duracao * 0.92, 0, 1, curvas.padrao)

  return (
    <Palco glow={0.45} cor={cores.azul}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 240 }}>
        <Grade preenchimento={preenche} />
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Você tentou|na planilha.'} destaque={dados.destaque || ['planilha.']} atraso={ms(60)} tamanho={dados.tamanho || 74} />
      <Assinatura atraso={ms(220)} />
    </Palco>
  )
}

/**
 * A grade colapsa. CONTINUIDADE: entra já preenchida, na mesma posição em que
 * o beat anterior a deixou — o corte não reinicia nada, só muda o que acontece.
 */
export function CenaPlanilhaColapso({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const colapso = faixa(frame, 4, duracao * 0.85, 0, 1, curvas.enfaseSaida)

  return (
    <Palco glow={0.4} cor={cores.vermelho}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 240 }}>
        <Grade preenchimento={1} colapso={colapso} />
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Durou três semanas.'} destaque={dados.destaque || ['três']} atraso={0} tamanho={dados.tamanho || 82} />
      <Assinatura atraso={ms(200)} />
    </Palco>
  )
}

/* ================================================================= BEAT 03 */

/**
 * O caos: marcas REAIS de gasto entrando em desordem, girando, caindo.
 *
 * Usa os ícones de marca com as cores oficiais (iFood vermelho, Netflix, Uber).
 * Reconhecer a marca é o que faz a cena doer — "ícone de carrinho" é abstrato,
 * o logo do iFood é a conta que chegou.
 */
export function CenaCaos({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const ITENS = dados.itens || [
    ['pix', -300, -170, -14],
    ['ifood', 190, -230, 11],
    ['netflix', -230, 40, 8],
    ['uber', 250, 60, -9],
    ['spotify', -120, 250, 13],
    ['mercadopago', 210, 290, -7]
  ]

  return (
    <Palco glow={0.5} cor={cores.vermelho}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 190 }}>
        <div style={{ position: 'relative', width: 900, height: 720 }}>
          {ITENS.map(([nome, dx, dy, rot], i) => {
            const m = marcas[nome]
            if (!m) return null
            const p = spring({ frame: frame - i * 2.4, fps, config: molas.pop })
            const tremor = Math.sin((frame + i * 9) / 5) * 4
            return (
              <div
                key={nome}
                style={{
                  position: 'absolute',
                  left: 450 + dx - 62,
                  top: 330 + dy - 62,
                  width: 124,
                  height: 124,
                  opacity: p,
                  transform: `scale(${escalaEntrada(p, 0.4)}) rotate(${rot * (1 - p) * 3 + tremor * 0.4}deg)`,
                  filter: `${blurDeMovimento(p, 14) === 'none' ? '' : blurDeMovimento(p, 14)} drop-shadow(0 14px 34px rgba(0,0,0,0.6))`.trim()
                }}
                dangerouslySetInnerHTML={{ __html: m.svg }}
              />
            )
          })}
        </div>
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'O pix que|ninguém anotou.'} destaque={dados.destaque || ['pix']} atraso={0} tamanho={dados.tamanho || 72} />
      <Assinatura atraso={ms(220)} />
    </Palco>
  )
}

/* ================================================================= BEAT 04 */

/** A conta que não fecha: um número vermelho descendo sem parar. */
export function CenaContaQuebrada({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const p = faixa(frame, 2, duracao * 0.8, 0, 1, curvas.padrao)
  const alvo = dados.valor ?? -2344.5
  const positivo = alvo > 0
  const valor = positivo ? alvo * p : -184.5 + (alvo + 184.5) * p
  const cor = positivo ? cores.verde : cores.vermelho

  return (
    <Palco glow={0.55} cor={cores.vermelho}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center' }}>
        <div
          style={{
            fontFamily: fontes.titulo,
            fontSize: 128,
            fontWeight: 700,
            color: cor,
            letterSpacing: -5,
            textShadow: `0 0 70px ${cor}66`,
            transform: `translateY(${movingHold(0, 6, 40)}px)`
          }}
        >
          {valor.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
        </div>
        <div
          style={{
            marginTop: 22,
            fontFamily: fontes.corpoLeve,
            fontSize: 36,
            color: cores.textoFraco,
            opacity: faixa(frame, 10, 10 + dur.entrada, 0, 1)
          }}
        >
          {dados.rotulo || 'a conta nunca fechava'}
        </div>
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'E nunca mais fechou.'} destaque={dados.destaque || ['nunca']} atraso={0} tamanho={dados.tamanho || 80} />
    </Palco>
  )
}

/* ============================================================ BEATS 05 · 06 */

/** A virada: o núcleo chega. Fica no CENTRO — posição que o beat 06 herda. */
export function CenaVirada({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const chegada = faixa(frame, 0, 14, 0, 1, curvas.entrada)

  return (
    <Palco glow={0.5 + chegada * 0.8}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center' }}>
        {[0, 1, 2].map((i) => {
          const p = faixa(frame, 8 + i * 5, 8 + i * 5 + 28, 0, 1, curvas.saida)
          if (p <= 0 || p >= 1) return null
          return (
            <div
              key={i}
              style={{
                position: 'absolute',
                width: 240 + p * 820,
                height: 240 + p * 820,
                borderRadius: '50%',
                border: `2px solid ${cores.azul}`,
                opacity: (1 - p) * 0.5
              }}
            />
          )
        })}
        <div style={{ filter: blurDeMovimento(chegada, 24), transform: `translateY(${movingHold(0, 5, 100)}px)` }}>
          <Nucleo tamanho={220} brilho={0.6 + chegada} escala={interpolate(chegada, [0, 1], [2.4, 1])} />
        </div>
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'O Dominnus|faz diferente.'} destaque={dados.destaque || ['diferente.']} atraso={ms(90)} tamanho={dados.tamanho || 78} />
    </Palco>
  )
}

/**
 * "Conecta uma vez": o núcleo CONTINUA onde estava e um único banco encosta
 * nele. É o beat que prepara a constelação — a órbita já começa a se insinuar.
 */
export function CenaConecta({ duracao }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const p = spring({ frame: frame - 3, fps, config: molas.suave })
  const linha = faixa(frame, 12, 26, 0, 1, curvas.movimento)
  const trava = faixa(frame, 26, 34, 0, 1, curvas.enfase)

  return (
    <Palco glow={0.9}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center' }}>
        <div style={{ position: 'relative', width: 700, height: 420, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          {/* linha ligando o banco ao núcleo */}
          <svg width="700" height="420" style={{ position: 'absolute' }}>
            <line x1="140" y1="210" x2={140 + 420 * linha} y2="210" stroke={cores.azul} strokeWidth="4" opacity={0.6} />
            {trava > 0 && <circle cx={560} cy={210} r={10 + trava * 6} fill={cores.azulClaro} opacity={1 - trava * 0.4} />}
          </svg>

          <div
            style={{
              position: 'absolute',
              left: 78,
              width: 124,
              height: 124,
              opacity: p,
              transform: `scale(${escalaEntrada(p, 0.5)})`,
              filter: 'drop-shadow(0 14px 34px rgba(0,0,0,0.55))'
            }}
            dangerouslySetInnerHTML={{ __html: bancos.nubank.svg }}
          />

          <div style={{ position: 'absolute', right: 60 }}>
            <Nucleo tamanho={190} brilho={0.8 + trava} escala={1 + trava * 0.05} />
          </div>
        </div>
      </AbsoluteFill>
      <Legenda texto="Conecte uma vez." destaque={['uma']} sub="e nunca mais anote na mão" atraso={0} tamanho={84} />
    </Palco>
  )
}

/* ============================================================ BEATS 07 · 08 */

/** Os bancos CHEGANDO à órbita — sem linhas ainda. */
export function CenaBancosChegam({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  return (
    <Palco glow={0.8}>
      <AbsoluteFill>
        <div style={{ position: 'absolute', left: CX - 95, top: CY - 95 }}>
          <Nucleo tamanho={190} brilho={1} escala={1 + Math.sin(frame / 13) * 0.02} />
        </div>

        {ORDEM_BANCOS.map((nome, i) => {
          const b = bancos[nome]
          if (!b) return null
          const ang = anguloDe(i, ORDEM_BANCOS.length)
          const p = spring({ frame: frame - (1 + i * 1.8), fps, config: molas.suave })
          const dist = interpolate(p, [0, 1], [1.9, 1])
          const x = CX + Math.cos(ang) * RAIO * dist
          const y = CY + Math.sin(ang) * RAIO * dist
          return (
            <div
              key={nome}
              style={{
                position: 'absolute',
                left: x - 60,
                top: y - 60 + movingHold(i, 5, 90),
                width: 120,
                height: 120,
                opacity: p,
                transform: `scale(${escalaEntrada(p, 0.55)})`,
                filter: `${blurDeMovimento(p, 12) === 'none' ? '' : blurDeMovimento(p, 12)} drop-shadow(0 12px 34px rgba(0,0,0,0.55))`.trim()
              }}
              dangerouslySetInnerHTML={{ __html: b.svg }}
            />
          )
        })}
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Nubank, Itaú, Inter…'} destaque={dados.destaque || ['Nubank,']} sub={dados.sub || 'Open Finance'} atraso={0} tamanho={dados.tamanho || 76} />
    </Palco>
  )
}

/**
 * As linhas se desenham e os pulsos viajam.
 * CONTINUIDADE: os bancos já estão na órbita, exatamente onde o beat 07 os
 * deixou — só as conexões são novas.
 */
export function CenaBancosConectam({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const conectados = ORDEM_BANCOS.filter((_, i) => frame > 2 + i * 2.2 + 9).length

  return (
    <Palco glow={0.9}>
      <AbsoluteFill>
        <svg width="1080" height="1920" style={{ position: 'absolute' }}>
          {ORDEM_BANCOS.map((nome, i) => {
            const ang = anguloDe(i, ORDEM_BANCOS.length)
            const x = CX + Math.cos(ang) * RAIO
            const y = CY + Math.sin(ang) * RAIO
            const inicio = 2 + i * 2.2
            const traco = faixa(frame, inicio, inicio + 9, 0, 1, curvas.movimento)
            if (traco <= 0) return null
            const t = ((frame - inicio - 9) / 17) % 1
            const px = x + (CX - x) * t
            const py = y + (CY - y) * t
            return (
              <g key={nome}>
                <line x1={x} y1={y} x2={x + (CX - x) * traco} y2={y + (CY - y) * traco} stroke={cores.azul} strokeWidth="3" opacity={0.5} />
                {frame > inicio + 9 && <circle cx={px} cy={py} r={8} fill={cores.azulClaro} opacity={(1 - t) * 0.95} />}
              </g>
            )
          })}
        </svg>

        <div style={{ position: 'absolute', left: CX - 95, top: CY - 95 }}>
          <Nucleo tamanho={190} brilho={0.8 + (conectados / ORDEM_BANCOS.length) * 1.6} escala={1 + Math.sin(frame / 11) * 0.025} />
        </div>

        {ORDEM_BANCOS.map((nome, i) => {
          const b = bancos[nome]
          if (!b) return null
          const ang = anguloDe(i, ORDEM_BANCOS.length)
          return (
            <div
              key={nome}
              style={{
                position: 'absolute',
                left: CX + Math.cos(ang) * RAIO - 60,
                top: CY + Math.sin(ang) * RAIO - 60 + movingHold(i, 5, 90),
                width: 120,
                height: 120,
                filter: 'drop-shadow(0 12px 34px rgba(0,0,0,0.55))'
              }}
              dangerouslySetInnerHTML={{ __html: b.svg }}
            />
          )
        })}
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Todos conversam|com ele.'} destaque={dados.destaque || ['Todos']} atraso={0} tamanho={dados.tamanho || 76} />
    </Palco>
  )
}

/* ============================================================ BEATS 09 · 10 */

/**
 * O fluxo: transações descendo em esteira contínua, com a marca real de cada
 * uma. O movimento é de ROLAGEM — não entra e para, escorre.
 */
export function CenaFluxo({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const LINHAS = dados.linhas || [
    ['ifood', 'iFood', '- R$ 64,90', false],
    ['uber', 'Uber', '- R$ 21,40', false],
    ['netflix', 'Netflix', '- R$ 39,90', false],
    ['spotify', 'Spotify', '- R$ 21,90', false],
    ['mercadopago', 'Salário', '+ R$ 6.400,00', true],
    ['ifood', 'iFood', '- R$ 52,30', false],
    ['uber', 'Uber', '- R$ 18,70', false]
  ]

  /* Rolagem contínua: o conjunto sobe sem parar e o resto se repete abaixo. */
  const desloca = (frame * 3.1) % (LINHAS.length * 112)

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', paddingTop: 520, overflow: 'hidden' }}>
        <div style={{ width: 840, height: 780, overflow: 'hidden', position: 'relative', maskImage: 'linear-gradient(180deg, transparent 0%, #000 12%, #000 84%, transparent 100%)' }}>
          <div style={{ position: 'absolute', top: -desloca, display: 'flex', flexDirection: 'column', gap: 16 }}>
            {[...LINHAS, ...LINHAS].map(([ic, nome, valor, receita], i) => {
              const m = marcas[ic]
              return (
                <div
                  key={i}
                  style={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 22,
                    padding: '24px 28px',
                    borderRadius: 22,
                    backgroundColor: cores.superficie,
                    border: `1px solid ${cores.borda}`,
                    width: 840,
                    boxSizing: 'border-box'
                  }}
                >
                  <div style={{ width: 66, height: 66, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: m.svg }} />
                  <span style={{ fontFamily: fontes.corpo, fontSize: 34, color: cores.texto, flex: 1 }}>{nome}</span>
                  <span style={{ fontFamily: fontes.corpo, fontSize: 32, color: receita ? cores.verde : cores.textoFraco }}>{valor}</span>
                </div>
              )
            })}
          </div>
        </div>
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Entram sozinhas.|Todo dia.'} destaque={dados.destaque || ['sozinhas.']} atraso={0} tamanho={dados.tamanho || 74} />
    </Palco>
  )
}

/**
 * A categorização: etiquetas caindo sobre as transações.
 * CONTINUIDADE com o beat 09 — mesmas marcas, agora recebendo categoria.
 */
export function CenaCategoriza({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const ITENS = dados.itens || [
    ['ifood', 'iFood', 'Alimentação'],
    ['uber', 'Uber', 'Transporte'],
    ['netflix', 'Netflix', 'Assinatura'],
    ['spotify', 'Spotify', 'Assinatura']
  ]

  return (
    <Palco glow={0.8}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 210 }}>
        <div style={{ width: 840, display: 'flex', flexDirection: 'column', gap: 18 }}>
          {ITENS.map(([ic, nome, cat], i) => {
            const p = spring({ frame: frame - i * 2.6, fps, config: molas.seco })
            const etiqueta = spring({ frame: frame - (7 + i * 2.6), fps, config: molas.pop })
            const m = marcas[ic]
            return (
              <div
                key={nome}
                style={{
                  display: 'flex',
                  alignItems: 'center',
                  gap: 22,
                  padding: '24px 28px',
                  borderRadius: 22,
                  backgroundColor: cores.superficie,
                  border: `1px solid ${etiqueta > 0.3 ? `${cores.azul}66` : cores.borda}`,
                  opacity: p,
                  transform: `translateX(${(1 - p) * -34}px)`
                }}
              >
                <div style={{ width: 62, height: 62, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: m.svg }} />
                <span style={{ fontFamily: fontes.corpo, fontSize: 34, color: cores.texto, flex: 1 }}>{nome}</span>
                <div
                  style={{
                    padding: '12px 24px',
                    borderRadius: 999,
                    backgroundColor: `${cores.azul}26`,
                    border: `1px solid ${cores.azul}66`,
                    opacity: etiqueta,
                    transform: `scale(${escalaEntrada(etiqueta, 0.7)})`
                  }}
                >
                  <span style={{ fontFamily: fontes.corpo, fontSize: 26, color: cores.azulClaro }}>{cat}</span>
                </div>
              </div>
            )
          })}
        </div>
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Já na categoria certa.'} destaque={dados.destaque || ['certa.']} sub={dados.sub || 'categorizado pela IA'} atraso={0} tamanho={dados.tamanho || 76} />
    </Palco>
  )
}

/* ============================================================ BEATS 11 · 12 */

/** A bolha digitando e a mensagem saindo. */
export function CenaWhatsappDigita({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const digitando = frame > 3 && frame < 20
  const enviada = faixa(frame, 20, 20 + dur.entrada, 0, 1, curvas.entrada)
  const nasce = spring({ frame: frame - 32, fps, config: molas.suave })

  return (
    <Palco glow={0.7} cor="#25D366">
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 170, gap: 30 }}>
        <div style={{ width: 780, display: 'flex', justifyContent: 'flex-end' }}>
          <div
            style={{
              padding: '28px 38px',
              borderRadius: 34,
              borderBottomRightRadius: 8,
              backgroundColor: '#12351F',
              border: '1px solid #1F7A45',
              opacity: digitando ? 1 : enviada,
              transform: `scale(${digitando ? 1 : escalaEntrada(enviada, 0.9)})`
            }}
          >
            {digitando ? (
              <div style={{ display: 'flex', gap: 12, alignItems: 'center', height: 42 }}>
                {[0, 1, 2].map((i) => (
                  <div key={i} style={{ width: 14, height: 14, borderRadius: '50%', backgroundColor: '#5BE58A', opacity: 0.4 + Math.abs(Math.sin((frame - i * 3) / 4)) * 0.6 }} />
                ))}
              </div>
            ) : (
              <span style={{ fontFamily: fontes.corpo, fontSize: 40, color: cores.texto }}>gastei 50 no mercado</span>
            )}
          </div>
        </div>

        <div style={{ width: 780, opacity: nasce, transform: `translateY(${(1 - nasce) * -36}px) scale(${escalaEntrada(nasce, 0.9)})` }}>
          <div style={{ backgroundColor: cores.superficie, border: `1px solid ${cores.azul}66`, borderRadius: 28, padding: '30px 34px', display: 'flex', alignItems: 'center', gap: 24, boxShadow: `0 0 60px ${cores.azul}2a` }}>
            <div style={{ width: 74, height: 74, borderRadius: 20, backgroundColor: `${cores.azul}22`, color: cores.azulClaro, padding: 18, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: icones['carrinho-compras'] }} />
            <div style={{ flex: 1 }}>
              <div style={{ fontFamily: fontes.corpo, fontSize: 38, color: cores.texto }}>Mercado</div>
              <div style={{ fontFamily: fontes.corpoLeve, fontSize: 26, color: cores.textoFraco }}>Alimentação</div>
            </div>
            <div style={{ fontFamily: fontes.titulo, fontSize: 44, color: cores.texto, letterSpacing: -1.5 }}>R$ 50</div>
          </div>
        </div>
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Manda no WhatsApp.'} destaque={dados.destaque || ['WhatsApp.']} atraso={0} tamanho={dados.tamanho || 80} />
    </Palco>
  )
}

/** Os três modos, cada um com seu ícone entrando em sequência rápida. */
export function CenaWhatsappModos({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const MODOS = dados.modos || [
    ['whatsapp', 'Escreve', '“gastei 50 no mercado”'],
    ['microfone', 'Fala', 'ele transcreve o áudio'],
    ['camera', 'Fotografa', 'lê o comprovante']
  ]

  return (
    <Palco glow={0.7} cor="#25D366">
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 150, paddingBottom: 100, gap: 34 }}>
        {MODOS.map(([ic, titulo, sub], i) => {
          const p = spring({ frame: frame - i * 3.4, fps, config: molas.pop })
          const svg = marcas[ic] ? marcas[ic].svg : icones[ic]
          const ehMarca = !!marcas[ic]
          return (
            <div
              key={ic}
              style={{
                width: 880,
                display: 'flex',
                alignItems: 'center',
                gap: 32,
                padding: '42px 40px',
                borderRadius: 28,
                backgroundColor: cores.superficie,
                border: `1px solid ${cores.borda}`,
                opacity: p,
                transform: `translateX(${(1 - p) * (i % 2 ? 60 : -60)}px) scale(${escalaEntrada(p, 0.92)})`
              }}
            >
              <div
                style={{
                  width: 92,
                  height: 92,
                  flexShrink: 0,
                  borderRadius: ehMarca ? 0 : 20,
                  backgroundColor: ehMarca ? 'transparent' : `${cores.azul}22`,
                  color: cores.azulClaro,
                  padding: ehMarca ? 0 : 20
                }}
                dangerouslySetInnerHTML={{ __html: svg }}
              />
              <div>
                <div style={{ fontFamily: fontes.corpo, fontSize: 44, color: cores.texto }}>{titulo}</div>
                <div style={{ fontFamily: fontes.corpoLeve, fontSize: 30, color: cores.textoFraco, marginTop: 4 }}>{sub}</div>
              </div>
            </div>
          )
        })}
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Texto, áudio|ou foto.'} destaque={dados.destaque || ['foto.']} atraso={0} tamanho={dados.tamanho || 78} />
    </Palco>
  )
}

/* ================================================================= BEAT 13 */

/** Arquivos sendo sugados por um funil e saindo como extrato. */
export function CenaImportacao({ duracao }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()

  const ARQUIVOS = [
    ['arquivo-pdf', -250],
    ['tabela-dados', -84],
    ['arquivo-excel', 84],
    ['imagem', 250]
  ]
  const LINHAS = [['Mercado', '- R$ 212,35', false], ['Salário', '+ R$ 6.400,00', true], ['Uber', '- R$ 21,40', false]]

  return (
    <Palco glow={0.7}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 200, paddingBottom: 120 }}>
        <div style={{ position: 'relative', width: 900, height: 180 }}>
          {ARQUIVOS.map(([ic, dx], i) => {
            const inicio = 1 + i * 2.2
            const p = faixa(frame, inicio, inicio + 15, 0, 1, curvas.movimento)
            const some = faixa(frame, inicio + 11, inicio + 16, 1, 0)
            return (
              <div
                key={ic}
                style={{
                  position: 'absolute',
                  left: 450 + dx * (1 - p * 0.85) - 46,
                  top: p * 120,
                  width: 92,
                  height: 92,
                  opacity: faixa(frame, inicio, inicio + 4, 0, 1) * some,
                  transform: `scale(${interpolate(p, [0, 1], [1, 0.55])}) rotate(${(1 - p) * (i % 2 ? 10 : -10)}deg)`,
                  borderRadius: 22,
                  backgroundColor: cores.superficie,
                  border: `1px solid ${cores.borda}`,
                  color: cores.azulClaro,
                  padding: 22
                }}
                dangerouslySetInnerHTML={{ __html: icones[ic] || icones['documento'] }}
              />
            )
          })}
        </div>

        <div style={{ width: 300, height: 6, borderRadius: 4, background: `linear-gradient(90deg, transparent, ${cores.azul}, transparent)`, boxShadow: `0 0 40px ${cores.azul}`, transform: `scaleX(${1 + Math.sin(frame / 6) * 0.08})` }} />

        <div style={{ width: 820, marginTop: 44, display: 'flex', flexDirection: 'column', gap: 14 }}>
          {LINHAS.map(([nome, valor, receita], i) => {
            const p = spring({ frame: frame - (18 + i * 2.6), fps, config: molas.seco })
            return (
              <div key={nome} style={{ display: 'flex', justifyContent: 'space-between', padding: '24px 30px', borderRadius: 20, backgroundColor: cores.superficie, border: `1px solid ${cores.borda}`, opacity: p, transform: `translateY(${(1 - p) * 22}px)` }}>
                <span style={{ fontFamily: fontes.corpo, fontSize: 32, color: cores.texto }}>{nome}</span>
                <span style={{ fontFamily: fontes.corpo, fontSize: 32, color: receita ? cores.verde : cores.textoFraco }}>{valor}</span>
              </div>
            )
          })}
        </div>
      </AbsoluteFill>
      <Legenda texto="Traga o histórico." destaque={['histórico.']} sub="PDF, planilha e até print" atraso={0} tamanho={78} />
    </Palco>
  )
}

/* ============================================================ BEATS 14 · 15 */

/** A pergunta + o anel processando. Beat curto de propósito. */
export function CenaPergunta({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const p = faixa(frame, 1, 1 + dur.entrada, 0, 1, curvas.entrada)
  const pergunta = dados.pergunta || 'Onde meu dinheiro está vazando?'
  const titulo = dados.titulo || 'Pergunte.' 

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', gap: 50 }}>
        <div style={{ padding: '30px 46px', borderRadius: 999, backgroundColor: cores.superficie, border: `1px solid ${cores.borda}`, opacity: p, transform: `translateY(${(1 - p) * 18}px) scale(${escalaEntrada(p, 0.92)})` }}>
          <span style={{ fontFamily: fontes.corpo, fontSize: 40, color: cores.texto }}>{`“${pergunta}”`}</span>
        </div>

        <svg width="110" height="110" style={{ transform: `rotate(${frame * 11}deg)`, opacity: faixa(frame, 8, 16, 0, 1) }}>
          <circle cx="55" cy="55" r="46" fill="none" stroke={`${cores.azul}33`} strokeWidth="7" />
          <circle cx="55" cy="55" r="46" fill="none" stroke={cores.azulClaro} strokeWidth="7" strokeDasharray="72 220" strokeLinecap="round" />
        </svg>
      </AbsoluteFill>
      <Legenda texto={titulo} destaque={dados.destaque || ['Pergunte.']} atraso={0} tamanho={dados.tamanho || 86} />
    </Palco>
  )
}

/** A resposta: barras crescendo com os valores contando. */
export function CenaResposta({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const CATEGORIAS = dados.barras || [
    ['Alimentação', 1284.4, 1, true],
    ['Transporte', 612.9, 0.48, false],
    ['Assinaturas', 436.4, 0.34, false]
  ]
  const veredito = dados.veredito || '32% a mais em delivery que no mês passado' 

  return (
    <Palco glow={0.8}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 180 }}>
        <div style={{ width: 850, display: 'flex', flexDirection: 'column', gap: 28 }}>
          {CATEGORIAS.map(([nome, valor, peso, alerta], i) => {
            const inicio = 1 + i * 3
            const p = faixa(frame, inicio, inicio + 17, 0, 1, curvas.enfase)
            return (
              <div key={nome} style={{ opacity: faixa(frame, inicio, inicio + 5, 0, 1) }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 12 }}>
                  <span style={{ fontFamily: fontes.corpo, fontSize: 34, color: alerta ? cores.texto : cores.textoFraco }}>{nome}</span>
                  <span style={{ fontFamily: fontes.titulo, fontSize: 40, color: alerta ? cores.vermelho : cores.textoFraco, letterSpacing: -1 }}>
                    R$ {(valor * p).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}
                  </span>
                </div>
                <div style={{ height: 22, borderRadius: 11, backgroundColor: cores.superficie, overflow: 'hidden' }}>
                  <div
                    style={{
                      height: '100%',
                      width: `${p * peso * 100}%`,
                      borderRadius: 11,
                      background: alerta ? `linear-gradient(90deg, ${cores.azul}, ${cores.vermelho})` : `linear-gradient(90deg, ${cores.azul}, ${cores.azulClaro})`,
                      boxShadow: alerta ? `0 0 30px ${cores.vermelho}66` : `0 0 24px ${cores.azul}44`
                    }}
                  />
                </div>
              </div>
            )
          })}

          <div style={{ marginTop: 10, padding: '24px 32px', borderRadius: 22, backgroundColor: `${cores.vermelho}14`, border: `1px solid ${cores.vermelho}55`, opacity: faixa(frame, 26, 26 + dur.entrada, 0, 1), transform: `translateY(${(1 - faixa(frame, 26, 26 + dur.entrada, 0, 1)) * 16}px)` }}>
            <span style={{ fontFamily: fontes.corpo, fontSize: 32, color: cores.texto }}>{veredito}</span>
          </div>
        </div>
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Ela lê seus números.'} destaque={dados.destaque || ['números.']} atraso={0} tamanho={dados.tamanho || 76} />
    </Palco>
  )
}

/* ============================================================ BEATS 16 · 17 */

/**
 * CADEADO QUE FECHA.
 *
 * Desenhado à mão em SVG, não um ícone da lib: o arco precisa ser um path
 * SEPARADO do corpo para poder descer e travar. Nenhum ícone pronto permite
 * animar as duas partes de forma independente.
 *
 * A coreografia é a de um cadeado real: o arco desce, encosta, e o corpo
 * responde com uma compressão mínima (o "clique"). Sem esse recuo o movimento
 * lê como uma peça deslizando, não como travamento.
 */
function CadeadoFechando({ progresso, travado, tamanho = 300 }) {
  /* O arco desce 46 unidades e assenta. */
  const desce = interpolate(progresso, [0, 1], [-46, 0], {
    easing: CURVA_TRAVA,
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp'
  })

  /* Clique: compressão de 4% que resolve em ~5 frames. Sem overshoot. */
  const clique = travado > 0 ? 1 - Math.sin(Math.min(1, travado) * Math.PI) * 0.04 : 1

  return (
    <svg width={tamanho} height={tamanho} viewBox="0 0 200 200" style={{ overflow: 'visible' }}>
      <defs>
        <linearGradient id="corpoCadeado" x1="0" y1="0" x2="0" y2="1">
          <stop offset="0%" stopColor={cores.azulClaro} />
          <stop offset="100%" stopColor={cores.azul} />
        </linearGradient>
      </defs>

      {/* Arco: só o U invertido, com a base aberta para entrar no corpo. */}
      <path
        d="M62 104 V74 a38 38 0 0 1 76 0 V104"
        fill="none"
        stroke={cores.azulSuave}
        strokeWidth="17"
        strokeLinecap="round"
        transform={`translate(0 ${desce})`}
        opacity={0.55 + progresso * 0.45}
      />

      {/* Corpo */}
      <g transform={`translate(100 143) scale(${clique}) translate(-100 -143)`}>
        <rect x="46" y="100" width="108" height="86" rx="20" fill="url(#corpoCadeado)" />
        {/* Fechadura: só aparece depois de travar */}
        <circle cx="100" cy="134" r="11" fill={cores.fundo} opacity={travado > 0.2 ? 1 : 0.25} />
        <rect x="95" y="140" width="10" height="22" rx="5" fill={cores.fundo} opacity={travado > 0.2 ? 1 : 0.25} />
      </g>
    </svg>
  )
}



/** O selo do Banco Central: escudo se fechando com o PIX dentro. */
export function CenaBancoCentral({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  /* O cadeado fecha primeiro; o resto responde a ele. */
  const fecha = faixa(frame, 2, 20, 0, 1, curvas.padrao)
  const travado = faixa(frame, 20, 26, 0, 1)
  const selo = faixa(frame, 26, 26 + dur.entrada, 0, 1, curvas.entrada)

  return (
    <Palco glow={0.7}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 150, gap: 44 }}>
        <div style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          {/* Anel de trava: sai do cadeado no instante do clique. */}
          {travado > 0 && travado < 1 && (
            <div
              style={{
                position: 'absolute',
                width: 300 + travado * 260,
                height: 300 + travado * 260,
                borderRadius: '50%',
                border: `2px solid ${cores.azulClaro}`,
                opacity: (1 - travado) * 0.6
              }}
            />
          )}

          <div style={{ filter: `drop-shadow(0 0 ${34 + travado * 46}px ${cores.azul}88)` }}>
            <CadeadoFechando progresso={fecha} travado={travado} tamanho={430} />
          </div>
        </div>

        <div
          style={{
            display: 'flex',
            alignItems: 'center',
            gap: 14,
            padding: '18px 32px',
            borderRadius: 999,
            backgroundColor: cores.superficie,
            border: `1px solid ${cores.azul}66`,
            opacity: selo,
            transform: `translateY(${(1 - selo) * 16}px)`
          }}
        >
          <div style={{ width: 32, height: 32, color: cores.azulClaro }} dangerouslySetInnerHTML={{ __html: icones['escudo-check'] }} />
          <span style={{ fontFamily: fontes.corpo, fontSize: 30, color: cores.textoFraco }}>{dados.selo || 'Padrão do Banco Central'}</span>
        </div>
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Regulado pelo|Banco Central.'} destaque={dados.destaque || ['Regulado']} atraso={0} tamanho={dados.tamanho || 70} />
    </Palco>
  )
}

/** Somente leitura: a tentativa de mover é barrada. */
export function CenaSomenteLeitura({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const bloqueio = faixa(frame, 8, 18, 0, 1, curvas.enfase)

  return (
    <Palco glow={0.65}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 160, gap: 40 }}>
        <div style={{ position: 'relative', width: 300, height: 300, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          <div style={{ width: 190, height: 190, color: cores.azulClaro, opacity: faixa(frame, 0, 12, 0, 1), transform: `scale(${escalaEntrada(faixa(frame, 0, 12, 0, 1), 0.7)})` }} dangerouslySetInnerHTML={{ __html: icones['olho'] }} />
          {bloqueio > 0 && (
            <div style={{ position: 'absolute', bottom: -6, display: 'flex', alignItems: 'center', gap: 12, padding: '16px 28px', borderRadius: 999, backgroundColor: `${cores.vermelho}1c`, border: `1px solid ${cores.vermelho}66`, opacity: bloqueio, transform: `scale(${escalaEntrada(bloqueio, 0.8)})` }}>
              <div style={{ width: 30, height: 30, color: cores.vermelho }} dangerouslySetInnerHTML={{ __html: icones['x'] }} />
              <span style={{ fontFamily: fontes.corpo, fontSize: 30, color: cores.vermelho }}>{dados.aviso || 'não movimenta'}</span>
            </div>
          )}
        </div>

        <div style={{ display: 'flex', gap: 14, flexWrap: 'wrap', justifyContent: 'center', maxWidth: 900 }}>
          {(dados.selos || [['cadeado', 'Sua senha nunca passa aqui'], ['escudo-check', 'Somente leitura']]).map(([ic, rotulo], i) => {
            const p = faixa(frame, 22 + i * 4, 22 + i * 4 + dur.entrada, 0, 1, curvas.entrada)
            return (
              <div key={ic} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '16px 26px', borderRadius: 999, backgroundColor: cores.superficie, border: `1px solid ${cores.borda}`, opacity: p, transform: `translateY(${(1 - p) * 14}px)` }}>
                <div style={{ width: 30, height: 30, color: cores.azulClaro }} dangerouslySetInnerHTML={{ __html: icones[ic] }} />
                <span style={{ fontFamily: fontes.corpoLeve, fontSize: 27, color: cores.textoFraco }}>{rotulo}</span>
              </div>
            )
          })}
        </div>
      </AbsoluteFill>
      <Legenda texto={dados.titulo || 'Enxerga.|Não movimenta.'} destaque={dados.destaque || ['Enxerga.']} atraso={0} tamanho={dados.tamanho || 76} />
    </Palco>
  )
}

/* ================================================================= BEAT 18 */

export function CenaCTA({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const chegada = faixa(frame, 0, 13, 0, 1, curvas.entrada)
  const respira = 1 + Math.sin(Math.max(0, frame - 24) / 9) * 0.022

  return (
    <Palco glow={1.1}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', gap: 40 }}>
        {[0, 1].map((i) => {
          const p = faixa(frame, 4 + i * 7, 4 + i * 7 + 30, 0, 1, curvas.saida)
          if (p <= 0 || p >= 1) return null
          return <div key={i} style={{ position: 'absolute', width: 280 + p * 860, height: 280 + p * 860, borderRadius: '50%', border: `2px solid ${cores.azul}`, opacity: (1 - p) * 0.4 }} />
        })}

        <div style={{ transform: `scale(${interpolate(chegada, [0, 1], [1.45, 1])}) translateY(${movingHold(0, 5, 110)}px)`, filter: blurDeMovimento(chegada, 18) }}>
          <Nucleo tamanho={180} brilho={1.4} />
        </div>

        <div style={{ fontFamily: fontes.titulo, fontSize: 100, fontWeight: 700, color: cores.texto, letterSpacing: -3.5, textAlign: 'center', opacity: faixa(frame, 12, 12 + dur.entrada, 0, 1) }}>
          <TituloAnimado texto={dados.titulo || '7 dias grátis.'} atraso={12} intervalo={1} cor={(p) => ((dados.destaque || ['grátis.']).includes(p) ? cores.azulClaro : undefined)} />
        </div>

        <div style={{ fontFamily: fontes.corpoLeve, fontSize: 34, color: cores.textoFraco, opacity: faixa(frame, 22, 22 + dur.entrada, 0, 1) }}>{dados.subtitulo || 'Sem cartão de crédito.'}</div>

        <div
          style={{
            marginTop: 6,
            padding: '28px 62px',
            borderRadius: 999,
            backgroundColor: cores.azul,
            fontFamily: fontes.corpo,
            fontSize: 44,
            color: cores.texto,
            opacity: faixa(frame, 30, 30 + dur.entrada, 0, 1),
            transform: `scale(${respira})`,
            boxShadow: `0 0 70px ${cores.azul}66`
          }}
        >
          dominnus.com.br
        </div>
      </AbsoluteFill>
    </Palco>
  )
}


/**
 * SELO — encerramento genérico de bloco.
 *
 * Um ícone grande que pulsa uma vez e um selo abaixo. Existe porque a cena do
 * cadeado (`bancoCentral`) estava sendo reaproveitada como "fecho" em vídeos
 * onde nada tem a ver com segurança — e o cadeado carrega significado forte
 * demais para virar pontuação genérica.
 */
export function CenaSelo({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const chega = faixa(frame, 1, 16, 0, 1, curvas.entrada)
  const pulso = faixa(frame, 14, 24, 0, 1)
  const selo = faixa(frame, 20, 20 + dur.entrada, 0, 1, curvas.entrada)
  const icone = icones[dados.icone] || icones['grafico-crescimento'] || icones['check-circulo']

  return (
    <Palco glow={0.75}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 150, gap: 46 }}>
        <div style={{ position: 'relative', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
          {pulso > 0 && pulso < 1 && (
            <div
              style={{
                position: 'absolute',
                width: 300 + pulso * 300,
                height: 300 + pulso * 300,
                borderRadius: '50%',
                border: `2px solid ${cores.azulClaro}`,
                opacity: (1 - pulso) * 0.55
              }}
            />
          )}
          <div
            style={{
              width: 300,
              height: 300,
              color: cores.azulClaro,
              opacity: chega,
              transform: `scale(${escalaEntrada(chega, 0.62)})`,
              filter: `drop-shadow(0 0 ${chega * 50}px ${cores.azul}aa)`
            }}
            dangerouslySetInnerHTML={{ __html: icone }}
          />
        </div>

        {dados.selo && (
          <div
            style={{
              display: 'flex',
              alignItems: 'center',
              gap: 14,
              padding: '18px 32px',
              borderRadius: 999,
              backgroundColor: cores.superficie,
              border: `1px solid ${cores.azul}66`,
              opacity: selo,
              transform: `translateY(${(1 - selo) * 16}px)`
            }}
          >
            <div style={{ width: 30, height: 30, color: cores.azulClaro }} dangerouslySetInnerHTML={{ __html: icones['check-circulo'] }} />
            <span style={{ fontFamily: fontes.corpo, fontSize: 30, color: cores.textoFraco }}>{dados.selo}</span>
          </div>
        )}
      </AbsoluteFill>
      <Legenda texto={dados.titulo || ''} destaque={dados.destaque} atraso={0} tamanho={dados.tamanho || 74} />
    </Palco>
  )
}

/* ------------------------------------------------------------------ registro */

export const CENAS = {
  planilha: CenaPlanilha,
  planilhaColapso: CenaPlanilhaColapso,
  caos: CenaCaos,
  contaQuebrada: CenaContaQuebrada,
  virada: CenaVirada,
  conecta: CenaConecta,
  bancosChegam: CenaBancosChegam,
  bancosConectam: CenaBancosConectam,
  fluxo: CenaFluxo,
  categoriza: CenaCategoriza,
  whatsappDigita: CenaWhatsappDigita,
  whatsappModos: CenaWhatsappModos,
  importacao: CenaImportacao,
  pergunta: CenaPergunta,
  resposta: CenaResposta,
  bancoCentral: CenaBancoCentral,
  somenteLeitura: CenaSomenteLeitura,
  selo: CenaSelo,

  /* --- exclusivas de cada vídeo (uma coreografia por assunto) --- */
  calendarioSangra: A.CenaCalendarioSangra,
  radar: A.CenaRadar,
  peneira: A.CenaPeneira,
  anoEmpilha: A.CenaAnoEmpilha,

  ralo: E.CenaRalo,
  balanca: E.CenaBalanca,
  cofre: E.CenaCofre,
  filtroRuido: E.CenaFiltroRuido,
  historicoSobra: E.CenaHistoricoSobra,

  heatmapSemana: P.CenaHeatmapSemana,
  relogio24h: P.CenaRelogio24h,
  podio: P.CenaPodio,
  comparaMeses: P.CenaComparaMeses,

  lequeCartoes: C.CenaLequeCartoes,
  cicloFatura: C.CenaCicloFatura,
  comprasNoCartao: C.CenaComprasNoCartao,
  abasFatura: C.CenaAbasFatura,

  busca: X.CenaBusca,
  numeroQuebra: X.CenaNumeroQuebra,
  contadorSobe: X.CenaContadorSobe,

  formularioCansa: W.CenaFormularioCansa,
  desistencia: W.CenaDesistencia,
  conversaSolta: W.CenaConversaSolta,
  audioVira: W.CenaAudioVira,
  fotoLida: W.CenaFotoLida,
  perguntaChat: W.CenaPerguntaChat,
  respostaDetalha: W.CenaRespostaDetalha,
  confirmaAntes: W.CenaConfirmaAntes,

  tempoPerdido: O.CenaTempoPerdido,
  constanciaQuebra: O.CenaConstanciaQuebra,
  autorizaUmaVez: O.CenaAutorizaUmaVez,
  sincronizaSozinho: O.CenaSincronizaSozinho,
  todosOsBancos: O.CenaTodosOsBancos,
  chegaCategorizado: O.CenaChegaCategorizado,
  campoIntocado: O.CenaCampoIntocado,
  leituraApenas: O.CenaLeituraApenas,

  appParado: R.CenaAppParado,
  notificacaoChega: R.CenaNotificacaoChega,
  relatorioMontando: R.CenaRelatorioMontando,
  canais: R.CenaCanais,
  cta: CenaCTA
}
