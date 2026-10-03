/**
 * marca.jsx — identidade da PagZero e primitivas visuais da VSL.
 *
 * Cores e fonte saem do front real (nuxt-web). O amarelo do site é #FEBE00;
 * o projeto de vídeo usava #FEBF00 (um dígito de diferença, provável erro de
 * transcrição) — aqui vale o do front, que é a fonte da verdade.
 */

// ---------------------------------------------------------------- paleta
//
// Extraída de assets/css/variaveis.sass do nuxt-web. O site é TEMA CLARO:
// fundo branco puro, sem dark mode. As duas únicas seções escuras são o
// fechamento (#000000) e a ContaJuros (#0C0C0E) — e é só nelas que o vídeo
// escurece também.

var CLARO = {
  fundo:        "#FFFFFF",
  banda:        "#F4F6FB",   // --cor-fundo-suave, as bandas alternadas
  card:         "#F4F6FB",
  borda:        "#E6E8EF",   // 1px, o único separador do site
  amarelo:      "#FEBE00",
  amareloClaro: "#FFD34D",   // hover
  amareloEsc:   "#E0A800",
  amareloSuave: "#FFF7DF",
  texto:        "#14161F",
  textoSuave:   "#4A4E5C",   // corpo/parágrafo
  textoFraco:   "#8A8F9E",   // legenda
  verde:        "#18C964",
  verdeEsc:     "#12A552",
  vermelho:     "#FF2E6D",   // rosa-choque, não vermelho
  vermelhoEsc:  "#E61E5A",
  azul:         "#3D5AFE",
  contraste:    "#000000"    // texto sobre amarelo é PRETO
};

// Usado só no ato 6 (fechamento), espelhando a seção preta da home.
var ESCURO = {
  fundo:        "#000000",
  banda:        "#0C0C0E",
  card:         "rgba",       // cards no escuro: branco a 5%
  borda:        "#1E1E22",
  amarelo:      "#FEBE00",
  amareloClaro: "#FFD34D",
  amareloEsc:   "#E0A800",
  amareloSuave: "#2A2410",
  texto:        "#FFFFFF",
  textoSuave:   "#C9CBD4",
  textoFraco:   "#8A8F9E",
  verde:        "#18C964",
  verdeEsc:     "#12A552",
  vermelho:     "#FF2E6D",
  vermelhoEsc:  "#E61E5A",
  azul:         "#3D5AFE",
  contraste:    "#000000"
};

var C = CLARO;
function tema(escuro) { C = escuro ? ESCURO : CLARO; return C; }

// A Söhne do site é VARIÁVEL e o AE ignora o eixo wght: renderiza tudo no
// default (281, mais leve que "regular"). A solução foi instanciar os pesos
// como fontes estáticas (ver ~/.claude/skills/after-effects/vsl/fontes.md).
var F = {
  book:     "PagZeroSohne-Book",      // 281 — o peso do site
  medium:   "PagZeroSohne-Medium",    // 436
  semibold: "PagZeroSohne-Semibold",  // 546
  bold:     "PagZeroSohne-Bold",      // 823
  black:    "PagZeroSohne-Black"      // 1000
};
var FONTE = F.book;

// Escala tipográfica do site (variaveis.sass, --f0 a --f13), em px desktop.
// O vídeo é 1920 e a home é 1200 de container: multiplicamos por ~1.5 para
// que a proporção percebida seja a mesma.
var ESC = 1.55;
function fs(token) {
  var px = {f0:11,f1:13,f2:15,f3:17,f4:19,f5:22,f6:25,f7:28,f8:32,
            f9:38,f10:46,f11:56,f12:68,f13:80}[token] || 15;
  return Math.round(px * ESC);
}

var W = 1920, H = 1080;
var CXf = W / 2;
var CYf = H / 2 + 61;   // medido: o texto do AE ancora na linha de base

var ASSETS = "/Users/eduardolecdt/.claude/skills/pagzero-video/projeto/public";

