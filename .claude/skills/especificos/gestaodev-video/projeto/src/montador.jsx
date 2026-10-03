import React from 'react'
import { AbsoluteFill, useCurrentFrame, useVideoConfig, spring } from 'remotion'
import { cores, fontes, seguro, temas, temaPadrao } from './marca.js'
import { Fundo, Titulo, Paragrafo, Cartao } from './base.jsx'
import { Pop, Molda, TituloAnimado, molas, Varredura, IconePulsante, usarContagem } from './movimento.jsx'
import { Coluna, Topo, Marca, FileiraTecnologias, BlocoLista, BlocoNumeros, BlocoGrafico, BlocoPassos, BlocoMarca, TemaContexto, usarTema } from './blocos.jsx'
import icones from './icones.json'

/*
 * Marca d'água: o ícone que representa o assunto da cena, ampliado no fundo.
 *
 * `contorno()` converte o ícone preenchido em traço — preenchido no tamanho de
 * 1500px vira uma mancha sólida, enquanto o contorno lê como desenho técnico e
 * deixa o conteúdo respirar por cima.
 */
function contorno(svg) {
  if (!svg) return null
  return svg
    .replace('<svg ', '<svg fill="none" stroke="currentColor" stroke-width="1.4" ')
    .replace(/fill="currentColor"/g, '')
}

/** Escolhe o ícone de fundo a partir do conteúdo declarado na cena. */
function marcaDaCena(cena) {
  const nome = cena.marca || cena.itens?.[0]?.icone || cena.tela?.seloIcone
  return contorno(icones[nome])
}

/*
 * Monta uma cena a partir da descrição no roteiro.
 *
 * Cada cena declara `{ tipo, etiqueta, titulo, ... }` e este arquivo escolhe o
 * bloco correspondente. É o que permite seis vídeos diferentes compartilharem
 * o mesmo vocabulário de animação: ajustar o ritmo de `BlocoLista` melhora
 * todos os vídeos que usam lista, de uma vez.
 */

/** Cena de abertura/virada: marca grande no centro com título. */
function CenaMarca({ cena }) {
  const tema = usarTema()

  return (
    <Fundo intensidade={0.25} cor={tema.acento} marca={contorno(icones['gd-raio'])}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', gap: 46, padding: `${seguro.topo}px ${seguro.lateral}px ${seguro.base}px` }}>
        <BlocoMarca atraso={0} tamanho={190} />
        <Titulo tamanho={cena.tamanho || 96} style={{ textAlign: 'center' }}>
          <TituloAnimado texto={cena.titulo} atraso={9} intervalo={2} cor={(palavra) => (cena.destaque && palavra.replace(/[.,]/g, '') === cena.destaque ? tema.acento : undefined)} />
        </Titulo>
      </AbsoluteFill>
    </Fundo>
  )
}

/** Cena de encerramento com o CTA. */
function CenaCta({ cena }) {
  const frame = useCurrentFrame()
  // Respiração no botão: mantém a tela viva no trecho final, que é o mais parado.
  const respira = 1 + Math.sin(Math.max(0, frame - 30) / 9) * 0.022

  return (
    <Fundo intensidade={0.25} marca={contorno(icones['gd-check'])}>
      <AbsoluteFill style={{ alignItems: 'center', justifyContent: 'center', padding: `${seguro.topo}px ${seguro.lateral}px ${seguro.base}px`, gap: 40 }}>
        <BlocoMarca atraso={0} tamanho={150} />

        <Titulo tamanho={cena.tamanho || 92} style={{ textAlign: 'center' }}>
          <TituloAnimado texto={cena.titulo} atraso={7} intervalo={2.2} cor={() => cores.verde} />
        </Titulo>

        {cena.subtitulo && (
          <Pop atraso={16} preset="suave">
            <Paragrafo style={{ textAlign: 'center', fontSize: 38 }}>
              {cena.subtitulo.split('\n').map((linha, i) => (
                <React.Fragment key={i}>
                  {i > 0 && <br />}
                  {linha}
                </React.Fragment>
              ))}
            </Paragrafo>
          </Pop>
        )}

        <Pop atraso={22} preset="pop">
          <div
            style={{
              padding: '28px 64px',
              borderRadius: 999,
              border: `2px solid ${cores.verde}`,
              backgroundColor: `${cores.verdeEscuro}44`,
              fontFamily: fontes.corpo,
              fontSize: 42,
              color: cores.verde,
              transform: `scale(${respira})`
            }}
          >
            {cena.cta || 'link na bio'}
          </div>
        </Pop>
      </AbsoluteFill>
    </Fundo>
  )
}

