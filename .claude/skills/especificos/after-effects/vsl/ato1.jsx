/**
 * ato1.jsx — as 9 cenas do Ato 1, na identidade REAL da PagZero.
 *
 * O que mudou em relação à primeira versão, e por quê:
 *   · Fundo BRANCO. O nuxt-web não tem dark mode; o site é #FFFFFF.
 *   · Pílula amarela no título — a assinatura visual da marca. No site o
 *     destaque nunca é peso de fonte nem cor de texto: é <strong> com fundo
 *     #FEBE00 e radius 16px.
 *   · Separação por borda 1px #E6E8EF, NUNCA sombra (os 4 tokens são `none`).
 *   · Erro é #FF2E6D (rosa-choque) e sucesso #18C964 — não os genéricos.
 *   · Texto sobre amarelo é PRETO.
 *   · Bandas de seção com radius 56px.
 */

var A1 = {};

/** Banda de fundo da cena: o gesto de seção do site. */
function fundoBanda(comp, t, dur, o) {
  o = o || {};
  var b = banda(comp, { cor: o.cor || C.banda, pos: [CXf, H / 2] });
  b.inPoint = t - 0.3; b.outPoint = t + dur + 0.3;
  anim(b.property("Transform").property("Opacity"),
    [[t - 0.1, 0], [t + 0.28, 100]], { ease: 55 });
  anim(b.property("Transform").property("Opacity"),
    [[t + dur - 0.2, 100], [t + dur + 0.1, 0]], { ease: 45 });
  b.moveToEnd();
  return b;
}

// ─────────────────────────────────────────────── 1 · a frase de abertura

A1.cena1 = function (comp, ctx) {
  var L = [];
  var t = ctx.t;

  // Personagem montado em partes: corpo, cabeça e braço animam separados.
  var pe = personagem(comp, { pos: [CXf - 600, CYf + 190], escala: 118, rosto: "preocupado" });
  if (pe) {
    respira(pe, { forca: 8, vel: 1.0 });
    entraVivo(pe.corpo, t + 0.1, { dir: "esq", dist: 130, duracao: 0.7 });
    // Ele olha para o texto quando a frase entra — ação secundária.
    olhaPara(pe, t + 1.5, 9, { segura: 1.6 });
    for (var q = 0; q < pe.camadas.length; q++) L.push(pe.camadas[q]);
  }

  var tit = titulo(comp, [
    "Seu cliente pagou",
    { destaque: "juros" },
    "pra comprar o seu produto."
  ], { tamanho: fs("f10"), pos: [CXf + 250, CYf - 40], lh: 1.42 });
  for (var i = 0; i < tit.length; i++) {
    // Cada linha entra com antecipação + overshoot, em cascata.
    entraVivo(tit[i], t + 0.5 + i * 0.16, { dist: 52, duracao: 0.55 });
    L.push(tit[i]);
  }

  // A moeda cai enquanto ele fala de juros: dá peso físico ao argumento.
  var mo = moedaCai(comp, t + 1.9, { pos: [CXf + 700, CYf + 210], tamanho: 96 });
  if (mo) L.push(mo);

  var sub = corpo(comp, "E esse dinheiro não voltou pra você.",
    { tamanho: fs("f4"), pos: [CXf + 250, CYf + 250] });
  entraVivo(sub, t + 1.2, { dist: 40, duracao: 0.5 });
  L.push(sub);
  return L;
};

// ─────────────────────────────────────────────── 2 · a pergunta

