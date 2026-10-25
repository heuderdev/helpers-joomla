/**
 * blocos.jsx — os 26 blocos visuais da VSL.
 *
 * BLOCOS[nome](comp, props, ctx) -> array de camadas.
 * ctx = { t: início da cena, dur: duração, i: índice, cena: objeto do roteiro }
 *
 * Cada bloco só DESENHA e devolve as camadas; quem anima entrada/saída e
 * recorta no tempo é o montador. Assim o gesto fica consistente no vídeo todo.
 */

var BLOCOS = {};

// ---------------------------------------------------------------- Ato 1

/** Frase de 1-2 linhas, com uma palavra em destaque. */
BLOCOS.frase = function (comp, p, ctx) {
  var L = [];
  // Personagem à esquerda, texto à direita: composição da referência.
  var pe = ilustraViva(comp, p.pessoa || "pessoa-preocupada", ctx.t + 0.1,
    { altura: 520, pos: [CXf - 560, CYf + 40] });
  if (pe) L.push(pe);
  var tx = txtRealce(comp, p.texto, p.realce, { tamanho: 76, pos: [CXf + 210, CYf] });
  for (var i = 0; i < tx.length; i++) L.push(tx[i]);
  return L;
};

/** Pergunta retórica: maior, respirando. */
BLOCOS.pergunta = function (comp, p, ctx) {
  var L = txtRealce(comp, p.texto, null, {
    tamanho: 104, cor: p.tom === "amarelo" ? C.amarelo : C.texto, pos: [CXf, CYf + 20]
  });
  if (ctx.cena.icone) {
    var ic = icone(comp, ctx.cena.icone, { tamanho: 96, pos: [CXf, CYf - 270] });
    if (ic) L.push(ic);
  }
  return L;
};

/** Itens negados, cada um com um X à esquerda. */
BLOCOS.negacao = function (comp, p, ctx) {
  var L = [];
  var il = ilustraViva(comp, "duvida", ctx.t + 0.1, { largura: 340, pos: [CXf - 620, CYf - 60] });
  if (il) L.push(il);
  var passo = 150;
  var topo = CYf - ((p.itens.length - 1) * passo) / 2;
  for (var i = 0; i < p.itens.length; i++) {
    var y = Math.round(topo + i * passo);
    var x = comp.layers.addShape();
    x.name = "x" + i;
    var g = x.property("Contents").addProperty("ADBE Vector Group");
    var pa = g.property("Contents").addProperty("ADBE Vector Shape - Group");
    var s = new Shape();
    s.vertices = [[-22, -22], [22, 22]]; s.inTangents = [[0,0],[0,0]];
    s.outTangents = [[0,0],[0,0]]; s.closed = false;
    pa.property("Path").setValue(s);
    var st = g.property("Contents").addProperty("ADBE Vector Graphic - Stroke");
    st.property("Color").setValue(hex(C.vermelho)); st.property("Stroke Width").setValue(7);
    st.property("Line Cap").setValue(2);
    var g2 = x.property("Contents").addProperty("ADBE Vector Group");
    var pa2 = g2.property("Contents").addProperty("ADBE Vector Shape - Group");
    var s2 = new Shape();
    s2.vertices = [[22, -22], [-22, 22]]; s2.inTangents = [[0,0],[0,0]];
    s2.outTangents = [[0,0],[0,0]]; s2.closed = false;
    pa2.property("Path").setValue(s2);
    var st2 = g2.property("Contents").addProperty("ADBE Vector Graphic - Stroke");
    st2.property("Color").setValue(hex(C.vermelho)); st2.property("Stroke Width").setValue(7);
    st2.property("Line Cap").setValue(2);
    x.property("Transform").property("Position").setValue([CXf - 400, y]);
    L.push(x);
    L.push(txt(comp, p.itens[i], { tamanho: 68, pos: [CXf + 40, y] }));
  }
  return L;
};

