#!/usr/bin/env node
/**
 * gerar.mjs — roteiro JSON  ->  script ExtendScript  ->  .aep  ->  .mp4
 *
 * Node monta o .jsx (onde dá pra usar JS moderno) e o AE só executa.
 * Assim a lógica fica testável e o ExtendScript ES3 vira detalhe de saída.
 */
import { readFileSync, writeFileSync, mkdirSync, existsSync } from "node:fs";
import { execFileSync } from "node:child_process";
import { dirname, resolve, join, basename } from "node:path";
import { fileURLToPath } from "node:url";

const AQUI = dirname(fileURLToPath(import.meta.url));
const SKILL = resolve(AQUI, "..");
const LIB = join(AQUI, "ae.jsx");

// ------------------------------------------------------------- helpers ES3

/** ExtendScript é ES3: string tem que ir escapada e sem template literal. */
const s = (v) => JSON.stringify(String(v ?? ""));
const n = (v, padrao = 0) => (typeof v === "number" && isFinite(v) ? v : padrao);

// ------------------------------------------------------------- temas

const TEMAS = {
  pagzero: {
    fundo: "#0a0e17", primaria: "#22e59a", texto: "#ffffff", suave: "#8b95a8",
    fonte: "Helvetica-Bold", fonteTexto: "Helvetica",
  },
  escuro: {
    fundo: "#0d0d0f", primaria: "#ffffff", texto: "#ffffff", suave: "#9aa0a6",
    fonte: "Helvetica-Bold", fonteTexto: "Helvetica",
  },
  claro: {
    fundo: "#f6f7f9", primaria: "#111318", texto: "#111318", suave: "#5f6672",
    fonte: "Helvetica-Bold", fonteTexto: "Helvetica",
  },
};

const FORMATOS = {
  reels:    { largura: 1080, altura: 1920 },
  vertical: { largura: 1080, altura: 1920 },
  quadrado: { largura: 1080, altura: 1080 },
  wide:     { largura: 1920, altura: 1080 },
  horizontal:{ largura: 1920, altura: 1080 },
};

// ------------------------------------------------------------- cenas

/**
 * Cada tipo devolve linhas de ExtendScript. `t` é o instante em que a cena
 * começa; `dur`, quanto ela dura. Tudo que aparece é recortado com janela()
 * para não vazar para a cena seguinte.
 */
