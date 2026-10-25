---
name: gestaodev-video
description: Cria vídeos motion verticais (Reels/Stories 1080x1920) do Gestão Dev com narração por IA sincronizada, usando Remotion. Cada vídeo é um roteiro JSON que descreve as cenas; o sistema gera a narração no ElevenLabs, deriva os cortes dos timestamps da fala e monta a animação com a identidade real da marca (fontes, logo e os 109 ícones gd-* do produto). Use SEMPRE que o Eduardo pedir "cria um vídeo sobre X", "faz um reels de [feature]", "vídeo pro destaque do instagram", "monta um motion falando de [tema]", ou quiser ajustar/regerar os vídeos já existentes (proposta, tarefas, contratos, cobranças, nota fiscal, dashboard).
---

# gestaodev-video

Vídeos motion 1080×1920 com narração sincronizada, prontos para Reels e destaques do Instagram. Um vídeo = um JSON de roteiro; o resto é automático.

```bash
~/.claude/skills/gestaodev-video/criar.sh proposta      # um vídeo
~/.claude/skills/gestaodev-video/criar.sh --todos       # todos
```

Saída em `~/Downloads/gestaodev-videos/`. Primeira execução instala as deps (~2 min); depois cada vídeo leva ~30s.

## Como funciona

```
roteiro.json → ElevenLabs (fala contínua + timestamps) → corte de vales
             → tempos-<id>.json (cortes derivados da fala) → Remotion → ffmpeg
```

O ponto que sustenta tudo: **a duração de cada cena vem da narração real**, não de número escolhido à mão. Trocou uma frase? Roda de novo e a animação se reajusta.

## Escrever um roteiro

`projeto/roteiros/<id>.json`. Cada cena tem a narração e a descrição visual:

```json
{
  "titulo": "Cobranças",
  "vozEleven": "iP95p4xoKVk53GoZ742B",
  "caudaFinal": 0.8,
  "cenas": [
    {
      "id": "01-dor",
      "narracao": "Cobrar cliente é a parte que ninguém gosta.",
      "conteudo": {
        "tipo": "lista",
        "etiqueta": "Como é hoje",
        "corEtiqueta": "vermelho",
        "titulo": "Mandar o Pix na mão.",
        "itens": [
          { "titulo": "Copiar a chave", "detalhe": "Toda vez de novo", "icone": "gd-copiar" }
        ]
      }
    }
  ]
}
```

Depois: `criar.sh <id>` — o `indice.json` se atualiza sozinho e a composição aparece.

### Tipos de cena

| `tipo` | mostra | campos |
|---|---|---|
| `lista` | cards com ícone, foco percorrendo | `itens[]`, `mostrarTempo` |
| `passos` | timeline em que a linha puxa o próximo | `itens[]` |
| `numeros` | métricas grandes que sobem | `metricas[]` com `valor`, `prefixo`, `icone` |
| `grafico` | barras crescendo, última em destaque | `barras[]`, `rotuloGrafico` |
| `tela` | mockup do produto no celular | `tela{}` (ver abaixo) |
| `marca` | símbolo grande centrado — virada | `titulo`, `destaque` |
| `cta` | encerramento | `titulo`, `subtitulo`, `cta` |

Uma cena com `numero: { valor, rotulo, cor }` ganha o contador grande ao lado do título.

### O mockup (`tipo: "tela"`)

```json
"tela": {
  "titulo": "Cobrança #2841", "rotulo": "R$ 4.800", "claro": true,
  "linhas": [["Cliente", "Ana Martins"], ["Vencimento", "Hoje"]],
  "corpo": ["#Formas de pagamento", "Pix com desconto", "Cartão em até 21x"],
  "total": "R$ 4.800", "rotuloTotal": "Valor",
  "confirmaEm": 26, "selo": "Pagamento confirmado", "seloIcone": "gd-check"
}
```

`#` no início de uma linha do `corpo` vira subtítulo verde. `confirmaEm: 999` deixa a tela sem confirmação. **Marque `tituloCurto: true` quando o título couber numa linha** — o mockup cresce de 760 para 840px.

## Identidade — não inventar

Tudo vem do produto real, extraído do repo:

- **Fontes**: Baloo 2 (títulos) e Figtree (corpo), de `nuxt-web/public/fonts`
- **Logo**: `logo-branco.svg` do nuxt-web, vetorial
- **Ícones**: 43 dos 109 `gd-*` da lib `@edusites/icons` — os mesmos do produto
- **Cores**: fundo `#0C0C11`, superfície `#14141B`, verde `#1FE54A`

⚠️ **O acento é SEMPRE o verde `#1FE54A`.** Já tentei variar por tema (laranja/azul/roxo) e foi rejeitado: trocar a cor faz o vídeo deixar de parecer da marca. A diferenciação entre vídeos vem da **forma** — raio, densidade, marcador, superfície — definida em `temas` no `marca.js`.