/** R$ 1.000 -> R$ 1.200 em 12x: o contador é a estrela. */
BLOCOS.parcelamento = function (comp, p, ctx) {
  var L = [];
  L.push(aneis(comp, { pos: [CXf + 200, CYf - 30], base: 430, passo: 165, forca: 26 }));
  var ic = ilustraViva(comp, "cartao", ctx.t + 0.15, { largura: 560, pos: [CXf - 520, CYf + 10] });
  if (ic) L.push(ic);
  L.push(etiqueta(comp, "venda em " + p.parcelas + "x", { pos: [CXf, CYf - 230] }));
  var c = contador(comp, {
    de: p.de, para: p.para, tamanho: 210, cor: C.amarelo,
    pos: [CXf, CYf - 40], inicio: ctx.t + 0.7, duracao: 1.3
  });
  L.push(c);
  L.push(txt(comp, "no cartão do seu cliente", {
    tamanho: 52, cor: C.textoFraco, pos: [CXf, CYf + 130]
  }));
  var ln = linha(comp, { largura: 520, espessura: 5, pos: [CXf, CYf + 55] });
  anim(ln._grupo.property("Transform").property("Scale"),
    [[ctx.t + 0.5, [0, 100]], [ctx.t + 1.4, [100, 100]]], { ease: 75 });
  L.push(ln);
  return L;
};

/** O martelo: um número grande que assenta, com linhas de apoio. */
BLOCOS.martelo = function (comp, p, ctx) {
  var cor = p.tom === "vermelho" ? C.vermelho : C.amarelo;
  var L = [];
  L.push(aneis(comp, { pos: [CXf, CYf - 60], base: 450, passo: 175, cor: cor, forca: 32 }));
  var d = txt(comp, p.destaque, { tamanho: 168, cor: cor, pos: [CXf, CYf - 60] });
  L.push(d);
  if (p.linhas) {
    for (var i = 0; i < p.linhas.length; i++) {
      L.push(txt(comp, p.linhas[i], {
        tamanho: 60, cor: C.texto, pos: [CXf, CYf + 90 + i * 82]
      }));
    }
  }
  return L;
};

/** Frase de impacto, tela quase vazia. */
BLOCOS.impacto = function (comp, p, ctx) {
  var cor = p.tom === "vermelho" ? C.vermelho : (p.tom === "amarelo" ? C.amarelo : C.texto);
  var L = [];
  // Anéis atrás da frase: o impacto precisa de energia, não de tela vazia.
  var an = aneis(comp, { pos: [CXf, CYf], base: 420, passo: 170, cor: cor, forca: 34 });
  L.push(an);
  var tx = txtRealce(comp, p.texto, null, {
    tamanho: p.gigante ? 168 : 116, cor: cor, pos: [CXf, CYf]
  });
  for (var i = 0; i < tx.length; i++) L.push(tx[i]);
  return L;
};

/** Fluxo do dinheiro: pagou X -> recebeu Y, com seta entre os dois. */
BLOCOS.fluxo = function (comp, p, ctx) {
  var L = [];
  var esq = CXf - 420, dir = CXf + 420;

  var i1 = iconeVivo(comp, "cartao", ctx.t + 0.25, { tamanho: 76, pos: [esq, CYf - 250] });
  if (i1) L.push(i1);
  var i2 = iconeVivo(comp, "dinheiro", ctx.t + 1.35, { tamanho: 76, pos: [dir, CYf - 250], corNome: "vermelho" });
  if (i2) L.push(i2);
  L.push(etiqueta(comp, "o cliente pagou", { pos: [esq, CYf - 150] }));
  L.push(txt(comp, "R$ " + fmt(p.pagou || 1200), {
    tamanho: 128, cor: C.texto, pos: [esq, CYf - 20] }));

  L.push(etiqueta(comp, "você recebeu", { pos: [dir, CYf - 150] }));
  L.push(txt(comp, "R$ " + fmt(p.recebeu || 950), {
    tamanho: 128, cor: C.vermelho, pos: [dir, CYf - 20] }));

  // Seta: a linha cresce e a ponta chega junto.
  var ln = linha(comp, { largura: 300, espessura: 5, pos: [CXf, CYf - 20], cor: C.amarelo });
  anim(ln._grupo.property("Transform").property("Scale"),
    [[ctx.t + 0.6, [0, 100]], [ctx.t + 1.3, [100, 100]]], { ease: 75 });
  L.push(ln);

  var pta = comp.layers.addShape(); pta.name = "ponta";
  var g = pta.property("Contents").addProperty("ADBE Vector Group");
  var pa = g.property("Contents").addProperty("ADBE Vector Shape - Group");
  var s = new Shape();
  s.vertices = [[-16, -16], [16, 0], [-16, 16]];
  s.inTangents = [[0,0],[0,0],[0,0]]; s.outTangents = [[0,0],[0,0],[0,0]];
  s.closed = true;
  pa.property("Path").setValue(s);
  g.property("Contents").addProperty("ADBE Vector Graphic - Fill")
   .property("Color").setValue(hex(C.amarelo));
  pta.property("Transform").property("Position").setValue([CXf + 155, CYf - 20]);
  anim(pta.property("Transform").property("Opacity"),
    [[ctx.t + 1.1, 0], [ctx.t + 1.35, 100]], { ease: 60 });
  L.push(pta);

  if (p.sumiu) {
    L.push(txt(comp, "R$ " + fmt(p.sumiu) + " no meio do caminho", {
      tamanho: 56, cor: C.vermelho, pos: [CXf, CYf + 220] }));
  }
  return L;
};

