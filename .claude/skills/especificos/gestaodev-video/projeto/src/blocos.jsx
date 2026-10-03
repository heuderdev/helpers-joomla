import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring } from 'remotion'
import { cores, fontes, seguro, temaPadrao } from './marca.js'
import { Fundo, Etiqueta, Titulo, Paragrafo, Cartao } from './base.jsx'
import { Pop, Molda, TituloAnimado, usarMola, molas, usarFlutuacao, usarDestaqueSequencial, Varredura, Icone, IconePulsante, usarContagem } from './movimento.jsx'
import { atrasoStagger, ms, movingHold, progresso, curvas, dur, escalaEntrada, blurDeMovimento } from './motion.js'
import icones from './icones.json'
import tecnologias from './tecnologias.json'
import logo from './logo.json'

/*
 * BLOCOS REUTILIZÁVEIS.
 *
 * O primeiro vídeo (proposta) teve cada cena escrita à mão. Para uma série de
 * seis, isso significaria reescrever a mesma lista com dados diferentes seis
 * vezes — e qualquer ajuste de ritmo teria que ser replicado em todas.
 *
 * Aqui as cenas viram TIPOS parametrizados por dados: um roteiro descreve
 * `{ tipo: 'lista', itens: [...] }` e o componente correspondente monta a
 * animação. Os tempos são derivados da duração real da cena, então o mesmo
 * bloco funciona numa cena de 1,5s e numa de 6s sem recalibrar na mão.
 */


/*
 * Contexto do tema: o montador injeta o tema do vídeo no topo da árvore e cada
 * bloco lê dali. Sem isso, seria preciso passar `tema` como prop por todos os
 * níveis, e um esquecimento faria um bloco isolado voltar ao visual padrão.
 */
export const TemaContexto = React.createContext(temaPadrao)
export const usarTema = () => React.useContext(TemaContexto)

/** Estilo de superfície do cartão, conforme o tema. */
export function superficieDo(tema, foco = 0, corOverride) {
  /*
   * `corOverride` existe porque a cena de "dor" usa vermelho enquanto o tema do
   * vídeo é verde. Sem isso, a borda em foco saía verde numa lista que fala de
   * problema — sinal invertido.
   */
  const acento = corOverride || tema.acento
  const base = { borderRadius: tema.raio }

  if (tema.superficie === 'contorno') {
    return {
      ...base,
      backgroundColor: 'transparent',
      border: `2px solid ${foco > 0.1 ? acento : cores.borda}`,
      boxShadow: foco > 0.1 ? `0 0 40px ${acento}22` : 'none'
    }
  }

  if (tema.superficie === 'destaque') {
    return {
      ...base,
      // Fundo ganha um véu do acento quando em foco: a linha inteira "acende".
      backgroundColor: foco > 0.1 ? `${acento}1c` : cores.superficie,
      border: `1px solid ${foco > 0.1 ? acento : cores.borda}`,
      boxShadow: foco > 0.1 ? `0 0 46px ${acento}22` : 'none'
    }
  }

  return {
    ...base,
    backgroundColor: cores.superficie,
    border: `1px solid ${foco > 0.1 ? acento : cores.borda}`,
    boxShadow: foco > 0.1 ? `0 0 40px ${acento}18` : 'none'
  }
}

/** Espaçamento vertical conforme a densidade do tema. */
export const espacoDo = (tema) => ({ compacta: 14, normal: 20, ampla: 28 })[tema.densidade] || 20
export const padDo = (tema) => ({ compacta: '24px 30px', normal: '30px 34px', ampla: '38px 40px' })[tema.densidade] || '30px 34px'

