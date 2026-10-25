/**
 * icones.jsx — ícones vetoriais desenhados por código, com Trim Paths.
 *
 * Por que não importar SVG: o AE importa SVG como footage rasterizada e o
 * traçado progressivo (Trim Paths) não funciona. Desenhando o path aqui, o
 * ícone é shape layer de verdade — escala sem perder nitidez, aceita cor por
 * parâmetro e pode se desenhar na tela.
 *
 * Todo ícone é centrado em (0,0) num quadro de ~200x200 e posicionado depois.
 */

/** Cria a shape layer base e devolve o grupo onde os paths entram. */
function _base(comp, nome, pos) {
  var sh = comp.layers.addShape();
  sh.name = nome;
  sh.property("Transform").property("Position").setValue(
    pos || [comp.width / 2, comp.height / 2]
  );
  return sh;
}

function _grupo(sh) {
  return sh.property("Contents").addProperty("ADBE Vector Group");
}

/** Adiciona um path aberto/fechado a partir de vértices. */
function _path(grupo, vertices, fechado, tangentesIn, tangentesOut) {
  var p = grupo.property("Contents").addProperty("ADBE Vector Shape - Group");
  var s = new Shape();
  s.vertices = vertices;
  var zeros = [];
  for (var i = 0; i < vertices.length; i++) zeros.push([0, 0]);
  s.inTangents  = tangentesIn  || zeros;
  s.outTangents = tangentesOut || zeros;
  s.closed = !!fechado;
  p.property("Path").setValue(s);
  return p;
}

function _stroke(grupo, cor, largura) {
  var st = grupo.property("Contents").addProperty("ADBE Vector Graphic - Stroke");
  st.property("Color").setValue(hex(cor));
  st.property("Stroke Width").setValue(largura);
  st.property("Line Cap").setValue(2);   // arredondado
  st.property("Line Join").setValue(2);
  return st;
}

function _fill(grupo, cor) {
  var f = grupo.property("Contents").addProperty("ADBE Vector Graphic - Fill");
  f.property("Color").setValue(hex(cor));
  return f;
}

function _elipse(grupo, w, h) {
  var e = grupo.property("Contents").addProperty("ADBE Vector Shape - Ellipse");
  e.property("Size").setValue([w, h]);
  return e;
}

function _retangulo(grupo, w, h, raio) {
  var r = grupo.property("Contents").addProperty("ADBE Vector Shape - Rect");
  r.property("Size").setValue([w, h]);
  if (raio) r.property("Roundness").setValue(raio);
  return r;
}

/**
 * Faz o grupo se desenhar entre t e t+dur.
 * É o gesto que dá vida a ícone em VSL: a forma nasce traçada, não aparece.
 */
function desenhar(grupo, t, dur) {
  var trim = grupo.property("Contents").addProperty("ADBE Vector Filter - Trim");
  anim(trim.property("End"), [[t, 0], [t + (dur || 0.8), 100]]);
  return trim;
}

// ---------------------------------------------------------------- catálogo

/**
 * ICONES[nome](comp, o) -> shape layer.
 * o = { cor, tamanho, pos, traco, t (quando começa a se desenhar), dur }
 *
 * `tamanho` é a escala relativa: 100 = quadro de 200px.
 */
