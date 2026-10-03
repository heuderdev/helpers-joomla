---
name: site-mockup
description: Cria mockups profissionais de sites/sistemas (notebook, celular e cena composta) usando o PRINT REAL do site dentro dos devices. Captura screenshots fiéis via Chrome+puppeteer (desktop e mobile emulado de verdade), sobe pro DigitalOcean, e gera com Nano Banana Pro (Gemini 3) com fundo temático da própria empresa. Use SEMPRE que o usuário pedir "cria mockup do site X", "monta mockup do projeto", "mockup de celular/notebook do site", "apresentação bonita do projeto", "device mockup", ou enviar uma URL pedindo apresentação visual.
---

# Site Mockup — mockups profissionais a partir do site REAL

Gera mockups de apresentação (portfólio/agência) colocando o **screenshot real do
site dentro dos devices**, com fundo temático que remete à empresa. Fluxo validado
e aprovado pelo Eduardo. **Sempre use esta skill quando ele pedir mockup de site.**

## Princípios que NÃO podem regredir (custaram iteração pra acertar)

1. **Print real, não invenção.** O device mostra o SITE DE VERDADE. O modelo só
   constrói o aparelho + ambiente. Prompt sempre com bloco de FIDELIDADE forçada.
2. **SPA/lazy-load precisa de espera.** Sites SPA (Nuxt, Inertia) e páginas Blade com Alpine/`loading="lazy"` carregam imagens depois do load.
   `shot.mjs` já espera `networkidle2` + delay; sem isso vem "bloco branco".
3. **Mobile DE VERDADE.** Só `--window-size` NÃO dispara as media queries — o título
   vaza/corta. `shot.mjs` emula iPhone real (`isMobile:true, hasTouch:true, DPR 3`).
4. **Laptop genérico.** No prompt: laptop prateado genérico, **sem marca / sem texto
   no chassi** (não dizer "MacBook Pro" — gera texto deformado no corpo).
5. **Fundo temático da empresa.** ANTES de gerar, entenda o site (cores reais,
   produtos, setor) e monte um fundo que remeta a ele — não um dark genérico.
6. **Nano Banana Pro só aceita ref por URL pública** → por isso o upload pro DO.

## Passo a passo

### 1. Entender a empresa (define o fundo temático)
Acesse o site (WebFetch) e identifique: setor, produtos, **cor de acento real**
(ex: o amarelo/laranja dos transformadores da Itaipu), imagery típica (torres,
grids, subestações...). O fundo do mockup deve usar esses tons/elementos.

### 2. Capturar os prints fiéis
```bash
cd ~/.claude/skills/site-mockup
node shot.mjs "https://SITE.com.br" "$HOME/Desktop/mockups-<projeto>/src"
# gera src/desktop.png e src/mobile.png
```
Depois **leia (Read) os 2 PNGs** e confirme visualmente que carregou tudo
(fotos presentes, título inteiro no mobile). Se algo faltou, aumente o delay
em `shot.mjs` (settle ms) e repita.

### 3. Subir os prints pro DigitalOcean (vira URL pública)
```bash
DESK_URL=$(./upload.sh "$HOME/Desktop/mockups-<projeto>/src/desktop.png" 1920)
MOB_URL=$(./upload.sh "$HOME/Desktop/mockups-<projeto>/src/mobile.png" 1080)
```

### 4. Gerar os mockups (Nano Banana Pro via skill magnific-image)
Use o `generate.py` da skill `magnific-image`. **PRIMEIRO gere em 1K pra aprovação**;
só depois de aprovado, regenere em 2K.

Blocos de prompt reutilizáveis (adapte o `BG` à empresa):
```
FID = "CRITICAL FIDELITY: the provided reference is a real website screenshot. Map it onto the device screen with PERFECT accuracy and alignment - identical layout, text, photos, logo, colors. Fill the ENTIRE screen edge-to-edge correctly scaled, no white gaps, no cropping, no stretching, no redesign, no hallucinated UI. Build only the realistic device and environment."

LAPTOP = "a clean modern silver aluminum laptop, generic design with a BLANK bezel and NO brand logo and NO text on the body or chassis."

BG = "<fundo temático da empresa: base dark + cor de acento real + silhuetas/elementos do setor (ex: torres de transmissão, grid elétrico), premium e clean, cinematic soft lighting>"
```