/** Anunciada 5% vs. real 21% — o confronto lado a lado. */
BLOCOS.taxaReal = function (comp, p, ctx) {
  var L = [];
  var esq = CXf - 400, dir = CXf + 400;
  L.push(etiqueta(comp, "taxa anunciada", { pos: [esq, CYf - 170] }));
  var a = contador(comp, { de: 0, para: p.anunciada || 5, prefixo: "", sufixo: "%",
    tamanho: 190, cor: C.textoFraco, pos: [esq, CYf], inicio: ctx.t + 0.5, duracao: 0.8 });
  L.push(a);
  L.push(etiqueta(comp, "taxa real nesta venda", { pos: [dir, CYf - 170] }));
  var b = contador(comp, { de: 0, para: p.real || 21, prefixo: "", sufixo: "%",
    tamanho: 190, cor: C.vermelho, pos: [dir, CYf], inicio: ctx.t + 1.2, duracao: 1.0 });
  L.push(b);
  var div = caixa(comp, { largura: 3, altura: 250, cor: C.borda, pos: [CXf, CYf - 20] });
  L.push(div);
  return L;
};

// ---------------------------------------------------------------- Ato 2

/** Cartela de capítulo: número grande + título. */
BLOCOS.capitulo = function (comp, p, ctx) {
  var L = [];
  var an = aneis(comp, { pos: [CXf, CYf - 170], base: 460, passo: 160, forca: 30 });
  L.push(an);
  var n = txt(comp, p.numero || "01", {
    tamanho: 300, cor: C.amareloFraco, pos: [CXf, CYf - 170] });
  L.push(n);
  L.push(txtRealce(comp, p.titulo, null, { tamanho: 84, pos: [CXf, CYf + 155] }));
  // A régua fica ENTRE o número e o título; com o número em 300px, o vão
  // abaixo dele começa por volta de -60.
  var ln = linha(comp, { largura: 200, espessura: 5, pos: [CXf, CYf - 20] });
  anim(ln._grupo.property("Transform").property("Scale"),
    [[ctx.t + 0.35, [0, 100]], [ctx.t + 0.95, [100, 100]]], { ease: 75 });
  L.push(ln);
  return achatar(L);
};

/** Cadeia: você -> plataforma -> adquirente. O elo escondido se revela. */
BLOCOS.cadeia = function (comp, p, ctx) {
  var L = [];

  var nomes = p.elos || ["Você", "Plataforma", "Adquirente"];
  var larg = 380, gap = 90;
  var total = nomes.length * larg + (nomes.length - 1) * gap;
  var x0 = CXf - total / 2 + larg / 2;

  for (var i = 0; i < nomes.length; i++) {
    var x = x0 + i * (larg + gap);
    var revelado = (i === nomes.length - 1);   // a adquirente é a revelação
    var cx = caixa(comp, {
      largura: larg, altura: 160, raio: 18,
      cor: C.superficie, traco: 3,
      corTraco: revelado ? C.amarelo : C.borda,
      pos: [x, CYf]
    });
    L.push(cx);
    L.push(txt(comp, nomes[i], {
      tamanho: 50, cor: revelado ? C.amarelo : C.texto, pos: [x, CYf] }));
    if (revelado) {
      anim(cx.property("Transform").property("Opacity"),
        [[ctx.t + 1.1, 0], [ctx.t + 1.6, 100]], { ease: 60 });
    }
    if (i < nomes.length - 1) {
      L.push(txt(comp, "→", { tamanho: 56, cor: C.textoFraco,
        pos: [x + larg / 2 + gap / 2, CYf] }));
    }
  }
  return L;
};