/** Enquadramento com a zona segura dos Stories. Último filho vira rodapé. */
export function Coluna({ children, gap = 46 }) {
  const filhos = React.Children.toArray(children).filter(React.isValidElement)
  const rodape = filhos.pop()

  return (
    <AbsoluteFill style={{ padding: `${seguro.topo}px ${seguro.lateral}px ${seguro.base}px`, display: 'flex', flexDirection: 'column', justifyContent: 'space-between' }}>
      <div style={{ display: 'flex', flexDirection: 'column', gap }}>{filhos}</div>
      {rodape}
    </AbsoluteFill>
  )
}

export function Topo({ etiqueta, cor, titulo, tamanho = 92, destaque }) {
  const tema = usarTema()
  const centrado = tema.alinhamento === 'center'

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 34, alignItems: centrado ? 'center' : 'flex-start', textAlign: centrado ? 'center' : 'left' }}>
      {/* Hierarquia: a etiqueta é o elemento MENOS importante, então entra
          primeiro e rápido. O título — o protagonista da cena — entra depois e
          com mais tempo. A regra é terminar a sequência no que mais importa. */}
      <Pop atraso={0} preset="pop" desloca={14} duracao={dur.curta}>
        <Etiqueta texto={etiqueta} cor={cor || acento} />
      </Pop>
      <Titulo tamanho={tamanho}>
        <TituloAnimado texto={titulo} atraso={ms(120)} intervalo={1.6} cor={destaque} />
      </Titulo>
    </div>
  )
}

/** Logo real do nuxt-web (1007x128). */
export function Marca({ largura = 250 }) {
  const p = usarMola(6, 'suave')
  return (
    <div
      style={{ width: largura, height: largura * (128 / 1007), opacity: p * 0.9, transform: `translateY(${(1 - p) * 12}px)` }}
      dangerouslySetInnerHTML={{ __html: logo.branco }}
    />
  )
}

export function FileiraTecnologias({ atraso = 0, cor = cores.textoFraco, tamanho = 44, stack }) {
  const STACK = stack || ['javascript', 'typescript', 'react', 'vue', 'nuxt', 'python', 'php', 'laravel']
  return (
    <div style={{ display: 'flex', alignItems: 'center', gap: 24 }}>
      {STACK.map((tec, i) => (
        <Icone key={tec} svg={tecnologias[tec]} atraso={atraso + i * 2.5} tamanho={tamanho} cor={cor} preset="pop" giro={-14} />
      ))}
    </div>
  )
}

/* ================================================================ LISTA */

/**
 * Lista de cards com ícone, título e detalhe.
 *
 * Os tempos derivam da duração da cena: os cards entram na primeira metade e o
 * foco percorre a lista na segunda. Assim a mesma lista funciona numa cena
 * curta (entra rápido, foco corrido) e numa longa, sem número mágico.
 */