var ICONES = {

  /** Check dentro de um anel — "resolvido", "aprovado". */
  check: function (comp, o) {
    var sh = _base(comp, "ic-check", o.pos);
    var g1 = _grupo(sh);
    _elipse(g1, 190, 190);
    _stroke(g1, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g1, o.t, o.dur);
    var g2 = _grupo(sh);
    _path(g2, [[-52, 4], [-14, 44], [56, -40]], false);
    _stroke(g2, o.cor, (o.traco || 9) + 3);
    if (o.t !== undefined) desenhar(g2, o.t + 0.25, (o.dur || 0.8) * 0.6);
    return sh;
  },

  /** X dentro de um anel — "problema", "não funciona". */
  x: function (comp, o) {
    var sh = _base(comp, "ic-x", o.pos);
    var g1 = _grupo(sh);
    _elipse(g1, 190, 190);
    _stroke(g1, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g1, o.t, o.dur);
    var g2 = _grupo(sh);
    _path(g2, [[-42, -42], [42, 42]], false);
    _stroke(g2, o.cor, (o.traco || 9) + 2);
    var g3 = _grupo(sh);
    _path(g3, [[42, -42], [-42, 42]], false);
    _stroke(g3, o.cor, (o.traco || 9) + 2);
    if (o.t !== undefined) {
      desenhar(g2, o.t + 0.2, 0.3);
      desenhar(g3, o.t + 0.35, 0.3);
    }
    return sh;
  },

  /** Raio — PIX, velocidade, "na hora". */
  raio: function (comp, o) {
    var sh = _base(comp, "ic-raio", o.pos);
    var g = _grupo(sh);
    _path(g, [[18, -95], [-45, 10], [-2, 10], [-18, 95], [45, -12], [2, -12]], true);
    _fill(g, o.cor);
    if (o.t !== undefined) {
      // Preenchimento não aceita trim: entra com pop.
      anim(sh.property("Transform").property("Scale"),
        [[o.t, [0, 0]], [o.t + 0.28, [112, 112]], [o.t + 0.42, [100, 100]]]);
    }
    return sh;
  },

  /** Cartão de crédito. */
  cartao: function (comp, o) {
    var sh = _base(comp, "ic-cartao", o.pos);
    var g = _grupo(sh);
    _retangulo(g, 190, 130, 16);
    _stroke(g, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g, o.t, o.dur);
    var g2 = _grupo(sh);
    _path(g2, [[-95, -22], [95, -22]], false);
    _stroke(g2, o.cor, o.traco || 9);
    var g3 = _grupo(sh);
    _path(g3, [[-70, 36], [-20, 36]], false);
    _stroke(g3, o.cor, (o.traco || 9) - 2);
    if (o.t !== undefined) {
      desenhar(g2, o.t + 0.3, 0.35);
      desenhar(g3, o.t + 0.45, 0.3);
    }
    return sh;
  },

  /** Relógio — tempo, espera, demora. */
  relogio: function (comp, o) {
    var sh = _base(comp, "ic-relogio", o.pos);
    var g = _grupo(sh);
    _elipse(g, 190, 190);
    _stroke(g, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g, o.t, o.dur);
    var g2 = _grupo(sh);
    _path(g2, [[0, -58], [0, 0], [46, 0]], false);
    _stroke(g2, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g2, o.t + 0.3, 0.4);
    return sh;
  },

  /** Cadeado — segurança. */
  cadeado: function (comp, o) {
    var sh = _base(comp, "ic-cadeado", o.pos);
    var g = _grupo(sh);
    _retangulo(g, 150, 110, 14);
    var r = g.property("Contents").property(1);
    r.property("Position").setValue([0, 35]);
    _stroke(g, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g, o.t + 0.2, 0.5);
    var g2 = _grupo(sh);
    _path(g2, [[-48, -20], [-48, -62], [48, -62], [48, -20]], false);
    _stroke(g2, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g2, o.t, 0.45);
    return sh;
  },

  /** Seta subindo — crescimento, mais vendas. */
  grafico: function (comp, o) {
    var sh = _base(comp, "ic-grafico", o.pos);
    var g = _grupo(sh);
    _path(g, [[-90, 55], [-25, -10], [20, 32], [90, -55]], false);
    _stroke(g, o.cor, (o.traco || 9) + 2);
    if (o.t !== undefined) desenhar(g, o.t, o.dur);
    var g2 = _grupo(sh);
    _path(g2, [[52, -55], [90, -55], [90, -18]], false);
    _stroke(g2, o.cor, (o.traco || 9) + 2);
    if (o.t !== undefined) desenhar(g2, o.t + 0.45, 0.3);
    return sh;
  },

  /** Cifrão em anel — dinheiro, receita. */
  dinheiro: function (comp, o) {
    var sh = _base(comp, "ic-dinheiro", o.pos);
    var g = _grupo(sh);
    _elipse(g, 190, 190);
    _stroke(g, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g, o.t, o.dur);
    var g2 = _grupo(sh);
    _path(g2, [[34, -46], [-16, -46], [-34, -22], [-16, 2], [16, 2], [34, 26], [16, 50], [-34, 50]], false);
    _stroke(g2, o.cor, (o.traco || 9) - 1);
    var g3 = _grupo(sh);
    _path(g3, [[0, -68], [0, 70]], false);
    _stroke(g3, o.cor, (o.traco || 9) - 3);
    if (o.t !== undefined) {
      desenhar(g2, o.t + 0.3, 0.5);
      desenhar(g3, o.t + 0.4, 0.3);
    }
    return sh;
  },

  /** Pessoa — cliente, usuário. */
  pessoa: function (comp, o) {
    var sh = _base(comp, "ic-pessoa", o.pos);
    var g = _grupo(sh);
    _elipse(g, 78, 78);
    var e = g.property("Contents").property(1);
    e.property("Position").setValue([0, -46]);
    _stroke(g, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g, o.t, 0.45);
    var g2 = _grupo(sh);
    _path(g2, [[-72, 78], [-72, 40], [-30, 12], [30, 12], [72, 40], [72, 78]], false);
    _stroke(g2, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g2, o.t + 0.25, 0.5);
    return sh;
  },

  /** Celular. */
  celular: function (comp, o) {
    var sh = _base(comp, "ic-celular", o.pos);
    var g = _grupo(sh);
    _retangulo(g, 120, 190, 18);
    _stroke(g, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g, o.t, o.dur);
    var g2 = _grupo(sh);
    _path(g2, [[-18, 72], [18, 72]], false);
    _stroke(g2, o.cor, (o.traco || 9) - 2);
    if (o.t !== undefined) desenhar(g2, o.t + 0.4, 0.25);
    return sh;
  },

  /** Engrenagem simplificada — automação. */
  engrenagem: function (comp, o) {
    var sh = _base(comp, "ic-engrenagem", o.pos);
    var g = _grupo(sh);
    _elipse(g, 110, 110);
    _stroke(g, o.cor, o.traco || 9);
    if (o.t !== undefined) desenhar(g, o.t, 0.5);
    // dentes como traços radiais
    for (var i = 0; i < 8; i++) {
      var a = (i / 8) * Math.PI * 2;
      var gi = _grupo(sh);
      _path(gi, [
        [Math.cos(a) * 68, Math.sin(a) * 68],
        [Math.cos(a) * 95, Math.sin(a) * 95]
      ], false);
      _stroke(gi, o.cor, (o.traco || 9) + 1);
      if (o.t !== undefined) desenhar(gi, o.t + 0.3 + i * 0.04, 0.2);
    }
    return sh;
  }
};

/**
 * Desenha um ícone do catálogo.
 *   icone(comp, "check", { cor:"#22e59a", pos:[540,700], tamanho:70, t:1.2 })
 */
function icone(comp, nome, o) {
  o = o || {};
  var fn = ICONES[nome];
  if (!fn) throw new Error("icone desconhecido: " + nome);
  o.cor = o.cor || "#ffffff";
  var sh = fn(comp, o);
  if (o.tamanho && o.t === undefined) {
    sh.property("Transform").property("Scale").setValue([o.tamanho, o.tamanho]);
  } else if (o.tamanho) {
    // Se há animação de entrada, a escala final tem que respeitar o tamanho.
    var esc = sh.property("Transform").property("Scale");
    if (esc.numKeys > 0) {
      for (var k = 1; k <= esc.numKeys; k++) {
        var v = esc.keyValue(k);
        esc.setValueAtKey(k, [v[0] * o.tamanho / 100, v[1] * o.tamanho / 100]);
      }
    } else {
      esc.setValue([o.tamanho, o.tamanho]);
    }
  }
  return sh;
}
