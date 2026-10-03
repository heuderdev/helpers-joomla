/**
 * motion.jsx — os princípios de animação aplicados como funções.
 *
 * O que separa "elemento aparecendo" de motion de verdade:
 *
 *  ANTECIPAÇÃO   antes de subir, o elemento desce um pouco. Sem isso o
 *                movimento nasce do nada e lê como corte, não como gesto.
 *  OVERSHOOT     ele passa do alvo e volta. Parar exatamente no alvo é o que
 *                faz animação de script parecer robótica.
 *  FOLLOW-THROUGH as partes secundárias chegam DEPOIS da principal (a cabeça
 *                acompanha o corpo com atraso), nunca junto.
 *  ARCO          nada se move em linha reta: um leve desvio lateral no meio
 *                do percurso é o que dá naturalidade.
 *  AÇÃO SECUNDÁRIA  o gesto de apoio que não é o assunto (a mão que balança
 *                enquanto a cabeça vira) — é o que dá vida.
 */

// ---------------------------------------------------------------- curvas

/** Overshoot: vai além do alvo e assenta. `forca` em % do trajeto. */
function chegaComOvershoot(prop, t, de, ate, dur, forca) {
  forca = forca === undefined ? 0.12 : forca;
  var over = [];
  var ehArray = ate instanceof Array;
  if (ehArray) {
    over = [];
    for (var i = 0; i < ate.length; i++) over.push(ate[i] + (ate[i] - de[i]) * forca);
  } else {
    over = ate + (ate - de) * forca;
  }
  anim(prop, [
    [t, de],
    [t + dur * 0.62, over],
    [t + dur, ate]
  ], { ease: 68 });
  return prop;
}

/**
 * Entrada completa de um elemento: antecipação → percurso em arco →
 * overshoot. É o gesto padrão da VSL.
 */
function entraVivo(camada, t, o) {
  o = o || {};
  var dur = o.duracao || 0.62;
  var dist = o.dist === undefined ? 90 : o.dist;
  var dir = o.dir || "baixo";          // baixo | cima | esq | dir
  var P = camada.property("Transform").property("Position");
  var alvo = P.value;
  var de = [alvo[0], alvo[1]];
  var ant = [alvo[0], alvo[1]];        // pose de antecipação

  if (dir === "baixo")      { de[1] += dist;  ant[1] += dist * 1.16; }
  else if (dir === "cima")  { de[1] -= dist;  ant[1] -= dist * 1.16; }
  else if (dir === "esq")   { de[0] -= dist;  ant[0] -= dist * 1.16; }
  else                      { de[0] += dist;  ant[0] += dist * 1.16; }

  // Arco: no meio do percurso o elemento desvia de lado.
  var meio = [
    alvo[0] + (dir === "baixo" || dir === "cima" ? (o.arco === undefined ? 14 : o.arco) : 0),
    alvo[1] + (dir === "esq" || dir === "dir" ? (o.arco === undefined ? -12 : -o.arco) : 0)
  ];
  var over = [
    alvo[0] + (alvo[0] - de[0]) * 0.10,
    alvo[1] + (alvo[1] - de[1]) * 0.10
  ];

  anim(P, [
    [t,               ant],                 // antecipação: recua
    [t + 0.09,        de],
    [t + dur * 0.55,  meio],                // arco
    [t + dur * 0.82,  over],                // overshoot
    [t + dur + 0.10,  alvo]                 // assenta
  ], { ease: 72 });

  anim(camada.property("Transform").property("Opacity"),
    [[t, 0], [t + dur * 0.42, 100]], { ease: 60 });
  return camada;
}

/** Pop com antecipação: encolhe antes de crescer. */
function popVivo(camada, t, o) {
  o = o || {};
  var dur = o.duracao || 0.6;
  var S = camada.property("Transform").property("Scale");
  var e = S.value;
  anim(S, [
    [t,              [e[0] * 0.55, e[1] * 0.55]],
    [t + dur * 0.30, [e[0] * 0.48, e[1] * 0.48]],   // antecipação: encolhe mais
    [t + dur * 0.68, [e[0] * 1.10, e[1] * 1.10]],   // overshoot
    [t + dur * 0.86, [e[0] * 0.97, e[1] * 0.97]],   // recuo
    [t + dur,        [e[0], e[1]]]
  ], { ease: 70 });
  anim(camada.property("Transform").property("Opacity"),
    [[t, 0], [t + dur * 0.28, 100]], { ease: 60 });
  return camada;
}