// ---------------------------------------------------------------- texto

/** Texto base. `peso` aceita book|medium|semibold|bold|black. */
function txt(comp, conteudo, o) {
  o = o || {};
  var camada = comp.layers.addText(conteudo);
  var d = camada.property("Source Text").value;
  d.resetCharStyle();
  d.fontSize = o.tamanho || fs("f2");
  d.fillColor = hex(o.cor || C.texto);
  d.applyFill = true;
  d.applyStroke = false;
  d.font = F[o.peso || "book"] || o.peso || FONTE;
  d.justification = o.alinha === "esq" ? ParagraphJustification.LEFT_JUSTIFY
                  : (o.alinha === "dir" ? ParagraphJustification.RIGHT_JUSTIFY
                                        : ParagraphJustification.CENTER_JUSTIFY);
  // line-height do site: 1.45 no H1, 1.2 nos H2, 1.6 no corpo.
  d.leading = o.entrelinha || Math.round((o.tamanho || fs("f2")) * (o.lh || 1.45));
  if (o.tracking !== undefined) d.tracking = o.tracking;
  camada.property("Source Text").setValue(d);
  camada.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  if (o.nome) camada.name = o.nome;
  return camada;
}

/**
 * Largura aproximada de um texto. O AE só dá sourceRectAtTime depois do
 * layout, e ele é confiável — usamos para dimensionar a pílula.
 */
function larguraDe(camada, t) {
  try {
    var r = camada.sourceRectAtTime(t === undefined ? 0 : t, false);
    return { w: r.width, h: r.height, top: r.top, left: r.left };
  } catch (e) { return null; }
}

/**
 * TÍTULO COM PÍLULA — a assinatura visual da PagZero.
 *
 * No site o destaque não vem de peso nem de cor de texto: é um <strong> com
 * fundo amarelo e cantos arredondados (border-radius 16px, texto preto,
 * box-decoration-break: clone) envolvendo as palavras-chave do título.
 * Sem isso o vídeo não parece da marca.
 *
 * `linhas` é um array; cada item é string (linha normal) ou
 * { destaque: "texto" } (linha inteira dentro da pílula).
 */
function titulo(comp, linhas, o) {
  o = o || {};
  var tam = o.tamanho || fs("f12");         // 68px do H1 * escala
  var lh = o.lh || 1.45;
  var passo = Math.round(tam * lh);
  var cx = o.pos ? o.pos[0] : CXf;
  var topo = (o.pos ? o.pos[1] : CYf) - ((linhas.length - 1) * passo) / 2;
  var out = [];

  for (var i = 0; i < linhas.length; i++) {
    var item = linhas[i];
    var y = Math.round(topo + i * passo);
    var ehDestaque = (typeof item === "object" && item.destaque);
    var texto = ehDestaque ? item.destaque : item;

    var l = txt(comp, texto, {
      tamanho: tam, lh: lh, peso: o.peso || "book",
      cor: ehDestaque ? C.contraste : (o.cor || C.texto),
      pos: [cx, y], alinha: o.alinha
    });

    if (ehDestaque) {
      // A pílula tem que existir ANTES do texto na pilha para ficar atrás.
      // sourceRectAtTime devolve NaN quando a camada ainda não tem layout
      // (acontece logo após criar a camada de texto). Sem esta guarda o
      // setValue da pílula recebe NaN e derruba a cena inteira.
      var m = larguraDe(l, o.medirEm === undefined ? 0 : o.medirEm);
      var lw = (m && isFinite(m.w) && m.w > 0) ? m.w : texto.length * tam * 0.54;
      var lhh = (m && isFinite(m.h) && m.h > 0) ? m.h : tam * 1.05;
      var padX = Math.round(tam * 0.24);     // 16px em 68px
      var padY = Math.round(tam * 0.14);
      var pil = caixa(comp, {
        largura: Math.round(lw + padX * 2),
        altura: Math.round(lhh + padY * 2),
        raio: Math.round(tam * 0.235),       // radius 16px do site
        cor: o.corPilula || C.amarelo,
        pos: [cx, y - Math.round(lhh * 0.30)],
        nome: "pilula"
      });
      l.moveBefore(pil);                     // texto por cima da pílula
      out.push(pil);
      pil._pilulaDe = l;
    }
    out.push(l);
  }
  return out;
}

