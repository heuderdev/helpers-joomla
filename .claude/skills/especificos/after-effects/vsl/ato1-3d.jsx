/**
 * ato1-3d.jsx — o Ato 1 como um único movimento de câmera.
 *
 * As 9 falas viram 9 ESTAÇÕES espalhadas num espaço 3D. A câmera parte da
 * primeira e viaja até a última sem cortar. Cada estação é composta com a
 * linguagem do nuxt-web: painel de dashboard, notificação iOS, pílula amarela.
 *
 * Mapa do espaço (X avança para a direita, Z afasta):
 *
 *   E1 (0,0,0) ─→ E2 (2600,-200,600) ─→ E3 (5200,150,0)
 *   ─→ E4 (7900,-120,900) ─→ E5 (10600,0,0) ─→ E6 (13200,220,500)
 *   ─→ E7 (15900,-160,0) ─→ E8 (18500,0,1100) ─→ E9 (21200,120,0)
 *
 * O Z alternando é o que cria paralaxe: estações vizinhas em profundidades
 * diferentes passam umas pelas outras enquanto a câmera anda.
 */

var A13D = {};

/** Onde cada estação fica. `t` é quando a câmera CHEGA nela. */
A13D.mapa = function (cortes) {
  var c = {};
  for (var i = 0; i < cortes.length; i++) c[cortes[i].id] = cortes[i];
  return [
    { id: 1, x: 0,     y: 0,    z: 0    },
    { id: 2, x: 2600,  y: -200, z: 600  },
    { id: 3, x: 5200,  y: 150,  z: 0    },
    { id: 4, x: 7900,  y: -120, z: 900  },
    { id: 5, x: 10600, y: 0,    z: 0    },
    { id: 6, x: 13200, y: 220,  z: 500  },
    { id: 7, x: 15900, y: -160, z: 0    },
    { id: 8, x: 18500, y: 0,    z: 1100 },
    { id: 9, x: 21200, y: 120,  z: 0    }
  ];
};

// ═══════════════════════════════════════════════════ estações

/** 1 · "Seu cliente pagou juros pra comprar o seu infoproduto." */
A13D.e1 = function (comp, t) {
  var L = [];
  var xT = colEsq(0);                 // borda esquerda: texto alinha AQUI
  var xV = col(7, 5);                 // centro da coluna do visual
  var wV = larguraCol(5);

  // Pilha vertical: etiqueta · título · apoio. Os gaps saem da baseline,
  // então os três blocos ficam com o mesmo ritmo — era isso que estava
  // desalinhado (cada um tinha um offset escolhido à mão).
  var ys = pilha(base(-34), [
    { h: 22 },                        // etiqueta
    { h: 210, gap: 34 },              // título (3 linhas)
    { h: 34,  gap: 40 }               // apoio
  ]);

  var et = etiqueta(comp, "o que ninguém te conta", { pos: [xT, ys[0]], alinha: "esq" });
  revela(et, t + 0.15, { duracao: 0.5, desloc: 30 });
  L.push(et);

  var tit = titulo(comp, [
    "Seu cliente pagou",
    { destaque: "juros" },
    "pra comprar de você."
  ], { tamanho: fs("f8"), pos: [xT, ys[1]], lh: 1.34, alinha: "esq" });
  // Cada linha revelada por máscara, em cascata: o texto entra, não aparece.
  emCascata(tit, t + 0.35, 0.11, function (c, tt) {
    revela(c, tt, { duracao: 0.58, desloc: 52 });
  });
  for (var i = 0; i < tit.length; i++) L.push(tit[i]);

  var sub = corpo(comp, "E esse dinheiro a mais não voltou pra você.", {
    tamanho: fs("f3"), pos: [xT, ys[2]], alinha: "esq" });
  revela(sub, t + 0.95, { duracao: 0.52, desloc: 38 });
  L.push(sub);

  // ── coluna direita: card e notificação com a MESMA largura e alinhados
  var cd = cardDado(comp, {
    t: t + 0.5, pos: [xV, base(-16)], largura: wV, altura: 380,
    rotulo: "venda aprovada", valor: "R$ 1.000",
    icone: "cartao", corIcone: C.texto, iconeCor: "branco",
    cor: C.banda, tamanho: fs("f10"), nota: "Curso de Marketing"
  });
  for (var a = 0; a < cd.camadas.length; a++) L.push(cd.camadas[a]);

  var nf = notificacao(comp, {
    t: t + 1.45, pos: [xV, base(30)], largura: wV, altura: 124,
    titulo: "Venda aprovada", linha: "12x de R$ 100,00"
  });
  for (var k = 0; k < nf.length; k++) L.push(nf[k]);

  // Conector do card à notificação: liga os dois elementos da coluna.
  L.push(conector(comp, [xV, base(-16) + 190], [xV, base(30) - 62],
    { t: t + 1.25, duracao: 0.4, cor: C.borda }));
  return L;
};

