/**
 * cena3d.jsx — a VSL como um PLANO-SEQUÊNCIA.
 *
 * A ideia que substitui tudo o que veio antes: em vez de cenas que entram e
 * saem, existe UM espaço 3D onde os elementos ficam parados em coordenadas
 * diferentes, e a CÂMERA viaja entre eles. O corte deixa de existir — o vídeo
 * inteiro é um movimento contínuo.
 *
 * Por que isso resolve o "amador":
 *   · Nada mais "aparece do nada": você chega até a informação.
 *   · A profundidade é real (paralaxe entre camadas em Z diferentes), não
 *     simulada por escala.
 *   · O ritmo vira o da câmera, não o de dezenas de fades independentes.
 *
 * A linguagem visual vem do hero do nuxt-web: painel de dashboard com barras
 * subindo, cartões de notificação iOS flutuando, pílula amarela no título.
 */

// Cada "estação" é um ponto do espaço onde a câmera para para mostrar algo.
var PALCO = { estacoes: [], comp: null };

/**
 * Camada 3D posicionada no espaço.
 * O AE trata Z negativo como "mais perto da câmera"; usamos Z positivo para
 * afastar, que é mais intuitivo de ler no código.
 */
function em3d(camada, x, y, z) {
  camada.threeDLayer = true;
  camada.property("Transform").property("Position").setValue([x, y, z || 0]);
  return camada;
}

/**
 * Cria uma ESTAÇÃO: um ponto do espaço com um conteúdo.
 * `fn(grupo, est)` desenha; tudo que ela criar é posicionado em volta de
 * (x, y, z) e vira 3D.
 */
function estacao(comp, id, x, y, z, fn) {
  var est = { id: id, x: x, y: y, z: z || 0, camadas: [] };
  PALCO.estacoes.push(est);
  var criadas = fn(comp, est) || [];
  for (var i = 0; i < criadas.length; i++) {
    var c = criadas[i];
    if (!c) continue;
    c.threeDLayer = true;
    var p = c.property("Transform").property("Position").value;
    // O que a função desenhou em coordenadas de tela vira offset da estação.
    c.property("Transform").property("Position").setValue([
      x + (p[0] - CXf), y + (p[1] - CYf), z + (p.length > 2 ? p[2] : 0)
    ]);
    est.camadas.push(c);
  }
  return est;
}

/**
 * A CÂMERA que atravessa tudo.
 * Um null carrega a câmera: mover o null é mais estável que mover a câmera
 * direto, e permite adicionar tremor/deriva por cima sem perder o percurso.
 *
 * `paradas` = [{ t, x, y, z }] — onde a câmera está em cada instante.
 */