/** Cena com um destaque numérico grande ao lado do título. */
function CenaDestaque({ cena, duracao }) {
  const tema = usarTema()
  const valor = usarContagem(0, cena.numero.valor, 8, Math.min(34, duracao * 0.55))

  return (
    <Fundo intensidade={cena.brilho ?? 0.28} cor={tema.acento} marca={marcaDaCena(cena)}>
      <Coluna>
        <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 20 }}>
          <Topo etiqueta={cena.etiqueta} cor={cena.corEtiqueta === 'vermelho' ? cores.vermelho : undefined} titulo={cena.titulo} tamanho={cena.tamanho || 92} />

          <Pop atraso={10} preset="pop" style={{ flexShrink: 0, marginTop: 6 }}>
            <div style={{ textAlign: 'right' }}>
              <div style={{ fontFamily: fontes.titulo, fontSize: 66, color: cena.numero.cor === 'vermelho' ? cores.vermelho : tema.acento, letterSpacing: -2, lineHeight: 1 }}>
                {cena.numero.prefixo || ''}
                {valor.toLocaleString('pt-BR')}
                {cena.numero.sufixo || ''}
              </div>
              <div style={{ fontFamily: fontes.corpo, fontSize: 23, color: cores.textoFraco, marginTop: 2 }}>{cena.numero.rotulo}</div>
            </div>
          </Pop>
        </div>

        {cena.itens && <BlocoLista itens={cena.itens} duracao={duracao} cor={cena.corEtiqueta === 'vermelho' ? cores.vermelho : undefined} mostrarTempo={cena.mostrarTempo} />}

        <Marca />
      </Coluna>
    </Fundo>
  )
}

/** Cena padrão: etiqueta + título + um bloco de conteúdo. */
function CenaConteudo({ cena, duracao }) {
  const tema = usarTema()
  const cor = cena.corEtiqueta === 'vermelho' ? cores.vermelho : tema.acento

  return (
    <Fundo intensidade={cena.brilho ?? 0.28} cor={tema.acento} marca={marcaDaCena(cena)}>
      <Coluna>
        <Topo etiqueta={cena.etiqueta} cor={cor} titulo={cena.titulo} tamanho={cena.tamanho || 92} destaque={cena.destaque ? (p) => (p.replace(/[.,]/g, '') === cena.destaque ? cores.verde : undefined) : undefined} />

        {cena.tipo === 'lista' && <BlocoLista itens={cena.itens} duracao={duracao} cor={cor} mostrarTempo={cena.mostrarTempo} />}
        {cena.tipo === 'passos' && <BlocoPassos passos={cena.itens} duracao={duracao} cor={cor} />}
        {cena.tipo === 'numeros' && <BlocoNumeros metricas={cena.metricas} duracao={duracao} />}
        {cena.tipo === 'grafico' && <BlocoGrafico barras={cena.barras} duracao={duracao} cor={cor} rotulo={cena.rotuloGrafico} />}
        {cena.tipo === 'tela' && <BlocoTela cena={cena} duracao={duracao} />}

        {cena.tecnologias && <FileiraTecnologias atraso={16} />}

        <Marca />
      </Coluna>
    </Fundo>
  )
}

/**
 * Mockup de tela do produto dentro de um celular.
 *
 * Fica genérico de propósito: recebe `linhas` e monta a interface. É o bloco
 * que mostra "isto acontece dentro do Gestão Dev".
 */