## Adicionar um ícone

Os 43 embutidos cobrem os roteiros atuais. Para outro:

```bash
P="/Users/eduardolecdt/Empresas/Gestão Dev/Repositórios/frontend/nuxt-web/node_modules/@edusites/icons/src/icones"
ls "$P" | grep '^gd-' | sed 's/\.js$//'    # ver os 109 disponíveis

cd ~/.claude/skills/gestaodev-video/projeto
node -e '
const fs=require("fs"), P=process.argv[1], nome=process.argv[2]
const d=JSON.parse(fs.readFileSync("src/icones.json","utf8"))
const m=fs.readFileSync(`${P}/${nome}.js`,"utf8").match(/`([\s\S]*)`/)
d[nome]=m[1].trim().replace("<svg ",`<svg fill="currentColor" width="100%" height="100%" `)
fs.writeFileSync("src/icones.json", JSON.stringify(d,null,2))
console.log("ok:", nome)
' "$P" gd-NOME
```

⚠️ O `fill="currentColor"` vai **no próprio `<svg>`**, não no `<div>` pai: CSS `fill` não atravessa a fronteira do SVG e o ícone sai preto.

## Narração

Voz padrão: **Chris** (`iP95p4xoKVk53GoZ742B`), premade do plano free. As vozes brasileiras nativas da biblioteca exigem plano pago (`paid_plan_required`); o modelo `eleven_multilingual_v2` pronuncia português bem com as premade.

O áudio bruto fica em `audio-bruto/` — dá para **recalibrar o corte sem gastar crédito**:

```bash
MARGEM=0.12s criar.sh proposta --recortar   # mais respiro
LIMIAR=0.04  criar.sh proposta --recortar   # corta mais agressivo
```

Padrão: `LIMIAR=0.03`, `MARGEM=0.08s` → ~190-220 ppm com respiro preservado.

## Armadilhas que já custaram retrabalho

1. **`remotion.config.js`, nunca `.mjs`.** Com `.mjs` o CLI ignora **em silêncio** e volta ao default — foi o que manteve a concorrência em 4x e o render em 80s em vez de 30s.
2. **A fala vai num request só.** Frase gerada isolada é entoada como sentença fechada; oito seguidas soam como leitor de tela.
3. **`silencedetect` do ffmpeg é cego aqui.** O ElevenLabs deixa ruído de fundo nas pausas — o detector não acha nada nem a −22dB, enquanto há ~40% de vales. Por isso o corte usa `auto-editor`, que mede envoltória.
4. **Ícone inexistente = quadrado preto.** `icones['gd-inexistente']` é `undefined` e não gera erro. Os componentes retornam `null` agora, mas confira o nome ao escrever o roteiro.
5. **Zona segura obrigatória**: 260px no topo, 430px na base. A UI do Instagram cobre isso — o que sair da faixa é cortado no aparelho, mesmo aparecendo certo no preview.
6. **Sem overshoot.** Bounce é registro *playful*; premium chega e para. Os presets em `motion.js` são criticamente amortecidos de propósito.
7. **Raio mínimo 18.** Abaixo disso o canto lê como quadrado e destoa da UI do produto.
8. **Reencode no ffmpeg é obrigatório.** O Remotion sai em `yuvj420p` (full range) e alguns players lavam o preto.

## Ajustar o movimento

`src/motion.js` centraliza os números (todos vindos de prática profissional pesquisada):

- entrada **420ms**, saída **280ms** (saída ~1/1.4 da entrada)
- stagger por **orçamento total de 400ms** redistribuído, não offset fixo por item
- escala de entrada parte de **0.94** — nunca de 0
- curva padrão `cubic-bezier(0.4, 0, 0.2, 1)`, ênfase `(0.05, 0.7, 0.1, 1)`
- transição de cena = **câmera Z**, não fade (100% das demos de SaaS analisadas usam câmera)

Mexer aqui afeta todos os vídeos de uma vez — é o ponto certo para calibrar ritmo.

## Publicar

Os vídeos saem prontos para o Instagram (H.264, yuv420p, AAC 48kHz, −14 LUFS). Para publicar, use a skill **`gestaodev-instagram`** — mas atenção: ela publica **carrossel de imagem**; Reels usa outro endpoint (`media_type=REELS` com `video_url`), ainda não implementado lá.

## Vídeos existentes

`proposta` · `tarefas` · `contratos` · `cobrancas` · `notafiscal` · `dashboard`

Todos escritos a partir do que o produto **realmente** faz (levantamento em `endpoints/*.md` e nas páginas do `nuxt-app`). Ao criar novos, verifique a feature no código antes — não prometa o que não existe.

Relacionado: [[gestaodev-instagram]] para publicação, [[gestaodev-criativos-figma]] para os carrosséis estáticos.