function cameraViajante(comp, paradas, o) {
  o = o || {};
  var dist = o.dist || 1500;

  var nulo = comp.layers.addNull();
  nulo.name = "cam-rig";
  nulo.threeDLayer = true;

  var quadros = [];
  for (var i = 0; i < paradas.length; i++) {
    quadros.push([paradas[i].t, [paradas[i].x, paradas[i].y, paradas[i].z]]);
  }
  // ease 100 = velocidade ZERO em cada keyframe: a câmera parte do repouso,
  // acelera, desacelera e para. Com ease menor ela ainda tem velocidade ao
  // atingir a parada e o movimento "arrasta" — é o que lê como não-fluido.
  anim(nulo.property("Transform").property("Position"), quadros, { ease: 100 });

  // A câmera GIRA para encarar cada estação. É o que transforma o percurso
  // num movimento 3D — sem isso ela só desliza lateralmente, que lê como 2D.
  var giros = [];
  for (var r = 0; r < paradas.length; r++) {
    giros.push([paradas[r].t, [0, paradas[r].ry || 0, 0]]);
  }
  anim(nulo.property("Transform").property("Y Rotation"),
    giros.map(function (g) { return [g[0], g[1][1]]; }), { ease: 92 });

  var cam = comp.layers.addCamera("Camera", [CXf, CYf]);

  // ARMADILHA: addCamera cria câmera de DOIS PONTOS, que mira num "Point of
  // Interest" fixo em [960,540,0]. Com o rig viajando até X=21000 ela aponta
  // para trás e o quadro fica vazio — foi o que deixou o preview branco.
  // autoOrient OFF a transforma em câmera de um ponto: ela olha para onde
  // está virada, e como é filha do rig, olha sempre para frente.
  try { cam.autoOrient = AutoOrientType.NO_AUTO_ORIENT; } catch (e0) {}

  cam.parent = nulo;
  // Recuada em Z relativo ao rig: o rig fica NO plano do conteúdo.
  cam.property("Transform").property("Position").setValue([0, 0, -dist]);
  try { cam.property("Transform").property("Point of Interest").setValue([0, 0, 0]); } catch (e1) {}

  if (o.foco) {
    try {
      var op = cam.property("ADBE Camera Options Group");
      op.property("ADBE Camera Depth of Field").setValue(1);
      op.property("ADBE Camera Focus Distance").setValue(dist);
      op.property("ADBE Camera Aperture").setValue(o.abertura || 22);
    } catch (e2) {}
  }

  // Deriva sutil: mesmo "parada" a câmera respira. Vai no rig, não na câmera,
  // para não brigar com a orientação.
  nulo.property("Transform").property("Orientation").expression =
    "[Math.sin(time*0.19)*0.35, Math.cos(time*0.15)*0.45, 0]";

  return { nulo: nulo, camera: cam };
}

// ---------------------------------------------------------------- painéis

/**
 * PAINEL — o card do site, em 3D.
 * radius 26px, fundo gelo→branco, borda 1px, sem sombra: é o card do hero.
 */
function painel(comp, o) {
  o = o || {};
  var L = [];
  var w = o.largura || 900, h = o.altura || 560;
  var base = caixa(comp, {
    largura: w, altura: h, raio: o.raio || 40,
    cor: o.cor || C.fundo, traco: 2, corTraco: C.borda,
    pos: o.pos || [CXf, CYf], nome: o.nome || "painel"
  });
  L.push(base);
  return { fundo: base, camadas: L, largura: w, altura: h };
}

/**
 * GRÁFICO DE BARRAS do hero: 10 barras com as alturas reais do site,
 * subindo com stagger e depois "respirando".
 */
function graficoBarras(comp, o) {
  o = o || {};
  var alturas = o.alturas || [30, 44, 38, 56, 50, 68, 62, 82, 76, 94];
  var t = o.t || 0;
  var larg = o.largura || 760, alt = o.altura || 300;
  var cx = o.pos ? o.pos[0] : CXf, cy = o.pos ? o.pos[1] : CYf;
  var bw = Math.floor(larg / alturas.length) - 14;
  var L = [];

  for (var i = 0; i < alturas.length; i++) {
    var hb = Math.round(alt * alturas[i] / 100);
    var x = cx - larg / 2 + i * (bw + 14) + bw / 2;
    var y = cy + alt / 2 - hb / 2;
    var b = caixa(comp, {
      largura: bw, altura: hb, raio: 10,
      cor: o.cor || C.amarelo, pos: [x, y], nome: "barra" + i
    });
    // Cresce de baixo: âncora no pé da barra.
    var g = b._grupo;
    g.property("Transform").property("Anchor Point").setValue([0, hb / 2]);
    g.property("Transform").property("Position").setValue([0, hb / 2]);
    var d = t + i * 0.075;           // stagger de 0.1s no site
    anim(g.property("Transform").property("Scale"),
      [[d, [100, 0]], [d + 0.42, [100, 108]], [d + 0.58, [100, 100]]], { ease: 74 });
    // Depois de crescer, respira (o site faz isso com animação infinita).
    g.property("Transform").property("Scale").expression =
      "var s = value; var t0=" + (d + 0.7) + ";" +
      "[s[0], s[1] * (time>t0 ? 1+Math.sin((time-t0)*1.9 + " + i + ")*0.035 : 1)]";
    L.push(b);
  }
  return L;
}

