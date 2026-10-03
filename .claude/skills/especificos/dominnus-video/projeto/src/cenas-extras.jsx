import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, interpolate, spring } from 'remotion'
import { cores, fontes, seguro } from './marca.js'
import { curvas, dur, ms, escalaEntrada, movingHold } from './motion.js'
import { molas } from './movimento.jsx'
import { Palco, Legenda, Assinatura, faixa } from './cenas.jsx'
import icones from './icones.json'

/*
 * VARIANTES DE ABERTURA E DESTAQUE.
 *
 * `pergunta` e `contaQuebrada` apareciam em quatro vídeos cada — mesmo com
 * texto diferente, quatro aberturas idênticas fazem a série parecer um vídeo
 * só. Aqui estão formas alternativas para os mesmos papéis narrativos.
 */

/* ------------------------------------------------- pergunta: busca digitando */

/**
 * Campo de busca sendo digitado.
 *
 * A pergunta aparece caractere a caractere com cursor piscando. Serve onde a
 * narração É uma pergunta que o usuário faria — diferente do balão estático.
 */
export function CenaBusca({ duracao, dados = {} }) {
  const frame = useCurrentFrame()
  const texto = dados.pergunta || 'Cadê o dinheiro que eu ganhei?'

  /* ~1 caractere a cada 1,4 frames */
  const n = Math.floor(faixa(frame, 4, 4 + texto.length * 1.4, 0, texto.length))
  const digitando = n < texto.length
  const cursor = Math.floor(frame / 7) % 2 === 0

  return (
    <Palco glow={0.6}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', paddingTop: 180 }}>
        <div
          style={{
            width: 900,
            display: 'flex',
            alignItems: 'center',
            gap: 24,
            padding: '38px 40px',
            borderRadius: 999,
            backgroundColor: cores.superficie,
            border: `1px solid ${digitando ? cores.borda : `${cores.azul}88`}`,
            boxShadow: digitando ? 'none' : `0 0 50px ${cores.azul}33`,
            opacity: faixa(frame, 1, 12, 0, 1),
            transform: `scale(${escalaEntrada(faixa(frame, 1, 12, 0, 1), 0.95)})`
          }}
        >
          <div style={{ width: 46, height: 46, color: cores.azulClaro, flexShrink: 0 }} dangerouslySetInnerHTML={{ __html: icones['lupa'] || icones['balao-pergunta'] }} />
          <span style={{ fontFamily: fontes.corpo, fontSize: 40, color: cores.texto, whiteSpace: 'pre' }}>
            {texto.slice(0, n)}
            {digitando && cursor ? '|' : ''}
          </span>
        </div>

        {dados.sub && (
          <div style={{ marginTop: 34, fontFamily: fontes.corpoLeve, fontSize: 32, color: cores.textoFraco, opacity: faixa(frame, texto.length * 1.4 + 8, texto.length * 1.4 + 20, 0, 1) }}>
            {dados.sub}
          </div>
        )}
      </AbsoluteFill>

      <Legenda texto={dados.titulo || ''} destaque={dados.destaque} atraso={0} tamanho={dados.tamanho || 76} />
      <Assinatura atraso={ms(300)} />
    </Palco>
  )
}

/* --------------------------------------------- destaque: número quebrando */

/**
 * O número que estilhaça.
 *
 * O valor aparece inteiro e depois se parte em fragmentos que caem. Para o
 * momento em que a narração diz que algo não fechou — é o oposto do contador
 * que sobe.
 */
export function CenaNumeroQuebra({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const valor = dados.valor ?? -2344.5
  const texto = Math.abs(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
  const chars = (valor < 0 ? '-' : '') + texto

  const quebra = faixa(frame, 22, duracao * 0.75, 0, 1, curvas.enfaseSaida)

  return (
    <Palco glow={0.55} cor={valor < 0 ? cores.vermelho : cores.azul}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center' }}>
        <div style={{ display: 'flex', alignItems: 'baseline' }}>
          {chars.split('').map((ch, i) => {
            const entra = faixa(frame, 2 + i * 1.6, 2 + i * 1.6 + 10, 0, 1, curvas.entrada)
            /* cada caractere cai com atraso e rotação própria */
            const cai = Math.max(0, quebra * 1.3 - (i % 5) * 0.08)
            return (
              <span
                key={i}
                style={{
                  fontFamily: fontes.titulo,
                  fontSize: 118,
                  fontWeight: 700,
                  color: valor < 0 ? cores.vermelho : cores.azulClaro,
                  letterSpacing: -4,
                  opacity: entra * (1 - cai),
                  transform: `translateY(${(1 - entra) * -30 + cai * 260}px) rotate(${cai * (i % 2 ? 26 : -26)}deg)`,
                  display: 'inline-block',
                  textShadow: `0 0 60px ${valor < 0 ? cores.vermelho : cores.azul}55`
                }}
              >
                {ch}
              </span>
            )
          })}
        </div>

        {dados.rotulo && (
          <div style={{ marginTop: 26, fontFamily: fontes.corpoLeve, fontSize: 34, color: cores.textoFraco, opacity: faixa(frame, 14, 26, 0, 1) * (1 - quebra) }}>
            {dados.rotulo}
          </div>
        )}
      </AbsoluteFill>

      <Legenda texto={dados.titulo || ''} destaque={dados.destaque} atraso={0} tamanho={dados.tamanho || 78} />
    </Palco>
  )
}

/* ----------------------------------------------- destaque: contador subindo */

/**
 * O contador que sobe e trava.
 *
 * Números girando como painel mecânico até assentar no valor. Para o momento
 * POSITIVO — quando a narração revela o que sobrou. É o inverso visual do
 * número que quebra.
 */
export function CenaContadorSobe({ duracao, dados = {} }) {
  const frame = useCurrentFrame()

  const alvo = dados.valor ?? 1976.5
  const p = faixa(frame, 3, 34, 0, 1, curvas.enfase)
  const atual = alvo * p
  const travou = p >= 0.995

  return (
    <Palco glow={0.85}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', gap: 30 }}>
        {/* anel que fecha ao travar */}
        <svg width="620" height="620" style={{ position: 'absolute' }}>
          <circle cx="310" cy="310" r="268" fill="none" stroke={cores.borda} strokeWidth="4" opacity="0.5" />
          <circle
            cx="310"
            cy="310"
            r="268"
            fill="none"
            stroke={cores.azul}
            strokeWidth="6"
            strokeLinecap="round"
            strokeDasharray={`${p * 1684} 1684`}
            transform="rotate(-90 310 310)"
            opacity="0.9"
          />
        </svg>

        <div
          style={{
            fontFamily: fontes.titulo,
            fontSize: 104,
            fontWeight: 700,
            color: cores.texto,
            letterSpacing: -4,
            textShadow: `0 0 ${travou ? 70 : 30}px ${cores.azul}88`,
            transform: `scale(${travou ? 1 + Math.sin((frame - 34) / 8) * 0.012 : 1})`
          }}
        >
          {atual.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
        </div>

        {dados.rotulo && (
          <div style={{ fontFamily: fontes.corpoLeve, fontSize: 34, color: cores.textoFraco, opacity: faixa(frame, 26, 38, 0, 1) }}>{dados.rotulo}</div>
        )}
      </AbsoluteFill>

      <Legenda texto={dados.titulo || ''} destaque={dados.destaque} atraso={0} tamanho={dados.tamanho || 78} />
    </Palco>
  )
}