/** 2 · "E você já parou pra pensar pra onde foi esse dinheiro?" */
A13D.e2 = function (comp, t) {
  var L = [];
  // Badge com ícone acima do título, tudo no eixo central.
  var badge = caixa(comp, {
    largura: 110, altura: 110, raio: 32, cor: C.amarelo,
    pos: [CXf, lin(-2.6)], nome: "badge-duvida" });
  L.push(badge);
  anim(badge.property("Transform").property("Scale"),
    [[t + 0.15, [40, 40]], [t + 0.4, [112, 112]], [t + 0.55, [100, 100]]], { ease: 70 });
  anim(badge.property("Transform").property("Opacity"), [[t + 0.15, 0], [t + 0.28, 100]], { ease: 60 });
  var ic = icone(comp, "duvida", { tamanho: 62, pos: [CXf, lin(-2.6)], corNome: "branco" });
  if (ic) {
    L.push(ic);
    anim(ic.property("Transform").property("Opacity"), [[t + 0.3, 0], [t + 0.45, 100]], { ease: 60 });
  }

  var tit = tituloEstacao(comp, [
    "Pra onde foi",
    { destaque: "esse dinheiro?" }
  ], { tamanho: fs("f12"), pos: [CXf, lin(0.4)], lh: 1.3, t: t + 0.45 });
  for (var i = 0; i < tit.length; i++) L.push(tit[i]);
  return L;
};

/** 3 · "Não foi pra você. Nem foi pro banco dele." */
A13D.e3 = function (comp, t) {
  var L = [];
  var itens = ["Não foi pra você.", "Nem foi pro banco dele."];
  var w = larguraCol(8);              // 8 colunas: proporcional ao texto
  var cx = col(2, 8);
  var h = 132;
  var passo = h + 34;
  var y0 = CYf - ((itens.length - 1) * passo) / 2;

  for (var i = 0; i < itens.length; i++) {
    var y = Math.round(y0 + i * passo);
    var cd = caixa(comp, {
      largura: w, altura: h, raio: 32, cor: C.fundo,
      traco: 2, corTraco: C.borda, pos: [cx, y]
    });
    L.push(cd);

    // X dentro de um círculo rosa: o mesmo vocabulário de badge dos cards.
    var bg = caixa(comp, {
      largura: 64, altura: 64, raio: 32, cor: C.vermelho,
      pos: [cx - w / 2 + 62, y], nome: "xbg" + i });
    L.push(bg);
    var xs = comp.layers.addShape(); xs.name = "x" + i;
    for (var k = 0; k < 2; k++) {
      var g = xs.property("Contents").addProperty("ADBE Vector Group");
      var pa = g.property("Contents").addProperty("ADBE Vector Shape - Group");
      var s = new Shape();
      s.vertices = k === 0 ? [[-13, -13], [13, 13]] : [[13, -13], [-13, 13]];
      s.inTangents = [[0,0],[0,0]]; s.outTangents = [[0,0],[0,0]]; s.closed = false;
      pa.property("Path").setValue(s);
      var st = g.property("Contents").addProperty("ADBE Vector Graphic - Stroke");
      st.property("Color").setValue(hex("#FFFFFF"));
      st.property("Stroke Width").setValue(6);
      st.property("Line Cap").setValue(2);
    }
    xs.property("Transform").property("Position").setValue([cx - w / 2 + 62, y]);
    L.push(xs);

    // Texto à ESQUERDA, logo após o badge — não centralizado no card.
    var tx = txt(comp, itens[i], {
      tamanho: fs("f6"), peso: "book", cor: C.texto,
      pos: [cx - w / 2 + 124, y], alinha: "esq" });
    L.push(tx);

    var d = t + 0.3 + i * 0.4;
    infla(cd, d, { duracao: 0.5 });
    anim(bg.property("Transform").property("Scale"),
      [[d + 0.22, [30, 30]], [d + 0.4, [112, 112]], [d + 0.52, [100, 100]]], { ease: 70 });
    anim(bg.property("Transform").property("Opacity"), [[d + 0.22, 0], [d + 0.32, 100]], { ease: 60 });
    anim(xs.property("Transform").property("Opacity"), [[d + 0.34, 0], [d + 0.44, 100]], { ease: 60 });
    revela(tx, d + 0.3, { duracao: 0.5, desloc: 40 });
  }
  return L;
};