/**
 * NOTIFICAÇÃO iOS — o cartão que pinga no hero do site.
 * radius 18px, ícone amarelo 42px com o símbolo, título + linha de apoio.
 */
function notificacao(comp, o) {
  o = o || {};
  var t = o.t || 0;
  var cx = o.pos ? o.pos[0] : CXf, cy = o.pos ? o.pos[1] : CYf;
  var w = o.largura || 620, h = o.altura || 150;
  var L = [];

  var cd = caixa(comp, {
    largura: w, altura: h, raio: 28, cor: C.fundo,
    traco: 2, corTraco: C.borda, pos: [cx, cy], nome: "notif"
  });
  L.push(cd);

  var ic = caixa(comp, {
    largura: 74, altura: 74, raio: 20, cor: C.amarelo,
    pos: [cx - w / 2 + 62, cy], nome: "notif-icone"
  });
  L.push(ic);

  var sb = simbolo(comp, { tamanho: 42, pos: [cx - w / 2 + 62, cy] });
  if (sb) L.push(sb);

  L.push(txt(comp, o.titulo || "Venda aprovada", {
    tamanho: fs("f3"), peso: "medium", cor: C.texto,
    pos: [cx - w / 2 + 130, cy - 22], alinha: "esq"
  }));
  L.push(txt(comp, o.linha || "R$ 497,00", {
    tamanho: fs("f1"), peso: "book", cor: C.textoFraco,
    pos: [cx - w / 2 + 130, cy + 24], alinha: "esq"
  }));
  L.push(txt(comp, "agora", {
    tamanho: fs("f0"), peso: "book", cor: C.textoFraco,
    pos: [cx + w / 2 - 70, cy - 22], alinha: "dir"
  }));

  // Entra deslizando de cima com overshoot, depois flutua — igual ao site.
  for (var i = 0; i < L.length; i++) {
    var P = L[i].property("Transform").property("Position");
    var v = P.value;
    anim(P, [
      [t,        [v[0], v[1] - 46]],
      [t + 0.34, [v[0], v[1] + 7]],
      [t + 0.48, [v[0], v[1]]]
    ], { ease: 72 });
    anim(L[i].property("Transform").property("Opacity"),
      [[t, 0], [t + 0.2, 100]], { ease: 60 });
  }
  return L;
}

/** Selo de check verde com rótulo — as condições do hero. */
function condicao(comp, rotulo, o) {
  o = o || {};
  var cx = o.pos ? o.pos[0] : CXf, cy = o.pos ? o.pos[1] : CYf;
  var L = [];
  var ck = check(comp, { tamanho: 22, pos: [cx - 20, cy], cor: C.verde });
  L.push(ck);
  L.push(txt(comp, rotulo, {
    tamanho: fs("f1"), peso: "book", cor: C.textoFraco,
    pos: [cx + 20, cy], alinha: "esq"
  }));
  return L;
}

/**
 * TÍTULO GRANDE da estação, com a pílula amarela.
 * Reaproveita `titulo()` do marca.jsx, mas as palavras entram uma a uma no
 * ritmo da fala — kinetic typography.
 */
function tituloEstacao(comp, linhas, o) {
  o = o || {};
  var out = titulo(comp, linhas, o);
  var t = o.t || 0;
  for (var i = 0; i < out.length; i++) {
    var P = out[i].property("Transform").property("Position");
    var v = P.value;
    var d = t + i * 0.11;
    anim(P, [
      [d,        [v[0], v[1] + 54]],
      [d + 0.38, [v[0], v[1] - 6]],
      [d + 0.52, [v[0], v[1]]]
    ], { ease: 76 });
    anim(out[i].property("Transform").property("Opacity"),
      [[d, 0], [d + 0.22, 100]], { ease: 60 });
  }
  return out;
}

