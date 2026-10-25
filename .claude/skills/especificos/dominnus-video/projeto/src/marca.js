import { staticFile } from 'remotion'

/*
 * Identidade da Dominnus, extraída do produto real:
 *   - cores: css/variaveis.sass do nuxt-web + identidade/README.md
 *   - fontes: Sohne (display dos criativos do Figma) + Figtree (a do produto)
 *   - logo:   identidade/svg, vetorial, sem rasterizar
 *
 * O preto oficial é #080A10 — nunca #000000 (regra do README da identidade).
 */
export const cores = {
  fundo: '#080A10',
  fundoAlto: '#0A0E1C',
  superficie: '#0E1426',
  superficieAlta: '#1C2740',
  borda: '#1C2740',
  azul: '#0D2EFC',
  azulClaro: '#4F6BFF',
  azulSuave: '#7D93FF',
  texto: '#FFFFFF',
  textoFraco: '#8A97B0',
  cinza: '#556680',
  verde: '#22C55E',
  vermelho: '#FF1856',
  laranja: '#FF831E',
  // aliases que os blocos herdados do Gestão Dev ainda consultam
  verdeEscuro: '#0A1E9E'
}

export const fontes = {
  titulo: 'Sohne',
  corpo: 'Figtree',
  corpoLeve: 'FigtreeLight'
}

/*
 * Zona segura do Instagram: o que sair daqui é coberto pela UI no aparelho,
 * mesmo aparecendo certo no preview do Remotion.
 */
export const seguro = {
  topo: 260,
  base: 430,
  lateral: 90
}

/*
 * O acento é SEMPRE o azul #0D2EFC — é o que faz o vídeo parecer da marca.
 * A diferenciação entre vídeos vem da FORMA (raio, densidade, superfície),
 * não da cor. Mesma lição já aprendida na série do Gestão Dev.
 */
export const temas = {
  abertura: {
    acento: '#0D2EFC',
    raio: 24,
    alinhamento: 'left',
    superficie: 'solida',
    numeracao: 'nenhuma',
    densidade: 'normal'
  },

  /* A diferenciação entre vídeos vem da FORMA (raio, densidade, superfície) —
     nunca da cor: o acento é sempre o azul da marca. */
  whatsapp: {
    acento: '#0D2EFC',
    raio: 40,          /* bolha de conversa: raio alto, cantos moles */
    alinhamento: 'left',
    superficie: 'solida',
    numeracao: 'nenhuma',
    densidade: 'solta'
  },
  openfinance: {
    acento: '#0D2EFC',
    raio: 20,          /* trilhos e ciclos: geometria mais dura */
    alinhamento: 'left',
    superficie: 'solida',
    numeracao: 'nenhuma',
    densidade: 'normal'
  }
}

export const temaPadrao = temas.abertura

/* Papel claro — usado nos mockups de tela clara do app. */
export const claro = {
  papel: '#FFFFFF',
  fundo: '#F4F6FB',
  texto: '#0A0E1C',
  titulo: '#070912',
  suave: '#556680',
  borda: '#E4E8F2',
  destaque: '#0D2EFC'
}

/* Nome herdado: o montador importa `proposta` para o mockup claro. */
export const proposta = claro

export const cssFontes = `
@font-face {
  font-family: 'Sohne';
  src: url('${staticFile('fontes/Sohne.woff2')}') format('woff2');
  font-weight: 400 700;
  font-display: block;
}
@font-face {
  font-family: 'Figtree';
  src: url('${staticFile('fontes/figtree-semibold.woff')}') format('woff');
  font-weight: 600;
  font-display: block;
}
@font-face {
  font-family: 'FigtreeLight';
  src: url('${staticFile('fontes/figtree-light.woff')}') format('woff');
  font-weight: 300;
  font-display: block;
}
`
