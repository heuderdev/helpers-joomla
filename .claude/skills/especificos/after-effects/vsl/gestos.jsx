/**
 * gestos.jsx — entradas, saídas e passagens da VSL.
 *
 * Duas regras que separam motion de PowerPoint:
 *
 * 1. O gesto de ENTRADA e o de SAÍDA são o mesmo, na mesma direção. Cena que
 *    entra subindo, sai subindo: o conteúdo ATRAVESSA o quadro em vez de
 *    quicar dentro dele.
 * 2. Escala se interpola em LOG, não linear. O olho lê escala logaritmicamente;
 *    1→16 linear parece travar no fim.
 *
 * Tempos de referência (motion design):
 *   entrada que assenta ... 350-500ms
 *   saída ................. ~75% da entrada
 *   match cut ............. 400-600ms
 *   whip pan .............. 270-400ms
 */

var ENTRA = 0.42;      // 420ms
var SAI   = 0.26;      // ~75%

// ---------------------------------------------------------------- entradas

/**
 * Entrada da cena. `t` é quando a cena começa, `dur` quanto ela dura.
 * O mesmo gesto é aplicado na saída, na mesma direção.
 */
function entrada(camadas, tipo, t, dur, indice) {
  if (!(camadas instanceof Array)) camadas = [camadas];
  var fim = t + dur;

  for (var i = 0; i < camadas.length; i++) {
    var c = camadas[i];
    if (!c) continue;
    var P = c.property("Transform").property("Position");
    var S = c.property("Transform").property("Scale");
    var O = c.property("Transform").property("Opacity");
    var base = P.value;
    var escala = S.value;
    // Cascata: elementos seguintes entram 0.06s depois. Entrada simultânea
    // de tudo lê como slide; escalonada lê como composição.
    var d = t + i * 0.06;

    if (tipo === "deslizeX") {
      // Entra na direção em que o dinheiro anda; alterna por cena para o
      // padrão não virar previsível depois de três repetições.
      var lado = (indice % 2 === 0) ? 1 : -1;
      anim(P, [[d, [base[0] + 150 * lado, base[1]]], [d + ENTRA, base]], { ease: 78 });
      anim(P, [[fim - SAI, base], [fim, [base[0] - 150 * lado, base[1]]]], { ease: 40 });

    } else if (tipo === "zoom") {
      // Avança para dentro: começa maior e assenta. Sai avançando MAIS —
      // recuar leria como "desfazer".
      anim(S, [[d, [escala[0] * 1.12, escala[1] * 1.12]], [d + ENTRA, escala]], { ease: 80 });
      anim(S, [[fim - SAI, escala], [fim, [escala[0] * 1.06, escala[1] * 1.06]]], { ease: 40 });
      anim(O, [[d, 0], [d + ENTRA * 0.6, 100]], { ease: 60 });
      anim(O, [[fim - SAI, 100], [fim, 0]], { ease: 40 });
      continue;

    } else if (tipo === "cortina") {
      anim(P, [[d, [base[0], base[1] + 50]], [d + ENTRA, base]], { ease: 80 });
      anim(P, [[fim - SAI, base], [fim, [base[0], base[1] - 50]]], { ease: 40 });

    } else {  // "subir" — o neutro: assenta o número na mesa
      anim(P, [[d, [base[0], base[1] + 75]], [d + ENTRA, base]], { ease: 82 });
      anim(P, [[fim - SAI, base], [fim, [base[0], base[1] - 75]]], { ease: 40 });
    }

    anim(O, [[d, 0], [d + ENTRA * 0.65, 100]], { ease: 60 });
    anim(O, [[fim - SAI, 100], [fim, 0]], { ease: 40 });
  }
  return camadas;
}

/** Recorta as camadas no tempo da cena (com folga para a transição respirar). */
function recortar(camadas, t, dur) {
  if (!(camadas instanceof Array)) camadas = [camadas];
  for (var i = 0; i < camadas.length; i++) {
    if (!camadas[i]) continue;
    camadas[i].inPoint = Math.max(0, t - 0.35);
    camadas[i].outPoint = t + dur + 0.35;
  }
  return camadas;
}