/**
 * NÚMERO GIGANTE que a câmera atravessa.
 * O gesto mais forte do vídeo: o valor ocupa a tela inteira e a câmera passa
 * por dentro dele para a próxima estação.
 */
function numeroGigante(comp, valor, o) {
  o = o || {};
  var t = o.t || 0;
  var l = txt(comp, valor, {
    tamanho: o.tamanho || fs("f13") + 120, peso: "black",
    cor: o.cor || C.texto, pos: o.pos || [CXf, CYf], lh: 1
  });
  anim(l.property("Transform").property("Scale"),
    [[t, [86, 86]], [t + 0.5, [103, 103]], [t + 0.7, [100, 100]]], { ease: 72 });
  anim(l.property("Transform").property("Opacity"),
    [[t, 0], [t + 0.25, 100]], { ease: 60 });
  return l;
}

/** Régua/linha que conecta duas estações no espaço. */
function trilho(comp, o) {
  o = o || {};
  var l = caixa(comp, {
    largura: o.comprimento || 900, altura: 4, raio: 2,
    cor: o.cor || C.borda, pos: o.pos || [CXf, CYf], nome: "trilho"
  });
  var t = o.t || 0;
  var g = l._grupo;
  g.property("Transform").property("Anchor Point").setValue([-(o.comprimento || 900) / 2, 0]);
  g.property("Transform").property("Position").setValue([-(o.comprimento || 900) / 2, 0]);
  anim(g.property("Transform").property("Scale"),
    [[t, [0, 100]], [t + (o.duracao || 0.9), [100, 100]]], { ease: 62 });
  return l;
}

/**
 * Empurra uma camada em Z DENTRO da estação, criando profundidade interna.
 * É o que faltava: com tudo no mesmo Z a cena é um adesivo plano no espaço.
 * Fundo atrás, conteúdo no plano, destaque à frente — a câmera se movendo
 * revela o paralaxe entre eles.
 */
function profundidade(camada, dz) {
  var P = camada.property("Transform").property("Position");
  if (P.numKeys > 0) {
    for (var k = 1; k <= P.numKeys; k++) {
      var v = P.keyValue(k);
      P.setValueAtKey(k, [v[0], v[1], (v.length > 2 ? v[2] : 0) + dz]);
    }
  } else {
    var p = P.value;
    P.setValue([p[0], p[1], (p.length > 2 ? p[2] : 0) + dz]);
  }
  return camada;
}

/**
 * Move um conjunto de camadas para a estação (x, y, z), tornando-as 3D.
 *
 * O detalhe que quebra se ignorado: camadas com KEYFRAMES de posição recusam
 * `setValue` ("use setValueAtTime"). Como a animação já foi criada quando o
 * bloco desenhou, aqui deslocamos CADA keyframe — e só usamos setValue quando
 * a propriedade é estática.
 */
function moverEstacao(camadas, x, y, z, dzs) {
  for (var i = 0; i < camadas.length; i++) {
    var c = camadas[i];
    if (!c) continue;
    c.threeDLayer = true;
    // Profundidade interna: cada camada num Z ligeiramente diferente.
    var dz = dzs ? (dzs[i] || 0) : 0;
    var P = c.property("Transform").property("Position");

    if (P.numKeys > 0) {
      // Desloca os keyframes existentes preservando o gesto da animação.
      for (var k = 1; k <= P.numKeys; k++) {
        var v = P.keyValue(k);
        P.setValueAtKey(k, [
          x + (v[0] - CXf),
          y + (v[1] - CYf),
          z + dz + (v.length > 2 ? v[2] : 0)
        ]);
      }
    } else if (P.expression && P.expression !== "") {
      // Expression manda na posição: em vez de mexer no valor, envolvemos a
      // expression com o deslocamento da estação.
      var ex = P.expression;
      P.expression =
        "var _b = (function(){" + ex + "})();" +
        "[" + x + " + (_b[0] - " + CXf + "), " +
        y + " + (_b[1] - " + CYf + "), " + (z + dz) + "]";
    } else {
      var p = P.value;
      P.setValue([x + (p[0] - CXf), y + (p[1] - CYf), z + dz + (p.length > 2 ? p[2] : 0)]);
    }
  }
  return camadas;
}