const CENAS = {
  /** Título grande centralizado, com linha de apoio. */
  titulo(c, ctx) {
    const { tema, comp } = ctx;
    const cx = comp.largura / 2;
    const cy = comp.altura / 2;
    const L = [];
    const tam = n(c.tamanho, Math.round(comp.largura * 0.14));

    L.push(`var _t = texto(comp, ${s(c.titulo)}, {tamanho:${tam}, cor:${s(c.cor || tema.primaria)}, fonte:${s(tema.fonte)}, pos:[${cx}, ${cy - Math.round(tam * 0.45)}]});`);
    L.push(`pop(_t, ${ctx.t + 0.15}, {duracao:0.7});`);
    // Glow só faz sentido sobre fundo escuro; no claro vira borrão.
    const fundoEscuro = (() => {
      const h = String(tema.fundo).replace("#", "");
      if (h.length < 6) return true;
      const lum = (parseInt(h.substring(0,2),16)*0.299 + parseInt(h.substring(2,4),16)*0.587 + parseInt(h.substring(4,6),16)*0.114) / 255;
      return lum < 0.5;
    })();
    if (c.brilho === true || (c.brilho !== false && fundoEscuro)) {
      L.push(`brilho(_t, {limiar:60, raio:36});`);
    }
    L.push(`janela(_t, ${ctx.t}, ${ctx.t + ctx.dur});`);

    if (c.subtitulo) {
      L.push(`var _st = texto(comp, ${s(c.subtitulo)}, {tamanho:${Math.round(tam * 0.36)}, cor:${s(tema.suave)}, fonte:${s(tema.fonteTexto)}, pos:[${cx}, ${cy + Math.round(tam * 0.72)}]});`);
      L.push(`entrar(_st, ${ctx.t + 0.6}, {duracao:0.6});`);
      L.push(`janela(_st, ${ctx.t}, ${ctx.t + ctx.dur});`);
    }
    if (c.linha !== false) {
      L.push(`var _ln = retangulo(comp, {largura:${Math.round(comp.largura * 0.6)}, altura:5, cor:${s(tema.primaria)}, raio:3, pos:[${cx}, ${cy + Math.round(tam * 0.30)}]});`);
      L.push(`anim(_ln._grupo.property("Transform").property("Scale"), [[${ctx.t + 0.5},[0,100]],[${ctx.t + 1.2},[100,100]]]);`);
      L.push(`janela(_ln, ${ctx.t}, ${ctx.t + ctx.dur});`);
    }
    return L;
  },

  /** Lista de itens entrando em cascata — o formato que mais rende em Reels. */
  lista(c, ctx) {
    const { tema, comp } = ctx;
    const cx = comp.largura / 2;
    const itens = c.itens || [];
    const L = [];
    const passo = n(c.espacamento, 210);
    // Centra o conjunto (título + itens), não só os itens.
    const reservaTitulo = c.titulo ? 220 : 0;
    const topo = comp.altura / 2 - ((itens.length - 1) * passo) / 2 + reservaTitulo / 2;

    if (c.titulo) {
      L.push(`var _ti = texto(comp, ${s(c.titulo)}, {tamanho:${Math.round(comp.largura * 0.082)}, cor:${s(tema.primaria)}, fonte:${s(tema.fonte)}, pos:[${cx}, ${Math.round(topo - 220)}]});`);
      L.push(`entrar(_ti, ${ctx.t + 0.1}, {duracao:0.6});`);
      L.push(`janela(_ti, ${ctx.t}, ${ctx.t + ctx.dur});`);
    }

    // Cascata: cada item entra 0.35s depois do anterior.
    itens.forEach((item, i) => {
      const y = Math.round(topo + i * passo);
      const inicio = +(ctx.t + 0.5 + i * 0.35).toFixed(2);
      const rotulo = typeof item === "string" ? item : item.titulo;
      const v = `_i${i}`;

      L.push(`var ${v} = texto(comp, ${s(rotulo)}, {tamanho:${n(c.tamanho, Math.round(comp.largura * 0.072))}, cor:${s(tema.texto)}, fonte:${s(tema.fonteTexto)}, pos:[${cx}, ${y}]});`);
      L.push(`entrar(${v}, ${inicio}, {duracao:0.55, desloc:40});`);
      L.push(`janela(${v}, ${ctx.t}, ${ctx.t + ctx.dur});`);

      if (typeof item === "object" && item.detalhe) {
        const d = `_d${i}`;
        L.push(`var ${d} = texto(comp, ${s(item.detalhe)}, {tamanho:${Math.round(comp.largura * 0.040)}, cor:${s(tema.suave)}, fonte:${s(tema.fonteTexto)}, pos:[${cx}, ${y + 66}]});`);
        L.push(`entrar(${d}, ${inicio + 0.12}, {duracao:0.5, desloc:30});`);
        L.push(`janela(${d}, ${ctx.t}, ${ctx.t + ctx.dur});`);
      }
    });
    return L;
  },

  /** Uma frase forte na tela. Bom para virada de argumento. */
  frase(c, ctx) {
    const { tema, comp } = ctx;
    const L = [];
    const tam = n(c.tamanho, Math.round(comp.largura * 0.085));
    L.push(`var _f = texto(comp, ${s(c.texto)}, {tamanho:${tam}, cor:${s(c.cor || tema.texto)}, fonte:${s(tema.fonte)}, pos:[${comp.largura / 2}, ${comp.altura / 2}], entrelinha:${Math.round(tam * 1.25)}});`);
    L.push(`entrar(_f, ${ctx.t + 0.1}, {duracao:0.7, desloc:50});`);
    L.push(`sair(_f, ${ctx.t + ctx.dur - 0.45}, {duracao:0.4});`);
    L.push(`janela(_f, ${ctx.t}, ${ctx.t + ctx.dur});`);
    return L;
  },

  /** Imagem/vídeo em tela cheia com leve zoom (Ken Burns). */
  midia(c, ctx) {
    const { comp } = ctx;
    const L = [];
    L.push(`var _m = midia(comp, ${s(resolve(c.arquivo))}, {cobrir:true, pos:[${comp.largura / 2}, ${comp.altura / 2}]});`);
    if (c.zoom !== false) {
      L.push(`var _ms = _m.property("Transform").property("Scale").value;`);
      L.push(`anim(_m.property("Transform").property("Scale"), [[${ctx.t},[_ms[0],_ms[1]]],[${ctx.t + ctx.dur},[_ms[0]*1.08,_ms[1]*1.08]]], {ease:30});`);
    }
    L.push(`janela(_m, ${ctx.t}, ${ctx.t + ctx.dur});`);
    if (c.legenda) {
      // Faixa por baixo: sobre imagem clara o texto branco sumiria.
      L.push(`var _mf = retangulo(comp, {largura:${comp.largura}, altura:${Math.round(comp.altura * 0.16)}, cor:"#000000", pos:[${comp.largura / 2}, ${comp.altura - Math.round(comp.altura * 0.08)}]});`);
      L.push(`_mf.property("Transform").property("Opacity").setValue(55);`);
      L.push(`janela(_mf, ${ctx.t}, ${ctx.t + ctx.dur});`);
      L.push(`var _ml = texto(comp, ${s(c.legenda)}, {tamanho:${Math.round(comp.largura * 0.045)}, cor:"#ffffff", fonte:${s(ctx.tema.fonteTexto)}, pos:[${comp.largura / 2}, ${comp.altura - Math.round(comp.altura * 0.075)}]});`);
      L.push(`entrar(_ml, ${ctx.t + 0.3}, {duracao:0.5});`);
      L.push(`janela(_ml, ${ctx.t}, ${ctx.t + ctx.dur});`);
    }
    return L;
  },

  /** Texto animado por preset nativo da Adobe (300 disponíveis). */
  preset(c, ctx) {
    const { tema, comp } = ctx;
    const L = [];
    const tam = n(c.tamanho, Math.round(comp.largura * 0.1));
    L.push(`var _p = texto(comp, ${s(c.texto)}, {tamanho:${tam}, cor:${s(c.cor || tema.primaria)}, fonte:${s(tema.fonte)}, pos:[${comp.largura / 2}, ${comp.altura / 2}]});`);
    // Busca por termo: nome do arquivo muda com o idioma do AE.
    L.push(`var _pp = acharPreset(${s(c.categoria || "Text/Animate In")}, ${s(c.preset || "Deslizar")});`);
    L.push(`if (_pp) { _p.startTime = ${ctx.t}; preset(_p, _pp); }`);
    L.push(`janela(_p, ${ctx.t}, ${ctx.t + ctx.dur});`);
    return L;
  },
};

