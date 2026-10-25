/**
 * ambiente.jsx — a camada que mantém a tela VIVA.
 *
 * Sem isso o quadro "morre" enquanto a locutora fala: o dado entra, assenta, e
 * ficam 5 segundos de imagem parada. Aqui tudo é expression — movimento
 * contínuo, sem keyframe, que roda o vídeo inteiro sem pesar o render.
 */

/**
 * Grade de pontos ao fundo. Some no escuro, mas dá textura: a tela deixa de
 * ser um vazio chapado. Opacidade baixa de propósito — se você NOTA a grade,
 * ela está forte demais.
 */
function grade(comp, o) {
  o = o || {};
  var cols = o.cols || 14, lins = o.lins || 8;
  var gx = W / (cols + 1), gy = H / (lins + 1);
  var sh = comp.layers.addShape();
  sh.name = "grade";
  for (var i = 0; i < cols; i++) {
    for (var j = 0; j < lins; j++) {
      var g = sh.property("Contents").addProperty("ADBE Vector Group");
      var e = g.property("Contents").addProperty("ADBE Vector Shape - Ellipse");
      e.property("Size").setValue([3, 3]);
      // idem: posiciona pelo Transform do grupo
      g.property("Transform").property("Position").setValue([gx * (i + 1) - CXf, gy * (j + 1) - H / 2]);
      g.property("Contents").addProperty("ADBE Vector Graphic - Fill")
       .property("Color").setValue(hex(o.cor || C.amarelo));
      // Onda diagonal: cada ponto pulsa defasado do vizinho.
      g.property("Transform").property("Opacity").expression =
        "38 + Math.sin(time*0.8 - " + ((i + j) * 0.32).toFixed(2) + ")*30";
    }
  }
  sh.property("Transform").property("Position").setValue([CXf, H / 2]);
  sh.property("Transform").property("Opacity").setValue(o.forca || 13);
  return sh;
}

/**
 * Partículas subindo devagar, como poeira na luz.
 * Densidade baixa: em VSL o fundo não pode competir com o número.
 */
function poeira(comp, dur, o) {
  o = o || {};
  var n = o.quantidade || 18;
  var sh = comp.layers.addShape();
  sh.name = "poeira";
  for (var i = 0; i < n; i++) {
    var g = sh.property("Contents").addProperty("ADBE Vector Group");
    var tam = 2 + (i % 3);
    var e = g.property("Contents").addProperty("ADBE Vector Shape - Ellipse");
    e.property("Size").setValue([tam, tam]);
    g.property("Contents").addProperty("ADBE Vector Graphic - Fill")
     .property("Color").setValue(hex(o.cor || C.amarelo));

    // A animação vai no Transform do GRUPO, não no Position da elipse:
    // "ADBE Vector Shape - Ellipse".Position é um valor de forma e recusa
    // expression — daí o "objeto é inválido".
    var x = ((i * 137) % 1900) - 950;      // 137 é primo: espalha sem alinhar
    var vel = 22 + (i % 5) * 9;
    var fase = (i / n) * 100;
    var T = g.property("Transform");
    T.property("Position").expression =
      "var v=" + vel + ", f=" + fase + ", x=" + x + ";" +
      "var y = 560 - ((time*v + f) % 1120);" +
      "[x + Math.sin(time*0.5 + " + i + ")*22, y]";
    T.property("Opacity").expression =
      "var v=" + vel + ", f=" + fase + ";" +
      "var p = ((time*v + f) % 1120)/1120;" +
      "Math.sin(p*Math.PI)*" + (55 + (i % 4) * 12);
  }
  sh.property("Transform").property("Position").setValue([CXf, H / 2]);
  sh.property("Transform").property("Opacity").setValue(o.forca || 26);
  return sh;
}

/**
 * Halo de luz atrás do conteúdo. Separa o texto do fundo sem borda dura e
 * dá a sensação de profundidade que fundo chapado não tem.
 */
function halo(comp, o) {
  o = o || {};
  var s = comp.layers.addShape();
  s.name = "halo";
  var g = s.property("Contents").addProperty("ADBE Vector Group");
  var e = g.property("Contents").addProperty("ADBE Vector Shape - Ellipse");
  e.property("Size").setValue([o.tamanho || 1500, (o.tamanho || 1500) * 0.62]);

  // Gradiente radial em vez de Gaussian Blur 320: o blur num shape grande,
  // com escala animada por expression, recalcula a cada frame e foi o que
  // derrubou o AE. O gradiente dá a mesma suavidade de graça.
  var f = g.property("Contents").addProperty("ADBE Vector Graphic - G-Fill");
  try {
    f.property("ADBE Vector Grad Type").setValue(2);          // radial
    f.property("ADBE Vector Grad Start Pt").setValue([0, 0]);
    f.property("ADBE Vector Grad End Pt").setValue([(o.tamanho || 1500) / 2, 0]);
    var cor = hex(o.cor || C.amarelo);
    var g1 = new Shape();                                     // placeholder
    var ramp = f.property("ADBE Vector Grad Colors");
    var gd = ramp.value;
    gd.setColorStopKey(0, 0, [cor[0], cor[1], cor[2]]);
    gd.setColorStopKey(0, 1, [cor[0], cor[1], cor[2]]);
    gd.setOpacityStopKey(0, 0, 1);
    gd.setOpacityStopKey(0, 1, 0);                            // some na borda
    ramp.setValue(gd);
  } catch (e2) {
    // Se o gradiente falhar, um preenchimento chapado bem fraco resolve.
    g.property("Contents").addProperty("ADBE Vector Graphic - Fill")
     .property("Color").setValue(hex(o.cor || C.amarelo));
  }

  s.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  s.property("Transform").property("Opacity").setValue(o.forca || 7);
  s.property("Transform").property("Scale").expression =
    "var v=100+Math.sin(time*0.55)*5; [v,v]";
  return s;
}