A1.cena2 = function (comp, ctx) {
  var L = [];
  var t = ctx.t;
  L.push(fundoBanda(comp, t, ctx.dur));

  // Mesmo personagem, agora com rosto de dúvida e o braço subindo.
  var pe = personagem(comp, { pos: [CXf - 560, CYf + 230], escala: 108, rosto: "duvida" });
  if (pe) {
    respira(pe, { forca: 7, vel: 1.1 });
    entraVivo(pe.corpo, t + 0.15, { dir: "baixo", dist: 90, duracao: 0.6 });
    levantaBraco(pe, t + 0.9, 42, { segura: 1.8 });
    olhaPara(pe, t + 1.2, -11, { segura: 1.5 });
    for (var q = 0; q < pe.camadas.length; q++) L.push(pe.camadas[q]);
  }

  // Balão de fala saindo da cabeça dele, com a pergunta dentro.
  var ba = ilustra(comp, "balao", { largura: 760, pos: [CXf + 300, CYf - 190] });
  if (ba) { popVivo(ba, t + 0.7, { duracao: 0.55 }); L.push(ba); }

  var tit = titulo(comp, [
    "Pra onde foi",
    { destaque: "esse dinheiro?" }
  ], { tamanho: fs("f9"), pos: [CXf + 300, CYf - 215], lh: 1.35 });
  for (var i = 0; i < tit.length; i++) {
    entraVivo(tit[i], t + 1.0 + i * 0.14, { dist: 34, duracao: 0.46 });
    L.push(tit[i]);
  }

  // A linha atravessa a cena e leva o olho para a próxima: continuidade.
  L.push(linhaGuia(comp, ctx.t + ctx.dur - 1.25, {
    pontos: [[-60, CYf + 330], [CXf, CYf + 250], [W + 60, CYf + 340]],
    duracao: 0.95, espessura: 6
  }));
  L.push(pontoQueCorre(comp, ctx.t + ctx.dur - 1.15, {
    de: [-40, CYf + 330], ate: [W + 40, CYf + 340], duracao: 1.0, arco: 70
  }));
  return L;
};

// ─────────────────────────────────────────────── 3 · a negação

A1.cena3 = function (comp, ctx) {
  var L = [];
  var t = ctx.t;
  var itens = ctx.props.itens || ["Não foi pra você.", "Nem foi pro banco dele."];
  var passo = 190;
  var topo = CYf - ((itens.length - 1) * passo) / 2;

  for (var i = 0; i < itens.length; i++) {
    var y = Math.round(topo + i * passo);
    // Card do site: gelo, borda 1px, radius 26px, sem sombra.
    var cd = card(comp, { largura: 1100, altura: 150, pos: [CXf, y] });
    L.push(cd);

    // X em rosa-choque (#FF2E6D é a cor de erro real do site).
    var xs = comp.layers.addShape(); xs.name = "x" + i;
    for (var k = 0; k < 2; k++) {
      var g = xs.property("Contents").addProperty("ADBE Vector Group");
      var pa = g.property("Contents").addProperty("ADBE Vector Shape - Group");
      var s = new Shape();
      s.vertices = k === 0 ? [[-17, -17], [17, 17]] : [[17, -17], [-17, 17]];
      s.inTangents = [[0,0],[0,0]]; s.outTangents = [[0,0],[0,0]]; s.closed = false;
      pa.property("Path").setValue(s);
      var st = g.property("Contents").addProperty("ADBE Vector Graphic - Stroke");
      st.property("Color").setValue(hex(C.vermelho));
      st.property("Stroke Width").setValue(7);
      st.property("Line Cap").setValue(2);
    }
    xs.property("Transform").property("Position").setValue([CXf - 420, y]);
    L.push(xs);

    L.push(txt(comp, itens[i], {
      tamanho: fs("f6"), peso: "book", cor: C.texto,
      pos: [CXf + 40, y], alinha: "esq"
    }));

    // Cascata: um card por vez, no ritmo da fala.
    // Card entra deslizando com overshoot; o X CARIMBA depois (pop grande
    // que assenta) — a ordem é o que faz ler como "negado", não "apareceu".
    var d = t + 0.35 + i * 0.42;
    entraVivo(cd, d, { dir: (i % 2 === 0 ? "esq" : "dir"), dist: 110, duracao: 0.55, arco: 8 });
    var Sx = xs.property("Transform").property("Scale");
    anim(Sx, [[d + 0.30, [220, 220]], [d + 0.46, [88, 88]], [d + 0.56, [100, 100]]], { ease: 62 });
    anim(xs.property("Transform").property("Opacity"), [[d + 0.30, 0], [d + 0.40, 100]], { ease: 60 });
  }
  return L;
};

// ─────────────────────────────────────────────── 4 · o parcelamento