/** Squash & stretch vertical na chegada — para coisa que "cai". */
function aterrissa(camada, t, o) {
  o = o || {};
  var S = camada.property("Transform").property("Scale");
  var e = S.value;
  anim(S, [
    [t,        [e[0], e[1]]],
    [t + 0.08, [e[0] * 1.14, e[1] * 0.86]],   // squash no impacto
    [t + 0.20, [e[0] * 0.94, e[1] * 1.07]],   // stretch de volta
    [t + 0.34, [e[0], e[1]]]
  ], { ease: 60 });
  return camada;
}

// ---------------------------------------------------------------- personagem

/**
 * Monta o personagem em partes, com hierarquia de pais.
 * A ordem importa: corpo é o pai, cabeça filha do corpo, rosto filho da
 * cabeça. Assim mover o corpo move tudo, e a cabeça pode virar sozinha.
 *
 * Devolve { corpo, cabeca, rosto, braco, camadas }.
 */
function personagem(comp, o) {
  o = o || {};
  var alt = o.altura || 620;
  var pos = o.pos || [CXf - 560, CYf + 60];
  var rosto = o.rosto || "preocupado";
  var L = [];

  // Todas as peças compartilham o viewBox 420x760 e já vêm desenhadas na
  // posição final: basta empilhar no MESMO ponto, mesma escala. Foi isso que
  // resolveu o personagem quebrado — não há offset a calcular.
  function peca(nome) {
    return ilustra(comp, nome, { altura: alt, pos: pos });
  }

  var sombra = peca("p-sombra");
  var braco  = peca("p-braco");
  var corpo  = peca("p-corpo");
  var cabeca = peca("p-cabeca");
  var face   = peca("p-rosto-" + rosto);
  if (!corpo) return null;

  // Ordem de pilha: sombra atrás, braço atrás do corpo, rosto na frente.
  var camadas = [face, cabeca, corpo, braco, sombra];
  for (var i = 0; i < camadas.length; i++) if (camadas[i]) L.push(camadas[i]);

  // Hierarquia: mover o corpo move tudo; a cabeça ainda gira sozinha.
  try {
    if (face && cabeca) face.parent = cabeca;
    if (cabeca) cabeca.parent = corpo;
    if (braco) braco.parent = corpo;
    if (sombra) sombra.parent = corpo;
  } catch (e) {}

  var esc = (alt / 760) * 100;

  // Âncora da cabeça no PESCOÇO (y=330 no viewBox), não no centro: é o que
  // faz a cabeça girar em vez de deslizar.
  if (cabeca) {
    try {
      var a = cabeca.property("Transform").property("Anchor Point").value;
      var dy = 330 - 380;                       // pescoço - centro do viewBox
      cabeca.property("Transform").property("Anchor Point").setValue([a[0], a[1] + dy]);
      var pc = cabeca.property("Transform").property("Position").value;
      cabeca.property("Transform").property("Position").setValue(
        [pc[0], pc[1] + dy * (esc / 100)]);
    } catch (e2) {}
  }
  // Âncora do braço no OMBRO (316, 402).
  if (braco) {
    try {
      var ab = braco.property("Transform").property("Anchor Point").value;
      var dx = 316 - 210, dyb = 402 - 380;
      braco.property("Transform").property("Anchor Point").setValue([ab[0] + dx, ab[1] + dyb]);
      var pb = braco.property("Transform").property("Position").value;
      braco.property("Transform").property("Position").setValue(
        [pb[0] + dx * (esc / 100), pb[1] + dyb * (esc / 100)]);
    } catch (e3) {}
  }

  return { corpo: corpo, cabeca: cabeca, rosto: face, braco: braco,
           sombra: sombra, camadas: L, escala: esc };
}