/** Anéis concêntricos pulsando — bom atrás de número/ícone que é o assunto. */
function aneis(comp, o) {
  o = o || {};
  var qtd = o.quantidade || 3;
  var sh = comp.layers.addShape();
  sh.name = "aneis";
  for (var i = 0; i < qtd; i++) {
    var g = sh.property("Contents").addProperty("ADBE Vector Group");
    var e = g.property("Contents").addProperty("ADBE Vector Shape - Ellipse");
    var d = (o.base || 380) + i * (o.passo || 150);
    e.property("Size").setValue([d, d]);
    var st = g.property("Contents").addProperty("ADBE Vector Graphic - Stroke");
    st.property("Color").setValue(hex(o.cor || C.amarelo));
    st.property("Stroke Width").setValue(2);
    g.property("Transform").property("Opacity").expression =
      "26 + Math.sin(time*1.15 - " + (i * 0.75).toFixed(2) + ")*20";
    g.property("Transform").property("Scale").expression =
      "var v=100+Math.sin(time*1.15 - " + (i * 0.75).toFixed(2) + ")*4.5; [v,v]";
  }
  sh.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  sh.property("Transform").property("Opacity").expression =
    "Math.min(value, " + (o.forca || 40) + ")";
  sh.moveToEnd();
  return sh;
}

/**
 * Ícone grande e apagado como marca d'água atrás do conteúdo.
 * Resolve as cenas que não têm ícone próprio: dá assunto visual sem competir.
 */
function iconeFundo(comp, nome, o) {
  o = o || {};
  var l = icone(comp, nome, { tamanho: o.tamanho || 620, pos: o.pos || [CXf, CYf],
                              corNome: o.corNome || "amarelo" });
  if (!l) return null;
  // Expression em vez de setValue: o entrada() do montador anima Opacity de
  // 0 a 100 e sobrescreveria o valor, deixando a marca d'água opaca por cima
  // do conteúdo. Expression vence keyframe, então o teto fica garantido.
  l.property("Transform").property("Opacity").expression =
    "Math.min(value, " + (o.forca || 7) + ")";
  l.property("Transform").property("Rotation").expression =
    "Math.sin(time*0.35)*2.5";
  l.moveToEnd();          // sempre atrás do conteúdo
  return l;
}

/**
 * Ícone que ENTRA com pop e depois flutua.
 * O pop passa de 100 e volta — escala que só cresce até o alvo lê como
 * "aparecendo", não como "chegando".
 */
function iconeVivo(comp, nome, t, o) {
  o = o || {};
  var l = icone(comp, nome, { tamanho: o.tamanho || 90, pos: o.pos,
                              corNome: o.corNome || "amarelo" });
  if (!l) return null;
  var base = ((o.tamanho || 90) / 200) * 100;
  var S = l.property("Transform").property("Scale");
  anim(S, [
    [t,        [base * 0.4, base * 0.4]],
    [t + 0.28, [base * 1.14, base * 1.14]],
    [t + 0.44, [base, base]]
  ], { ease: 70 });
  anim(l.property("Transform").property("Opacity"), [[t, 0], [t + 0.2, 100]], { ease: 60 });
  var p = l.property("Transform").property("Position").value;
  l.property("Transform").property("Position").expression =
    "var t0=" + (t + 0.5) + ";" +
    "[" + p[0] + ", " + p[1] + " + (time>t0 ? Math.sin((time-t0)*1.5)*7 : 0)]";
  return l;
}

/**
 * Barra de progresso do vídeo, no rodapé.
 * Em VSL longa isso segura a audiência: mostra que tem fim.
 */
function progresso(comp, dur) {
  var trilho = caixa(comp, { largura: W, altura: 5, cor: C.borda,
    pos: [CXf, H - 3], nome: "trilho" });
  trilho.property("Transform").property("Opacity").setValue(30);
  var barra = caixa(comp, { largura: W, altura: 5, cor: C.amarelo,
    pos: [CXf, H - 3], nome: "progresso" });
  // Ancora à esquerda para crescer da borda, não do centro.
  barra._grupo.property("Transform").property("Anchor Point").setValue([-W / 2, 0]);
  barra._grupo.property("Transform").property("Position").setValue([-W / 2, 0]);
  barra._grupo.property("Transform").property("Scale").expression =
    "var p=Math.min(100, (time/" + dur + ")*100); [p, 100]";
  return [trilho, barra];
}

/**
 * Monta o ambiente de uma comp inteira (um ato).
 * A ordem importa: halo e grade ficam no fundo, poeira por cima, e tudo
 * abaixo do conteúdo — moveToEnd() empurra para trás na pilha.
 */
function ambienteDoAto(comp, dur, o) {
  o = o || {};
  var g = grade(comp, { forca: o.grade === undefined ? 12 : o.grade });
  g.moveToEnd();
  var p = poeira(comp, dur, { quantidade: o.poeira || 16 });
  p.moveToEnd();
  var h = halo(comp, { forca: o.halo === undefined ? 6 : o.halo });
  h.moveToEnd();
  return [h, p, g];
}