/** Achata array de arrays. */
function achatar(arr) {
  var out = [];
  for (var i = 0; i < arr.length; i++) {
    if (arr[i] instanceof Array) {
      for (var j = 0; j < arr[i].length; j++) out.push(arr[i][j]);
    } else out.push(arr[i]);
  }
  return out;
}

// ---------------------------------------------------------------- grid
//
// Sistema de layout com BASELINE GRID. A regra que elimina desalinhamento:
// nenhuma posição é escolhida à mão. Toda coordenada X sai de uma coluna e
// toda coordenada Y sai de uma linha de base de 8px (o mesmo passo do site).
//
// Isso resolve por construção o que vinha aparecendo: etiqueta, título e
// apoio em três alinhamentos diferentes, cards de larguras distintas na
// mesma coluna, e texto centralizado onde deveria estar à esquerda.

var GRID = { colunas: 12, largura: 1160, gutter: 30, offset: 63, base: 8 };

/** Centro X da coluna `c` abrangendo `span` colunas. */
function col(c, span) {
  span = span || 1;
  var passo = GRID.largura / GRID.colunas;
  var x0 = CXf - GRID.largura / 2 + (GRID.offset || 0);
  return x0 + c * passo + (span * passo) / 2;
}

/**
 * Borda ESQUERDA da coluna `c`. Com texto alinhado à esquerda a posição é
 * onde ele COMEÇA — passar o centro empurra tudo e invade a coluna vizinha.
 */
function colEsq(c) {
  return CXf - GRID.largura / 2 + (GRID.offset || 0) + c * (GRID.largura / GRID.colunas);
}

/** Borda DIREITA de `c` + `span`. */
function colDir(c, span) {
  return CXf - GRID.largura / 2 + (GRID.offset || 0) +
         (c + (span || 1)) * (GRID.largura / GRID.colunas) - GRID.gutter;
}

/** Largura de `span` colunas, descontando o gutter. */
function larguraCol(span) {
  return (GRID.largura / GRID.colunas) * span - GRID.gutter;
}

/** Linha de base: `n` passos de 8px a partir do centro vertical. */
function base(n) { return CYf + n * GRID.base; }

/** Linha em passos de 90px (mantido para compatibilidade). */
function lin(l) { return CYf + l * 90; }

/**
 * PILHA vertical: empilha blocos com espaçamento consistente e devolve a
 * posição Y de cada um. É o que garante que etiqueta, título e apoio fiquem
 * com o mesmo ritmo vertical em todas as estações.
 *
 *   var y = pilha(base(-24), [{h:20}, {h:150, gap:28}, {h:40, gap:36}]);
 */
function pilha(yInicial, blocos) {
  var ys = [], y = yInicial;
  for (var i = 0; i < blocos.length; i++) {
    y += (blocos[i].gap || 0);
    ys.push(Math.round(y + blocos[i].h / 2));
    y += blocos[i].h;
  }
  return ys;
}

/**
 * CARD com cabeçalho, ícone e valor — o bloco padrão das estações.
 * Tudo posicionado por grid, com paddings fixos: é o que dá o alinhamento
 * "redondinho" que faltava.
 */