// ---------------------------------------------------------------- passagens

/**
 * ESTOURO — pulso de luz da marca no corte. Reservado às viradas de ato.
 * Curto de propósito: flash longo vira efeito de vídeo caseiro.
 */
function passagemEstouro(comp, t, o) {
  o = o || {};
  var f = comp.layers.addSolid(hex(o.cor || C.amarelo), "flash", W, H, 1);
  f.property("Transform").property("Opacity").expression =
    'var t0=' + t + ', d=' + (o.duracao || 0.34) + ';' +
    'var p=(time-t0)/d;' +
    '(p<0||p>1) ? 0 : Math.sin(p*Math.PI)*' + (o.forca || 70);
  f.inPoint = t - 0.05; f.outPoint = t + (o.duracao || 0.34) + 0.05;
  f.blendingMode = BlendingMode.ADD;
  return f;
}

/**
 * IRIS — a cena nova nasce de um PONTO da anterior. É a passagem que mais
 * AMARRA duas cenas: passe as coordenadas do elemento âncora em cx/cy.
 */
function passagemIris(comp, t, o) {
  o = o || {};
  var cx = o.cx === undefined ? CXf : o.cx;
  var cy = o.cy === undefined ? CYf : o.cy;
  var dur = o.duracao || 0.5;

  // Raio que cobre a tela a partir de (cx,cy): distância ao canto mais
  // distante. Usar metade da largura deixa canto descoberto quando a origem
  // não é o centro — aparece como uma sobra de um frame na virada.
  var dx = Math.max(cx, W - cx), dy = Math.max(cy, H - cy);
  var raioMax = Math.sqrt(dx * dx + dy * dy);

  var m = comp.layers.addShape();
  m.name = "iris";
  var g = m.property("Contents").addProperty("ADBE Vector Group");
  var el = g.property("Contents").addProperty("ADBE Vector Shape - Ellipse");
  el.property("Size").setValue([raioMax * 2, raioMax * 2]);
  g.property("Contents").addProperty("ADBE Vector Graphic - Fill")
   .property("Color").setValue(hex(C.fundo));
  m.property("Transform").property("Position").setValue([cx, cy]);

  // O círculo ENCOLHE para revelar: começa cobrindo tudo e some no ponto.
  anim(m.property("Transform").property("Scale"),
    [[t, [100, 100]], [t + dur, [0, 0]]], { ease: 70 });
  m.inPoint = t - 0.05; m.outPoint = t + dur + 0.05;
  return m;
}

/**
 * WHIP — varre no sentido em que o dinheiro anda. O rastro acompanha a
 * VELOCIDADE, não o progresso: é a distância percorrida no frame que
 * fisicamente causa borrão.
 */
function passagemWhip(comp, t, o) {
  o = o || {};
  var sentido = o.sentido === undefined ? 1 : o.sentido;
  var dur = o.duracao || 0.32;
  // Sólido de 1.2x a largura (não 2x) e SEM Gaussian Blur: o blur num sólido
  // de 3840px animado por expression foi o que derrubou o AE (o relatório de
  // falha apontou ADBE Gaussian Blur 2 como único efeito em uso). O rastro
  // vem do motion blur da própria comp, que é bem mais barato.
  var f = comp.layers.addSolid(hex(o.cor || C.fundo), "whip", Math.round(W * 1.2), H, 1);
  f.motionBlur = true;
  f.property("Transform").property("Position").expression =
    'var t0=' + t + ', d=' + dur + ', s=' + sentido + ';' +
    'var p=Math.min(1,Math.max(0,(time-t0)/d));' +
    'var e=p<0.5 ? 4*p*p*p : 1-Math.pow(-2*p+2,3)/2;' +
    '[' + CXf + ' + (e*2-1)*' + Math.round(W * 1.5) + '*s, ' + CYf + ']';
  f.inPoint = t - 0.05; f.outPoint = t + dur + 0.05;
  return f;
}

/**
 * MATCH CUT — a câmera entra DENTRO do elemento. As duas cenas escalam em
 * fatores diferentes; esse paralaxe é o que lê como câmera avançando, em vez
 * de "elemento crescendo sobre fundo parado".
 */