/** 4 · "Mil reais em 12x vira mil e duzentos no cartão." */
A13D.e4 = function (comp, t) {
  var L = [];
  var p = painel(comp, { largura: 1500, altura: 660, pos: [CXf, CYf + 20] });
  L.push(p.fundo);

  L.push(etiqueta(comp, "venda em 12x no cartão", { pos: [CXf - 330, CYf - 240] }));

  // O valor antigo riscado, como o site trata preço anterior.
  var velho = txt(comp, "R$ 1.000", {
    tamanho: fs("f9"), peso: "book", cor: C.textoFraco, pos: [CXf - 330, CYf - 130] });
  L.push(velho);
  var risco = caixa(comp, { largura: 250, altura: 5, raio: 3, cor: C.textoFraco,
    pos: [CXf - 330, CYf - 142] });
  L.push(risco);
  var gr = risco._grupo;
  gr.property("Transform").property("Anchor Point").setValue([-125, 0]);
  gr.property("Transform").property("Position").setValue([-125, 0]);
  anim(gr.property("Transform").property("Scale"),
    [[t + 0.9, [0, 100]], [t + 1.35, [100, 100]]], { ease: 66 });

  var c = contador(comp, {
    de: 1000, para: 1200, tamanho: fs("f13"), peso: "black",
    cor: C.texto, pos: [CXf - 330, CYf + 40], inicio: t + 0.8, duracao: 1.5
  });
  anim(c.property("Transform").property("Scale"),
    [[t + 2.3, [100, 100]], [t + 2.45, [108, 108]], [t + 2.62, [100, 100]]], { ease: 66 });
  L.push(c);

  // O gráfico do hero, à direita: mostra a venda no painel.
  var bars = graficoBarras(comp, {
    t: t + 0.5, largura: 480, altura: 260, pos: [CXf + 400, CYf + 10]
  });
  for (var i = 0; i < bars.length; i++) L.push(bars[i]);
  L.push(etiqueta(comp, "o que ele realmente pagou", { pos: [CXf + 400, CYf + 215] }));
  return L;
};

/** 5 · "São duzentos reais a mais na sua venda." */
A13D.e5 = function (comp, t) {
  var L = [];
  var tit = titulo(comp, [{ destaque: "R$ 200 a mais" }],
    { tamanho: fs("f13"), pos: [CXf, CYf - 40], peso: "black" });
  for (var i = 0; i < tit.length; i++) {
    // Bate de cima: cai rápido e assenta com squash.
    var P = tit[i].property("Transform").property("Position");
    var v = P.value;
    anim(P, [[t + 0.1, [v[0], v[1] - 260]], [t + 0.42, v]], { ease: 34 });
    anim(tit[i].property("Transform").property("Opacity"),
      [[t + 0.1, 0], [t + 0.24, 100]], { ease: 50 });
    var S = tit[i].property("Transform").property("Scale");
    var e = S.value;
    anim(S, [[t + 0.42, e], [t + 0.5, [e[0] * 1.1, e[1] * 0.9]],
             [t + 0.62, [e[0] * 0.97, e[1] * 1.04]], [t + 0.74, e]], { ease: 58 });
    L.push(tit[i]);
  }
  var linhas = ["Na sua venda.", "Pelo seu produto."];
  for (var k = 0; k < linhas.length; k++) {
    var l = txt(comp, linhas[k], {
      tamanho: fs("f6"), peso: "book", cor: C.textoSuave, pos: [CXf, CYf + 170 + k * 86] });
    var vp = l.property("Transform").property("Position").value;
    anim(l.property("Transform").property("Position"),
      [[t + 0.8 + k * 0.2, [vp[0], vp[1] + 40]], [t + 1.2 + k * 0.2, vp]], { ease: 74 });
    anim(l.property("Transform").property("Opacity"),
      [[t + 0.8 + k * 0.2, 0], [t + 1.05 + k * 0.2, 100]], { ease: 60 });
    L.push(l);
  }
  return L;
};

