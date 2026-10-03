/*
 * VSL "Quem está pagando a conta" — 35 cenas, ~4min.
 *
 * Este arquivo é a FONTE DA VERDADE do vídeo: dele saem a narração (o texto
 * vai inteiro para a ElevenLabs, na ordem) e o visual (cada cena declara o
 * bloco que a desenha). Manter os dois no mesmo lugar evita o erro clássico de
 * a locução dizer um número e a tela mostrar outro.
 *
 * `dur` é a duração ALVO em segundos. O roteiro original somava 240s; o vídeo
 * ficou lento na revisão, então as durações foram comprimidas a 75% (180s) com
 * piso de 3s por cena. Abaixo de 3s a cena vira flash e o dado que ela carrega
 * não é lido — o limite foi checado contra ~17 caracteres/segundo, a referência
 * de legibilidade da Netflix, e nenhuma fala ficou sem tempo. Depois que o
 * áudio existe, `gerar-vsl.mjs` recalcula a duração real de cada cena pelos
 * timestamps da ElevenLabs — o alvo serve só para o preview antes da narração.
 *
 * `claro: true` inverte a cena para o TEMA CLARO (ver tema.jsx). Alternar
 * quebra a saturação de um vídeo inteiro no escuro e marca hierarquia: o claro
 * fica nos dados que devem ser lembrados. O ato 6 (a marca) é todo escuro de
 * propósito — o amarelo salta mais depois do branco.
 *
 * `sons` são os efeitos da cena, com `em` em frames DENTRO dela. Ancorados no
 * EVENTO (o número que assenta, a moeda que chega), nunca no corte: efeito em
 * toda passagem vira tique nervoso.
 *
 * `passagem` é a TRANSIÇÃO que traz esta cena (ver passagens.jsx), escolhida
 * pelo conteúdo: `iris` quando a cena anterior tem uma âncora visual e a nova
 * deve nascer dali (cx/cy apontam para ela), `matchCut` quando a anterior
 * termina num número grande, `whip` nas cenas de fluxo (varre no sentido em
 * que o dinheiro anda), `estouro` nas viradas de ato.
 *
 * `entrada` é o gesto INTERNO da cena (ver transicoes.jsx): deslizeX para
 * fluxo, subir para dado, zoom para impacto, cortina para troca de ato. `icone`
 * é o nome na lib @edusites/icons — a mesma dos fronts da PagZero.
 *
 * Todo número exibido saiu do site da PagZero (páginas de taxas e planos) e foi
 * conferido antes de virar copy. Não invente valor novo aqui sem checar lá.
 */