// ------------------------------------------------------------- montagem

function montar(roteiro, saidaAep) {
  const tema = { ...(TEMAS[roteiro.tema] || TEMAS.pagzero), ...(roteiro.cores || {}) };
  const fmt = FORMATOS[roteiro.formato || "reels"] || FORMATOS.reels;
  const fps = n(roteiro.fps, 30);
  const cenas = roteiro.cenas || [];

  if (!cenas.length) throw new Error("roteiro sem cenas");

  // Duração: a da cena, ou a da narração já medida, ou 3s.
  const duracoes = cenas.map((c) => n(c.duracao, n(c.duracaoNarracao, 3)));
  const total = duracoes.reduce((a, b) => a + b, 0) + n(roteiro.caudaFinal, 0.5);

  const comp = { largura: fmt.largura, altura: fmt.altura };
  const L = [];

  L.push(`#include ${s(LIB)}`);
  L.push(`var _rel = [];`);
  L.push(`try {`);
  L.push(`novoProjeto();`);
  L.push(`var comp = novaComp(${s(roteiro.titulo || "Video")}, {largura:${fmt.largura}, altura:${fmt.altura}, duracao:${total.toFixed(2)}, fps:${fps}, fundo:${s(tema.fundo)}});`);

  let t = 0;
  cenas.forEach((c, i) => {
    const dur = duracoes[i];
    const tipo = c.tipo || "titulo";
    const fn = CENAS[tipo];
    if (!fn) throw new Error(`cena ${i} (${c.id || i}): tipo desconhecido "${tipo}"`);
    L.push(`// ---- cena ${i}: ${tipo} ${c.id ? "(" + c.id + ")" : ""}`);
    L.push(...fn(c, { t: +t.toFixed(2), dur, tema, comp, fps }));
    L.push(`_rel.push("OK cena ${i} ${tipo}");`);
    t += dur;
  });

  // Narração por cima de tudo, começando em 0 (a fala dita os cortes).
  if (roteiro.narracao && existsSync(resolve(roteiro.narracao))) {
    L.push(`audio(comp, ${s(resolve(roteiro.narracao))}, 0);`);
    L.push(`_rel.push("OK narracao");`);
  }
  if (roteiro.trilha && existsSync(resolve(roteiro.trilha))) {
    L.push(`var _tr = audio(comp, ${s(resolve(roteiro.trilha))}, 0);`);
    L.push(`_tr.property("Audio").property("Audio Levels").setValue([${n(roteiro.volumeTrilha, -18)}, ${n(roteiro.volumeTrilha, -18)}]);`);
  }

  L.push(`salvar(${s(resolve(saidaAep))});`);
  L.push(`_rel.push("OK salvo");`);
  L.push(`} catch(e) { _rel.push("FALHA :: " + e.toString() + " @linha " + e.line); }`);
  L.push(`escrever(${s(resolve(saidaAep) + ".log")}, _rel.join("\\n"));`);

  return { jsx: L.join("\n"), total, comp: roteiro.titulo || "Video" };
}