/** 6 · "E esses duzentos ficam com a plataforma." */
A13D.e6 = function (comp, t) {
  var L = [];
  var tit = tituloEstacao(comp, [
    "E esses R$ 200 ficam",
    { destaque: "com a plataforma." }
  ], { tamanho: fs("f11"), pos: [CXf, CYf], lh: 1.4, t: t + 0.15,
       corPilula: C.vermelho });
  for (var i = 0; i < tit.length; i++) {
    if (tit[i]._pilulaDe) {
      var d = tit[i]._pilulaDe.property("Source Text").value;
      d.fillColor = hex("#FFFFFF");
      tit[i]._pilulaDe.property("Source Text").setValue(d);
    }
    L.push(tit[i]);
  }
  return L;
};

/** 7 · "O cliente pagou 1.200, sua comissão foi 950." */
A13D.e7 = function (comp, t) {
  var L = [];
  // Grid: cada card ocupa 4 colunas, com 4 de vão no meio para a seta.
  var esq = col(0, 5), dir = col(7, 5);
  var w = larguraCol(5), h = 440;

  var c1 = cardDado(comp, {
    t: t + 0.2, pos: [esq, CYf], largura: w, altura: h,
    rotulo: "o cliente pagou", valor: "R$ 1.200",
    icone: "cartao", corIcone: C.texto, iconeCor: "branco",
    cor: C.banda, tamanho: fs("f11")
  });
  for (var a = 0; a < c1.camadas.length; a++) L.push(c1.camadas[a]);

  var c2 = cardDado(comp, {
    t: t + 0.62, pos: [dir, CYf], largura: w, altura: h,
    rotulo: "você recebeu", valor: "R$ 950",
    icone: "dinheiro", corIcone: C.vermelho, iconeCor: "branco",
    cor: C.fundo, traco: 3, corTraco: C.vermelho,
    corValor: C.vermelho, tamanho: fs("f11")
  });
  for (var b = 0; b < c2.camadas.length; b++) L.push(c2.camadas[b]);

  // Seta ligando os dois, no eixo do valor.
  var st = seta(comp, [esq + w / 2, CYf + 60], [dir - w / 2, CYf + 60], { t: t + 1.15 });
  for (var s2 = 0; s2 < st.length; s2++) L.push(st[s2]);

  // Moedas percorrendo a seta: o dinheiro andando, agora no eixo certo.
  for (var m = 0; m < 3; m++) {
    var mo = ilustra(comp, "moeda", { largura: 58, pos: [esq + w / 2 + 40, CYf + 60] });
    if (!mo) continue;
    var dm = t + 1.35 + m * 0.2;
    anim(mo.property("Transform").property("Position"), [
      [dm,        [esq + w / 2 + 40, CYf + 60]],
      [dm + 0.34, [CXf, CYf - 10 - m * 16]],
      [dm + 0.7,  [dir - w / 2 - 40, CYf + 60]]
    ], { ease: 32 });
    mo.property("Transform").property("Rotation").expression =
      "var t0=" + dm + "; (time-t0)*280";
    anim(mo.property("Transform").property("Opacity"),
      [[dm, 0], [dm + 0.1, 100], [dm + 0.58, 100], [dm + 0.7, 0]], { ease: 50 });
    L.push(mo);
  }

  // A diferença, embaixo e centralizada: fecha a leitura da cena.
  var dif = txt(comp, "R$ 250 ficaram no caminho", {
    tamanho: fs("f5"), peso: "medium", cor: C.vermelho, pos: [CXf, CYf + 330] });
  var vd = dif.property("Transform").property("Position").value;
  anim(dif.property("Transform").property("Position"),
    [[t + 2.1, [vd[0], vd[1] + 40]], [t + 2.5, vd]], { ease: 74 });
  anim(dif.property("Transform").property("Opacity"), [[t + 2.1, 0], [t + 2.35, 100]], { ease: 60 });
  L.push(dif);
  return L;
};

