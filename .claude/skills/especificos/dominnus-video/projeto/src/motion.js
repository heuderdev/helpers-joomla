import { interpolate, Easing, useCurrentFrame } from 'remotion'

/*
 * SISTEMA DE MOVIMENTO — registro premium.
 *
 * Reescrito a partir do que a prática profissional prescreve (Material Design
 * tokens, LottieFiles motion-design skill, Emil Kowalski, School of Motion,
 * Netflix Timed Text, e a análise de 7 demos de SaaS reais).
 *
 * As cinco correções contra o que eu tinha antes:
 *
 * 1. OVERSHOOT ZERO. O bounce que eu usava (spring damping 12) é assinatura do
 *    registro *playful*; premium é 350–600ms com bezier e sem ultrapassar o
 *    alvo. Overshoot marcado é o que desqualifica o look caro.
 * 2. STAGGER POR ORÇAMENTO. Eu usava 200ms fixos por item — numa lista de 4 dá
 *    800ms só de cascata. O correto é orçamento total (≤400ms) redistribuído.
 * 3. NUNCA ease-in na entrada, e nunca escalar de 0 (começa em 0.94).
 * 4. HIERARQUIA: a sequência TERMINA no elemento mais importante. Ordem
 *    canônica: superfície → texto → conteúdo → destaque.
 * 5. REGRA DE 1/3: no máximo um terço dos elementos em movimento simultâneo.
 */

const FPS = 30
export const ms = (n) => (n / 1000) * FPS

/*
 * Curvas — valores oficiais do Material 3 e do padrão de motion assistido.
 *
 * `enfase` (emphasized decelerate) é o curinga das transições de cena: arranca
 * devagar, dispara e chega com desaceleração longa. É o que soa premium.
 */
export const curvas = {
  padrao: Easing.bezier(0.4, 0, 0.2, 1),
  entrada: Easing.bezier(0.23, 1, 0.32, 1),
  saida: Easing.bezier(0.3, 0, 1, 1),
  enfase: Easing.bezier(0.05, 0.7, 0.1, 1),
  enfaseSaida: Easing.bezier(0.3, 0, 0.8, 0.15),
  movimento: Easing.bezier(0.77, 0, 0.175, 1)
}

/*
 * Durações em frames. A entrada dura 30–50% mais que a saída: o que entra
 * precisa ser lido, o que sai não.
 */
/*
 * Durações base. Reduzidas de 400ms para 260ms na entrada depois do feedback
 * de que o vídeo estava lento: 400ms é a referência de UI web, onde o usuário
 * controla o ritmo. Num Reels de 70s com oito cenas, quem controla o ritmo é o
 * vídeo — e a fala corre a ~180 ppm. Entrada de 260ms acompanha a locução;
 * 400ms fica sempre um passo atrás dela.
 */
export const dur = {
  micro: ms(80),
  curta: ms(140),
  entrada: ms(260),
  entradaLenta: ms(380),
  saida: ms(180),
  cena: ms(200)
}

/** Progresso 0→1 sem overshoot. Substitui o spring que eu usava em tudo. */
export function progresso(atraso = 0, duracao = dur.entrada, curva = curvas.padrao) {
  const frame = useCurrentFrame()
  return interpolate(frame - atraso, [0, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curva
  })
}

/**
 * Distância escala duração: 100px baseline, 200px = 1,3x, 400px = 1,6x.
 * Sem isso, um elemento que percorre 400px na mesma duração de um que percorre
 * 40px parece disparado — a velocidade aparente denuncia.
 */
export function duracaoPorDistancia(px, base = dur.entrada) {
  return base * (1 + Math.min(0.6, Math.log2(Math.max(1, px / 100)) * 0.3))
}

/**
 * Stagger por ORÇAMENTO TOTAL, não offset fixo por item.
 *
 * Com 4 itens e 400ms, cada um sai 100ms depois; com 8 itens, 50ms. A lista
 * cresce sem estourar o tempo da cena. O piso de 30ms evita "tudo junto".
 */