/** Compra por X, revende por Y. */
BLOCOS.revenda = function (comp, p, ctx) {
  var L = [];
  var esq = CXf - 400, dir = CXf + 400;
  L.push(etiqueta(comp, "ela compra por", { pos: [esq, CYf - 160] }));
  L.push(txt(comp, p.compra || "0,5% a 2%", {
    tamanho: 130, cor: C.verde, pos: [esq, CYf] }));
  L.push(etiqueta(comp, "e revende por", { pos: [dir, CYf - 160] }));
  L.push(txt(comp, p.revende || "5% a 10%", {
    tamanho: 130, cor: C.vermelho, pos: [dir, CYf] }));
  var ln = linha(comp, { largura: 260, espessura: 5, pos: [CXf, CYf] });
  anim(ln._grupo.property("Transform").property("Scale"),
    [[ctx.t + 0.6, [0, 100]], [ctx.t + 1.2, [100, 100]]], { ease: 75 });
  L.push(ln);
  return L;
};

/** Lista de funções em cards. */
BLOCOS.listaFuncoes = function (comp, p, ctx) {
  var L = [];

  var itens = p.itens || [];
  var larg = 400, alt = 130, gapX = 40, gapY = 34;
  var cols = 2;
  var linhas = Math.ceil(itens.length / cols);
  var x0 = CXf - (cols * larg + (cols - 1) * gapX) / 2 + larg / 2;
  var y0 = CYf - (linhas * alt + (linhas - 1) * gapY) / 2 + alt / 2;

  for (var i = 0; i < itens.length; i++) {
    var cl = i % cols, li = Math.floor(i / cols);
    var x = x0 + cl * (larg + gapX), y = y0 + li * (alt + gapY);
    var cx = caixa(comp, { largura: larg, altura: alt, raio: 16,
      cor: C.superficie, traco: 2, corTraco: C.borda, pos: [x, y] });
    var tx = txt(comp, itens[i], { tamanho: 46, pos: [x, y] });
    // Cascata dentro da cena: um card por vez.
    var d = ctx.t + 0.4 + i * 0.16;
    anim(cx.property("Transform").property("Opacity"), [[d, 0], [d + 0.32, 100]], { ease: 60 });
    anim(tx.property("Transform").property("Opacity"), [[d + 0.06, 0], [d + 0.36, 100]], { ease: 60 });
    L.push(cx); L.push(tx);
  }
  return L;
};

// ---------------------------------------------------------------- Ato 3

/** Custo real de processar um Pix. */
BLOCOS.custoPix = function (comp, p, ctx) {
  var L = [];
  var ce = ilustraViva(comp, "celular-pix", ctx.t + 0.15, { altura: 560, pos: [CXf - 520, CYf + 20] });
  if (ce) L.push(ce);
  L.push(etiqueta(comp, "custo real de um pix", { pos: [CXf + 240, CYf - 200] }));
  L.push(txt(comp, p.custo || "0,5%", {
    tamanho: 260, cor: C.verde, pos: [CXf + 240, CYf + 10] }));
  return L;
};

/** As três faixas cobradas, entrando uma a uma. */
BLOCOS.taxaCheia = function (comp, p, ctx) {
  var L = [];
  L.push(etiqueta(comp, "o que a plataforma cobra", { pos: [CXf, CYf - 250] }));
  var faixas = p.faixas || ["3%", "5%", "10%"];
  var larg = 300, gap = 50;
  var x0 = CXf - (faixas.length * larg + (faixas.length - 1) * gap) / 2 + larg / 2;
  for (var i = 0; i < faixas.length; i++) {
    var x = x0 + i * (larg + gap);
    var cx = caixa(comp, { largura: larg, altura: 220, raio: 20,
      cor: C.superficie, traco: 3, corTraco: C.vermelho, pos: [x, CYf + 30] });
    var tx = txt(comp, faixas[i], { tamanho: 120, cor: C.vermelho, pos: [x, CYf + 30] });
    var d = ctx.t + 0.5 + i * 0.7;      // uma por vez: cada pop é um som
    anim(cx.property("Transform").property("Scale"),
      [[d, [70, 70]], [d + 0.3, [106, 106]], [d + 0.45, [100, 100]]], { ease: 70 });
    anim(cx.property("Transform").property("Opacity"), [[d, 0], [d + 0.2, 100]], { ease: 60 });
    anim(tx.property("Transform").property("Opacity"), [[d + 0.1, 0], [d + 0.3, 100]], { ease: 60 });
    L.push(cx); L.push(tx);
  }
  return L;
};

