#!/usr/bin/env node
/**
 * ilustra.mjs — gera as ilustrações da VSL como SVG, por código.
 *
 * Por que SVG e não shape layer: para cena ilustrada (um cartão, um celular,
 * um personagem) são dezenas de formas. Montar isso com `ADBE Vector Shape`
 * ficaria ilegível, e o AE importa SVG mantendo o vetor — escalado a 320% o
 * traço continua limpo (testado).
 *
 * Cada peça é UM arquivo, para poder animar separado no AE: o AE não converte
 * SVG em shape layer por script, então o que vem num arquivo anima junto.
 */
import { writeFileSync, mkdirSync } from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'

const AQUI = path.dirname(fileURLToPath(import.meta.url))
const OUT = path.join(AQUI, 'svg')
mkdirSync(OUT, { recursive: true })

// Paleta idêntica à do marca.jsx — se divergir, a ilustração destoa da cena.
const K = {
  fundo: '#0B0A0D', sup: '#141317', supAlta: '#1E1D22', borda: '#26252B',
  amarelo: '#FEBE00', amareloEsc: '#8A6800',
  texto: '#FFFFFF', fraco: '#8E8C97',
  verde: '#22C55E', vermelho: '#EF4444',
  pele: '#F2C9A0', peleEsc: '#D9A87C', cabelo: '#1A1A1E', branco: '#FFFFFF'
}

const svg = (w, h, corpo) =>
  `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${w} ${h}" width="${w}" height="${h}">\n${corpo}\n</svg>`

const peças = {}

// ─────────────────────────────────────────────────────── dispositivos

/** Cartão de crédito em perspectiva leve. */
peças['cartao'] = svg(520, 340, `
  <defs><linearGradient id="g1" x1="0" y1="0" x2="1" y2="1">
    <stop offset="0" stop-color="${K.supAlta}"/><stop offset="1" stop-color="${K.sup}"/>
  </linearGradient></defs>
  <rect x="30" y="40" width="460" height="270" rx="26" fill="url(#g1)" stroke="${K.borda}" stroke-width="3"/>
  <rect x="30" y="105" width="460" height="46" fill="${K.amarelo}" opacity="0.92"/>
  <rect x="66" y="196" width="86" height="60" rx="10" fill="${K.amareloEsc}" opacity="0.75"/>
  <rect x="66" y="196" width="86" height="60" rx="10" fill="none" stroke="${K.amarelo}" stroke-width="2.5"/>
  <path d="M92 196 v60 M122 196 v60 M66 216 h86 M66 236 h86" stroke="${K.amarelo}" stroke-width="2" opacity="0.6"/>
  <rect x="66" y="278" width="200" height="12" rx="6" fill="${K.fraco}" opacity="0.5"/>
  <circle cx="400" cy="248" r="34" fill="${K.amarelo}" opacity="0.9"/>
  <circle cx="440" cy="248" r="34" fill="${K.amarelo}" opacity="0.45"/>`)

/** Celular com um Pix confirmado na tela. */
peças['celular-pix'] = svg(340, 620, `
  <rect x="30" y="20" width="280" height="580" rx="42" fill="${K.sup}" stroke="${K.borda}" stroke-width="4"/>
  <rect x="46" y="52" width="248" height="516" rx="26" fill="${K.fundo}"/>
  <rect x="140" y="30" width="60" height="10" rx="5" fill="${K.borda}"/>
  <circle cx="170" cy="250" r="72" fill="none" stroke="${K.verde}" stroke-width="10"/>
  <path d="M138 250 l22 24 l44 -52" stroke="${K.verde}" stroke-width="14" fill="none"
        stroke-linecap="round" stroke-linejoin="round"/>
  <rect x="96" y="360" width="148" height="16" rx="8" fill="${K.texto}" opacity="0.85"/>
  <rect x="120" y="394" width="100" height="12" rx="6" fill="${K.fraco}" opacity="0.6"/>
  <rect x="86" y="446" width="168" height="46" rx="23" fill="${K.amarelo}"/>`)