export function atrasoStagger(indice, total, orcamento = ms(260)) {
  if (total <= 1) return 0
  return indice * Math.max(ms(22), orcamento / total)
}

/** Escala de entrada — NUNCA de 0. Elemento que nasce do nada parece barato. */
export const escalaEntrada = (p, de = 0.94) => de + (1 - de) * p

/**
 * Moving hold: micro-movimento depois que o elemento assenta.
 * A diferença entre objeto vivo e congelado. Amplitude pequena de propósito —
 * o que se percebe não é o deslocamento, é a ausência de imobilidade.
 */
export function movingHold(indice = 0, amplitude = 3, periodo = 90) {
  const frame = useCurrentFrame()
  return Math.sin(frame / (periodo / (2 * Math.PI)) + indice * 1.3) * amplitude
}

/**
 * Blur como cola perceptual entre dois estados (Emil Kowalski).
 *
 * O valor de referência é 2px para UI web; num quadro de 1080x1920 o
 * equivalente perceptual é maior, daí o padrão de 8px. Cai a zero quando
 * assenta — blur residual em elemento parado só suja.
 */
export function blurDeMovimento(p, maximo = 8) {
  const v = (1 - p) * maximo
  return v < 0.3 ? 'none' : `blur(${v.toFixed(1)}px)`
}

/**
 * Hold de leitura a 17 caracteres por segundo (referência Netflix) + 0,5s de
 * assentamento. Serve para checar se a cena tem tempo para o texto que carrega:
 * se não tem, o problema é o roteiro, não a animação.
 */
export function framesDeLeitura(texto) {
  return Math.ceil((texto.length / 17) * FPS) + ms(500)
}

/*
 * FADE THROUGH — a alternativa institucional ao cross-fade.
 *
 * O detalhe que separa um do outro: os fades NÃO se sobrepõem. O que sai
 * termina de sair antes de o que entra começar. Nunca há dois conteúdos
 * semi-transparentes no mesmo frame — é por isso que o fade through é limpo e
 * o cross-fade é sujo.
 *
 * Spec: 0–100ms sai · 100–300ms entra escalando de 92% para 100%.
 */
export function fadeThrough(frame, duracao) {
  const saiAte = ms(100)
  const entraDe = ms(100)
  const entraAte = ms(300)

  const saindoAgora = frame < saiAte
  if (saindoAgora) {
    const p = interpolate(frame, [0, saiAte], [1, 0], { extrapolateRight: 'clamp', easing: curvas.padrao })
    return { opacidade: p, escala: 1 }
  }

  const p = interpolate(frame, [entraDe, entraAte], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.padrao
  })
  return { opacidade: p, escala: 0.92 + p * 0.08 }
}

/**
 * SHARED AXIS Y — o eixo natural do 9:16.
 *
 * Spec: fade out 0–100ms · fade in 100–300ms · translação de 30dp ao longo dos
 * 300ms inteiros. Em 1080px de largura, 30dp ≈ 90px.
 *
 * `reversivel` implementa a regra da Apple HIG: quem entra deslizando para cima
 * sai deslizando para cima; nunca o contrário.
 */
export function sharedAxisY(frame, duracao, { entrando = true, deslocamento = 90 } = {}) {
  const total = ms(300)
  const direcao = entrando ? 1 : -1

  const opacidadeEntrada = interpolate(frame, [0, ms(100), total], [0, 0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.padrao
  })

  const opacidadeSaida = interpolate(frame, [duracao - ms(100), duracao], [1, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.padrao
  })

  const desloca = interpolate(frame, [0, total], [deslocamento * direcao, 0], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  const saiPara = interpolate(frame, [duracao - ms(200), duracao], [0, -deslocamento * 0.5 * direcao], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfaseSaida
  })

  return {
    opacidade: opacidadeEntrada * opacidadeSaida,
    y: desloca + saiPara
  }
}

