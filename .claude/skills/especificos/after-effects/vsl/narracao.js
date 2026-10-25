/**
 * narracao.js — as 35 falas da VSL, com direção para o eleven_v3.
 *
 * Texto mantido palavra por palavra do roteiro aprovado. O que mudou aqui é
 * só a DIREÇÃO: onde entra cada audio tag, e a grafia do que é falado.
 *
 * Regras que valem para todo o arquivo:
 *  - Número SEMPRE por extenso: `apply_text_normalization` fica em 'off', então
 *    "R$ 1.200" sairia lido errado. "mil e duzentos" é o que se escreve.
 *  - A tag só aparece onde o registro MUDA; as demais falas herdam o estado.
 *    Tag em toda frase achata a direção — o contraste é o que dá vida.
 *  - Vírgula e conjunção emendam orações do mesmo pensamento. Ponto final é
 *    queda de entonação: muitos pontos seguidos soam entrecortados.
 */

export const falas = [
  // --- Ato 1: o juro que ninguém viu -------------------------------------
  // Abre em tom de conversa: é uma constatação, não uma denúncia ainda.
  { id: 1,  fala: '[rápida, animada] Seu cliente pagou juros pra comprar o seu infoproduto,' },
  { id: 2,  fala: '[curiosa] e você já parou pra pensar pra onde foi esse dinheiro?' },
  { id: 3,  fala: 'Não foi pra você. Nem foi pro banco dele.' },
  { id: 4,  fala: 'Uma venda de mil reais em doze vezes vira mil e duzentos no cartão do seu cliente —' },
  { id: 5,  fala: 'ou seja, duzentos reais a mais na sua venda, pelo seu produto,' },
  // A revelação. Muda para confiante: aqui a frase acusa.
  { id: 6,  fala: '[confiante] e esses duzentos ficam com a plataforma.' },
  { id: 7,  fala: 'O cliente pagou mil e duzentos. A sua comissão? Novecentos e cinquenta.' },
  { id: 8,  fala: 'então duzentos e cinquenta reais ficaram no meio do caminho.' },
  { id: 9,  fala: 'A sua plataforma diz que cobra cinco por cento de taxa. Mas nessa venda ela cobrou vinte e um.' },

  // --- Ato 2: por que isso é possível ------------------------------------
  // Volta pro tom de conversa: agora explica, não acusa.
  { id: 10, fala: '[rápida, conversacional] Deixa eu te explicar como funcionam as plataformas tradicionais.' },
  { id: 11, fala: 'A plataforma não processa o seu pagamento. Quem processa é uma adquirente, atrás dela.' },
  { id: 12, fala: 'Ela é uma intermediária, que compra o processamento por um preço e revende pra você por outro, muito maior.' },
  { id: 13, fala: 'Ela tem a função dela, claro: checkout, dashboard, área de membros, integrações,' },
  { id: 14, fala: '[curiosa] mas será que isso deveria custar um percentual do seu faturamento?' },

  // --- Ato 3: o Pix -------------------------------------------------------
  { id: 15, fala: '[confiante] E aí tem o Pix.' },
  { id: 16, fala: 'Processar um Pix custa em torno de meio por cento.' },
  { id: 17, fala: 'mas a plataforma cobra a taxa cheia: três, cinco, às vezes dez por cento, pra processar um simples Pix.' },
  { id: 18, fala: 'Numa venda de dez mil no Pix, você paga de trezentos a mil reais por uma operação que custa cinquenta.' },

  // --- Ato 4: o tempo -----------------------------------------------------
  { id: 19, fala: '[rápida] E ainda tem o prazo pra sacar o seu dinheiro.' },
  { id: 20, fala: 'Você se acostumou a sacar em dê mais quatorze, ou em dê mais trinta,' },
  { id: 21, fala: 'e adaptou todo o seu fluxo de caixa pra receber um mês depois da venda,' },
  // A reviravolta do ato: animada, é a primeira boa notícia do vídeo.
  { id: 22, fala: '[animada] quando o tempo todo dava pra receber em dois dias, mesmo no cartão, sem pagar antecipação.' },

  // --- Ato 5: o que isso vale --------------------------------------------
  { id: 23, fala: '[confiante] Agora junta tudo isso: o juro, a taxa inflada, o Pix caro, o dinheiro parado.' },
  { id: 24, fala: 'Entre dez e quinze por cento do seu faturamento fica no caminho.' },
  { id: 25, fala: 'e se você fatura trinta mil por mês, são três a quatro mil e quinhentos reais.' },
  { id: 26, fala: 'E isso não sai da sua receita. Sai inteiro do seu lucro.' },
  { id: 27, fala: 'Se a sua margem é de trinta por cento, trocar de plataforma coloca de trinta a cinquenta por cento de lucro a mais no seu bolso,' },
  { id: 28, fala: '[animada] vendendo exatamente o que você já vende hoje.' },

  // --- Ato 6: a saída -----------------------------------------------------
  // A marca entra. Confiante até o fim, com a virada final em animada.
  { id: 29, fala: '[confiante] A PagZero não fica no meio do caminho do seu dinheiro, e não cobra taxa nenhuma por venda: você paga uma mensalidade fixa,' },
  { id: 30, fala: 'e a venda vai direto pro seu gateway: zero de comissão, zero de custo escondido.' },
  // "Pagar Mi" com espaço: "Pagar.me" seria lido como endereço.
  { id: 31, fala: 'Você conecta o seu próprio gateway, seja Pagar Mi, Asaas, Mercado Pago, Stripe ou PayPal,' },
  { id: 32, fala: 'e o dinheiro cai inteiro na sua conta. Porque o gateway é seu, não da plataforma.' },
  { id: 33, fala: 'O juro que o seu cliente pagou volta pra onde deveria ter ido:' },
  { id: 34, fala: '[animada] pra você.' },
  { id: 35, fala: 'Clique no botão abaixo e fale com a nossa equipe.' }
]

/** O texto vai num request só: frase isolada soa como leitor de tela. */
export const textoCompleto = falas.map((f) => f.fala).join(' ')

export const VOZ = {
  id: 'HOfBIVLhom4mc9WvXfyH',        // Andrea Lot — brasileira, autoritativa
  modelo: 'eleven_v3',
  ajustes: {
    stability: 0.28,                 // baixo = mais variação de entonação (menos robótico);
                                     // <= 0.5 é obrigatório senão o v3 suprime as tags
    similarity_boost: 0.75,
    use_speaker_boost: true
    // style e speed ficam de fora de propósito: achatam a entonação.
  }
}