export function BlocoLista({ itens, duracao, cor, mostrarTempo = false }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const tema = usarTema()
  const acento = cor || acento

  /*
   * Stagger por ORÇAMENTO, não por offset fixo.
   *
   * Antes eram 6 frames (200ms) fixos entre cards: numa lista de 4 isso dá
   * 800ms só de cascata, e o olho termina de ler antes de a animação acabar.
   * A referência profissional é orçamento total de ~400ms redistribuído — com
   * 4 itens dá 100ms cada, com 8 dá 50ms, e a lista cresce sem estourar a cena.
   */
  const orcamento = Math.min(ms(400), duracao * 0.3)
  const inicioFoco = orcamento + ms(120)
  const focoCada = Math.max(9, (duracao - inicioFoco - 4) / itens.length)

  const centrado = tema.alinhamento === 'center'

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: espacoDo(tema) }}>
      {itens.map((item, i) => {
        const atraso = atrasoStagger(i, itens.length, orcamento)
        const p = progresso(atraso, dur.entrada, curvas.entrada)
        const flutua = movingHold(i, 3)
        const foco = usarDestaqueSequencial(i, itens.length, inicioFoco, focoCada)

        return (
          <div
            key={item.titulo}
            style={{
              position: 'relative',
              overflow: 'hidden',
              opacity: Math.min(1, p * 1.4),
              transform: `translateX(${(1 - p) * (centrado ? 0 : -36)}px) translateY(${flutua + (1 - p) * (centrado ? 22 : 0)}px) scale(${escalaEntrada(p) + foco * 0.014})`,
              filter: blurDeMovimento(p, 5),
              display: 'flex',
              alignItems: 'center',
              justifyContent: centrado ? 'center' : 'flex-start',
              textAlign: centrado ? 'center' : 'left',
              gap: 24,
              padding: padDo(tema),
              ...superficieDo(tema, foco, acento)
            }}
          >
            <Varredura atraso={atraso + 3} duracao={20} cor={acento} forca={0.06} />

            <MarcadorItem indice={i} item={item} tema={tema} atraso={atraso + 2} foco={foco} acento={acento} />

            <div style={{ flex: centrado ? '0 1 auto' : 1 }}>
              <div style={{ fontFamily: fontes.corpo, fontSize: 36, color: cores.texto }}>{item.titulo}</div>
              {item.detalhe && <div style={{ fontFamily: fontes.corpoLeve, fontSize: 28, color: cores.textoFraco, marginTop: 4 }}>{item.detalhe}</div>}
            </div>

            {mostrarTempo && item.tempo && (
              <div style={{ fontFamily: fontes.titulo, fontSize: 28, color: acento, opacity: foco, transform: `translateX(${(1 - foco) * 14}px)`, flexShrink: 0 }}>{item.tempo}</div>
            )}
          </div>
        )
      })}
    </div>
  )
}

/**
 * O marcador à esquerda de cada item muda por tema.
 *
 * É o detalhe que mais diferencia dois vídeos na miniatura: um traz caixa de
 * ícone, outro um número grande, outro só um traço. Mesmo conteúdo, leitura
 * visual distinta.
 */
function MarcadorItem({ indice, item, tema, atraso, foco, acento }) {
  if (tema.numeracao === 'nenhuma') return null

  if (tema.numeracao === 'indice') {
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: 18, flexShrink: 0 }}>
        <span style={{ fontFamily: fontes.titulo, fontSize: 46, color: foco > 0.1 ? acento : cores.borda, letterSpacing: -1, lineHeight: 1, minWidth: 44 }}>{String(indice + 1).padStart(2, '0')}</span>
        {item.icone && <IconePulsante svg={icones[item.icone]} atraso={atraso} tamanho={30} foco={foco} cor={cores.textoFraco} corAtiva={acento} giro={-16} />}
      </div>
    )
  }

  if (tema.numeracao === 'marcador') {
    return (
      <div style={{ display: 'flex', alignItems: 'center', gap: 20, flexShrink: 0 }}>
        <div style={{ width: 4, height: 52, borderRadius: 999, backgroundColor: foco > 0.1 ? acento : cores.borda }} />
        {item.icone && <IconePulsante svg={icones[item.icone]} atraso={atraso} tamanho={34} foco={foco} cor={cores.textoFraco} corAtiva={acento} giro={-16} />}
      </div>
    )
  }

  // 'icone' e 'tempo': ícone dentro de uma caixa
  return (
    <div
      style={{
        width: 62,
        height: 62,
        borderRadius: Math.max(8, tema.raio * 0.7),
        backgroundColor: cores.fundo,
        border: `1px solid ${cores.borda}`,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        flexShrink: 0
      }}
    >
      <IconePulsante svg={icones[item.icone]} atraso={atraso} tamanho={34} foco={foco} cor={cores.textoFraco} corAtiva={acento} giro={-20} />
    </div>
  )
}

/* ============================================================== NÚMEROS */

/**
 * Painel de métricas — três números grandes que sobem.
 *
 * Serve para o vídeo de dashboard: número animado comunica "isto é um dado
 * vivo" de um jeito que texto estático não alcança.
 */