function cardDado(comp, o) {
  o = o || {};
  var t = o.t || 0;
  var cx = o.pos[0], cy = o.pos[1];
  var w = o.largura || larguraCol(4), h = o.altura || 420;
  var L = [];
  var padTopo = 56;

  var base = caixa(comp, {
    largura: w, altura: h, raio: 40,
    cor: o.cor || C.banda,
    traco: o.traco || 0, corTraco: o.corTraco || C.borda,
    pos: [cx, cy], nome: o.nome || "card"
  });
  L.push(base);

  // Ícone no topo, dentro de um quadrado arredondado (padrão do site).
  if (o.icone) {
    var badge = caixa(comp, {
      largura: 84, altura: 84, raio: 24,
      cor: o.corIcone || C.amarelo,
      pos: [cx, cy - h / 2 + 74], nome: "badge"
    });
    L.push(badge);
    var ic = icone(comp, o.icone, {
      tamanho: 46, pos: [cx, cy - h / 2 + 74],
      corNome: o.iconeCor || "branco"
    });
    if (ic) L.push(ic);
    // O badge chega com pop: é o primeiro elemento a se mexer no card.
    anim(badge.property("Transform").property("Scale"),
      [[t + 0.2, [40, 40]], [t + 0.42, [110, 110]], [t + 0.56, [100, 100]]], { ease: 70 });
    anim(badge.property("Transform").property("Opacity"), [[t + 0.2, 0], [t + 0.32, 100]], { ease: 60 });
  }

  // Layout vertical do card, de cima para baixo, com espaços proporcionais:
  //   badge (se houver) · rótulo · valor · nota
  // Antes o rótulo e o valor eram posicionados por offsets fixos e se
  // sobrepunham quando o valor era grande.
  var topo = cy - h / 2;
  var yBadge  = topo + 74;
  var yRotulo = topo + (o.icone ? 158 : 68);
  var yValor  = topo + (o.icone ? 250 : 150) + (o.tamanho || fs("f12")) * 0.32;
  var yNota   = cy + h / 2 - 46;

  if (o.rotulo) L.push(etiqueta(comp, o.rotulo, { pos: [cx, yRotulo] }));

  if (o.contador) {
    L.push(contador(comp, {
      de: 0, para: o.contador, prefixo: o.prefixo === undefined ? "R$ " : o.prefixo,
      sufixo: o.sufixo || "", tamanho: o.tamanho || fs("f12"),
      peso: o.peso || "black", cor: o.corValor || C.texto,
      pos: [cx, yValor], inicio: t + 0.55, duracao: o.durContador || 1.1
    }));
  } else if (o.valor) {
    L.push(txt(comp, o.valor, {
      tamanho: o.tamanho || fs("f12"), peso: o.peso || "black",
      cor: o.corValor || C.texto, pos: [cx, yValor]
    }));
  }
  if (o.nota) {
    L.push(txt(comp, o.nota, {
      tamanho: fs("f2"), peso: "book", cor: C.textoFraco, pos: [cx, yNota] }));
  }

  // Entrada do card: sobe com overshoot.
  var vb = base.property("Transform").property("Position").value;
  anim(base.property("Transform").property("Position"),
    [[t, [vb[0], vb[1] + 70]], [t + 0.44, [vb[0], vb[1] - 8]], [t + 0.58, vb]], { ease: 76 });
  anim(base.property("Transform").property("Opacity"), [[t, 0], [t + 0.24, 100]], { ease: 60 });

  return { camadas: L, base: base, largura: w, altura: h };
}