/** Venda de R$ 10.000 no Pix: custo real vs cobrado. */
BLOCOS.comparaPix = function (comp, p, ctx) {
  var L = [];
  L.push(etiqueta(comp, "venda de R$ " + fmt(p.venda || 10000) + " no pix", {
    pos: [CXf, CYf - 240] }));
  var esq = CXf - 400, dir = CXf + 400;
  L.push(etiqueta(comp, "custa", { pos: [esq, CYf - 110] }));
  L.push(txt(comp, "R$ " + fmt(p.custa || 50), {
    tamanho: 140, cor: C.verde, pos: [esq, CYf + 30] }));
  L.push(etiqueta(comp, "você paga", { pos: [dir, CYf - 110] }));
  L.push(txt(comp, p.paga || "R$ 300 a 1.000", {
    tamanho: 110, cor: C.vermelho, pos: [dir, CYf + 30] }));
  L.push(caixa(comp, { largura: 3, altura: 240, cor: C.borda, pos: [CXf, CYf ] }));
  return L;
};

// ---------------------------------------------------------------- Ato 4

/** Calendário com os marcos D+14 e D+30. */
BLOCOS.calendario = function (comp, p, ctx) {
  var L = [];
  var marcos = p.marcos || ["D+14", "D+30"];
  var larg = 340, gap = 70;
  var x0 = CXf - (marcos.length * larg + (marcos.length - 1) * gap) / 2 + larg / 2;
  var ca = ilustraViva(comp, "calendario", ctx.t + 0.15, { altura: 420, pos: [CXf - 600, CYf + 10] });
  if (ca) L.push(ca);
  L.push(etiqueta(comp, "quando o dinheiro chega", { pos: [CXf + 120, CYf - 230] }));
  for (var i = 0; i < marcos.length; i++) {
    var x = x0 + i * (larg + gap);
    var cx = caixa(comp, { largura: larg, altura: 200, raio: 20,
      cor: C.superficie, traco: 3, corTraco: C.borda, pos: [x, CYf + 30] });
    L.push(cx);
    L.push(txt(comp, marcos[i], { tamanho: 104, cor: C.textoFraco, pos: [x, CYf + 30] }));
  }
  return L;
};

/** A reviravolta: D+30 vira D+2, com selos de apoio. */
BLOCOS.reviravolta = function (comp, p, ctx) {
  var L = [];
  var de = txt(comp, p.de || "D+30", {
    tamanho: 150, cor: C.textoFraco, pos: [CXf - 380, CYf - 30] });
  L.push(de);
  var para = txt(comp, p.para || "D+2", {
    tamanho: 190, cor: C.verde, pos: [CXf + 340, CYf - 30] });
  L.push(para);
  // O "para" só chega depois: é a virada.
  anim(para.property("Transform").property("Scale"),
    [[ctx.t + 1.0, [60, 60]], [ctx.t + 1.35, [108, 108]], [ctx.t + 1.5, [100, 100]]], { ease: 70 });
  anim(para.property("Transform").property("Opacity"),
    [[ctx.t + 1.0, 0], [ctx.t + 1.2, 100]], { ease: 60 });

  var ln = linha(comp, { largura: 220, espessura: 5, pos: [CXf - 10, CYf - 30] });
  anim(ln._grupo.property("Transform").property("Scale"),
    [[ctx.t + 0.6, [0, 100]], [ctx.t + 1.1, [100, 100]]], { ease: 75 });
  L.push(ln);

  var selos = p.selos || ["Mesmo no cartão", "Sem antecipação"];
  for (var i = 0; i < selos.length; i++) {
    var x = CXf + (i === 0 ? -300 : 300);
    var cx = caixa(comp, { largura: 480, altura: 90, raio: 45,
      cor: C.superficie, traco: 2, corTraco: C.verde, pos: [x, CYf + 220] });
    var tx = txt(comp, selos[i], { tamanho: 42, cor: C.verde, pos: [x, CYf + 220] });
    var d = ctx.t + 1.6 + i * 0.2;
    anim(cx.property("Transform").property("Opacity"), [[d, 0], [d + 0.3, 100]], { ease: 60 });
    anim(tx.property("Transform").property("Opacity"), [[d, 0], [d + 0.3, 100]], { ease: 60 });
    L.push(cx); L.push(tx);
  }
  return L;
};

