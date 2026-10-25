import { staticFile } from 'remotion'

/*
 * Tokens extraídos do nuxt-web (assets/css). Não inventar cor aqui: o vídeo
 * precisa parecer o mesmo produto que a pessoa vai encontrar ao clicar no link.
 *
 * Atenção à armadilha do arquivo original: lá `--cor-preto` vale #FFFFFF e
 * `--cor-branco` vale #0C0C11 (os nomes descrevem o TEMA, não a cor). Aqui os
 * nomes dizem a cor de verdade, para não errar na hora de usar.
 */
export const cores = {
  fundo: '#0C0C11',
  superficie: '#14141B',
  superficieAlta: '#26262F',
  borda: '#1F1F28',
  verde: '#1FE54A',
  verdeEscuro: '#0E7526',
  texto: '#FFFFFF',
  textoFraco: '#8A8A96',
  vermelho: '#FF4D6D',
  azul: '#5B8DEF'
}

export const fontes = {
  titulo: 'BalooDois',
  corpo: 'Figtree',
  corpoLeve: 'FigtreeLight'
}

/*
 * ZONA SEGURA dos Stories/Reels (1080x1920).
 *
 * O Instagram desenha a própria interface por cima do vídeo, e o que cair
 * embaixo dela some ou fica ilegível:
 *
 *   topo    ~250px  → foto do perfil, @usuário, "···" e a barra de progresso
 *   base    ~340px  → campo "Enviar mensagem", curtir, compartilhar; nos Reels
 *                     a legenda e o áudio ocupam ainda mais
 *   laterais ~60px  → cantos arredondados em aparelho com tela curva
 *
 * Uso 260 no topo e 400 na base — a base é mais generosa porque a UI de Reels
 * é mais alta que a de Stories, e é ali que fica o CTA do vídeo.
 *
 * Conteúdo fora dessa faixa não é "quase cortado": ele É cortado no aparelho
 * de quem assiste, mesmo aparecendo certo no preview do desktop.
 */
export const seguro = {
  topo: 260,
  base: 430,
  lateral: 90
}

/*
 * IDENTIDADE POR VÍDEO.
 *
 * A primeira leva dos seis saiu visualmente idêntica: mesmo card, mesmo raio,
 * mesmo alinhamento, só mudando o texto. Numa grade de destaques do Instagram
 * eles viram seis miniaturas indistinguíveis — some justamente a razão de
 * existirem separados.
 *
 * Aqui cada tema recebe um TRATAMENTO próprio, mantendo a paleta da marca
 * (fundo escuro + verde) para a série continuar reconhecível:
 *
 *   `acento`     SEMPRE o verde da marca (#1FE54A). Cheguei a variar por tema
 *                (laranja/azul/roxo) e foi um erro: a identidade do Gestão Dev
 *                é o verde, e trocá-lo faz o vídeo deixar de parecer da marca.
 *                A diferenciação entre os vídeos vem da FORMA — raio, densidade,
 *                marcador, superfície —, nunca da cor.
 *   `raio`       arredondamento do cartão. PISO DE 18: abaixo disso o canto
 *                lê como quadrado e destoa da UI do produto, que é toda
 *                arredondada. O tema aprovado (cobranças) usa 20.
 *   `alinhamento` texto à esquerda ou centralizado
 *   `superficie` cartão sólido, contornado ou preenchido pelo acento
 *   `numeracao`  lista com número, ícone, marcador ou nada
 */
export const temas = {
  proposta: {
    acento: '#1FE54A',
    raio: 24,
    alinhamento: 'left',
    superficie: 'solida',
    numeracao: 'tempo',
    densidade: 'normal'
  },
  tarefas: {
    acento: '#1FE54A',
    raio: 18,
    alinhamento: 'left',
    superficie: 'destaque',
    numeracao: 'indice',
    densidade: 'compacta'
  },
  contratos: {
    acento: '#1FE54A',
    raio: 34,
    alinhamento: 'left',
    superficie: 'solida',
    numeracao: 'marcador',
    densidade: 'ampla'
  },
  cobrancas: {
    acento: '#1FE54A',
    raio: 20,
    alinhamento: 'left',
    // Faixa preenchida com o acento: dinheiro pede peso visual.
    superficie: 'destaque',
    numeracao: 'icone',
    densidade: 'normal'
  },
  notafiscal: {
    acento: '#1FE54A',
    raio: 22,
    alinhamento: 'left',
    superficie: 'destaque',
    numeracao: 'icone',
    densidade: 'compacta'
  },
  dashboard: {
    acento: '#1FE54A',
    raio: 28,
    alinhamento: 'center',
    superficie: 'solida',
    numeracao: 'nenhuma',
    densidade: 'ampla'
  }
}

export const temaPadrao = temas.proposta

/*
 * Tokens da PROPOSTA renderizada (nuxt-proposta), que tem paleta própria e
 * clara — não é a mesma superfície escura do site.
 *
 * Fonte: components/pages/proposta/SectionConteudo.vue. A folha é branca, o
 * corpo roda a 16px com line-height 1.7, e `destaque` é a cor que o usuário
 * escolhe (corLayout) — aparece no rótulo do cabeçalho e nos h2.
 */
export const proposta = {
  papel: '#FFFFFF',
  texto: '#222222',
  titulo: '#111111',
  suave: '#6B7280',
  borda: '#E5E7EB',
  destaque: '#1FE54A'
}

/*
 * As fontes são injetadas por CSS em vez de @remotion/fonts porque são
 * arquivos locais da marca, não do Google Fonts. `font-display: block` evita
 * que um único frame renderize com a fonte de sistema — num vídeo isso não é
 * um flash imperceptível, é um frame errado gravado para sempre.
 */
export const cssFontes = `
@font-face {
  font-family: 'BalooDois';
  src: url('${staticFile('fontes/baloo-tamma-2-bold.woff2')}') format('woff2');
  font-weight: 800;
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