A1.cena4 = function (comp, ctx) {
  var L = [];
  var t = ctx.t, p = ctx.props;
  L.push(fundoBanda(comp, t, ctx.dur));

  var ic = ilustra(comp, "cartao", { largura: 640, pos: [CXf - 520, CYf + 20] });
  if (ic) {
    entraVivo(ic, t + 0.2, { dir: "esq", dist: 160, duracao: 0.72, arco: 18 });
    // Inclinação que volta ao normal: o cartão "chega", não aparece.
    anim(ic.property("Transform").property("Rotation"),
      [[t + 0.2, -9], [t + 0.78, 2.4], [t + 0.98, 0]], { ease: 68 });
    ic.property("Transform").property("Rotation").expression =
      "value + Math.sin(time*0.8)*1.2";        // flutua depois de assentar
    L.push(ic);
  }

  L.push(etiqueta(comp, "venda em " + (p.parcelas || 12) + "x no cartão",
    { pos: [CXf + 330, CYf - 250] }));

  // De → para. O "de" fica riscado, como o site faz com preço antigo.
  L.push(txt(comp, "R$ " + fmt(p.de || 1000), {
    tamanho: fs("f9"), peso: "book", cor: C.textoFraco, pos: [CXf + 330, CYf - 120] }));
  var risco = linha(comp, { largura: 240, espessura: 4, cor: C.textoFraco,
    pos: [CXf + 330, CYf - 132] });
  L.push(risco);
  anim(risco._grupo.property("Transform").property("Scale"),
    [[t + 0.9, [0, 100]], [t + 1.3, [100, 100]]], { ease: 75 });

  var c = contador(comp, {
    de: p.de || 1000, para: p.para || 1200, tamanho: fs("f13") + 40, peso: "black",
    cor: C.texto, pos: [CXf + 330, CYf + 40], inicio: t + 0.8, duracao: 1.4
  });
  // Pulsa quando o contador CHEGA no valor final: marca o destino.
  anim(c.property("Transform").property("Scale"),
    [[t + 2.15, [100, 100]], [t + 2.32, [109, 109]], [t + 2.52, [100, 100]]], { ease: 66 });
  popVivo(c, t + 0.7, { duracao: 0.5 });
  L.push(c);

  L.push(corpo(comp, "é o que ele realmente pagou.",
    { tamanho: fs("f4"), pos: [CXf + 330, CYf + 190] }));
  return L;
};

// ─────────────────────────────────────────────── 5 · o martelo (R$ 200)

A1.cena5 = function (comp, ctx) {
  var L = [];
  var t = ctx.t, p = ctx.props;

  // O número inteiro dentro da pílula: é o gesto mais forte da marca.
  var tit = titulo(comp, [{ destaque: p.destaque || "R$ 200 a mais" }],
    { tamanho: fs("f13"), pos: [CXf, CYf - 60], peso: "black" });
  for (var i = 0; i < tit.length; i++) {
    // O martelo BATE: vem de cima, esmaga na chegada e assenta.
    var Pm = tit[i].property("Transform").property("Position");
    var av = Pm.value;
    anim(Pm, [[t, [av[0], av[1] - 230]], [t + 0.30, av]], { ease: 40 });
    anim(tit[i].property("Transform").property("Opacity"), [[t, 0], [t + 0.14, 100]], { ease: 50 });
    aterrissa(tit[i], t + 0.30);
    L.push(tit[i]);
  }

  var linhas = p.linhas || ["Na sua venda.", "Pelo seu produto."];
  for (var k = 0; k < linhas.length; k++) {
    var ln = txt(comp, linhas[k], {
      tamanho: fs("f6"), peso: "book", cor: C.textoSuave,
      pos: [CXf, CYf + 140 + k * 78]
    });
    entraVivo(ln, t + 0.62 + k * 0.20, { dist: 46, duracao: 0.5 });
    L.push(ln);
  }
  return L;
};

// ─────────────────────────────────────────────── 6 · o impacto