/** Seta de fluxo entre dois pontos, com haste que cresce e ponta que chega. */
function seta(comp, de, ate, o) {
  o = o || {};
  var t = o.t || 0;
  var L = [];
  var meio = [(de[0] + ate[0]) / 2, (de[1] + ate[1]) / 2];
  var comprimento = Math.abs(ate[0] - de[0]) - 40;

  var haste = caixa(comp, {
    largura: comprimento, altura: 6, raio: 3,
    cor: o.cor || C.amarelo, pos: meio, nome: "haste"
  });
  var g = haste._grupo;
  g.property("Transform").property("Anchor Point").setValue([-comprimento / 2, 0]);
  g.property("Transform").property("Position").setValue([-comprimento / 2, 0]);
  anim(g.property("Transform").property("Scale"),
    [[t, [0, 100]], [t + 0.55, [100, 100]]], { ease: 66 });
  L.push(haste);

  var pta = comp.layers.addShape(); pta.name = "ponta";
  var gp = pta.property("Contents").addProperty("ADBE Vector Group");
  var pa = gp.property("Contents").addProperty("ADBE Vector Shape - Group");
  var s = new Shape();
  s.vertices = [[-15, -16], [16, 0], [-15, 16]];
  s.inTangents = [[0,0],[0,0],[0,0]]; s.outTangents = [[0,0],[0,0],[0,0]];
  s.closed = true;
  pa.property("Path").setValue(s);
  gp.property("Contents").addProperty("ADBE Vector Graphic - Fill")
    .property("Color").setValue(hex(o.cor || C.amarelo));
  pta.property("Transform").property("Position").setValue([meio[0] + comprimento / 2, meio[1]]);
  anim(pta.property("Transform").property("Opacity"),
    [[t + 0.45, 0], [t + 0.62, 100]], { ease: 60 });
  L.push(pta);
  return L;
}

/**
 * Gira as camadas da estação em torno do eixo Y dela.
 * Sem isso todas as estações ficam paralelas à tela e o percurso lê como
 * deslize 2D. Com ângulos alternados a câmera precisa GIRAR para encarar
 * cada uma, e aí a viagem vira tridimensional de verdade.
 */
function girarEstacao(camadas, x, y, z, graus) {
  if (!graus) return camadas;
  var rad = graus * Math.PI / 180;
  var cos = Math.cos(rad), sen = Math.sin(rad);
  for (var i = 0; i < camadas.length; i++) {
    var c = camadas[i];
    if (!c) continue;
    c.property("Transform").property("Y Rotation").setValue(graus);

    // Rotaciona a POSIÇÃO em torno do centro da estação: girar só a camada
    // deixaria cada uma virada no seu próprio eixo, e o conjunto se abriria
    // como um leque em vez de girar como um painel só.
    var P = c.property("Transform").property("Position");
    function gira(v) {
      var dx = v[0] - x, dz = (v.length > 2 ? v[2] : 0) - z;
      return [x + dx * cos + dz * sen, v[1], z - dx * sen + dz * cos];
    }
    if (P.numKeys > 0) {
      for (var k = 1; k <= P.numKeys; k++) P.setValueAtKey(k, gira(P.keyValue(k)));
    } else if (!P.expression || P.expression === "") {
      P.setValue(gira(P.value));
    }
  }
  return camadas;
}

// ---------------------------------------------------------------- revelação

/**
 * REVELA o texto por máscara: ele surge de trás de uma borda, deslizando.
 * É a diferença mais visível entre motion profissional e "fade + slide":
 * o texto não aparece com opacidade, ele ENTRA no quadro.
 *
 * A máscara acompanha a camada, então funciona em 3D e com a câmera girando.
 */
function revela(camada, t, o) {
  o = o || {};
  var dur = o.duracao || 0.55;
  var r = null;
  try { r = camada.sourceRectAtTime(0, false); } catch (e) {}
  var w = (r && isFinite(r.width) && r.width > 0) ? r.width : 900;
  var h = (r && isFinite(r.height) && r.height > 0) ? r.height : 120;
  var cx = r ? r.left + r.width / 2 : 0;
  var cy = r ? r.top + r.height / 2 : 0;
  var folga = 60;

  var m = camada.property("ADBE Mask Parade").addProperty("ADBE Mask Atom");
  var mp = m.property("ADBE Mask Shape");

  // A máscara é ANIMADA no próprio caminho: a borda direita varre da
  // esquerda para a direita. (Mask Offset é escalar e não serve para isso —
  // ele expande a forma inteira, não desloca.)
  function janela(direita) {
    var f = new Shape();
    f.vertices = [
      [cx - w / 2 - folga, cy - h / 2 - folga],
      [direita,            cy - h / 2 - folga],
      [direita,            cy + h / 2 + folga],
      [cx - w / 2 - folga, cy + h / 2 + folga]
    ];
    f.inTangents = [[0,0],[0,0],[0,0],[0,0]];
    f.outTangents = [[0,0],[0,0],[0,0],[0,0]];
    f.closed = true;
    return f;
  }
  mp.setValueAtTime(t, janela(cx - w / 2 - folga + 1));
  mp.setValueAtTime(t + dur, janela(cx + w / 2 + folga));

  // O conteúdo entra levemente atrasado em relação à máscara: esse
  // descompasso é o que lê como "empurrado para dentro".
  var P = camada.property("Transform").property("Position");
  var v = P.value;
  anim(P, [
    [t,             [v[0] - (o.desloc || 46), v[1]]],
    [t + dur * 0.8, [v[0] + 6, v[1]]],
    [t + dur,       v]
  ], { ease: 78 });
  return camada;
}