Os 3 (rode em paralelo, `&` + `wait`):
```bash
cd ~/.claude/skills/magnific-image
OUT="$HOME/Desktop/mockups-<projeto>/preview"; mkdir -p "$OUT"

# 1) NOTEBOOK 16:9
python3 generate.py --model nano-banana-pro --aspect 16:9 --resolution 1K --ref "$DESK_URL" \
  --out "$OUT/1-notebook.png" \
  --prompt "Photorealistic mockup of $LAPTOP At an elegant 45-degree hero angle, realistic proportions. $FID $BG Soft studio key light, subtle screen glow, realistic soft contact shadow. Ultra realistic, sharp."

# 2) CELULAR 9:16
python3 generate.py --model nano-banana-pro --aspect 9:16 --resolution 1K --ref "$MOB_URL" \
  --out "$OUT/2-celular.png" \
  --prompt "Photorealistic mockup of a modern black smartphone, front-facing centered, realistic proportions, blank bezel no brand. $FID The screenshot aligns PERFECTLY inside the screen, edge to edge, straight. $BG Realistic bezel highlights, subtle reflection, soft floating shadow. Ultra realistic, sharp."

# 3) CENA COMPOSTA 16:9
python3 generate.py --model nano-banana-pro --aspect 16:9 --resolution 1K --ref "$DESK_URL" --ref "$MOB_URL" \
  --out "$OUT/3-cena.png" \
  --prompt "Photorealistic device showcase: $LAPTOP next to a modern black smartphone on a clean dark reflective surface, realistic proportions. FIRST reference (desktop) maps EXACTLY edge-to-edge onto the laptop screen; SECOND reference (mobile) maps EXACTLY onto the phone screen. $FID $BG Realistic surface reflections, soft shadows, screen glow. High-end agency portfolio, ultra realistic, sharp."
```

### 5. Aprovar e finalizar
- **Leia (Read) os 3 PNGs** e confira fidelidade antes de mostrar.
- Copie pra `~/Downloads/` pro usuário ver. Para preview use subpasta
  `~/Downloads/<projeto>-preview/`.
- Após o "aprovado", **regenere os 3 em `--resolution 2K`** na pasta final.

## Resolução & aspectos
- Notebook e cena: `--aspect 16:9` (vira 1920x1080 em 2K).
- Celular: `--aspect 9:16`.
- Preview: `--resolution 1K`. Final aprovado: `--resolution 2K`.

## Variações que o usuário pode pedir
- 3º mockup alternativo: tablet, ou "trio flutuante" (notebook+tablet+celular 3D).
- Estilo de fundo: mais sutil / mais forte / outra cor de acento.
- Ângulo do notebook: hero 45°, frontal, top-down.

## Dependências
- Google Chrome instalado (`/Applications/Google Chrome.app`).
- `puppeteer-core` em algum projeto de `~/Empresas` (o `shot.mjs` acha sozinho).
- App Laravel com `POST /api/storage/imagem` (skill `api-upload-files`) — caminho em `SITE_MOCKUP_APP_DIR`; o `upload.sh` sobe/derruba o `php artisan serve` sozinho e gera o token Sanctum.
- Skill `magnific-image` (Nano Banana Pro / Gemini 3) pra geração.

## Lote grande (vários sites de uma vez)
- A Magnific tem **rate limit (HTTP 429 "Too many requests")**. NÃO dispare dezenas
  de gerações em paralelo — passam só as primeiras e o resto falha silenciosamente
  (arquivo não criado). Em lote, gere **sequencial** (1 imagem por vez) com `sleep ~8s`
  entre chamadas. Pode rodar sites diferentes em ondas, mas sem estourar a concorrência.