// ------------------------------------------------------------- cli

function main() {
  const args = process.argv.slice(2);
  if (!args.length) {
    console.error("uso: gerar.mjs <roteiro.json> [--saida DIR] [--so-aep]");
    process.exit(1);
  }
  const arquivo = resolve(args[0]);
  const soAep = args.includes("--so-aep");
  const iSaida = args.indexOf("--saida");
  const dirSaida = iSaida !== -1 ? resolve(args[iSaida + 1]) : join(process.env.HOME, "Downloads", "ae-videos");

  const roteiro = JSON.parse(readFileSync(arquivo, "utf8"));
  const id = roteiro.id || basename(arquivo, ".json");
  mkdirSync(dirSaida, { recursive: true });

  const aep = join(dirSaida, `${id}.aep`);
  const { jsx, total, comp } = montar(roteiro, aep);

  const jsxPath = join(dirSaida, `${id}.jsx`);
  writeFileSync(jsxPath, jsx, "utf8");
  console.error(`==> roteiro: ${roteiro.cenas.length} cenas, ${total.toFixed(1)}s`);

  execFileSync(join(SKILL, "scripts", "rodar.sh"), [jsxPath, "900"], { stdio: "inherit" });

  const logPath = `${aep}.log`;
  if (existsSync(logPath)) {
    const rel = readFileSync(logPath, "utf8");
    if (rel.includes("FALHA")) {
      console.error("erro ao montar o projeto:\n" + rel);
      process.exit(2);
    }
  }
  if (!existsSync(aep)) {
    console.error("erro: o .aep nao foi gerado. O AE respondeu? Ha dialogo aberto?");
    process.exit(2);
  }
  console.error(`==> projeto: ${aep}`);

  if (soAep) { console.log(aep); return; }

  const mp4 = join(dirSaida, `${id}.mp4`);
  const real = execFileSync(join(SKILL, "scripts", "render.sh"), [aep, comp, mp4], {
    encoding: "utf8", stdio: ["inherit", "pipe", "inherit"],
  }).trim();
  console.log(real);
}

main();