A1.cena6 = function (comp, ctx) {
  var L = [];
  var t = ctx.t;
  L.push(fundoBanda(comp, t, ctx.dur, { cor: C.banda }));

  var tit = titulo(comp, [
    "E esses R$ 200 ficam",
    { destaque: "com a plataforma." }
  ], { tamanho: fs("f11"), pos: [CXf, CYf - 20], lh: 1.4,
       corPilula: C.vermelho });
  for (var i = 0; i < tit.length; i++) {
    entraVivo(tit[i], t + 0.2 + i * 0.18, { dist: 60, duracao: 0.56 });
    L.push(tit[i]);
  }

  // A pílula da cena de perda é rosa, e o texto dela vira branco.
  for (var k = 0; k < tit.length; k++) {
    if (tit[k]._pilulaDe) {
      var d = tit[k]._pilulaDe.property("Source Text").value;
      d.fillColor = hex("#FFFFFF");
      tit[k]._pilulaDe.property("Source Text").setValue(d);
    }
  }
  return L;
};

// ─────────────────────────────────────────────── 7 · o fluxo

A1.cena7 = function (comp, ctx) {
  var L = [];
  var t = ctx.t, p = ctx.props;
  var esq = CXf - 440, dir = CXf + 440;

  // Dois cards do site, lado a lado.
  var c1 = card(comp, { largura: 620, altura: 380, pos: [esq, CYf] });
  var c2 = card(comp, { largura: 620, altura: 380, pos: [dir, CYf],
    cor: C.fundo, corTraco: C.vermelho, traco: 3 });
  L.push(c1); L.push(c2);

  L.push(etiqueta(comp, "o cliente pagou", { pos: [esq, CYf - 110] }));
  L.push(txt(comp, "R$ " + fmt(1200), {
    tamanho: fs("f12"), peso: "bold", cor: C.texto, pos: [esq, CYf + 30] }));

  L.push(etiqueta(comp, "você recebeu", { pos: [dir, CYf - 110] }));
  L.push(txt(comp, "R$ " + fmt(950), {
    tamanho: fs("f12"), peso: "bold", cor: C.vermelho, pos: [dir, CYf + 30] }));

  // A seta que atravessa: o dinheiro andando.
  var ln = linha(comp, { largura: 200, espessura: 5, cor: C.amarelo, pos: [CXf, CYf] });
  L.push(ln);
  anim(ln._grupo.property("Transform").property("Scale"),
    [[t + 0.7, [0, 100]], [t + 1.4, [100, 100]]], { ease: 75 });

  var pta = comp.layers.addShape(); pta.name = "ponta";
  var g = pta.property("Contents").addProperty("ADBE Vector Group");
  var pa = g.property("Contents").addProperty("ADBE Vector Shape - Group");
  var s = new Shape();
  s.vertices = [[-14, -15], [15, 0], [-14, 15]];
  s.inTangents = [[0,0],[0,0],[0,0]]; s.outTangents = [[0,0],[0,0],[0,0]];
  s.closed = true;
  pa.property("Path").setValue(s);
  g.property("Contents").addProperty("ADBE Vector Graphic - Fill")
   .property("Color").setValue(hex(C.amarelo));
  pta.property("Transform").property("Position").setValue([CXf + 108, CYf]);
  L.push(pta);
  anim(pta.property("Transform").property("Opacity"),
    [[t + 1.2, 0], [t + 1.45, 100]], { ease: 60 });

  // Três moedas atravessam de um card ao outro: o dinheiro ANDANDO.
  // Sem isso a seta é um enfeite; com isso a cena conta o percurso.
  for (var m = 0; m < 3; m++) {
    var mo = ilustra(comp, "moeda", { largura: 62, pos: [esq + 190, CYf + 120] });
    if (!mo) continue;
    var dm = t + 0.95 + m * 0.16;
    anim(mo.property("Transform").property("Position"), [
      [dm,        [esq + 190, CYf + 120]],
      [dm + 0.34, [CXf, CYf - 40 - m * 18]],
      [dm + 0.68, [dir - 190, CYf + 120]]
    ], { ease: 35 });
    mo.property("Transform").property("Rotation").expression =
      "var t0=" + dm + "; (time-t0)*260";
    anim(mo.property("Transform").property("Opacity"),
      [[dm, 0], [dm + 0.1, 100], [dm + 0.58, 100], [dm + 0.68, 0]], { ease: 50 });
    L.push(mo);
  }

  // Os cards reagem: o da esquerda encolhe de leve, o da direita recebe.
  anim(c1.property("Transform").property("Scale"),
    [[t + 1.55, [100, 100]], [t + 1.75, [97, 97]]], { ease: 55 });
  anim(c2.property("Transform").property("Scale"),
    [[t + 1.6, [100, 100]], [t + 1.78, [104, 104]], [t + 1.98, [100, 100]]], { ease: 62 });
  return L;
};