export function BlocoNumeros({ metricas, duracao }) {
  const dur = Math.min(26, duracao * 0.5)
  const frame = useCurrentFrame()

  /*
   * EMPILHADO, não lado a lado.
   *
   * Três cards em linha num quadro de 1080px de largura viram caixinhas de
   * ~330px: o número fica pequeno e sobra meia tela vazia embaixo. Empilhados,
   * cada métrica ganha a largura inteira, o número respira em 84px, e a coluna
   * preenche a altura útil — que é justamente o que faltava.
   *
   * O rótulo à esquerda e o valor à direita criam uma coluna de leitura: o olho
   * desce pelos números alinhados em vez de saltar entre caixas.
   */
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 18 }}>
      {metricas.map((m, i) => {
        const atraso = 5 + i * 6
        const valor = usarContagem(0, m.valor, atraso, dur)
        const flutua = usarFlutuacao(i, 3.5)

        return (
          <div key={m.rotulo} style={{ transform: `translateY(${flutua}px)` }}>
            <Pop atraso={atraso} preset="pop">
              <Cartao style={{ padding: '34px 38px', position: 'relative', overflow: 'hidden', display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 20 }}>
                <Varredura atraso={atraso + 4} duracao={24} cor={m.cor || cores.verde} forca={0.08} />

                <div style={{ display: 'flex', alignItems: 'center', gap: 22 }}>
                  {m.icone && (
                    <div style={{ width: 58, height: 58, borderRadius: 16, backgroundColor: cores.fundo, border: `1px solid ${cores.borda}`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }}>
                      <Icone svg={icones[m.icone]} atraso={atraso + 2} tamanho={32} cor={m.cor || cores.verde} preset="pop" giro={-18} />
                    </div>
                  )}
                  <span style={{ fontFamily: fontes.corpo, fontSize: 32, color: cores.textoFraco }}>{m.rotulo}</span>
                </div>

                <span
                  style={{
                    fontFamily: fontes.titulo,
                    fontSize: 62,
                    color: m.cor || cores.verde,
                    letterSpacing: -1.8,
                    lineHeight: 1,
                    // Dígitos tabulares: sem isso o número "pula" de largura a
                    // cada frame da contagem e o card inteiro treme.
                    fontVariantNumeric: 'tabular-nums'
                  }}
                >
                  {m.prefixo || ''}
                  {valor.toLocaleString('pt-BR')}
                  {m.sufixo || ''}
                </span>
              </Cartao>
            </Pop>
          </div>
        )
      })}
    </div>
  )
}

/* =============================================================== GRÁFICO */

/**
 * Barras que crescem da base.
 *
 * `transformOrigin: bottom` é o detalhe que importa: crescendo do chão a barra
 * lê como valor acumulando. Crescendo do centro, lê como caixa aparecendo.
 */
export function BlocoGrafico({ barras, duracao, cor = cores.verde, rotulo }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const maior = Math.max(...barras.map((b) => b.valor))

  return (
    <Molda atraso={4}>
      <Cartao style={{ padding: 38, position: 'relative', overflow: 'hidden' }}>
        <Varredura atraso={12} duracao={26} cor={cor} forca={0.06} />

        {rotulo && <div style={{ fontFamily: fontes.corpo, fontSize: 26, color: cores.textoFraco, marginBottom: 26 }}>{rotulo}</div>}

        <div style={{ display: 'flex', alignItems: 'flex-end', gap: 14, height: 260 }}>
          {barras.map((b, i) => {
            const p = spring({ frame: frame - (10 + i * 3), fps, config: molas.seco })
            const altura = (b.valor / maior) * 100

            return (
              <div key={b.rotulo} style={{ flex: 1, display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12, height: '100%', justifyContent: 'flex-end' }}>
                <div
                  style={{
                    width: '100%',
                    height: `${altura * p}%`,
                    minHeight: 4,
                    borderRadius: '10px 10px 4px 4px',
                    // Último mês em destaque: é onde a história chega.
                    backgroundColor: i === barras.length - 1 ? cor : cores.superficieAlta,
                    transformOrigin: 'bottom'
                  }}
                />
                <div style={{ fontFamily: fontes.corpo, fontSize: 21, color: cores.textoFraco }}>{b.rotulo}</div>
              </div>
            )
          })}
        </div>
      </Cartao>
    </Molda>
  )
}