/** Relógio: o tempo que o dinheiro fica parado. */
peças['relogio'] = svg(400, 400, `
  <circle cx="200" cy="200" r="150" fill="${K.sup}" stroke="${K.amarelo}" stroke-width="10"/>
  <circle cx="200" cy="200" r="150" fill="none" stroke="${K.amareloEsc}" stroke-width="10"
          stroke-dasharray="180 760" stroke-linecap="round" transform="rotate(-90 200 200)"/>
  ${Array.from({ length: 12 }, (_, i) => {
    const a = (i / 12) * Math.PI * 2
    const x1 = 200 + Math.cos(a) * 122, y1 = 200 + Math.sin(a) * 122
    const x2 = 200 + Math.cos(a) * 136, y2 = 200 + Math.sin(a) * 136
    return `<line x1="${x1.toFixed(1)}" y1="${y1.toFixed(1)}" x2="${x2.toFixed(1)}" y2="${y2.toFixed(1)}" stroke="${K.fraco}" stroke-width="5" stroke-linecap="round" opacity="0.7"/>`
  }).join('\n  ')}
  <circle cx="200" cy="200" r="14" fill="${K.amarelo}"/>`)

/** Cofre fechado — o dinheiro que não sai. */
peças['cofre'] = svg(460, 420, `
  <rect x="40" y="40" width="380" height="340" rx="26" fill="${K.supAlta}" stroke="${K.borda}" stroke-width="4"/>
  <rect x="76" y="76" width="308" height="268" rx="16" fill="${K.sup}" stroke="${K.amareloEsc}" stroke-width="3"/>
  <circle cx="230" cy="210" r="82" fill="none" stroke="${K.amarelo}" stroke-width="10"/>
  <circle cx="230" cy="210" r="30" fill="${K.amarelo}" opacity="0.25"/>
  ${Array.from({ length: 8 }, (_, i) => {
    const a = (i / 8) * Math.PI * 2
    return `<line x1="${(230 + Math.cos(a) * 82).toFixed(1)}" y1="${(210 + Math.sin(a) * 82).toFixed(1)}" x2="${(230 + Math.cos(a) * 104).toFixed(1)}" y2="${(210 + Math.sin(a) * 104).toFixed(1)}" stroke="${K.amarelo}" stroke-width="9" stroke-linecap="round"/>`
  }).join('\n  ')}
  <rect x="60" y="380" width="60" height="30" rx="8" fill="${K.borda}"/>
  <rect x="340" y="380" width="60" height="30" rx="8" fill="${K.borda}"/>`)

/** Nota de dinheiro. */
peças['nota'] = svg(420, 220, `
  <rect x="20" y="20" width="380" height="180" rx="16" fill="${K.sup}" stroke="${K.verde}" stroke-width="4"/>
  <circle cx="210" cy="110" r="52" fill="none" stroke="${K.verde}" stroke-width="5" opacity="0.8"/>
  <text x="210" y="130" font-family="Helvetica,Arial" font-size="58" font-weight="bold"
        fill="${K.verde}" text-anchor="middle">R$</text>
  <circle cx="72" cy="110" r="20" fill="${K.verde}" opacity="0.28"/>
  <circle cx="348" cy="110" r="20" fill="${K.verde}" opacity="0.28"/>`)

/** Gráfico de barras subindo — crescimento. */
peças['grafico-sobe'] = svg(460, 360, `
  <line x1="50" y1="310" x2="430" y2="310" stroke="${K.borda}" stroke-width="4"/>
  <line x1="50" y1="40" x2="50" y2="310" stroke="${K.borda}" stroke-width="4"/>
  ${[90, 140, 200, 262].map((h, i) =>
    `<rect x="${86 + i * 88}" y="${310 - h}" width="58" height="${h}" rx="8" fill="${i === 3 ? K.verde : K.amareloEsc}" opacity="${i === 3 ? 1 : 0.55}"/>`
  ).join('\n  ')}
  <path d="M104 220 L192 178 L280 132 L368 62" stroke="${K.amarelo}" stroke-width="6"
        fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M336 62 L368 62 L368 94" stroke="${K.amarelo}" stroke-width="6" fill="none"
        stroke-linecap="round" stroke-linejoin="round"/>`)

