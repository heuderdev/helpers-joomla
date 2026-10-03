---
name: workshop-video
description: "Reels e stories 1080x1920 narrados para os produtos do Eduardo (EduSites, workshops, Gestão Dev, UnicPages), feitos em Remotion com narração ElevenLabs sincronizada, legenda karaokê e logos de tecnologia reais. Use quando pedir 'cria um reels', 'monta um story', 'vídeo narrado', 'vídeo pro workshop/produto X'."
---

# workshop-video

Vídeo vertical narrado, renderizado por código. Um vídeo = um roteiro JSON + um
arquivo de cenas; o resto o pipeline resolve.

```bash
cd "<projeto>"
./gerar.sh <id>                                    # narração + cortes + tempos
./node_modules/.bin/remotion studio src/index.js --port 3333   # o usuário dá play
```

Projeto de referência (5 vídeos aprovados):
`/Users/eduardolecdt/Empresas/Edu Sites/Workshops/Sistema com Claude/reels`

## Pipeline

```
roteiro.json → ElevenLabs v3 (fala contínua + alignment) → auto-editor (corte)
             → tempos-<id>.json (cortes derivados da fala) → Remotion
```

**A duração de cada cena vem da narração real.** Trocou uma frase? Roda
`./gerar.sh <id>` de novo e a animação se reajusta — nenhum número na mão.

## Narração — o que está aprovado

```jsonc
{
  "vozEleven": "cjVigY5qzO86Huf0OWal",  // Eric — masculina, escolhida pelo Eduardo
  "modelo": "eleven_v3",
  "voz": { "stability": 0.45, "similarity_boost": 0.8, "use_speaker_boost": true }
}
```

Mais: `apply_text_normalization: 'off'`, **sem `speed`**, **sem `style`**.

**Corte de silêncio AGRESSIVO** (o Eduardo pediu "bem dinâmico, sem pausa"):
`--edit "audio:threshold=0.025" --margin 0.06s`. Tira ~20% da duração.
(A skill do Dominnus usa 0.20s, conservador — aqui não serve.)

**Audio tags dirigem o tom por contraste**: `[conversacional]` na dor →
`[curiosa]` na virada → `[confiante]` na oferta → `[animada]` no CTA.
`stability` ≤ 0.5 é obrigatório, senão o v3 suprime as tags.

**Escreva por extenso**: `dezessete reais`, não `R$ 17`.

### Vozes da conta

| Voz | ID | Perfil |
|---|---|---|
| **Eric** (padrão) | `cjVigY5qzO86Huf0OWal` | masculina, smooth, confiável |
| Chris | `iP95p4xoKVk53GoZ742B` | masculina, informal |
| Liam | `TX3LPaxmHKxFdv7VOQHJ` | masculina, energética |
| Roberta | `RGymW84CSmfVugnA5tvA` | feminina BR nativa |

⚠️ As masculinas são nativas de inglês (sotaque leve). As BR nativas são todas
femininas. Existe uma voz "EduSites" (`RMxiAUoxGMNEce5vsuPj`) que é clone do
Eduardo, mas **o fine-tuning nunca foi concluído** — a API recusa.

## Identidade — vem do produto, nunca inventada

`src/marca.js`. Para o workshop: fundo `#0c141a`, superfície `#141f2a`, acento
**laranja `#ff6f40`**, azul `#76a4ff` só como secundário (grade, linhas).

Fontes reais copiadas de `nuxt-web/public/fonts`: **Unison Pro** (títulos),
**Brier** (números/valores), **Söhne** (corpo). Aqui elas funcionam — são
arquivos locais, diferente do Figma.

**Logo oficial**: extraída do componente Vue (`LogoWorkshopSistema.vue`),
convertendo os bindings `:id="\`x-${uid}\`"` em atributos fixos.

## Regras de layout que custaram retrabalho

**1. Todo conteúdo CENTRALIZADO na área útil.** O padrão é:
```jsx
position: 'absolute', top: 545, bottom: seguro.base + 30,
left: seguro.lateral, right: seguro.lateral,
display: 'flex', flexDirection: 'column',
alignItems: 'center', justifyContent: 'center'
```
Nunca ancorar no topo e deixar vazio embaixo.

**2. `alignItems: 'center'` no container, `alignSelf: 'stretch'` em quem
precisa esticar** (barra, lista, terminal, tabela). Aplicar `stretch` no
container COLAPSA a largura de pílulas, grades e títulos.

**3. Exceções que NÃO podem virar column:**
- gráfico de barras → `alignItems: 'flex-end'` (crescem do mesmo piso)
- filhos em posição absoluta (moedas, curvas SVG, waveform) → container simples
- grade → `display: 'grid'` + `alignContent: 'center'`

**4. Título: medir a largura REAL, glifo a glifo.**
A Unison é larguíssima — avanço médio das maiúsculas é **1.02 do fontSize**.
Estimar por média (usei 0.62) estoura a margem. Extraia com fontTools:
```python
from fontTools.ttLib import TTFont
f=TTFont('public/fontes/UnisonPro-Bold.woff2')
larguras={chr(c): f['hmtx'][n][0]/f['head'].unitsPerEm for c,n in f.getBestCmap().items()}
```
`tamanhoQueCabe(linhas, desejado)` em `cenas.jsx` já faz a conta.