/**
 * Respiração ociosa: o personagem nunca fica parado.
 * O corpo sobe e desce de leve; a cabeça acompanha com ATRASO (follow-through)
 * e amplitude menor — é o atraso que faz parecer peso, não bloco rígido.
 */
function respira(pers, o) {
  o = o || {};
  var forca = o.forca || 7;
  var vel = o.vel || 1.05;
  if (pers.corpo) {
    var P = pers.corpo.property("Transform").property("Position");
    var v = P.value;
    P.expression =
      "[" + v[0] + ", " + v[1] + " + Math.sin(time*" + vel + ")*" + forca + "]";
  }
  if (pers.cabeca) {
    // Atraso de 0.18s e 60% da amplitude: follow-through.
    pers.cabeca.property("Transform").property("Rotation").expression =
      "Math.sin((time-0.18)*" + vel + ")*" + (o.giro || 1.6);
  }
  if (pers.braco) {
    pers.braco.property("Transform").property("Rotation").expression =
      "Math.sin((time-0.26)*" + vel + ")*" + (o.bracoGiro || 2.4);
  }
  return pers;
}

/** A cabeça vira para um lado e volta — reação. */
function olhaPara(pers, t, graus, o) {
  o = o || {};
  if (!pers.cabeca) return pers;
  var R = pers.cabeca.property("Transform").property("Rotation");
  var dur = o.duracao || 0.5;
  R.expression = "";     // a expression de respiração cederia lugar
  anim(R, [
    [t,              0],
    [t + 0.10,       -graus * 0.22],     // antecipação: vira ao contrário
    [t + dur * 0.7,  graus * 1.10],      // overshoot
    [t + dur,        graus],
    [t + dur + (o.segura || 1.1), graus],
    [t + dur + (o.segura || 1.1) + 0.42, 0]
  ], { ease: 70 });
  return pers;
}

/** O braço sobe (apontar, mostrar a placa). */
function levantaBraco(pers, t, graus, o) {
  o = o || {};
  if (!pers.braco) return pers;
  var R = pers.braco.property("Transform").property("Rotation");
  R.expression = "";
  var dur = o.duracao || 0.55;
  anim(R, [
    [t,             0],
    [t + 0.11,      graus * 0.16],       // antecipação
    [t + dur * 0.72, -graus * 1.12],     // overshoot
    [t + dur,       -graus],
    [t + dur + (o.segura || 1.4), -graus],
    [t + dur + (o.segura || 1.4) + 0.5, 0]
  ], { ease: 70 });
  return pers;
}

// ---------------------------------------------------------------- continuidade

/**
 * A LINHA que atravessa a cena e leva para a próxima.
 * É o gesto de continuidade: o olho segue a linha e o corte deixa de ser
 * corte. Desenha-se com Trim Paths, então ela é traçada, não aparece pronta.
 */
function linhaGuia(comp, t, o) {
  o = o || {};
  var pontos = o.pontos || [[0, CYf], [W, CYf]];
  var sh = comp.layers.addShape();
  sh.name = o.nome || "linha-guia";
  var g = sh.property("Contents").addProperty("ADBE Vector Group");
  var pa = g.property("Contents").addProperty("ADBE Vector Shape - Group");
  var s = new Shape();
  var vs = [], ins = [], outs = [];
  for (var i = 0; i < pontos.length; i++) {
    vs.push([pontos[i][0] - CXf, pontos[i][1] - H / 2]);
    // Tangentes horizontais: a linha vira curva, não zigue-zague.
    var tg = (o.curva === undefined ? 120 : o.curva);
    ins.push([-tg, 0]); outs.push([tg, 0]);
  }
  s.vertices = vs; s.inTangents = ins; s.outTangents = outs; s.closed = false;
  pa.property("Path").setValue(s);
  var st = g.property("Contents").addProperty("ADBE Vector Graphic - Stroke");
  st.property("Color").setValue(hex(o.cor || C.amarelo));
  st.property("Stroke Width").setValue(o.espessura || 5);
  st.property("Line Cap").setValue(2);

  // Trim: a linha se DESENHA, e depois o rabo alcança a cabeça e ela some.
  var tr = g.property("Contents").addProperty("ADBE Vector Filter - Trim");
  var dur = o.duracao || 1.0;
  anim(tr.property("End"),   [[t, 0], [t + dur, 100]], { ease: 62 });
  anim(tr.property("Start"), [[t + dur * 0.42, 0], [t + dur * 1.35, 100]], { ease: 62 });

  sh.property("Transform").property("Position").setValue([CXf, H / 2]);
  sh.inPoint = t - 0.1;
  sh.outPoint = t + dur * 1.4 + 0.2;
  return sh;
}