function BlocoTela({ cena, duracao }) {
  const frame = useCurrentFrame()
  const { fps } = useVideoConfig()
  const confirmado = frame >= (cena.tela.confirmaEm ?? 34)
  const confirma = spring({ frame: frame - (cena.tela.confirmaEm ?? 34), fps, config: molas.pop })

  const claro = cena.tela.claro
  const corTexto = claro ? '#111111' : cores.texto
  const corFraca = claro ? '#6B7280' : cores.textoFraco
  const corBorda = claro ? '#E5E7EB' : cores.borda

  return (
    <Pop atraso={6} preset="suave" origem="top center" desloca={30} style={{ display: 'flex', justifyContent: 'center' }}>
      {/*
       * Mockup em 660x860 (era 380x520).
       *
       * No quadro de 1080px o anterior ocupava 35% da largura e sobrava metade
       * da tela vazia — o exemplo ficava "nanico no centro", que é exatamente o
       * defeito de composição. Em 660px ele ocupa 61% e vira o protagonista da
       * cena, que é o papel dele: mostrar o produto.
       *
       * O conteúdo cresceu junto: além das linhas de dados, agora há corpo de
       * documento (título, itens, total) preenchendo a folha. Antes as 3 linhas
       * ocupavam o topo e o resto era branco morto.
       */}
      <div
        style={{
          position: 'relative',
          width: 620,
          /*
           * Altura adaptativa ao tamanho do título.
           *
           * A faixa segura deixa 1230px úteis. Um título de DUAS linhas come
           * ~330px (título + etiqueta + gaps), então os 860px fixos que havia
           * aqui estouravam e o mockup encostava na logo do rodapé.
           *
           * 760px cabe com título de duas linhas; 840px só quando o título é de
           * uma linha. O roteiro sinaliza via `tituloCurto`.
           */
          height: cena.tituloCurto ? 840 : 760,
          borderRadius: 40,
          backgroundColor: claro ? '#FFFFFF' : cores.superficie,
          border: `3px solid ${confirmado ? cores.verde : cores.borda}`,
          overflow: 'hidden',
          display: 'flex',
          flexDirection: 'column',
          transform: `scale(${1 - confirma * 0.015})`,
          boxShadow: confirmado ? `0 0 70px ${cores.verde}35` : '0 26px 70px rgba(0,0,0,0.5)'
        }}
      >
        {/* Cabeçalho */}
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', padding: '34px 36px 22px', borderBottom: `1px solid ${corBorda}` }}>
          <span style={{ fontFamily: fontes.titulo, fontSize: 32, color: corTexto, letterSpacing: -0.5 }}>{cena.tela.titulo}</span>
          <span style={{ fontFamily: fontes.corpo, fontSize: 22, color: cores.verde }}>{cena.tela.rotulo}</span>
        </div>

        <div style={{ padding: '28px 36px', display: 'flex', flexDirection: 'column', gap: 20, flex: 1 }}>
          {cena.tela.linhas.map((linha, i) => {
            const p = spring({ frame: frame - (8 + i * 3), fps, config: molas.seco })
            return (
              <div key={i} style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', opacity: p, transform: `translateX(${(1 - p) * 12}px)` }}>
                <span style={{ fontFamily: fontes.corpoLeve, fontSize: 30, color: corFraca }}>{linha[0]}</span>
                <span style={{ fontFamily: fontes.corpo, fontSize: 30, color: corTexto }}>{linha[1]}</span>
              </div>
            )
          })}

          {/* Corpo do documento: o que preenche a folha e a faz parecer real.
              Sem isto sobrava branco morto abaixo das linhas de dados. */}
          {cena.tela.corpo && (
            <>
              <div style={{ height: 1, backgroundColor: corBorda, margin: '4px 0' }} />

              {cena.tela.corpo.map((bloco, i) => {
                const p = spring({ frame: frame - (18 + i * 3), fps, config: molas.seco })
                const ehTitulo = bloco.startsWith('#')
                return (
                  <div
                    key={i}
                    style={{
                      opacity: p,
                      transform: `translateY(${(1 - p) * 8}px)`,
                      fontFamily: ehTitulo ? fontes.corpo : fontes.corpoLeve,
                      fontSize: ehTitulo ? 24 : 26,
                      color: ehTitulo ? cores.verde : corFraca,
                      marginTop: ehTitulo ? 8 : 0,
                      lineHeight: 1.4
                    }}
                  >
                    {ehTitulo ? bloco.slice(1).trim() : `•  ${bloco}`}
                  </div>
                )
              })}
            </>
          )}

          {/* Valor em destaque, ancorado na base da folha. */}
          {cena.tela.total && (
            <div style={{ marginTop: 'auto', display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', opacity: spring({ frame: frame - 30, fps, config: molas.seco }) }}>
              <span style={{ fontFamily: fontes.corpo, fontSize: 28, color: corFraca }}>{cena.tela.rotuloTotal || 'Total'}</span>
              <span style={{ fontFamily: fontes.titulo, fontSize: 56, color: claro ? '#111111' : cores.verde, letterSpacing: -1.5 }}>{cena.tela.total}</span>
            </div>
          )}
        </div>

        {/* Faixa de confirmação, colada na base do mockup */}
        {confirmado && cena.tela.selo && (
          <div
            style={{
              /*
               * Faixa no rodapé, não selo sobreposto.
               *
               * Como `position: absolute` centrado, ele cobria o valor total —
               * justamente o dado que a cena existe para mostrar. Como faixa,
               * confirma a ação sem tapar nada.
               */
              width: '100%',
              transform: `translateY(${(1 - confirma) * 100}%)`,
              display: 'flex',
              alignItems: 'center',
              gap: 10,
              padding: '22px 34px',
              borderRadius: 0,
              backgroundColor: cores.verde,
              whiteSpace: 'nowrap',
              justifyContent: 'center',
              opacity: confirma,
              boxShadow: `0 8px 28px ${cores.verde}55`
            }}
          >
            <IconePulsante svg={icones[cena.tela.seloIcone || 'gd-check']} atraso={cena.tela.confirmaEm ?? 34} tamanho={30} cor={cores.fundo} corAtiva={cores.fundo} />
            <span style={{ fontFamily: fontes.corpo, fontSize: 26, color: cores.fundo, lineHeight: 1 }}>{cena.tela.selo}</span>
          </div>
        )}
      </div>
    </Pop>
  )
}

/** Escolhe o componente pelo `tipo` declarado no roteiro. */
export function montarCena(cena, duracao, temaId) {
  const tema = temas[temaId] || temaPadrao

  const conteudo =
    cena.tipo === 'marca' ? <CenaMarca cena={cena} /> : cena.tipo === 'cta' ? <CenaCta cena={cena} /> : cena.numero ? <CenaDestaque cena={cena} duracao={duracao} /> : <CenaConteudo cena={cena} duracao={duracao} />

  return <TemaContexto.Provider value={tema}>{conteudo}</TemaContexto.Provider>
}