// ─────────────────────────────────────────────── 8 · o martelo (R$ 250)

A1.cena8 = function (comp, ctx) {
  var L = [];
  var p = ctx.props;
  var t = ctx.t;
  var tit = titulo(comp, [{ destaque: p.destaque || "R$ 250" }],
    { tamanho: fs("f13") + 30, pos: [CXf, CYf - 70], peso: "black",
      corPilula: C.vermelho });
  for (var i = 0; i < tit.length; i++) {
    var Pm = tit[i].property("Transform").property("Position");
    var av = Pm.value;
    anim(Pm, [[t, [av[0], av[1] - 250]], [t + 0.28, av]], { ease: 38 });
    anim(tit[i].property("Transform").property("Opacity"), [[t, 0], [t + 0.12, 100]], { ease: 50 });
    aterrissa(tit[i], t + 0.28);
    L.push(tit[i]);
  }
  for (var k = 0; k < tit.length; k++) {
    if (tit[k]._pilulaDe) {
      var d = tit[k]._pilulaDe.property("Source Text").value;
      d.fillColor = hex("#FFFFFF");
      tit[k]._pilulaDe.property("Source Text").setValue(d);
    }
  }
  L.push(txt(comp, "sumiram no meio do caminho.", {
    tamanho: fs("f6"), peso: "book", cor: C.textoSuave, pos: [CXf, CYf + 150] }));
  return L;
};

// ─────────────────────────────────────────────── 9 · a taxa real

A1.cena9 = function (comp, ctx) {
  var L = [];
  var t = ctx.t, p = ctx.props;
  L.push(fundoBanda(comp, t, ctx.dur));

  var esq = CXf - 430, dir = CXf + 430;

  var c1 = card(comp, { largura: 600, altura: 420, pos: [esq, CYf], cor: C.fundo });
  entraVivo(c1, t + 0.25, { dir: "esq", dist: 120, duracao: 0.6 });
  L.push(c1);
  L.push(etiqueta(comp, "taxa anunciada", { pos: [esq, CYf - 130] }));
  L.push(contador(comp, { de: 0, para: p.anunciada || 5, prefixo: "", sufixo: "%",
    tamanho: fs("f13"), peso: "bold", cor: C.textoFraco, pos: [esq, CYf + 40],
    inicio: t + 0.5, duracao: 0.7 }));

  var c2 = card(comp, { largura: 600, altura: 420, pos: [dir, CYf],
    cor: C.fundo, corTraco: C.vermelho, traco: 3 });
  entraVivo(c2, t + 1.05, { dir: "dir", dist: 120, duracao: 0.6 });
  L.push(c2);
  L.push(etiqueta(comp, "taxa real nesta venda", { pos: [dir, CYf - 130] }));
  L.push(contador(comp, { de: 0, para: p.real || 21, prefixo: "", sufixo: "%",
    tamanho: fs("f13"), peso: "black", cor: C.vermelho, pos: [dir, CYf + 40],
    inicio: t + 1.3, duracao: 1.1 }));

  // O card da direita "acende" quando o número dele sobe.
  anim(c2.property("Transform").property("Scale"),
    [[t + 1.25, [100, 100]], [t + 1.5, [103, 103]], [t + 1.75, [100, 100]]], { ease: 65 });
  return L;
};

/** 1200 -> "1.200" */
function fmt(n) {
  var s = "" + Math.round(n), o = "";
  for (var i = 0; i < s.length; i++) {
    if (i > 0 && (s.length - i) % 3 === 0) o += ".";
    o += s[i];
  }
  return o;
}

/** Achata array de arrays (titulo/botao devolvem arrays). */
function achatar(arr) {
  var out = [];
  for (var i = 0; i < arr.length; i++) {
    if (arr[i] instanceof Array) {
      for (var j = 0; j < arr[i].length; j++) out.push(arr[i][j]);
    } else out.push(arr[i]);
  }
  return out;
}