// ---------------------------------------------------------------- Ato 5

/** Soma dos quatro vilões dos atos anteriores. */
BLOCOS.somatorio = function (comp, p, ctx) {
  var L = [];
  var co = ilustraViva(comp, "cofre", ctx.t + 0.1, { altura: 400, pos: [CXf - 620, CYf] });
  if (co) L.push(co);
  var itens = p.itens || ["O juro", "A taxa inflada", "O Pix caro", "O dinheiro parado"];
  var passo = 118;
  var topo = CYf - ((itens.length - 1) * passo) / 2;
  for (var i = 0; i < itens.length; i++) {
    var y = Math.round(topo + i * passo);
    var pt = caixa(comp, { largura: 14, altura: 14, raio: 7, cor: C.amarelo,
      pos: [CXf - 400, y] });
    var tx = txt(comp, itens[i], { tamanho: 62, pos: [CXf + 20, y] });
    var d = ctx.t + 0.4 + i * 0.34;    // um por vez, no ritmo da fala
    anim(pt.property("Transform").property("Opacity"), [[d, 0], [d + 0.25, 100]], { ease: 60 });
    anim(tx.property("Transform").property("Opacity"), [[d, 0], [d + 0.3, 100]], { ease: 60 });
    anim(tx.property("Transform").property("Position"),
      [[d, [CXf + 60, y]], [d + 0.35, [CXf + 20, y]]], { ease: 78 });
    L.push(pt); L.push(tx);
  }
  return L;
};

/** Fatura X por mês, perde de Y a Z. */
BLOCOS.contaMes = function (comp, p, ctx) {
  var L = [];
  L.push(aneis(comp, { pos: [CXf, CYf - 30], base: 430, passo: 165, forca: 30 }));
  L.push(etiqueta(comp, "se você fatura", { pos: [CXf, CYf - 250] }));
  var f = contador(comp, { de: 0, para: p.fatura || 30000, tamanho: 190,
    cor: C.texto, pos: [CXf, CYf - 90], inicio: ctx.t + 0.4, duracao: 1.1 });
  L.push(f);
  L.push(txt(comp, "por mês, fica no caminho", {
    tamanho: 46, cor: C.textoFraco, pos: [CXf, CYf + 50] }));
  L.push(txt(comp, p.perde || "R$ 3.000 a R$ 4.500", {
    tamanho: 130, cor: C.vermelho, pos: [CXf, CYf + 180] }));
  return L;
};

/** Receita vs. lucro: a barra do lucro é a que encolhe. */
BLOCOS.receitaLucro = function (comp, p, ctx) {
  var L = [];
  var gr = ilustraViva(comp, "grafico-sobe", ctx.t + 0.15, { altura: 340, pos: [CXf, CYf - 260] });
  if (gr) L.push(gr);
  L.push(etiqueta(comp, "não sai da receita", { pos: [CXf - 380, CYf - 220] }));
  L.push(etiqueta(comp, "sai do lucro", { pos: [CXf + 380, CYf - 220] }));

  var b1 = caixa(comp, { largura: 320, altura: 260, raio: 16,
    cor: C.superficieAlta, pos: [CXf - 380, CYf + 20] });
  L.push(b1);
  L.push(txt(comp, "Receita", { tamanho: 46, cor: C.textoFraco, pos: [CXf - 380, CYf + 20] }));

  var b2 = caixa(comp, { largura: 320, altura: 260, raio: 16,
    cor: C.superficieAlta, pos: [CXf + 380, CYf + 20] });
  L.push(b2);
  // O lucro encolhe: é o gesto que carrega o argumento.
  anim(b2._grupo.property("Transform").property("Scale"),
    [[ctx.t + 0.8, [100, 100]], [ctx.t + 1.8, [100, 42]]], { ease: 70 });
  var t2 = txt(comp, "Lucro", { tamanho: 46, cor: C.vermelho, pos: [CXf + 380, CYf + 20] });
  L.push(t2);
  return L;
};