/** Pizza de margem: fatia que sai. */
peças['pizza'] = svg(400, 400, `
  <circle cx="200" cy="200" r="150" fill="${K.amareloEsc}" opacity="0.35"/>
  <path d="M200 200 L200 50 A150 150 0 0 1 330 275 Z" fill="${K.amarelo}"/>
  <path d="M200 200 L330 275 A150 150 0 0 1 200 350 Z" fill="${K.vermelho}" opacity="0.9"
        transform="translate(16,10)"/>
  <circle cx="200" cy="200" r="66" fill="${K.fundo}"/>`)

// ─────────────────────────────────────────────────────── personagem

/**
 * Pessoa preocupada, meio corpo. Estilo flat, sem contorno — o mesmo
 * vocabulário da referência: silhueta forte, poucos detalhes.
 */
/**
 * Pessoa preocupada, meio corpo com braços. Flat, silhueta forte, poucos
 * detalhes — o vocabulário da referência. O torso precisa de ombro e braço:
 * cabeça sobre um borrão escuro lê como boneco, não como personagem.
 */
peças['pessoa-preocupada'] = svg(460, 560, `
  <ellipse cx="230" cy="516" rx="168" ry="34" fill="${K.amarelo}" opacity="0.09"/>
  <!-- torso -->
  <path d="M110 520 Q110 372 230 372 Q350 372 350 520 Z" fill="${K.cabelo}"/>
  <!-- braços cruzados: gesto de preocupação -->
  <path d="M124 432 Q230 470 336 432" stroke="${K.cabelo}" stroke-width="46" fill="none" stroke-linecap="round"/>
  <path d="M124 432 Q230 470 336 432" stroke="${K.supAlta}" stroke-width="6" fill="none" stroke-linecap="round" opacity="0.5"/>
  <circle cx="150" cy="446" r="24" fill="${K.pele}"/>
  <circle cx="310" cy="446" r="24" fill="${K.pele}"/>
  <!-- pescoço -->
  <rect x="200" y="316" width="60" height="70" rx="22" fill="${K.peleEsc}"/>
  <!-- cabeça -->
  <ellipse cx="230" cy="252" rx="88" ry="94" fill="${K.pele}"/>
  <path d="M142 244 Q146 136 230 136 Q314 136 318 244 Q312 186 230 186 Q148 186 142 244 Z" fill="${K.cabelo}"/>
  <ellipse cx="198" cy="258" rx="8" ry="10" fill="${K.cabelo}"/>
  <ellipse cx="262" cy="258" rx="8" ry="10" fill="${K.cabelo}"/>
  <path d="M204 310 Q230 294 256 310" stroke="${K.cabelo}" stroke-width="8" fill="none" stroke-linecap="round"/>
  <path d="M180 228 Q198 216 216 226" stroke="${K.cabelo}" stroke-width="7" fill="none" stroke-linecap="round"/>
  <path d="M244 226 Q262 216 280 228" stroke="${K.cabelo}" stroke-width="7" fill="none" stroke-linecap="round"/>
  <!-- gota de tensão -->
  <path d="M330 236 q10 18 0 26 q-10 -8 0 -26 Z" fill="${K.amarelo}" opacity="0.8"/>`)