export const cenas = [
  // ─────────── ATO 1 · O juro que ninguém viu ───────────
  {
    id: 1, ato: 1, dur: 4, entrada: 'subir',
    sons: [{ som: 'papel', em: 8, volume: 0.5 }, { som: 'pop', em: 34, volume: 0.45 }], icone: 'moeda-game',
    fala: 'Seu cliente pagou juros pra comprar o seu infoproduto.',
    bloco: 'frase',
    props: { texto: 'Seu cliente\npagou juros\npra comprar de você.', realce: 'juros' }
  },
  {
    id: 2, ato: 1, dur: 4, entrada: 'zoom',
    sons: [{ som: 'pop', em: 6, volume: 0.5 }, { som: 'trava', em: 26, volume: 0.6 }, { som: 'whoosh', em: 62, volume: 0.4 }], icone: 'duvida',
    passagem: { tipo: 'iris', cx: 960, cy: 540, raio: 110 },
    fala: 'Você já parou pra pensar pra onde foi esse dinheiro?',
    bloco: 'pergunta',
    props: { texto: 'Pra onde foi\nesse dinheiro?' }
  },
  {
    id: 3, ato: 1, dur: 4, entrada: 'deslizeX',
    passagem: { tipo: 'empurra' },
    fala: 'Não foi pra você. Nem foi pro banco dele.',
    bloco: 'negacao',
    props: { itens: ['Não foi pra você.', 'Nem foi pro banco dele.'] }
  },
  {
    id: 4, ato: 1, dur: 6, entrada: 'subir', icone: 'cartao',
    sons: [{ som: 'pop', em: 27 }, { som: 'papel', em: 40, volume: 0.7 }],
    passagem: { tipo: 'matchCut', escalaSaida: 5 }, cortavel: true,
    fala: 'Uma venda de mil reais em doze vezes vira mil e duzentos no cartão do seu cliente.',
    bloco: 'parcelamento',
    props: { de: 1000, para: 1200, parcelas: 12 }
  },
  {
    id: 5, ato: 1, dur: 4, entrada: 'zoom', icone: 'dinheiro',
    sons: [{ som: 'impacto', em: 4 }],
    passagem: { tipo: 'iris', cx: 960, cy: 470, raio: 130 }, cortavel: true,
    fala: 'Duzentos reais a mais. Na sua venda. Pelo seu produto.',
    bloco: 'martelo',
    props: { destaque: 'R$ 200 a mais', linhas: ['Na sua venda.', 'Pelo seu produto.'] }
  },
  {
    id: 6, ato: 1, dur: 4, entrada: 'zoom',
    sons: [{ som: 'trovao', em: 2, volume: 0.75 }, { som: 'erro', em: 8, volume: 0.6 }],
    passagem: { tipo: 'matchCut', escalaSaida: 6 },
    fala: 'E ficam com a plataforma.',
    bloco: 'impacto',
    props: { texto: 'E ficam com\na plataforma.', tom: 'vermelho' }
  },
  {
    id: 7, ato: 1, dur: 6, entrada: 'deslizeX',
    sons: [{ som: 'whoosh', em: 20 }, { som: 'trava', em: 52, volume: 0.85 }],
    passagem: { tipo: 'whip', sentido: 1 },
    fala: 'O cliente pagou 1.200. A sua comissão foi de 950.',
    bloco: 'fluxo',
    props: { pagou: 1200, recebeu: 950 }
  },
  {
    id: 8, ato: 1, dur: 4, entrada: 'zoom', icone: 'dinheiro',
    sons: [{ som: 'impacto', em: 4 }, { som: 'queda', em: 16, volume: 0.6 }],
    passagem: { tipo: 'iris', cx: 1180, cy: 560, raio: 100 },
    fala: '250 reais no meio do caminho.',
    bloco: 'martelo',
    props: { destaque: 'R$ 250', linhas: ['no meio do caminho.'], tom: 'vermelho' }
  },
  {
    id: 9, ato: 1, dur: 6, entrada: 'subir', icone: 'porcentagem',
    claro: true,
    sons: [{ som: 'tensao', em: 6, volume: 0.5 }, { som: 'erro', em: 44, volume: 0.9 }],
    passagem: { tipo: 'matchCut', escalaSaida: 5 },
    fala: 'A sua plataforma diz que cobra 5% de taxa. Nessa venda, ela cobrou 21%.',
    bloco: 'taxaReal',
    props: { anunciada: 5, real: 21 }
  },

  // ─────────── ATO 2 · Por que isso é possível ───────────
  {
    id: 10, ato: 2, dur: 4, entrada: 'cortina',
    sons: [{ som: 'whoosh', em: 2 }],
    passagem: { tipo: 'estouro' },
    fala: 'Deixa eu te explicar como funcionam as plataformas tradicionais.',
    bloco: 'capitulo',
    props: { numero: '01', titulo: 'Como funcionam as\nplataformas tradicionais' }
  },
  {
    id: 11, ato: 2, dur: 6, entrada: 'deslizeX',
    sons: [{ som: 'whoosh', em: 12, volume: 0.45 }, { som: 'pop', em: 46, volume: 0.4 }],
    passagem: { tipo: 'whip', sentido: 1 },
    fala: 'A plataforma não processa o seu pagamento. Quem processa é uma adquirente, atrás dela.',
    bloco: 'cadeia',
    props: { revelar: 'adquirente' }
  },
  {
    id: 12, ato: 2, dur: 7, entrada: 'deslizeX',
    sons: [{ som: 'papel', em: 14, volume: 0.5 }, { som: 'erro', em: 52, volume: 0.55 }], icone: 'etiqueta-preco',
    claro: true,
    passagem: { tipo: 'whip', sentido: 1 },
    fala: 'Ela é uma intermediária. Compra o processamento por um preço e revende pra você por outro, muito maior.',
    bloco: 'revenda',
    props: { compra: '0,5% a 2%', revende: '5% a 10%' }
  },
  {
    id: 13, ato: 2, dur: 5, entrada: 'subir',
    sons: [{ som: 'pop', em: 10, volume: 0.45 }, { som: 'pop', em: 20, volume: 0.42 }, { som: 'pop', em: 30, volume: 0.4 }, { som: 'pop', em: 40, volume: 0.38 }],
    passagem: { tipo: 'empurra' },
    fala: 'Ela tem a função dela. Checkout, dashboard, área de membros, integrações.',
    bloco: 'listaFuncoes',
    props: { itens: ['Checkout', 'Dashboard', 'Área de membros', 'Integrações'] }
  },
  {
    id: 14, ato: 2, dur: 4, entrada: 'zoom',
    sons: [{ som: 'erro', em: 10, volume: 0.6 }], icone: 'porcentagem',
    passagem: { tipo: 'iris', cx: 960, cy: 540, raio: 120 },
    fala: 'Mas será que isso deveria custar um percentual do seu faturamento?',
    bloco: 'pergunta',
    props: { texto: 'Mas isso deveria custar\num % do seu faturamento?', tom: 'amarelo' }
  },

  // ─────────── ATO 3 · O Pix ───────────
  {
    id: 15, ato: 3, dur: 3, entrada: 'cortina', icone: 'pix',
    sons: [{ som: 'whoosh', em: 2 }],
    passagem: { tipo: 'estouro' },
    fala: 'E aí tem o Pix.',
    bloco: 'capitulo',
    props: { numero: '02', titulo: 'E aí tem o Pix.' }
  },
  {
    id: 16, ato: 3, dur: 4, entrada: 'zoom', icone: 'pix',
    claro: true,
    passagem: { tipo: 'matchCut', escalaSaida: 5 },
    fala: 'Processar um Pix custa em torno de meio por cento.',
    bloco: 'custoPix',
    props: { custo: 'centavos' }
  },
  {
    id: 17, ato: 3, dur: 7, entrada: 'subir', icone: 'porcentagem',
    claro: true,
    sons: [{ som: 'pop', em: 10, volume: 0.5 }, { som: 'pop', em: 24, volume: 0.55 }, { som: 'trovao', em: 40, volume: 0.8 }],
    passagem: { tipo: 'iris', cx: 960, cy: 500, raio: 140 },
    fala: 'Mas a plataforma cobra a taxa cheia. 3, 5, às vezes 10%, pra processar um simples Pix.',
    bloco: 'taxaCheia',
    props: { faixas: ['3%', '5%', '10%'] }
  },
  {
    id: 18, ato: 3, dur: 7, entrada: 'deslizeX',
    sons: [{ som: 'whoosh', em: 14, volume: 0.45 }, { som: 'erro', em: 54, volume: 0.6 }], icone: 'pix',
    claro: true,
    passagem: { tipo: 'whip', sentido: -1 },
    fala: 'Numa venda de dez mil no Pix, você paga de 300 a 1.000 reais por uma operação que custa 50.',
    bloco: 'comparaPix',
    props: { venda: 10000, custoReal: 50, cobrado: [300, 1000] }
  },

  // ─────────── ATO 4 · O tempo ───────────
  {
    id: 19, ato: 4, dur: 4, entrada: 'cortina', icone: 'relogio',
    sons: [{ som: 'whoosh', em: 2 }],
    passagem: { tipo: 'estouro' }, cortavel: true,
    fala: 'E ainda tem o prazo pra sacar o seu dinheiro.',
    bloco: 'capitulo',
    props: { numero: '03', titulo: 'E ainda tem\no prazo pra sacar.' }
  },
  {
    id: 20, ato: 4, dur: 5, entrada: 'subir',
    sons: [{ som: 'digita', em: 12, volume: 0.45 }, { som: 'trava', em: 48, volume: 0.6 }], icone: 'calendario',
    passagem: { tipo: 'empurra' },
    fala: 'Você se acostumou a sacar em D+14. Ou em D+30.',
    bloco: 'calendario',
    props: { marcos: ['D+14', 'D+30'] }
  },
  {
    id: 21, ato: 4, dur: 5, entrada: 'subir', icone: 'relogio',
    passagem: { tipo: 'iris', cx: 960, cy: 620, raio: 120 },
    fala: 'Adaptou todo o seu fluxo de caixa pra receber um mês depois da venda.',
    bloco: 'frase',
    props: { texto: 'Você adaptou o seu fluxo de caixa\npra receber um mês depois.', realce: 'um mês depois' }
  },
  {
    id: 22, ato: 4, dur: 7, entrada: 'zoom', icone: 'raio',
    sons: [{ som: 'chime', em: 40 }],
    passagem: { tipo: 'matchCut', escalaSaida: 6 },
    fala: 'E o tempo todo dava pra receber em dois dias. Mesmo no cartão. Sem pagar antecipação.',
    bloco: 'reviravolta',
    props: { de: 'D+30', para: 'D+2', selos: ['Mesmo no cartão', 'Sem antecipação'] }
  },

  // ─────────── ATO 5 · O que isso vale ───────────
  {
    id: 23, ato: 5, dur: 6, entrada: 'cortina',
    sons: [{ som: 'tensao', em: 4, volume: 0.6 }, { som: 'whoosh', em: 2, volume: 0.45 }],
    passagem: { tipo: 'estouro' },
    fala: 'Agora junta tudo isso. O juro, a taxa inflada, o Pix caro, o dinheiro parado.',
    bloco: 'somatorio',
    props: { itens: ['O juro', 'A taxa inflada', 'O Pix caro', 'O dinheiro parado'] }
  },
  {
    id: 24, ato: 5, dur: 4, entrada: 'zoom',
    sons: [{ som: 'trovao', em: 4, volume: 0.85 }], icone: 'porcentagem',
    passagem: { tipo: 'iris', cx: 700, cy: 540, raio: 110 },
    fala: 'Entre 10 e 15% do seu faturamento fica no caminho.',
    bloco: 'martelo',
    props: { destaque: '10% a 15%', linhas: ['do seu faturamento', 'fica no caminho.'], tom: 'vermelho' }
  },
  {
    id: 25, ato: 5, dur: 6, entrada: 'subir',
    sons: [{ som: 'digita', em: 10, volume: 0.45 }, { som: 'erro', em: 58, volume: 0.6 }], icone: 'grafico-dinheiro',
    claro: true,
    passagem: { tipo: 'matchCut', escalaSaida: 5 },
    fala: 'Se você fatura trinta mil por mês, são três a quatro mil e quinhentos reais.',
    bloco: 'contaMes',
    props: { fatura: 30000, perdeDe: 3000, perdeAte: 4500 }
  },
  {
    id: 26, ato: 5, dur: 5, entrada: 'deslizeX',
    sons: [{ som: 'papel', em: 12, volume: 0.5 }, { som: 'erro', em: 44, volume: 0.55 }], icone: 'grafico',
    passagem: { tipo: 'whip', sentido: 1 },
    fala: 'E isso não sai da sua receita. Sai inteiro do seu lucro.',
    bloco: 'receitaLucro',
    props: {}
  },
  {
    id: 27, ato: 5, dur: 8, entrada: 'subir',
    sons: [{ som: 'chime', em: 20, volume: 0.6 }, { som: 'pop', em: 60, volume: 0.5 }], icone: 'grafico-dinheiro',
    claro: true,
    passagem: { tipo: 'iris', cx: 960, cy: 560, raio: 120 },
    fala: 'Se a sua margem é de trinta por cento, trocar de plataforma coloca de trinta a cinquenta por cento de lucro a mais no seu bolso.',
    bloco: 'margem',
    props: { margem: 30, ganhoDe: 30, ganhoAte: 50 }
  },
  {
    id: 28, ato: 5, dur: 4, entrada: 'zoom',
    passagem: { tipo: 'matchCut', escalaSaida: 6 },
    fala: 'Vendendo exatamente o que você já vende hoje.',
    bloco: 'impacto',
    props: { texto: 'Vendendo exatamente\no que você já vende hoje.', tom: 'amarelo' }
  },

  // ─────────── ATO 6 · A saída ───────────
  {
    id: 29, ato: 6, dur: 7, entrada: 'cortina',
    sons: [{ som: 'ascensao', em: 0, volume: 0.85 }, { som: 'brilho', em: 30, volume: 0.6 }],
    passagem: { tipo: 'estouro' },
    fala: 'A PagZero faz a mesma intermediação que as plataformas convencionais. Por uma mensalidade fixa.',
    bloco: 'viradaMarca',
    props: {}
  },
  {
    id: 30, ato: 6, dur: 4, entrada: 'zoom',
    sons: [{ som: 'brilho', em: 6, volume: 0.6 }, { som: 'trava', em: 20, volume: 0.55 }],
    passagem: { tipo: 'iris', cx: 960, cy: 470, raio: 150 },
    fala: 'Zero de comissão. Zero de custo escondido.',
    bloco: 'zeros',
    props: { itens: ['Zero de comissão', 'Zero de custo escondido'] }
  },
  {
    id: 31, ato: 6, dur: 8, entrada: 'subir',
    sons: [{ som: 'pop', em: 12 }, { som: 'pop', em: 22 }, { som: 'pop', em: 32 }],
    passagem: { tipo: 'empurra' },
    fala: 'Você conecta o seu próprio gateway. Pagar.me, Asaas, Mercado Pago, Stripe, PayPal, seja qual for.',
    bloco: 'gateways',
    props: { nomes: ['Pagar.me', 'Asaas', 'Mercado Pago', 'Stripe', 'PayPal'] }
  },
  {
    id: 32, ato: 6, dur: 5, entrada: 'deslizeX',
    sons: [{ som: 'whoosh', em: 12, volume: 0.5 }, { som: 'caixa', em: 48, volume: 0.8 }],
    passagem: { tipo: 'whip', sentido: 1 },
    fala: 'A venda cai inteira lá. O gateway é seu. O dinheiro também.',
    bloco: 'vendaInteira',
    props: { valor: 1200 }
  },
  {
    id: 33, ato: 6, dur: 5, entrada: 'subir', icone: 'moeda-game',
    passagem: { tipo: 'iris', cx: 1580, cy: 560, raio: 110 },
    fala: 'O juro que o seu cliente pagou volta pra onde deveria ter ido.',
    bloco: 'frase',
    props: { texto: 'O juro volta pra onde\ndeveria ter ido.', realce: 'volta' }
  },
  {
    id: 34, ato: 6, dur: 3, entrada: 'zoom',
    sons: [{ som: 'impacto', em: 3 }, { som: 'ascensao', em: 6, volume: 0.7 }],
    passagem: { tipo: 'matchCut', escalaSaida: 7 },
    fala: 'Pra você.',
    bloco: 'impacto',
    props: { texto: 'Pra você.', tom: 'amarelo', gigante: true }
  },
  {
    id: 35, ato: 6, dur: 4, entrada: 'subir', icone: 'foguete',
    sons: [{ som: 'brilho', em: 6, volume: 0.6 }, { som: 'chime', em: 22, volume: 0.7 }],
    passagem: { tipo: 'iris', cx: 960, cy: 540, raio: 130 },
    fala: 'Clique no botão abaixo para conversar com a nossa equipe.',
    bloco: 'cta',
    props: { texto: 'Fale com a nossa equipe', site: 'pagzero.com' }
  }
]

/** Texto corrido da narração, na ordem — é o que vai para a ElevenLabs. */
export const narracao = cenas.map((c) => c.fala).join(' ')

export const FPS = 30