/** Margem de 30% -> +30 a 50% de lucro. */
BLOCOS.margem = function (comp, p, ctx) {
  var L = [];
  var pz = ilustraViva(comp, "pizza", ctx.t + 0.15, { altura: 380, pos: [CXf - 600, CYf + 20] });
  if (pz) L.push(pz);
  L.push(etiqueta(comp, "com margem de " + (p.margem || "30%"), { pos: [CXf, CYf - 230] }));
  L.push(txt(comp, "trocar de plataforma", {
    tamanho: 54, cor: C.textoFraco, pos: [CXf, CYf - 110] }));
  L.push(txt(comp, p.ganho || "+30% a +50%", {
    tamanho: 200, cor: C.verde, pos: [CXf, CYf + 50] }));
  L.push(txt(comp, "de lucro no seu bolso", {
    tamanho: 52, cor: C.texto, pos: [CXf, CYf + 200] }));
  return L;
};

// ---------------------------------------------------------------- Ato 6

/** A marca entra. É a virada do vídeo. */
BLOCOS.viradaMarca = function (comp, p, ctx) {
  var L = [];
  var pf = ilustraViva(comp, "pessoa-feliz", ctx.t + 0.3, { altura: 480, pos: [CXf - 620, CYf + 40] });
  if (pf) L.push(pf);
  var lg = logo(comp, { largura: 560, pos: [CXf + 180, CYf - 60] });
  L.push(lg);
  anim(lg.property("Transform").property("Scale"),
    [[ctx.t + 0.2, [(620 / 624) * 100 * 0.7, (620 / 624) * 100 * 0.7]],
     [ctx.t + 0.75, [(620 / 624) * 100 * 1.04, (620 / 624) * 100 * 1.04]],
     [ctx.t + 0.95, [(620 / 624) * 100, (620 / 624) * 100]]], { ease: 72 });
  L.push(txt(comp, p.linha || "Mensalidade fixa. Sem taxa por venda.", {
    tamanho: 56, cor: C.textoFraco, pos: [CXf, CYf + 130] }));
  var ln = linha(comp, { largura: 420, espessura: 5, pos: [CXf, CYf + 45] });
  anim(ln._grupo.property("Transform").property("Scale"),
    [[ctx.t + 0.9, [0, 100]], [ctx.t + 1.5, [100, 100]]], { ease: 75 });
  L.push(ln);
  return L;
};

/** Os dois zeros. */
BLOCOS.zeros = function (comp, p, ctx) {
  var L = [];
  var es = ilustraViva(comp, "escudo", ctx.t + 0.1, { altura: 400, pos: [CXf - 600, CYf + 40] });
  if (es) L.push(es);
  var itens = p.itens || ["Zero de comissão", "Zero de custo escondido"];
  for (var i = 0; i < itens.length; i++) {
    var y = CYf - 80 + i * 190;
    var z = txt(comp, "0", { tamanho: 170, cor: C.amarelo, pos: [CXf - 330, y] });
    var tx = txt(comp, itens[i].replace(/^Zero de /, ""), {
      tamanho: 66, cor: C.texto, pos: [CXf + 120, y] });
    var d = ctx.t + 0.4 + i * 0.45;
    anim(z.property("Transform").property("Scale"),
      [[d, [55, 55]], [d + 0.3, [108, 108]], [d + 0.45, [100, 100]]], { ease: 70 });
    anim(z.property("Transform").property("Opacity"), [[d, 0], [d + 0.2, 100]], { ease: 60 });
    anim(tx.property("Transform").property("Opacity"), [[d + 0.15, 0], [d + 0.4, 100]], { ease: 60 });
    L.push(z); L.push(tx);
  }
  return L;
};