/** Parágrafo de corpo: 15px, cor suave, line-height 1.6. */
function corpo(comp, conteudo, o) {
  o = o || {};
  return txt(comp, conteudo, {
    tamanho: o.tamanho || fs("f2"), lh: 1.6, peso: "book",
    cor: o.cor || C.textoSuave, pos: o.pos, alinha: o.alinha
  });
}

/** Rótulo pequeno em caixa alta (11px, tracking 0.5px no site). */
function etiqueta(comp, conteudo, o) {
  o = o || {};
  return txt(comp, conteudo.toUpperCase(), {
    tamanho: o.tamanho || fs("f0"), peso: "medium",
    cor: o.cor || C.textoFraco, tracking: 60, pos: o.pos, alinha: o.alinha
  });
}

// ---------------------------------------------------------------- formas

/**
 * Retângulo vetorial. No site TODA separação é borda 1px #E6E8EF — os quatro
 * tokens de sombra valem `none` de propósito. Drop-shadow aqui foge da marca.
 */
function caixa(comp, o) {
  o = o || {};
  var sh = comp.layers.addShape();
  sh.name = o.nome || "caixa";
  var g = sh.property("Contents").addProperty("ADBE Vector Group");
  var cont = g.property("Contents");
  var r = cont.addProperty("ADBE Vector Shape - Rect");
  r.property("Size").setValue([o.largura || 400, o.altura || 200]);
  if (o.raio) r.property("Roundness").setValue(o.raio);
  if (o.preenche !== false) {
    cont.addProperty("ADBE Vector Graphic - Fill")
        .property("Color").setValue(hex(o.cor || C.card));
  }
  if (o.traco) {
    var st = cont.addProperty("ADBE Vector Graphic - Stroke");
    st.property("Color").setValue(hex(o.corTraco || C.borda));
    st.property("Stroke Width").setValue(o.traco);
  }
  sh.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  sh._grupo = g;
  return sh;
}

/** Card do site: radius 26px, fundo gelo, borda 1px, sem sombra. */
function card(comp, o) {
  o = o || {};
  return caixa(comp, {
    largura: o.largura || 700, altura: o.altura || 320,
    raio: o.raio === undefined ? 40 : o.raio,     // 26px * escala
    cor: o.cor || C.card,
    traco: o.traco === undefined ? 2 : o.traco,
    corTraco: o.corTraco || C.borda,
    pos: o.pos, nome: o.nome || "card"
  });
}

/**
 * Banda de seção: o macro-padrão da home. radius 56px, fundo gelo (ou preto),
 * ocupando quase a largura toda.
 */
function banda(comp, o) {
  o = o || {};
  return caixa(comp, {
    largura: o.largura || (W - 60), altura: o.altura || (H - 60),
    raio: o.raio === undefined ? 86 : o.raio,      // 56px * escala
    cor: o.cor || C.banda, preenche: true,
    pos: o.pos || [CXf, H / 2], nome: o.nome || "banda"
  });
}

/** Botão CTA: pill 50px, amarelo, texto PRETO, 17px. */
function botao(comp, rotulo, o) {
  o = o || {};
  var tam = o.tamanho || fs("f3");
  var alt = o.altura || Math.round(58 * ESC);
  var lw = rotulo.length * tam * 0.55 + Math.round(32 * ESC) * 2;
  var b = caixa(comp, {
    largura: o.largura || Math.round(lw), altura: alt,
    raio: alt / 2,                                  // pill
    cor: o.cor || C.amarelo, pos: o.pos, nome: "botao"
  });
  var l = txt(comp, rotulo, {
    tamanho: tam, peso: "medium",
    cor: o.corTexto || C.contraste, pos: o.pos
  });
  return [b, l];
}