/** Pessoa satisfeita, braço aberto — a virada do vídeo. */
peças['pessoa-feliz'] = svg(460, 560, `
  <ellipse cx="230" cy="516" rx="168" ry="34" fill="${K.verde}" opacity="0.12"/>
  <path d="M110 520 Q110 372 230 372 Q350 372 350 520 Z" fill="${K.cabelo}"/>
  <!-- braço aberto, palma pra cima -->
  <path d="M336 424 Q392 396 404 340" stroke="${K.cabelo}" stroke-width="44" fill="none" stroke-linecap="round"/>
  <circle cx="406" cy="330" r="26" fill="${K.pele}"/>
  <path d="M124 430 Q150 470 168 496" stroke="${K.cabelo}" stroke-width="44" fill="none" stroke-linecap="round"/>
  <rect x="200" y="316" width="60" height="70" rx="22" fill="${K.peleEsc}"/>
  <ellipse cx="230" cy="252" rx="88" ry="94" fill="${K.pele}"/>
  <path d="M142 244 Q146 136 230 136 Q314 136 318 244 Q312 186 230 186 Q148 186 142 244 Z" fill="${K.cabelo}"/>
  <path d="M186 252 q12 -16 24 0" stroke="${K.cabelo}" stroke-width="8" fill="none" stroke-linecap="round"/>
  <path d="M250 252 q12 -16 24 0" stroke="${K.cabelo}" stroke-width="8" fill="none" stroke-linecap="round"/>
  <path d="M194 292 Q230 328 266 292" stroke="${K.cabelo}" stroke-width="9" fill="none" stroke-linecap="round"/>
  <circle cx="168" cy="288" r="13" fill="${K.vermelho}" opacity="0.18"/>
  <circle cx="292" cy="288" r="13" fill="${K.vermelho}" opacity="0.18"/>`)

// ── PERSONAGEM ────────────────────────────────────────────────────────
//
// Um sistema de coordenadas ÚNICO para todas as peças: todas usam o mesmo
// viewBox 420x760 e são desenhadas na posição final. Assim empilhar as peças
// no AE (todas no mesmo ponto, mesma escala) já resulta no personagem
// montado — sem cálculo de offset, que foi o que quebrou a versão anterior.
//
// Marcos do corpo (y): topo cabeça 84 · queixo 300 · pescoço 300-350 ·
// ombro 356 · quadril 760. Cabeça = 216px ≈ 1/6 do corpo (adulto).

var VB = 'viewBox="0 0 420 760" width="420" height="760"';
var svgP = function (corpo) {
  return '<svg xmlns="http://www.w3.org/2000/svg" ' + VB + '>\n' + corpo + '\n</svg>'
}

/** Só o torso: ombros, gola e braço esquerdo (o fixo). */
peças['p-corpo'] = svgP(`
  <!-- torso: ombro em y=356, largura 300 (1.4x a cabeça) -->
  <path d="M80 700 Q80 374 210 358 Q340 374 340 700 Z" fill="${K.cabelo}"/>
  <!-- braço esquerdo, encostado no corpo -->
  <path d="M104 432 Q84 530 96 612" stroke="${K.cabelo}" stroke-width="54" fill="none" stroke-linecap="round"/>
  <circle cx="98" cy="632" r="27" fill="${K.pele}"/>
  <!-- gola em V -->
  <path d="M172 364 L210 432 L248 364 Q230 356 210 356 Q190 356 172 364 Z" fill="${K.supAlta}"/>
  <path d="M210 436 L210 500" stroke="${K.supAlta}" stroke-width="5" opacity="0.4"/>
  <!-- pescoço: sai de DENTRO do torso, nunca solto -->
  <path d="M178 300 h64 v62 q-32 16 -64 0 Z" fill="${K.peleEsc}"/>
  <path d="M178 300 h64 v20 q-32 14 -64 0 Z" fill="#C99A6E"/>`)

