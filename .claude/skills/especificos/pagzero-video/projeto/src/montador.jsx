import React from 'react'
import { Abertura, CenaPrint, CenaEventos, CenaCta, CenaConceito } from './blocos.jsx'

export function montarCena(conteudo, duracao, passo, total) {
  switch (conteudo.tipo) {
    case 'abertura':
      return <Abertura {...conteudo} duracao={duracao} />
    case 'print':
      return <CenaPrint conteudo={conteudo} duracao={duracao} passo={passo} total={total} />
    case 'eventos':
      return <CenaEventos conteudo={conteudo} duracao={duracao} passo={passo} total={total} />
    case 'conceito':
      return <CenaConceito conteudo={conteudo} duracao={duracao} passo={passo} total={total} />
    case 'cta':
      return <CenaCta conteudo={conteudo} duracao={duracao} />
    default:
      return null
  }
}
