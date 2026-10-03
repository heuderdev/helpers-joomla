---
name: portfolio-post
description: Gera carrosséis de portfólio para Instagram (1080x1350) mostrando projetos feitos pela Team Lecdt, usando a identidade visual real do lecdt.com (tema mono P&B, fonte TWK Lausanne, padrões de seção). A partir dos mockups de um projeto (desktop/mobile/ambos) e de um briefing, a IA escreve os textos dos slides no tom da Team Lecdt, você aprova, e a skill renderiza os PNGs prontos pra postar. Use SEMPRE que o usuário pedir "cria um carrossel do projeto X", "post pra @teamlecdt mostrando o trabalho no projeto Y", "carrossel de portfólio", "post de case", "monta os slides do projeto pro ig", ou enviar mockups de um projeto pedindo um post.
---

# portfolio-post — Carrossel de Portfólio Team Lecdt

Gera carrosséis de Instagram (4:5, 1080x1350) que mostram projetos feitos pela
Team Lecdt, **100% na identidade visual do lecdt.com**. Self-contained: as fontes,
cores e padrões já estão embutidos na skill — não precisa de nenhum projeto rodando.

## Identidade visual (não invente, é fixa)

Extraída de `lecdt.com`. Está em `assets/tokens.css` e `template.html`:

- **Tema mono P&B**: fundo preto (`#000`), texto branco, cinzas `#b5b5b5`/`#7a7a7a`,
  hairlines em branco translúcido. **Sem cores chapadas** na UI — a única cor
  permitida é o **acento do projeto** (`--acento`), usado com parcimônia (o `<i>`
  dos rótulos e a seta). Se o projeto não tiver cor marcante, deixe acento branco.
- **Fonte**: TWK Lausanne — `--regular` (400) para tudo, `--light` (350) itálico
  para os trechos `<em>` dentro dos títulos.
- **Padrão de título**: `<h2>texto <em>destaque itálico</em></h2>`.
- **Padrão de rótulo**: `<span class="rotulo"><i>01</i> texto uppercase</span>`.
- **Grão sutil** + bordas hairline em cards/mockups. Nada de sombras coloridas,
  gradientes vistosos ou emojis. Sofisticado, escuro, editorial.

## Estrutura do carrossel (7 slides)

1. **Capa** — rótulo do nicho, título de impacto, mockup `ambos` (notebook+celular), nome do cliente.
2. **Desafio** (01/06) — o problema que o cliente tinha + 3 stats.
3. **Desktop** (02/06) — mockup notebook + legenda.
4. **Mobile** (03/06) — mockup celular + legenda.
5. **Identidade** (04/06) — texto + 3 amostras de cor do projeto.
6. **Stack** (05/06) — texto + pills das tecnologias.
7. **CTA** (06/06) — chamada pra fechar com a Team Lecdt + botão.

## Fluxo de trabalho

1. **Descobrir o projeto e os mockups.** Pergunte (ou identifique) o nome do projeto.
   Os mockups normalmente estão em `~/Downloads/conteudo/mockups-preview/<projeto>/`
   como `1-desktop.png`, `2-mobile.png`, `3-ambos.png`. Confirme os caminhos.
   Se o projeto já existe no `storeProjetos.js` do lecdt.com, reaproveite empresa/
   nicho/cor/stats/cores/tecnologias de lá para manter consistência.

2. **Briefing rápido.** Pergunte o ângulo (case de site, antes/depois, showcase),
   a cor de acento do projeto (hex) e qualquer dado real (ano, n.º de clientes, etc).

3. **Escrever os textos.** A IA preenche TODOS os campos do JSON (ver abaixo) no tom
   da Team Lecdt: direto, confiante, editorial, pt-BR, sem clichê de agência. Títulos
   curtos com um `<em>` de destaque. **Mostre os textos ao usuário e espere aprovação/
   ajustes antes de renderizar.**

4. **Renderizar.** Grave o JSON e rode:
   ```bash
   node ~/.claude/skills/portfolio-post/render.mjs <dados.json> <pasta-saida>
   ```
   Saída: `slide-01.png` … `slide-07.png` em 2160x2700 (deviceScaleFactor 2).
   Salve em `~/Downloads/conteudo/carrossel-<projeto>/`.

5. **Mostrar o resultado.** Leia 2-3 dos PNGs gerados para conferir visualmente
   (capa, um showcase, CTA) e apresente ao usuário.

## Formato do dados.json

```json
{
  "ACENTO": "#E0A93B",
  "EMPRESA": "Black Rooster Tattoo",

  "IMG_AMBOS":   "/caminho/abs/3-ambos.png",
  "IMG_DESKTOP": "/caminho/abs/1-desktop.png",
  "IMG_MOBILE":  "/caminho/abs/2-mobile.png",

  "CAPA_ROTULO": "estúdio de tatuagem",
  "CAPA_TITULO": "Demos um novo <em>traço digital</em> ao Black Rooster",
  "CAPA_SUB":    "Identidade, site e experiência criados do zero pela Team Lecdt.",

  "S2_ROTULO": "o desafio",
  "S2_TITULO": "Um estúdio com <em>arte de sobra</em>, sem vitrine à altura.",
  "S2_TEXTO":  "...",
  "S2_STAT1_N": "100%", "S2_STAT1_L": "sob medida",
  "S2_STAT2_N": "<1,5s", "S2_STAT2_L": "carregamento",
  "S2_STAT3_N": "mobile", "S2_STAT3_L": "first",

  "S3_ROTULO": "a experiência",
  "S3_TITULO": "Uma home que <em>impõe presença</em>.",
  "S3_LEGENDA": "...",

  "S4_ROTULO": "no bolso do cliente",
  "S4_TITULO": "Pensado <em>primeiro</em> para o celular.",
  "S4_LEGENDA": "...",

  "S5_ROTULO": "identidade visual",
  "S5_TITULO": "Preto, dourado e <em>atitude</em>.",
  "S5_TEXTO":  "...",
  "COR1_NOME": "Dourado", "COR1_HEX": "#E0A93B",
  "COR2_NOME": "Preto",   "COR2_HEX": "#0A0A0A",
  "COR3_NOME": "Off-white","COR3_HEX": "#F5F5F5",

  "S6_ROTULO": "por dentro",
  "S6_TITULO": "A stack que faz <em>tudo voar</em>.",
  "S6_TEXTO":  "...",
  "stack": ["Laravel 12", "PHP", "Alpine.js", "Tailwind CSS", "MySQL", "SEO técnico"],

  "S7_ROTULO": "sua marca é a próxima?",
  "S7_TITULO": "A gente transforma seu negócio em <em>experiência digital</em>.",
  "S7_TEXTO":  "Sites, sistemas e aplicativos sob medida — do UX à segurança.",
  "S7_BOTAO":  "Chama no direct"
}
```

Notas:
- Use `<em>...</em>` nos títulos para o destaque itálico cinza (1 por título).
- Imagens: caminhos absolutos de arquivo (o render converte pra `file://`).
- `stack` é um array; o render gera os `<li>` automaticamente.
- Cores das amostras (slide 5): use as cores REAIS da marca do projeto, não o mono.

## Reaproveitar / customizar

- Para outro número de slides ou outra ordem, edite `template.html` (cada `<article
  class="slide" id="slide-N">` é capturado individualmente por id sequencial).
- Para outro cliente/marca além da Team Lecdt no futuro, duplique a pasta da skill e
  troque `assets/tokens.css`, `assets/logo-*.svg` e os textos de rodapé do template.
- Mockups novos: a skill `site-mockup` gera os PNGs de device a partir da URL do site.
```