/* ================================================================ PASSOS */

/** Timeline vertical: cada passo puxa o próximo por uma linha que cresce. */
export function BlocoPassos({ passos, duracao, cor }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const tema = usarTema()
  cor = cor || acento

  const orcamento = Math.min(ms(400), duracao * 0.3)
  const inicioFoco = orcamento + ms(120)
  const focoCada = Math.max(10, (duracao - inicioFoco - 4) / passos.length)

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: espacoDo(tema) - 2 }}>
      {passos.map((item, i) => {
        const atraso = atrasoStagger(i, passos.length, orcamento)
        const p = progresso(atraso, dur.entrada, curvas.entrada)
        const linha = spring({ frame: frame - Math.max(0, atraso - 4), fps, config: molas.seco })
        const foco = usarDestaqueSequencial(i, passos.length, inicioFoco, focoCada)

        return (
          <div key={item.titulo}>
            {i > 0 && <div style={{ width: 2, height: 26, marginLeft: 48, backgroundColor: cor, marginBottom: 6, transform: `scaleY(${linha})`, transformOrigin: 'top', opacity: 0.5 }} />}

            <div
              style={{
                position: 'relative',
                overflow: 'hidden',
                opacity: Math.min(1, p * 1.4),
                transform: `translateY(${(1 - p) * 24}px) scale(${escalaEntrada(p) + foco * 0.012})`,
                filter: blurDeMovimento(p, 5),
                display: 'flex',
                alignItems: 'center',
                gap: 26,
                padding: padDo(tema),
                ...superficieDo(tema, foco, cor)
              }}
            >
              <Varredura atraso={atraso + 3} duracao={22} cor={cor} forca={0.08} />

              <div
                style={{
                  width: 64,
                  height: 64,
                  borderRadius: 999,
                  border: `2px solid ${cor}`,
                  backgroundColor: `${cor}14`,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  flexShrink: 0,
                  transform: `scale(${0.6 + p * 0.4})`
                }}
              >
                <IconePulsante svg={icones[item.icone]} atraso={atraso + 2} tamanho={34} foco={foco} cor={cor} corAtiva={cor} giro={-22} />
              </div>

              <div style={{ flex: 1 }}>
                <div style={{ fontFamily: fontes.corpo, fontSize: 38, color: cores.texto }}>{item.titulo}</div>
                {item.detalhe && <div style={{ fontFamily: fontes.corpoLeve, fontSize: 28, color: cores.textoFraco, marginTop: 4 }}>{item.detalhe}</div>}
              </div>
            </div>
          </div>
        )
      })}
    </div>
  )
}

/* ================================================================ SELO */

/** Marca central grande — usado na virada e no CTA. */
export function BlocoMarca({ atraso = 0, tamanho = 190 }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const p = spring({ frame: frame - atraso, fps, config: molas.elastico })

  return (
    <div
      style={{
        width: tamanho,
        height: tamanho,
        borderRadius: tamanho * 0.24,
        backgroundColor: cores.verde,
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        transform: `scale(${0.45 + p * 0.55}) rotate(${(1 - p) * -12}deg)`,
        boxShadow: `0 0 ${p * 130}px ${cores.verde}55`
      }}
    >
      <span style={{ fontFamily: fontes.titulo, fontSize: tamanho * 0.45, color: cores.fundo, letterSpacing: -3 }}>{'</>'}</span>
    </div>
  )
}

export { Fundo, Titulo, Paragrafo, Cartao, Pop, Molda, Icone, IconePulsante, Varredura, usarContagem, usarMola, molas, cores, fontes, icones }