**5. Zona segura**: 260px topo, 430px base — a UI do Instagram cobre isso.

## Beats: 2,5–3s cada

Uma frase da narração vira UMA cena. Frase longa → quebre em duas.
Beat de 5s fica parado por mais que o conteúdo se mexa.

## Coreografia PRÓPRIA por frase

Trocar o texto de uma cena existente não cria vídeo novo, cria o mesmo vídeo com
outra legenda. A forma sai do que a frase PEDE:

| a narração diz | a forma é |
|---|---|
| "quebra em produção" | a tela racha de verdade |
| "cortando no tempo da minha voz" | waveform com marcadores nos vales |
| "o dinheiro troca de mão" | moedas migrando em arco entre duas caixas |
| "poucos entenderam" | 48 quadrados cinza, 5 acendem |
| "a vaga encolheu" | duas barras do mesmo piso, uma despencando |
| "o MCP liga tudo" | hub central com linhas para 6 ferramentas em órbita |
| "nenhuma fez sozinha" | barra segmentada, cada logo com sua fatia |
| "não decide a arquitetura" | 3 opções, alguém precisa escolher |

**Pode repetir entre vídeos**: `loteVira`, `dataEvento`, `stackViva`, `ctaStory`
— são assinatura da série.

## Ícones de tecnologia — use os PNGs OFICIAIS

`/Users/eduardolecdt/Empresas/Gestão Dev/Repositórios/.claude/assets/icones-tecnologias/`
tem **444 PNGs** + `tecnologias.json` com a cor de marca de cada uma.

**Copie o PNG, não recolora SVG monocromático** — o logo real com a cor certa
vale muito mais. `IconeTec` aceita os dois (`png: true` no json usa `<Img>`).

Alguns são pretos e somem no fundo escuro (ElevenLabs, Vercel, GitHub):
```bash
ffmpeg -y -i icone.png -vf "format=rgba,lutrgb=r=255:g=255:b=255" saida.png
```
Não existem no catálogo: MCP, Anthropic, Replit, Magnific → SVG ou desenho próprio.

## Legenda karaokê

`src/Legenda.jsx`. Janela de até 3 palavras (quebra na pontuação), a palavra
acende quando é falada. Bloco longo em vertical ninguém lê.
Os tempos vêm do alignment real, já compensando o silêncio cortado.

## Som por EVENTO visual, não por corte

Um whoosh em cada transição vira tique nervoso. O efeito entra onde há algo que
o justifique: a tecla digitada, a tela rachando, o preço travando.
Biblioteca em `public/audio-lib/sfx/`: pop, whoosh, trava, chime, impacto,
digita, erro, papel. Níveis: narração −14 LUFS, efeitos −20, trilha −26.

⚠️ `posicoesReais()` no `Video.jsx`: a transição SOBREPÕE as vizinhas, então
usar `cena.inicio` cru atrasa os sons progressivamente.

## CTA padrão da série

**"COMENTA EU QUERO"** com balão de comentário + "que eu mando o link".
Nunca citar valor de lote — o vídeo precisa servir em qualquer data.
Marca d'água **@edusites** no canto superior direito de TODO vídeo.

## Dois bugs de sincronia que já custaram retrabalho

1. **Não conte caracteres do texto enviado** para achar o fim de cada cena. O
   alignment devolve seu próprio array; procure a cauda da frase DENTRO dele,
   removendo `[tags]` (ocupam índice sem serem faladas).
2. **Não remapeie por fator linear** (`depois/antes`). Use os `chunks` do
   `auto-editor --export v1` para saber quanto saiu ANTES de cada instante.

Ambos produzem vídeo que parece certo no começo e desencaixa no fim.

## Revisar sem ficar achando defeito aos poucos

Renderize um frame no meio de CADA cena e monte contact sheets:
```bash
# lista.txt com "Composicao|frame|cena", derivada de tempos-*.json
while IFS='|' read -r comp frame cena; do
  ./node_modules/.bin/remotion still src/index.js "$comp" "/tmp/rev/$cena.png" --frame=$frame --scale=0.4
done < lista.txt
```
Depois junte com PIL em grade 7xN. Foi assim que achei 3 layouts quebrados de
uma vez em vez de descobrir um por print do usuário.

## Vídeos existentes

| id | tema | duração |
|---|---|---|
| `ia-fez-tudo` | a IA fez esse reels (zoeira) | 37s |
| `troca-de-mao` | o dinheiro vai trocar de mão | 45s |
| `dev-acabou` | dizem que a IA acabou com o dev | 48s |
| `ferramentas-certas` | não é só abrir o ChatGPT | 37s |
| `perfil-tomado` | o perfil foi tomado pela IA (story) | 42s |

## Fazer um vídeo novo

1. `roteiros/<id>.json` — beats de 2,5-3s, audio tags, tudo por extenso
2. `src/cenas-<tema>.jsx` — coreografia própria por frase, registre em `CENAS_*`
3. `Video.jsx` — importe as cenas, adicione ao `SONS` e crie a `<Composition>`
4. `./gerar.sh <id>` — e ouça a narração antes de aprovar o visual