- Sempre confira no fim quantos PNGs foram criados vs esperado; regenere os faltantes.
- Para mockup "mobile em 1920x1080" (landscape): peça UM celular em pé CENTRALIZADO
  numa composição 16:9, com o fundo temático preenchendo as laterais (negative space).
- Suba os N prints numa chamada só (batch: `api-upload-files/upload.sh --app-dir ... --max-width 1920 a.png b.png ...`), não subindo/derrubando o `artisan serve` a
  cada arquivo. Salve as URLs num arquivo `key=url` e dê `source` nele no script de geração.

## Vídeo walkthrough (scroll do site) — pro lecdt.com

Além dos mockups estáticos, cada projeto do lecdt.com tem um **vídeo de scroll**
(desktop + mobile) que toca DENTRO do mockup na página do projeto. Fluxo:

### 1. Gravar o scroll (desktop + mobile)
```bash
cd ~/.claude/skills/site-mockup
node record.mjs "https://SITE.com.br" "$HOME/Desktop/rec-<projeto>/<projeto>-desktop.mp4" desktop
node record.mjs "https://SITE.com.br" "$HOME/Desktop/rec-<projeto>/<projeto>-mobile.mp4" mobile
```
O `record.mjs` já espera a SPA assentar e esconde widgets fixos (chat/whatsapp/cookie).
O MP4 cru sai pesado e em retina — NÃO é o que vai pro site; passa pela otimização.

### 2. Otimizar pro padrão do site (mp4 + webm + poster)
```bash
LECDT="$HOME/Empresas/Team Lecdt/Repositórios/Team Lecdt/lecdt.com"
./otimiza-video.sh "$HOME/Desktop/rec-<projeto>/<projeto>-desktop.mp4" \
  "$LECDT/public/videos/projetos/<projeto>-desktop" desktop
./otimiza-video.sh "$HOME/Desktop/rec-<projeto>/<projeto>-mobile.mp4" \
  "$LECDT/public/videos/projetos/<projeto>-mobile" mobile
```
Gera `<projeto>-{desktop,mobile}.{mp4,webm,jpg}` (~2MB cada) — exatamente o que o
`<video>` da página de projeto consome (autoplay loop mudo + poster).

### 3. Ligar o projeto no site

> O lecdt.com é um site **Nuxt** (produto existente) — os caminhos abaixo são dele. Num site Laravel/Blade o equivalente é: dados do projeto no model/seed (`database/seeders/ProjetoSeeder.php` ou `config/projetos.php`), flag `com_video` no registro e o `<video>` no componente `resources/views/components/projeto/visualizacao.blade.php`, com os arquivos em `public/videos/projetos/`.

- O `<nome>` tem que bater com o `nome` na `stores/storeProjetos.js`.
- Adicionar `<nome>` ao array `COM_VIDEO` em
  `components/pages/projeto/SectionVisualizacao.vue` (sem isso, cai no mockup estático).

## Padrão de assets de um projeto no lecdt.com (resumo)
Em `public/images/projetos/`:
- `<nome>.png` (cena 3-ambos), `<nome>-desktop.png`, `<nome>-mobile.png`
  (cópias dos `1-desktop` / `2-mobile` / `3-ambos` gerados pela skill).
Em `public/videos/projetos/`:
- `<nome>-desktop.{mp4,webm,jpg}`, `<nome>-mobile.{mp4,webm,jpg}`.

A **galeria** da página de projeto usa só os 2 mockups (`<nome>-desktop.png` +
`<nome>-mobile.png`). O **showcase** usa os vídeos (se o projeto estiver em `COM_VIDEO`).

## Notas
- O `nano-banana-pro` custa ~24 créditos/img — por isso aprova em 1K antes do 2K.
- Texto MUITO pequeno na tela ainda pode sair levemente suave (limite de IA);
  os títulos grandes saem nítidos.
- Limpe os prints intermediários (`src/`) se o usuário não os quiser.
- Cores reais saem melhor do PRINT do que de pesquisa — capture primeiro, depois
  defina o fundo temático olhando o desktop.png de cada site.