/*
 * CÂMERA CONTÍNUA — o que 100% das demos de SaaS analisadas usam.
 *
 * Em 5 de 7 casos é Z-punch-in: a câmera avança PARA DENTRO da cena que entra,
 * em vez de fundir duas imagens. Como o movimento é o mesmo nas duas pontas, o
 * olho lê continuidade — uma peça só, não N slides.
 *
 * O ponto que faz funcionar: a câmera nunca para completamente entre cenas.
 * Uma deriva residual sustenta a sensação de shot único.
 */
export function cameraZ(frame, duracao, { indice = 0 } = {}) {
  /*
   * TRANSIÇÃO ENTRE CENAS — quatro tipos alternados por índice.
   *
   * A primeira versão era um crossfade com 7% de zoom: tão suave que a troca
   * não se lia como corte, e num vídeo de oito cenas isso faz tudo parecer
   * lento e igual. O feedback foi exatamente esse.
   *
   * Agora cada troca tem DIREÇÃO e o corte é curto (200ms entrando, 130ms
   * saindo). O blur de velocidade acompanha o deslocamento, que é o que faz o
   * movimento ler como impulso em vez de "sumiu e apareceu".
   *
   * Alternar o tipo importa: mesmo uma transição boa vira padrão previsível
   * depois de três repetições.
   */
  const entrada = ms(200)
  const saida = ms(130)

  const entrando = interpolate(frame, [0, entrada], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfase
  })

  /*
   * A OPACIDADE sobe em ~1/3 do tempo da geometria.
   *
   * Separar as duas é o que faz o corte ler como rápido: o movimento pode
   * continuar assentando por 200ms, mas o quadro precisa estar visível quase
   * imediatamente. Com opacidade e escala na mesma curva, os primeiros frames
   * de cada cena ficam escuros e a troca parece lenta mesmo com a geometria
   * correta — foi o que apareceu no teste de transição.
   */
  const aparecendo = interpolate(frame, [0, ms(70)], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.padrao
  })

  const saindo = interpolate(frame, [duracao - saida, duracao], [0, 1], {
    extrapolateLeft: 'clamp',
    extrapolateRight: 'clamp',
    easing: curvas.enfaseSaida
  })

  /* Deriva contínua: a câmera avança ~2% ao longo da cena. Lento demais para
     ser notado como movimento, rápido o bastante para o quadro não morrer. */
  const deriva = interpolate(frame, [0, duracao], [0, 0.02], { extrapolateRight: 'clamp' })

  const tipo = indice % 4
  const e = 1 - entrando
  let escala = 1 + deriva
  let x = 0
  let y = 0

  if (tipo === 0) {
    /* PUNCH IN: entra grande e assenta. O mais enérgico — para cenas que
       começam com um objeto chegando. */
    escala = 1 + e * 0.16 + deriva + saindo * 0.06
  } else if (tipo === 1) {
    /* EMPURRA DA DIREITA: deslocamento lateral com leve recuo de escala. */
    x = e * 260 - saindo * 150
    escala = 1 - e * 0.05 + deriva
  } else if (tipo === 2) {
    /* SOBE DE BAIXO: o conteúdo é empurrado para cima, como um card novo. */
    y = e * 300 - saindo * 170
    escala = 1 - e * 0.04 + deriva
  } else {
    /* PUNCH OUT: entra pequena e cresce — inverso do tipo 0, dá a sensação de
       a câmera recuar para revelar a cena. */
    escala = 1 - e * 0.12 + deriva - saindo * 0.05
  }

  /* Blur de velocidade proporcional ao deslocamento real: quanto mais longe o
     quadro está de assentar, mais borrado. Some por completo ao parar. */
  const velocidade = Math.max(e, saindo * 0.8)

  return {
    escala,
    x,
    y,
    opacidade: aparecendo * (1 - saindo),
    blur: blurDeMovimento(1 - velocidade, 14)
  }
}

/**
 * Parallax: o que faz o movimento da câmera ser lido como MOVIMENTO, e não
 * como camadas deslizando. Fundo a 20–40% da velocidade, frente a 110–130%.
 */
export const parallax = (deslocamento, camada = 'meio') =>
  deslocamento * ({ fundo: 0.3, meio: 1, frente: 1.25 }[camada] ?? 1)