/**
 * STAGGER: aplica um gesto a uma lista, com atraso crescente.
 * O atraso é o que faz a composição "montar" em vez de piscar inteira.
 * `passo` de 0.07-0.12s é a faixa que lê como intencional.
 */
function emCascata(camadas, t, passo, gesto) {
  passo = passo || 0.09;
  for (var i = 0; i < camadas.length; i++) {
    if (!camadas[i]) continue;
    gesto(camadas[i], t + i * passo, i);
  }
  return camadas;
}

/**
 * Entrada com escala ANISOTRÓPICA: o elemento nasce achatado e "infla".
 * Escalar X e Y igualmente lê como zoom; escalar diferente lê como material.
 */
function infla(camada, t, o) {
  o = o || {};
  var dur = o.duracao || 0.5;
  var S = camada.property("Transform").property("Scale");
  var e = S.value;
  anim(S, [
    [t,             [e[0] * 0.86, e[1] * 0.55]],
    [t + dur * 0.55,[e[0] * 1.04, e[1] * 1.08]],
    [t + dur * 0.8, [e[0] * 0.99, e[1] * 0.97]],
    [t + dur,       e]
  ], { ease: 72 });
  anim(camada.property("Transform").property("Opacity"),
    [[t, 0], [t + dur * 0.3, 100]], { ease: 60 });
  return camada;
}

/**
 * LINHA que se desenha ligando dois pontos — conecta elementos e guia o olho.
 * Usa Trim Paths, então é traçada, não aparece pronta.
 */
function conector(comp, de, ate, o) {
  o = o || {};
  var t = o.t || 0;
  var sh = comp.layers.addShape();
  sh.name = o.nome || "conector";
  var g = sh.property("Contents").addProperty("ADBE Vector Group");
  var pa = g.property("Contents").addProperty("ADBE Vector Shape - Group");
  var s = new Shape();
  var mx = (de[0] + ate[0]) / 2;
  s.vertices = [[de[0] - CXf, de[1] - CYf], [mx - CXf, de[1] - CYf],
                [mx - CXf, ate[1] - CYf], [ate[0] - CXf, ate[1] - CYf]];
  s.inTangents = [[0,0],[0,0],[0,0],[0,0]];
  s.outTangents = [[0,0],[0,0],[0,0],[0,0]];
  s.closed = false;
  pa.property("Path").setValue(s);
  var st = g.property("Contents").addProperty("ADBE Vector Graphic - Stroke");
  st.property("Color").setValue(hex(o.cor || C.borda));
  st.property("Stroke Width").setValue(o.espessura || 3);
  st.property("Line Cap").setValue(2);
  st.property("Line Join").setValue(2);
  var tr = g.property("Contents").addProperty("ADBE Vector Filter - Trim");
  anim(tr.property("End"), [[t, 0], [t + (o.duracao || 0.7), 100]], { ease: 64 });
  sh.property("Transform").property("Position").setValue([CXf, CYf]);
  return sh;
}