function matchCutSaida(camadas, t, escalaFinal, dur) {
  if (!(camadas instanceof Array)) camadas = [camadas];
  dur = dur || 0.5;
  for (var i = 0; i < camadas.length; i++) {
    var c = camadas[i];
    if (!c) continue;
    var S = c.property("Transform").property("Scale");
    var e = S.value;
    // Escala em log: interpolar 1→16 linearmente trava no fim.
    var passos = 6, quadros = [];
    for (var k = 0; k <= passos; k++) {
      var p = k / passos;
      var fator = Math.exp(Math.log(escalaFinal || 12) * p);
      quadros.push([t + dur * p, [e[0] * fator, e[1] * fator]]);
    }
    anim(S, quadros, { ease: false });
    anim(c.property("Transform").property("Opacity"),
      [[t, 100], [t + dur * 0.75, 0]], { ease: 40 });
  }
  return camadas;
}

/** EMPURRA — a cena nova empurra a anterior para cima. A passagem neutra. */
function passagemEmpurra(camadas, t, dur) {
  if (!(camadas instanceof Array)) camadas = [camadas];
  dur = dur || 0.42;
  for (var i = 0; i < camadas.length; i++) {
    var c = camadas[i];
    if (!c) continue;
    var P = c.property("Transform").property("Position");
    var b = P.value;
    anim(P, [[t, b], [t + dur, [b[0], b[1] - H * 0.55]]], { ease: 45 });
  }
  return camadas;
}

// ---------------------------------------------------------------- vida

/**
 * Respiração: movimento contínuo e quase imperceptível.
 * Sem isso a cena "morre" enquanto a locutora fala — é o que separa vídeo
 * vivo de slide parado.
 */
function respirar(camada, forca, vel) {
  forca = forca || 6; vel = vel || 1.1;
  var P = camada.property("Transform").property("Position");
  var v = P.value;
  P.expression =
    '[' + v[0] + ' + Math.sin(time*' + (vel * 0.7) + ')*' + (forca * 0.5) + ', ' +
    v[1] + ' + Math.sin(time*' + vel + ')*' + forca + ']';
  return camada;
}

/** Deriva lenta da câmera: dá profundidade sem custo de render. */
function derivaCamera(comp, dur) {
  var cam = comp.layers.addCamera("Cam", [CXf, CYf]);
  var P = cam.property("Transform").property("Position");
  anim(P, [[0, [CXf, CYf, -2050]], [dur, [CXf, CYf, -1900]]], { ease: 30 });
  return cam;
}

/**
 * Vinheta: escurece as bordas e puxa o olho para o centro.
 * Feita com shape + máscara em vez do efeito "Ellipse": o matchName do efeito
 * varia entre versões do AE e derruba o script inteiro quando não existe.
 */
function vinheta(comp) {
  var v = comp.layers.addSolid(hex("#000000"), "vinheta", W * 1.6, H * 1.6, 1);
  v.property("Transform").property("Position").setValue([CXf, CYf]);

  // Máscara elíptica subtraída: sobra só a moldura escura nas bordas.
  var m = v.property("ADBE Mask Parade").addProperty("ADBE Mask Atom");
  var forma = new Shape();
  forma.vertices = [[-W * 0.74, 0], [0, -H * 0.74], [W * 0.74, 0], [0, H * 0.74]];
  var k = 0.5523;
  forma.inTangents  = [[0, H * 0.74 * k], [-W * 0.74 * k, 0], [0, -H * 0.74 * k], [W * 0.74 * k, 0]];
  forma.outTangents = [[0, -H * 0.74 * k], [W * 0.74 * k, 0], [0, H * 0.74 * k], [-W * 0.74 * k, 0]];
  forma.closed = true;
  m.property("ADBE Mask Shape").setValue(forma);
  m.maskMode = MaskMode.SUBTRACT;
  m.property("ADBE Mask Feather").setValue([520, 520]);

  v.property("Transform").property("Opacity").setValue(20);
  return v;
}