/** Cabeça (sem rosto). Mesmo viewBox: entra no AE sem offset. */
peças['p-cabeca'] = svgP(`
  <ellipse cx="118" cy="196" rx="17" ry="25" fill="${K.pele}"/>
  <ellipse cx="302" cy="196" rx="17" ry="25" fill="${K.pele}"/>
  <path d="M210 84 Q312 84 314 186 Q314 258 266 292 Q240 312 210 312
           Q180 312 154 292 Q106 258 106 186 Q108 84 210 84 Z" fill="${K.pele}"/>
  <path d="M154 276 Q210 306 266 276 Q240 312 210 312 Q180 312 154 276 Z"
        fill="${K.peleEsc}" opacity="0.28"/>
  <path d="M106 184 Q100 74 210 74 Q320 74 314 184 Q308 138 256 128
           Q226 160 168 146 Q132 140 106 184 Z" fill="${K.cabelo}"/>
  <path d="M256 128 Q286 136 300 160 Q294 126 256 128 Z" fill="${K.cabelo}"/>`)

/** Rostos — mesmo viewBox, desenhados sobre a cabeça. */
peças['p-rosto-preocupado'] = svgP(`
  <ellipse cx="172" cy="208" rx="10" ry="13" fill="${K.cabelo}"/>
  <ellipse cx="248" cy="208" rx="10" ry="13" fill="${K.cabelo}"/>
  <circle cx="175" cy="204" r="3.4" fill="#FFFFFF" opacity="0.9"/>
  <circle cx="251" cy="204" r="3.4" fill="#FFFFFF" opacity="0.9"/>
  <path d="M144 176 Q170 162 192 174" stroke="${K.cabelo}" stroke-width="7" fill="none" stroke-linecap="round"/>
  <path d="M228 174 Q250 162 276 176" stroke="${K.cabelo}" stroke-width="7" fill="none" stroke-linecap="round"/>
  <path d="M202 232 q8 8 16 0" stroke="${K.peleEsc}" stroke-width="4" fill="none" stroke-linecap="round"/>
  <path d="M182 270 Q210 254 238 270" stroke="${K.cabelo}" stroke-width="7.5" fill="none" stroke-linecap="round"/>`)

peças['p-rosto-duvida'] = svgP(`
  <ellipse cx="172" cy="208" rx="10" ry="13" fill="${K.cabelo}"/>
  <ellipse cx="248" cy="208" rx="10" ry="13" fill="${K.cabelo}"/>
  <circle cx="176" cy="203" r="3.4" fill="#FFFFFF" opacity="0.9"/>
  <circle cx="252" cy="203" r="3.4" fill="#FFFFFF" opacity="0.9"/>
  <path d="M144 182 Q170 172 192 180" stroke="${K.cabelo}" stroke-width="7" fill="none" stroke-linecap="round"/>
  <path d="M228 162 Q252 146 276 160" stroke="${K.cabelo}" stroke-width="7" fill="none" stroke-linecap="round"/>
  <path d="M202 232 q8 8 16 0" stroke="${K.peleEsc}" stroke-width="4" fill="none" stroke-linecap="round"/>
  <path d="M184 266 Q212 258 240 272" stroke="${K.cabelo}" stroke-width="7.5" fill="none" stroke-linecap="round"/>`)

peças['p-rosto-feliz'] = svgP(`
  <path d="M158 202 q14 -18 28 0" stroke="${K.cabelo}" stroke-width="7.5" fill="none" stroke-linecap="round"/>
  <path d="M234 202 q14 -18 28 0" stroke="${K.cabelo}" stroke-width="7.5" fill="none" stroke-linecap="round"/>
  <path d="M148 170 Q172 160 194 168" stroke="${K.cabelo}" stroke-width="6.5" fill="none" stroke-linecap="round"/>
  <path d="M226 168 Q248 160 272 170" stroke="${K.cabelo}" stroke-width="6.5" fill="none" stroke-linecap="round"/>
  <path d="M172 254 Q210 296 248 254 Q210 272 172 254 Z" fill="${K.cabelo}"/>
  <ellipse cx="136" cy="240" rx="16" ry="11" fill="${K.vermelho}" opacity="0.20"/>
  <ellipse cx="284" cy="240" rx="16" ry="11" fill="${K.vermelho}" opacity="0.20"/>`)