/** Chip discreto: pill, fundo gelo, 11px. */
function chip(comp, rotulo, o) {
  o = o || {};
  var tam = o.tamanho || fs("f0");
  var alt = Math.round(34 * ESC);
  var lw = rotulo.length * tam * 0.58 + Math.round(12 * ESC) * 2;
  var b = caixa(comp, {
    largura: Math.round(lw), altura: alt, raio: alt / 2,
    cor: o.cor || C.banda, pos: o.pos, nome: "chip"
  });
  var l = txt(comp, rotulo, {
    tamanho: tam, peso: "book", cor: o.corTexto || C.textoSuave, pos: o.pos
  });
  return [b, l];
}

/** Linha/régua horizontal. */
function linha(comp, o) {
  o = o || {};
  return caixa(comp, {
    largura: o.largura || 400, altura: o.espessura || 3,
    cor: o.cor || C.borda, raio: (o.espessura || 3) / 2,
    pos: o.pos, nome: o.nome || "linha"
  });
}

/** Check verde do site (fill #18C964), desenhado como path. */
function check(comp, o) {
  o = o || {};
  var tam = o.tamanho || 26;
  var sh = comp.layers.addShape();
  sh.name = "check";
  var g = sh.property("Contents").addProperty("ADBE Vector Group");
  var pa = g.property("Contents").addProperty("ADBE Vector Shape - Group");
  var s = new Shape();
  var k = tam / 26;
  s.vertices = [[-11 * k, 0], [-3 * k, 8 * k], [11 * k, -8 * k]];
  s.inTangents = [[0,0],[0,0],[0,0]];
  s.outTangents = [[0,0],[0,0],[0,0]];
  s.closed = false;
  pa.property("Path").setValue(s);
  var st = g.property("Contents").addProperty("ADBE Vector Graphic - Stroke");
  st.property("Color").setValue(hex(o.cor || C.verde));
  st.property("Stroke Width").setValue(o.traco || Math.round(4 * k));
  st.property("Line Cap").setValue(2);
  st.property("Line Join").setValue(2);
  sh.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  sh._grupo = g;
  return sh;
}

// ---------------------------------------------------------------- assets reais

/** Logo da PagZero (SVG vetorial do repo). */
function logo(comp, o) {
  o = o || {};
  var item = importar(ASSETS + "/logo-branco.svg");
  var l = comp.layers.add(item);
  l.name = "logo";
  var esc = ((o.largura || 520) / item.width) * 100;
  l.property("Transform").property("Scale").setValue([esc, esc]);
  l.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  return l;
}

/** Símbolo (o losango) — bom para marca d'água e selo. */
function simbolo(comp, o) {
  o = o || {};
  var item = importar(ASSETS + "/simbolo.svg");
  var l = comp.layers.add(item);
  l.name = "simbolo";
  var esc = ((o.tamanho || 120) / item.width) * 100;
  l.property("Transform").property("Scale").setValue([esc, esc]);
  l.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  return l;
}

/** Ícone do set da VSL (52 SVGs, 13 ícones × 4 cores). */
function icone(comp, nome, o) {
  o = o || {};
  var cor = o.corNome || "amarelo";      // amarelo | branco | verde | vermelho
  var caminho = ASSETS + "/vsl/icones/" + nome + "--" + cor + ".svg";
  var f = new File(caminho);
  if (!f.exists) return null;            // ícone opcional nunca derruba a cena
  var item = importar(caminho);
  var l = comp.layers.add(item);
  l.name = "ic-" + nome;
  var esc = ((o.tamanho || 90) / item.width) * 100;
  l.property("Transform").property("Scale").setValue([esc, esc]);
  l.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  return l;
}

