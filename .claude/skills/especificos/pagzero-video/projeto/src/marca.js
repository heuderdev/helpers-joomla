import { staticFile } from 'remotion'

/*
 * Tokens da PagZero — extraídos de Identidade/README.md e do nuxt-app.
 * Não inventar cor aqui: o vídeo tem que parecer o mesmo produto que a
 * pessoa encontra ao clicar no link.
 */
export const cores = {
  fundo: '#0B0A0D',
  superficie: '#141317',
  superficieAlta: '#1E1D22',
  borda: '#26252B',
  amarelo: '#FEBE00',
  amareloEscuro: '#8A6800',
  texto: '#FFFFFF',
  textoFraco: '#8E8C97',
  verde: '#18C964',
  vermelho: '#FF2E6D',
  // verde institucional da Pagar.me, para as cenas do painel deles
  pagarme: '#65A300'
}

export const fontes = { corpo: 'Sohne' }

export const cssFontes = `
@font-face {
  font-family: 'Sohne';
  src: url('${staticFile('fontes/Sohne.woff2')}') format('woff2');
  font-weight: 400 700;
  font-display: block;
}
`

/*
 * 1920x1080. Diferente do 9:16, aqui não há UI de rede social por cima —
 * a margem existe só por respiro de composição.
 */
export const T = { w: 1920, h: 1080 }
export const margem = 96