/**
 * Braço direito, o que se move. Desenhado a partir do OMBRO (330, 400) para
 * baixo; no AE a âncora vai nesse ponto e a rotação fica natural.
 */
peças['p-braco'] = svgP(`
  <path d="M316 402 Q338 508 326 596" stroke="${K.cabelo}" stroke-width="54" fill="none" stroke-linecap="round"/>
  <path d="M316 402 Q338 508 326 596" stroke="${K.supAlta}" stroke-width="4.5" fill="none"
        stroke-linecap="round" opacity="0.26"/>
  <path d="M306 598 q24 9 38 -6" stroke="${K.supAlta}" stroke-width="6.5" fill="none" stroke-linecap="round"/>
  <path d="M300 610 q31 -9 46 16 q10 23 -9 35 q-26 14 -43 -6 q-13 -19 6 -45 Z" fill="${K.pele}"/>
  <path d="M318 628 v27 M333 630 v23 M346 636 v16" stroke="${K.peleEsc}" stroke-width="2.8" opacity="0.5" stroke-linecap="round"/>`)

/** Sombra no chão. */
peças['p-sombra'] = svgP(`
  <ellipse cx="210" cy="712" rx="150" ry="24" fill="${K.amarelo}" opacity="0.10"/>`)

/** Plaquinha que o personagem segura. */
peças['placa'] = svg(460, 340, `
  <rect x="26" y="22" width="408" height="212" rx="26" fill="#FFFFFF"
        stroke="${K.amarelo}" stroke-width="7"/>
  <rect x="26" y="22" width="408" height="212" rx="26" fill="${K.amarelo}" opacity="0.06"/>
  <rect x="214" y="230" width="30" height="96" rx="12" fill="${K.amareloEsc}"/>`)

peças['balao'] = svg(520, 320, `
  <path d="M26 26 h468 a28 28 0 0 1 28 28 v158 a28 28 0 0 1 -28 28 h-286 l-82 66 v-66 h-100
           a28 28 0 0 1 -28 -28 V54 a28 28 0 0 1 28 -28 Z"
        fill="#FFFFFF" stroke="${K.amarelo}" stroke-width="7"/>`)

peças['moeda'] = svg(160, 160, `
  <circle cx="80" cy="80" r="68" fill="${K.amarelo}" stroke="${K.amareloEsc}" stroke-width="7"/>
  <circle cx="80" cy="80" r="50" fill="none" stroke="${K.amareloEsc}" stroke-width="4" opacity="0.55"/>
  <text x="80" y="102" font-family="Helvetica,Arial" font-size="56" font-weight="bold"
        fill="${K.amareloEsc}" text-anchor="middle">$</text>`)

// ─────────────────────────────────────────────────────── diagramas

/** Cadeia de intermediação: 3 elos, o último destacado. */
peças['cadeia'] = svg(1160, 280, `
  ${[['Você', 0, K.borda], ['Plataforma', 1, K.borda], ['Adquirente', 2, K.amarelo]]
    .map(([, i, cor]) => {
      const x = 40 + Number(i) * 380
      return `<rect x="${x}" y="60" width="320" height="150" rx="20" fill="${K.sup}" stroke="${cor}" stroke-width="4"/>`
    }).join('\n  ')}
  <path d="M372 135 h44" stroke="${K.fraco}" stroke-width="5" stroke-linecap="round"/>
  <path d="M404 122 l16 13 l-16 13" stroke="${K.fraco}" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
  <path d="M752 135 h44" stroke="${K.amarelo}" stroke-width="5" stroke-linecap="round"/>
  <path d="M784 122 l16 13 l-16 13" stroke="${K.amarelo}" stroke-width="5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>`)