/** Logo de gateway (Pagar.me, Asaas, Mercado Pago, Stripe, PayPal). */
function gateway(comp, nome, o) {
  o = o || {};
  var caminho = ASSETS + "/vsl/gateways/" + nome + ".svg";
  var f = new File(caminho);
  if (!f.exists) return null;
  var item = importar(caminho);
  var l = comp.layers.add(item);
  l.name = "gw-" + nome;
  var esc = ((o.largura || 200) / item.width) * 100;
  l.property("Transform").property("Scale").setValue([esc, esc]);
  l.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  return l;
}

/**
 * Ilustração SVG gerada por ilustra.mjs.
 * collapseTransformation ("continuous rasterize") é o que mantém o vetor
 * nítido ao escalar — sem ele o AE rasteriza no tamanho original e borra.
 */
var ILUSTRA = "/Users/eduardolecdt/.claude/skills/after-effects/vsl/svg";

function ilustra(comp, nome, o) {
  o = o || {};
  var caminho = ILUSTRA + "/" + nome + ".svg";
  var f = new File(caminho);
  if (!f.exists) return null;
  var item = importar(caminho);
  var l = comp.layers.add(item);
  l.name = "il-" + nome;
  var esc = o.altura ? (o.altura / item.height) * 100
                     : ((o.largura || 420) / item.width) * 100;
  l.property("Transform").property("Scale").setValue([esc, esc]);
  l.property("Transform").property("Position").setValue(o.pos || [CXf, CYf]);
  try { l.collapseTransformation = true; } catch (e) {}
  l._escala = esc;
  return l;
}

/** Ilustração que entra com pop e depois respira. */
function ilustraViva(comp, nome, t, o) {
  o = o || {};
  var l = ilustra(comp, nome, o);
  if (!l) return null;
  var e = l._escala;
  anim(l.property("Transform").property("Scale"), [
    [t,        [e * 0.72, e * 0.72]],
    [t + 0.34, [e * 1.05, e * 1.05]],
    [t + 0.52, [e, e]]
  ], { ease: 72 });
  anim(l.property("Transform").property("Opacity"), [[t, 0], [t + 0.22, 100]], { ease: 60 });
  var pz = l.property("Transform").property("Position").value;
  l.property("Transform").property("Position").expression =
    "var t0=" + (t + 0.6) + ";" +
    "[" + pz[0] + ", " + pz[1] + " + (time>t0 ? Math.sin((time-t0)*1.2)*8 : 0)]";
  return l;
}

// ---------------------------------------------------------------- números

/**
 * Número que CONTA de um valor a outro.
 * Expression em vez de keyframe de texto: o AE não interpola texto, e contador
 * é o gesto que mais prende atenção em VSL de dado.
 */
function contador(comp, o) {
  o = o || {};
  var l = txt(comp, "0", {
    tamanho: o.tamanho || fs("f12"), peso: o.peso || "bold",
    cor: o.cor || C.texto, pos: o.pos, nome: o.nome, lh: 1.1
  });
  var t0 = o.de === undefined ? 0 : o.de;
  var t1 = o.para === undefined ? 100 : o.para;
  var ini = o.inicio || 0, dur = o.duracao || 1.2;
  var pre = o.prefixo === undefined ? "R$ " : o.prefixo;
  var suf = o.sufixo || "";

  // Milhar com ponto, à brasileira.
  l.property("Source Text").expression =
    'var a=' + t0 + ', b=' + t1 + ', i=' + ini + ', d=' + dur + ';' +
    'var p=Math.min(1,Math.max(0,(time-i)/d));' +
    'p = 1-Math.pow(1-p,3);' +                      // ease-out: assenta no fim
    'var v=Math.round(a+(b-a)*p);' +
    'var s=""+Math.abs(v), o="";' +
    'for(var k=0;k<s.length;k++){ if(k>0 && (s.length-k)%3===0) o+="."; o+=s[k]; }' +
    '"' + pre + '"+o+"' + suf + '"';
  return l;
}