/** Ponto que corre sobre a linha — leva o olho junto. */
function pontoQueCorre(comp, t, o) {
  o = o || {};
  var de = o.de || [0, CYf], ate = o.ate || [W, CYf];
  var dur = o.duracao || 1.0;
  var c = caixa(comp, {
    largura: o.tamanho || 22, altura: o.tamanho || 22,
    raio: (o.tamanho || 22) / 2, cor: o.cor || C.amarelo,
    pos: de, nome: "ponto"
  });
  // Arco no meio: reta pura lê como máquina.
  var meio = [(de[0] + ate[0]) / 2, (de[1] + ate[1]) / 2 - (o.arco || 90)];
  anim(c.property("Transform").property("Position"),
    [[t, de], [t + dur * 0.5, meio], [t + dur, ate]], { ease: 45 });
  anim(c.property("Transform").property("Opacity"),
    [[t, 0], [t + 0.14, 100], [t + dur - 0.1, 100], [t + dur, 0]], { ease: 50 });
  c.inPoint = t - 0.1; c.outPoint = t + dur + 0.2;
  return c;
}

/** Moeda que cai e quica — a "perda" ganha corpo físico. */
function moedaCai(comp, t, o) {
  o = o || {};
  var m = ilustra(comp, "moeda", { largura: o.tamanho || 110, pos: o.pos || [CXf, CYf] });
  if (!m) return null;
  var alvo = m.property("Transform").property("Position").value;
  var P = m.property("Transform").property("Position");
  var altura = o.altura || 420;
  anim(P, [
    [t,          [alvo[0], alvo[1] - altura]],
    [t + 0.42,   [alvo[0], alvo[1]]],
    [t + 0.60,   [alvo[0] + 8, alvo[1] - 62]],   // quica
    [t + 0.78,   [alvo[0] + 12, alvo[1]]],
    [t + 0.90,   [alvo[0] + 14, alvo[1] - 18]],
    [t + 1.02,   [alvo[0] + 16, alvo[1]]]
  ], { ease: 40 });
  m.property("Transform").property("Rotation").expression =
    "var t0=" + t + "; (time-t0)*" + (o.giro || 210);
  aterrissa(m, t + 0.42);
  anim(m.property("Transform").property("Opacity"), [[t, 0], [t + 0.1, 100]], { ease: 50 });
  return m;
}

// ---------------------------------------------------------------- texto

/**
 * Kinetic typography: as palavras entram uma a uma.
 * `texto` vira N camadas, cada uma com atraso — é o gesto que mais prende
 * atenção em VSL, porque o olho lê no ritmo da fala.
 */
function palavraAPalavra(comp, texto, t, o) {
  o = o || {};
  var palavras = texto.split(" ");
  var tam = o.tamanho || fs("f10");
  var passo = o.passo === undefined ? 0.09 : o.passo;
  var largTotal = 0, larguras = [];
  // Estimativa de largura: medir cada uma seria N chamadas de layout.
  for (var i = 0; i < palavras.length; i++) {
    var w = palavras[i].length * tam * 0.52 + tam * 0.28;
    larguras.push(w); largTotal += w;
  }
  var x = (o.pos ? o.pos[0] : CXf) - largTotal / 2;
  var y = o.pos ? o.pos[1] : CYf;
  var out = [];
  for (var k = 0; k < palavras.length; k++) {
    var cx = x + larguras[k] / 2;
    var l = txt(comp, palavras[k], {
      tamanho: tam, peso: o.peso || "book", cor: o.cor || C.texto, pos: [cx, y]
    });
    entraVivo(l, t + k * passo, { dist: 34, duracao: 0.46, arco: 6 });
    out.push(l);
    x += larguras[k];
  }
  return out;
}