/** Funil: entra muito, sai pouco. */
peças['funil'] = svg(420, 460, `
  <path d="M40 40 h340 l-116 190 v150 h-108 v-150 Z" fill="none" stroke="${K.amarelo}" stroke-width="6"
        stroke-linejoin="round"/>
  <path d="M64 64 h292 l-104 168 v18 h-84 v-18 Z" fill="${K.amarelo}" opacity="0.16"/>
  ${[0, 1, 2].map((i) => `<circle cx="${140 + i * 70}" cy="${110 + i * 8}" r="16" fill="${K.amarelo}" opacity="${0.9 - i * 0.2}"/>`).join('\n  ')}
  <circle cx="210" cy="392" r="14" fill="${K.vermelho}"/>`)

/** Calendário com um dia marcado. */
peças['calendario'] = svg(420, 400, `
  <rect x="30" y="60" width="360" height="310" rx="22" fill="${K.sup}" stroke="${K.borda}" stroke-width="4"/>
  <rect x="30" y="60" width="360" height="66" rx="22" fill="${K.amarelo}"/>
  <rect x="30" y="104" width="360" height="22" fill="${K.amarelo}"/>
  <rect x="96" y="34" width="20" height="56" rx="10" fill="${K.fraco}"/>
  <rect x="304" y="34" width="20" height="56" rx="10" fill="${K.fraco}"/>
  ${Array.from({ length: 20 }, (_, i) => {
    const c = i % 5, l = Math.floor(i / 5)
    const marcado = i === 13
    return `<rect x="${68 + c * 60}" y="${156 + l * 52}" width="40" height="38" rx="8" fill="${marcado ? K.vermelho : K.supAlta}" opacity="${marcado ? 1 : 0.8}"/>`
  }).join('\n  ')}`)

/** Selo de porcentagem — a taxa. */
peças['selo-taxa'] = svg(360, 360, `
  <circle cx="180" cy="180" r="150" fill="${K.vermelho}" opacity="0.14"/>
  <circle cx="180" cy="180" r="150" fill="none" stroke="${K.vermelho}" stroke-width="8"
          stroke-dasharray="26 18"/>
  <circle cx="140" cy="140" r="26" fill="none" stroke="${K.vermelho}" stroke-width="12"/>
  <circle cx="220" cy="220" r="26" fill="none" stroke="${K.vermelho}" stroke-width="12"/>
  <line x1="118" y1="242" x2="242" y2="118" stroke="${K.vermelho}" stroke-width="12" stroke-linecap="round"/>`)

/** Escudo — segurança/gateway próprio. */
peças['escudo'] = svg(360, 420, `
  <path d="M180 24 L330 88 v130 q0 108 -150 178 Q30 326 30 218 V88 Z"
        fill="${K.sup}" stroke="${K.verde}" stroke-width="6"/>
  <path d="M180 52 L302 104 v112 q0 88 -122 148 Q58 304 58 216 V104 Z"
        fill="${K.verde}" opacity="0.12"/>
  <path d="M126 214 l38 40 l74 -88" stroke="${K.verde}" stroke-width="18" fill="none"
        stroke-linecap="round" stroke-linejoin="round"/>`)

/** Balão de fala com interrogação. */
peças['duvida'] = svg(360, 340, `
  <path d="M30 40 h300 a24 24 0 0 1 24 24 v170 a24 24 0 0 1 -24 24 h-172 l-70 62 v-62 h-58
           a24 24 0 0 1 -24 -24 V64 a24 24 0 0 1 24 -24 Z"
        fill="${K.sup}" stroke="${K.amarelo}" stroke-width="5"/>
  <path d="M148 116 q0 -34 34 -34 q34 0 34 32 q0 26 -30 34 v20" stroke="${K.amarelo}"
        stroke-width="14" fill="none" stroke-linecap="round"/>
  <circle cx="186" cy="212" r="10" fill="${K.amarelo}"/>`)

// ─────────────────────────────────────────────────────── escrita

let n = 0
for (const [nome, conteudo] of Object.entries(peças)) {
  writeFileSync(path.join(OUT, `${nome}.svg`), conteudo.trim() + '\n', 'utf8')
  n++
}
console.log(`${n} ilustrações em ${OUT}`)