/** Os 5 gateways, entrando em cascata. */
BLOCOS.gateways = function (comp, p, ctx) {
  var L = [];

  var nomes = p.nomes || ["pagarme", "asaas", "mercadopago", "stripe", "paypal"];
  var cols = 3, larg = 380, alt = 150, gapX = 40, gapY = 40;
  L.push(etiqueta(comp, "conecte o seu gateway", { pos: [CXf, CYf - 280] }));

  for (var i = 0; i < nomes.length; i++) {
    var linhaI = Math.floor(i / cols);
    var nesta = Math.min(cols, nomes.length - linhaI * cols);
    var col = i % cols;
    var x = CXf - (nesta * larg + (nesta - 1) * gapX) / 2 + larg / 2 + col * (larg + gapX);
    var y = CYf - 40 + linhaI * (alt + gapY);
    var cx = caixa(comp, { largura: larg, altura: alt, raio: 18,
      cor: C.superficie, traco: 2, corTraco: C.borda, pos: [x, y] });
    L.push(cx);
    var g = gateway(comp, nomes[i], { largura: 220, pos: [x, y] });
    if (!g) g = txt(comp, nomes[i], { tamanho: 42, pos: [x, y] });
    L.push(g);
    var d = ctx.t + 0.5 + i * 0.22;
    anim(cx.property("Transform").property("Opacity"), [[d, 0], [d + 0.3, 100]], { ease: 60 });
    anim(g.property("Transform").property("Opacity"), [[d + 0.08, 0], [d + 0.35, 100]], { ease: 60 });
  }
  return L;
};

/** A venda cai INTEIRA na sua conta — fecha o arco do R$ 1.200. */
BLOCOS.vendaInteira = function (comp, p, ctx) {
  var L = [];
  L.push(aneis(comp, { pos: [CXf, CYf - 30], base: 430, passo: 165, forca: 30 }));
  L.push(etiqueta(comp, "a venda cai inteira", { pos: [CXf, CYf - 230] }));
  var c = contador(comp, { de: 0, para: p.valor || 1200, tamanho: 230,
    cor: C.verde, pos: [CXf, CYf - 30], inicio: ctx.t + 0.5, duracao: 1.2 });
  L.push(c);
  L.push(txt(comp, "na sua conta", { tamanho: 58, cor: C.texto, pos: [CXf, CYf + 130] }));
  var ln = linha(comp, { largura: 480, espessura: 5, cor: C.verde, pos: [CXf, CYf + 60] });
  anim(ln._grupo.property("Transform").property("Scale"),
    [[ctx.t + 1.3, [0, 100]], [ctx.t + 1.9, [100, 100]]], { ease: 75 });
  L.push(ln);
  return L;
};

/** CTA final. */
BLOCOS.cta = function (comp, p, ctx) {
  var L = [];
  var lg = logo(comp, { largura: 420, pos: [CXf, CYf - 230] });
  L.push(lg);
  var bt = caixa(comp, { largura: 760, altura: 130, raio: 65, cor: C.amarelo,
    pos: [CXf, CYf + 20] });
  L.push(bt);
  L.push(txt(comp, p.botao || "Fale com a nossa equipe", {
    tamanho: 54, cor: "#0B0A0D", pos: [CXf, CYf + 20] }));
  L.push(txt(comp, p.site || "pagzero.com", {
    tamanho: 48, cor: C.textoFraco, pos: [CXf, CYf + 200] }));
  // O botão pulsa de leve: chama o clique sem piscar.
  bt.property("Transform").property("Scale").expression =
    'var s=100+Math.sin((time-' + (ctx.t + 1) + ')*2.4)*1.6; [s,s]';
  return L;
};

// ---------------------------------------------------------------- utilidades

/** Achata array de arrays (txtRealce devolve array). */
function achatar(arr) {
  var out = [];
  for (var i = 0; i < arr.length; i++) {
    if (arr[i] instanceof Array) { for (var j = 0; j < arr[i].length; j++) out.push(arr[i][j]); }
    else out.push(arr[i]);
  }
  return out;
}

/** 1200 -> "1.200" */
function fmt(n) {
  var s = "" + Math.round(n), o = "";
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 === 0) o += ".";
    o += s[i];
  }
  return o;
}
