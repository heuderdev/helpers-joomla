/**
 * ae.jsx — biblioteca base para montar vídeos no After Effects por código.
 *
 * ExtendScript é ES3: nada de arrow function, let/const, template literal.
 *
 * Regra que guia tudo aqui: efeito e propriedade sempre por matchName
 * ("ADBE Glo2"), nunca pelo nome exibido — o AE do Eduardo está em português
 * e o nome muda com o idioma, o matchName não.
 */

// ---------------------------------------------------------------- utilidades

function hex(h) {
  h = h.replace("#", "");
  if (h.length === 3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
  return [
    parseInt(h.substring(0,2),16)/255,
    parseInt(h.substring(2,4),16)/255,
    parseInt(h.substring(4,6),16)/255
  ];
}

function log(msg) {
  if (typeof $ !== "undefined" && $.writeln) $.writeln(msg);
}

/** Grava JSON/texto em disco. Exige a preferência de scripts gravarem arquivos. */
function escrever(caminho, texto) {
  var f = new File(caminho);
  f.open("w");
  f.encoding = "UTF-8";
  f.write(texto);
  f.close();
  return f;
}

function ler(caminho) {
  var f = new File(caminho);
  if (!f.exists) throw new Error("arquivo nao encontrado: " + caminho);
  f.open("r");
  f.encoding = "UTF-8";
  var t = f.read();
  f.close();
  return t;
}

// ES3 não tem JSON nativo em versões antigas; o AE moderno tem, mas garantimos.
function parseJSON(txt) {
  if (typeof JSON !== "undefined" && JSON.parse) return JSON.parse(txt);
  return eval("(" + txt + ")");
}

// ---------------------------------------------------------------- projeto

function novoProjeto() {
  app.newProject();
  // Sem diálogos: qualquer alerta trava o AE e mata a automação por AppleScript.
  app.beginSuppressDialogs();
  return app.project;
}

function fecharProjeto() {
  try { app.endSuppressDialogs(false); } catch (e) {}
}

function novaComp(nome, opts) {
  opts = opts || {};
  var largura  = opts.largura  || 1080;
  var altura   = opts.altura   || 1920;
  var duracao  = opts.duracao  || 10;
  var fps      = opts.fps      || 30;
  var comp = app.project.items.addComp(nome, largura, altura, 1, duracao, fps);
  if (opts.fundo) comp.bgColor = hex(opts.fundo);
  comp.motionBlur = opts.motionBlur !== false;   // ligado por padrão: dá polimento
  return comp;
}

// ---------------------------------------------------------------- camadas

function solido(comp, cor, nome, largura, altura) {
  return comp.layers.addSolid(
    hex(cor), nome || "solido",
    largura || comp.width, altura || comp.height, 1
  );
}

/**
 * Camada de texto já estilizada.
 * o.fonte usa PostScript name (ex "Helvetica-Bold"), que é o que o AE aceita.
 */
function texto(comp, conteudo, o) {
  o = o || {};
  var camada = comp.layers.addText(conteudo);
  var doc = camada.property("Source Text").value;
  doc.resetCharStyle();
  doc.fontSize   = o.tamanho || 80;
  doc.fillColor  = hex(o.cor || "#ffffff");
  doc.applyFill  = true;
  doc.applyStroke = false;
  if (o.fonte) doc.font = o.fonte;
  doc.justification = ParagraphJustification.CENTER_JUSTIFY;
  if (o.entrelinha) doc.leading = o.entrelinha;
  if (o.espacamento) doc.tracking = o.espacamento;
  camada.property("Source Text").setValue(doc);

  // Ancora no centro do próprio texto para escalar sem "pular"
  var pos = o.pos || [comp.width/2, comp.height/2];
  camada.property("Transform").property("Position").setValue(pos);
  if (o.nome) camada.name = o.nome;
  return camada;
}

/** Retângulo vetorial (shape layer real, não sólido) — escala sem perder nitidez. */
function retangulo(comp, o) {
  o = o || {};
  var sh = comp.layers.addShape();
  sh.name = o.nome || "retangulo";
  var grupo = sh.property("Contents").addProperty("ADBE Vector Group");
  var conteudo = grupo.property("Contents");
  var rect = conteudo.addProperty("ADBE Vector Shape - Rect");
  rect.property("Size").setValue([o.largura || 200, o.altura || 8]);
  if (o.raio) rect.property("Roundness").setValue(o.raio);
  var fill = conteudo.addProperty("ADBE Vector Graphic - Fill");
  fill.property("Color").setValue(hex(o.cor || "#ffffff"));
  sh.property("Transform").property("Position").setValue(
    o.pos || [comp.width/2, comp.height/2]
  );
  sh._grupo = grupo;   // guardado para animar a escala interna
  return sh;
}

function importar(caminho) {
  var f = new File(caminho);
  if (!f.exists) throw new Error("asset nao encontrado: " + caminho);
  return app.project.importFile(new ImportOptions(f));
}

/** Coloca uma imagem/vídeo na comp já ajustado à largura. */
function midia(comp, caminho, o) {
  o = o || {};
  var item = importar(caminho);
  var camada = comp.layers.add(item);
  if (o.pos) camada.property("Transform").property("Position").setValue(o.pos);
  if (o.escala) {
    camada.property("Transform").property("Scale").setValue([o.escala, o.escala]);
  } else if (o.cobrir) {
    // Cobre a comp inteira sem deformar (o maior dos dois fatores), como
    // background-size:cover. Sobra é cortada pela borda da comp.
    var sc = Math.max(comp.width / camada.source.width,
                      comp.height / camada.source.height) * 100;
    camada.property("Transform").property("Scale").setValue([sc, sc]);
  } else if (o.preencherLargura) {
    var s = (comp.width / camada.source.width) * 100;
    camada.property("Transform").property("Scale").setValue([s, s]);
  }
  if (o.inicio !== undefined) camada.startTime = o.inicio;
  return camada;
}

function audio(comp, caminho, inicio) {
  var camada = comp.layers.add(importar(caminho));
  camada.startTime = inicio || 0;
  return camada;
}

// ---------------------------------------------------------------- animação

function prop(camada, nome) {
  return camada.property("Transform").property(nome);
}

/**
 * Keyframes com easing de verdade.
 * O padrão do AE é linear, que é o que faz animação de script parecer robótica.
 * Aqui todo keyframe nasce com ease — é o detalhe que separa "feito por script"
 * de "feito por motion designer".
 */
function anim(propriedade, quadros, o) {
  o = o || {};
  var i;
  for (i = 0; i < quadros.length; i++) {
    propriedade.setValueAtTime(quadros[i][0], quadros[i][1]);
  }
  var facilidade = o.ease === false ? null : (o.ease || 75);
  if (facilidade !== null) {
    var n = propriedade.numKeys;
    for (i = 1; i <= n; i++) {
      var easeIn  = new KeyframeEase(0, facilidade);
      var easeOut = new KeyframeEase(0, facilidade);
      var dim = propriedade.value instanceof Array ? propriedade.value.length : 1;
      var ins = [], outs = [];
      for (var d = 0; d < dim; d++) { ins.push(easeIn); outs.push(easeOut); }
      try {
        propriedade.setTemporalEaseAtKey(i, ins, outs);
      } catch (e) {
        // Propriedades 1D com dimensão separada podem recusar; ignora sem quebrar.
      }
    }
  }
  return propriedade;
}

/** Entrada padrão: sobe + aparece. t = instante em que começa. */
function entrar(camada, t, o) {
  o = o || {};
  var dur = o.duracao || 0.6;
  var desloc = o.desloc === undefined ? 60 : o.desloc;
  var p = prop(camada, "Position");
  var base = p.value;
  anim(p, [
    [t,       [base[0], base[1] + desloc]],
    [t + dur, [base[0], base[1]]]
  ]);
  anim(prop(camada, "Opacity"), [[t, 0], [t + dur * 0.7, 100]]);
  return camada;
}

function sair(camada, t, o) {
  o = o || {};
  var dur = o.duracao || 0.4;
  anim(prop(camada, "Opacity"), [[t, 100], [t + dur, 0]]);
  return camada;
}

/** Escala com "pop" (passa de 100 e volta) — leitura muito mais viva. */
function pop(camada, t, o) {
  o = o || {};
  var dur = o.duracao || 0.5;
  var alvo = o.alvo || 100;
  anim(prop(camada, "Scale"), [
    [t,           [alvo * 0.6, alvo * 0.6]],
    [t + dur*0.6, [alvo * 1.06, alvo * 1.06]],
    [t + dur,     [alvo, alvo]]
  ]);
  anim(prop(camada, "Opacity"), [[t, 0], [t + dur * 0.4, 100]]);
  return camada;
}

/** Recorta a camada no tempo — o que define a duração da cena. */
function janela(camada, inicio, fim) {
  camada.inPoint = inicio;
  camada.outPoint = fim;
  return camada;
}

// ---------------------------------------------------------------- presets .ffx

var PRESETS_BASE = "/Applications/Adobe After Effects 2026/Presets";

/**
 * Aplica um preset nativo da Adobe. É o maior salto de qualidade disponível:
 * são animações feitas por motion designers da Adobe, não geradas por script.
 * Aceita caminho absoluto ou "Text/Animate In/Nome.ffx".
 */
function preset(camada, caminhoRelativo) {
  var caminho = caminhoRelativo.charAt(0) === "/"
    ? caminhoRelativo
    : PRESETS_BASE + "/" + caminhoRelativo;
  var f = new File(caminho);
  if (!f.exists) throw new Error("preset nao encontrado: " + caminho);
  camada.applyPreset(f);
  return camada;
}

/** Primeiro preset cujo nome contenha o termo, dentro de uma categoria. */
function acharPreset(categoria, termo) {
  var fo = new Folder(PRESETS_BASE + "/" + categoria);
  if (!fo.exists) return null;
  var arquivos = fo.getFiles("*.ffx");
  var alvo = termo.toLowerCase();
  for (var i = 0; i < arquivos.length; i++) {
    if (decodeURI(arquivos[i].name).toLowerCase().indexOf(alvo) !== -1) {
      return arquivos[i].fsName;
    }
  }
  return null;
}

// ---------------------------------------------------------------- efeitos

/** matchNames dos efeitos que mais rendem em vídeo curto. */
var FX = {
  glow:      "ADBE Glo2",
  blurGauss: "ADBE Gaussian Blur 2",
  blurFast:  "ADBE Fast Blur",
  curvas:    "ADBE CurvesCustom",
  matiz:     "ADBE Pro Levels2",
  sombra:    "ADBE Drop Shadow",
  preencher: "ADBE Fill",
  transform: "ADBE Geometry2",
  vinheta:   "ADBE Vignette",
  ruido:     "ADBE Noise"
};

function efeito(camada, chaveOuMatchName, valores) {
  var mn = FX[chaveOuMatchName] || chaveOuMatchName;
  var fx = camada.property("ADBE Effect Parade").addProperty(mn);
  if (valores) {
    for (var k in valores) {
      if (!valores.hasOwnProperty(k)) continue;
      try { fx.property(k).setValue(valores[k]); } catch (e) { log("efeito: " + k + " -> " + e); }
    }
  }
  return fx;
}

/** Glow calibrado — o padrão do AE é forte demais e satura. */
function brilho(camada, o) {
  o = o || {};
  var fx = efeito(camada, "glow");
  try {
    fx.property("ADBE Glo2-0002").setValue(o.limiar   || 70);  // threshold
    fx.property("ADBE Glo2-0003").setValue(o.raio     || 30);  // radius
    fx.property("ADBE Glo2-0004").setValue(o.intensidade || 1);
  } catch (e) {}
  return fx;
}

// ---------------------------------------------------------------- 3D / câmera

function camera(comp, nome) {
  return comp.layers.addCamera(nome || "Camera", [comp.width/2, comp.height/2]);
}

/** Empurra a câmera lentamente: dá vida a cena estática sem custo de render. */
function pushIn(cam, de, ate, duracao) {
  var p = cam.property("Transform").property("Position");
  var x = p.value[0], y = p.value[1];
  anim(p, [[0, [x, y, de]], [duracao, [x, y, ate]]], { ease: 40 });
  return cam;
}

// ---------------------------------------------------------------- render

/**
 * Enfileira a comp. Deixamos o módulo de saída no padrão de propósito:
 * o AE em português não reconhece "Best Settings"/"Lossless", e forçar
 * template pelo nome quebra a automação.
 */
function enfileirar(comp, saida) {
  var item = app.project.renderQueue.items.add(comp);
  item.outputModule(1).file = new File(saida);
  return item;
}

function salvar(caminho) {
  app.project.save(new File(caminho));
  return caminho;
}