/** 8 · "Duzentos e cinquenta reais ficaram no meio do caminho." */
A13D.e8 = function (comp, t) {
  var L = [];
  var tit = titulo(comp, [{ destaque: "R$ 250" }],
    { tamanho: fs("f13") + 20, pos: [CXf, CYf - 50], peso: "black",
      corPilula: C.vermelho });
  for (var i = 0; i < tit.length; i++) {
    if (tit[i]._pilulaDe) {
      var d = tit[i]._pilulaDe.property("Source Text").value;
      d.fillColor = hex("#FFFFFF");
      tit[i]._pilulaDe.property("Source Text").setValue(d);
    }
    var P = tit[i].property("Transform").property("Position");
    var v = P.value;
    anim(P, [[t + 0.1, [v[0], v[1] - 280]], [t + 0.4, v]], { ease: 32 });
    anim(tit[i].property("Transform").property("Opacity"),
      [[t + 0.1, 0], [t + 0.22, 100]], { ease: 50 });
    L.push(tit[i]);
  }
  var sub = txt(comp, "sumiram no meio do caminho.", {
    tamanho: fs("f6"), peso: "book", cor: C.textoSuave, pos: [CXf, CYf + 180] });
  var vs = sub.property("Transform").property("Position").value;
  anim(sub.property("Transform").property("Position"),
    [[t + 0.7, [vs[0], vs[1] + 40]], [t + 1.1, vs]], { ease: 74 });
  anim(sub.property("Transform").property("Opacity"), [[t + 0.7, 0], [t + 0.95, 100]], { ease: 60 });
  L.push(sub);
  return L;
};

/** 9 · "Ela diz que cobra 5%, mas nessa venda cobrou 21%." */
A13D.e9 = function (comp, t) {
  var L = [];
  var esq = col(0, 5), dir = col(7, 5);
  var w = larguraCol(5), h = 460;

  var c1 = cardDado(comp, {
    t: t + 0.25, pos: [esq, CYf], largura: w, altura: h,
    rotulo: "taxa anunciada", contador: 5, prefixo: "", sufixo: "%",
    icone: "porcentagem", corIcone: C.textoFraco, iconeCor: "branco",
    cor: C.banda, corValor: C.textoFraco, tamanho: fs("f12"), durContador: 0.7
  });
  for (var a = 0; a < c1.camadas.length; a++) L.push(c1.camadas[a]);

  var c2 = cardDado(comp, {
    t: t + 1.15, pos: [dir, CYf], largura: w, altura: h,
    rotulo: "taxa real nesta venda", contador: 21, prefixo: "", sufixo: "%",
    icone: "porcentagem", corIcone: C.vermelho, iconeCor: "branco",
    cor: C.fundo, traco: 3, corTraco: C.vermelho,
    corValor: C.vermelho, tamanho: fs("f12"), durContador: 1.2
  });
  for (var b = 0; b < c2.camadas.length; b++) L.push(c2.camadas[b]);

  anim(c2.base.property("Transform").property("Scale"),
    [[t + 2.6, [100, 100]], [t + 2.78, [104, 104]], [t + 2.98, [100, 100]]], { ease: 64 });

  var quatro = txt(comp, "quatro vezes mais", {
    tamanho: fs("f5"), peso: "medium", cor: C.vermelho, pos: [CXf, CYf + 340] });
  var vq = quatro.property("Transform").property("Position").value;
  anim(quatro.property("Transform").property("Position"),
    [[t + 2.9, [vq[0], vq[1] + 40]], [t + 3.3, vq]], { ease: 74 });
  anim(quatro.property("Transform").property("Opacity"), [[t + 2.9, 0], [t + 3.15, 100]], { ease: 60 });
  L.push(quatro);
  return L;
};
