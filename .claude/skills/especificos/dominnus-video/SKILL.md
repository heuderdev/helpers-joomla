---
name: dominnus-video
description: Cria vídeos motion verticais (Reels/Stories 1080x1920) da Dominnus com narração feminina brasileira dirigida (ElevenLabs v3 com audio tags), trilha e efeitos sonoros próprios, usando Remotion. Cada vídeo é um roteiro JSON de beats curtos; o sistema gera a narração, deriva os cortes dos timestamps da fala e monta a animação com a identidade real da marca (azul #0D2EFC, Sohne + Figtree, logos reais de bancos e marcas). Use SEMPRE que o Eduardo pedir "cria um vídeo/reels da Dominnus", "faz um motion de [feature]", "vídeo pro destaque do instagram da Dominnus", ou quiser ajustar/regerar os vídeos já existentes.
---

# dominnus-video

Vídeos motion 1080×1920 com narração sincronizada, prontos para Reels e destaques. Um vídeo = um JSON de roteiro; o resto é automático.

```bash
~/.claude/skills/dominnus-video/criar.sh abertura     # um vídeo
~/.claude/skills/dominnus-video/criar.sh --todos      # todos
```

Saída em `~/Downloads/dominnus-videos/`. Cada vídeo leva ~1min de render.

## Como funciona

```
roteiro.json → ElevenLabs (fala contínua + timestamps) → corte de vales
             → tempos-<id>.json (cortes derivados da fala) → Remotion → ffmpeg
```

O ponto que sustenta tudo: **a duração de cada cena vem da narração real**, não de número escolhido à mão. Trocou uma frase? Roda de novo e a animação se reajusta.

## Narração — a receita que funcionou

> Esta seção é o resultado de **quatro rodadas de correção**. A configuração
> abaixo é a que ficou aprovada; a seção "O que já foi tentado e falhou" no fim
> guarda os becos sem saída para não os repetir.

### A configuração aprovada

```jsonc
// no roteiro
{
  "vozEleven": "RGymW84CSmfVugnA5tvA",   // Roberta
  "modelo": "eleven_v3",
  "voz": { "stability": 0.45, "similarity_boost": 0.8, "use_speaker_boost": true }
}
```

Mais, no `gerar.mjs`: `apply_text_normalization: 'off'`, **sem `speed`**, **sem
`style`**, `seed` variável por `SEED=n`.

E no pipeline: corte de silêncio **conservador** — `CORTAR=1 MARGEM=0.20s
LIMIAR=0.03`.

### Os cinco ingredientes

**1. Modelo `eleven_v3`.** O salto de qualidade veio daqui. O
`multilingual_v2` **não modela estado emocional**: produz uma leitura
prosodicamente correta e *uniforme*, e nenhum parâmetro cria variação de energia
ou ataque de frase porque o modelo não tem representação disso. Era um teto real.

⚠️ **O v3 funciona em `/with-timestamps` e devolve `alignment` válido** — testado
na API. A doc não o lista ali e várias fontes dizem que é incerto; não é. Bônus:
o alignment vem **1:1 com o texto enviado** (o v2 normalizava e devolvia menos).

**2. Direção por audio tags.** Só existe no v3, e não são faladas (colapsam para
~0.06s no alignment):

```json
{ "id": "05", "narracao": "[animada] O Dominnus resolve isso de um jeito diferente:" }
```

Vocabulário: `[conversacional]` `[animada]` `[confiante]` `[curiosa]`
`[sussurra]` `[pausa]` `[apressada]`.

Dirija por **contraste**, que é como locução publicitária ganha energia — nunca
por volume ou velocidade constantes. A direção deste vídeo:
`[conversacional]` na dor → `[animada]` na virada → `[curiosa]` na pergunta →
`[confiante]` na segurança → `[animada]` no CTA.

⚠️ **`stability` ≤ 0.5 é obrigatório**: em valor alto o v3 **suprime as tags** e
as marcações não têm efeito nenhum.

**3. Sem `speed`, sem `style`.**
- `speed` condiciona a geração e comprime as excursões de entonação. A 1.10
  (topo da faixa) estava achatando a melodia *justamente enquanto se tentava
  ganhar fluidez*. O ritmo vem das tags e da pontuação.
- `style` > 0 desestabiliza — a própria ElevenLabs recomenda 0. A voz
  `professional` já carrega o estilo da locutora; o slider empilhava ênfase
  artificial e criava picos desconectados.

**4. Redação que encadeia.** O ponto final é o sinal mais forte de queda de
entonação: uma sequência de frases curtas com ponto é uma sequência de
fechamentos — a definição perceptual de "entrecortado". Emende as orações do
mesmo pensamento com vírgula e conjunção, e reserve o ponto para o fim do bloco.
Períodos de 15–25 palavras encadeiam melhor. Este roteiro caiu de 18 pontos
finais para 9.

**5. Corte de silêncio conservador.** O `auto-editor` foi feito para gravação
humana, onde há hesitação e tempo morto real. **Num TTS os vales entre orações
SÃO a prosódia que o modelo escolheu.** Margem de 0.05s deixava a fala
entrecortada; 0.20s remove só pausa anômala e preserva a respiração.

### Escolher o take

O modelo é estocástico: a mesma frase variou de **8,6s a 11,2s** entre seeds,
com entrega audivelmente diferente. Locutor humano grava vários takes e o
diretor escolhe:

```bash
SEED=7 node gerar.mjs abertura --forcar     # outra tomada
```

Escolha o melhor take **inteiro**. Não costure trechos de takes diferentes —
reintroduz o "cada frase num take diferente".

### Escreva como o locutor vai ler

A regra da redação publicitária de rádio vale literalmente para o TTS: *o roteiro
se escreve do jeito que será lido*. Com `apply_text_normalization: 'off'`, **nada
é expandido automaticamente**.

| ❌ Não escreva | ✅ Escreva |
|---|---|
| `R$ 50` | `cinquenta reais` |
| `R$ 49,90` | `quarenta e nove reais e noventa` |
| `7 dias` | `sete dias` |
| `32%` | `trinta e dois por cento` |
| `PDF` | `pê dê efe` |
| `CNPJ` | `cê ene pê jota` |

⚠️ **Nunca deixe vírgula decimal em dígito** (`49,90`): é lida como separador de
milhar do inglês.

### Sigla e endereço — a armadilha do idioma

O modelo **infere** o idioma numa janela curta de contexto, então sigla e letra
solta **escapam da âncora** da frase em português:

- ❌ `bê érre` (para `.br`) → sai com R de inglês ("bê érri"): "erre" colide com
  padrão ortográfico anglófono e o prior do inglês vence.
- ✅ `pê dê efe` (para PDF) → sai certo: "efe" não é palavra inglesa plausível.

**Regra prática:** ao escrever uma sigla nova, pergunte *"esse pedaço parece
inglês?"*. Letras de risco: **R, H, W, Y** e vogais soltas.

**A solução do `.br` foi cortar a sigla.** Locutor de rádio brasileiro fala
"acesse dominnus ponto com" — o spot fixa a marca, não a string do endereço.
Resolveu a pronúncia e melhorou a copy.

⚠️ **`language_code` não existe no v2** (doc: *"not supported for
multilingual_v2"*). A única alavanca de idioma é o texto.

### As vozes disponíveis

Esta conta tem vozes **brasileiras nativas** `professional` (a skill do Gestão
Dev diz o contrário e está desatualizada):

| Voz | ID | Perfil |
|---|---|---|
| **Roberta** (padrão) | `RGymW84CSmfVugnA5tvA` | jovem, confiante, suave |
| Yasmin Alves | `lWq4KDY8znfkV0DrK8Vb` | jovem, clara e musical |
| Jenifer | `GOkMqfyKMLVUcYfO2WbB` | jovem |
| Fernanda | `7iqXtOF3wl3pomwXFY7G` | madura, formal e direta |

ℹ️ O v3 não é oficialmente otimizado para vozes `professional` (PVC) — a
ElevenLabs recomenda IVC para v3. Na prática a Roberta ficou ótima; se um dia o
timbre incomodar, o caminho é clonar uma locução de referência como IVC.

### Se um dia precisar de outra voz (ou de locutor humano)

`POST /v1/forced-alignment` (multipart: `file` + `text`) alinha áudio e texto e
devolve timestamps por palavra. Precisão medida: **erro médio ~100ms, máximo
~200ms** — pior que o alignment nativo, então **não troque enquanto o nativo
existir**. Mas desacopla a sincronia da escolha de voz: com ele, qualquer TTS ou
até um locutor humano gravado entra no pipeline.

### O que o modelo suporta

| Recurso | `eleven_v3` (usado) | `multilingual_v2` |
|---|---|---|
| audio tags `[animada]` | ✅ | ❌ saem **lidas em voz alta** |
| `alignment` em `/with-timestamps` | ✅ 1:1 com o texto | ✅ mas normalizado |
| `<break time="0.3s"/>` | ❌ | ✅ |
| `speed` | evite | 0.95–1.10 |
| `<phoneme>`, `<prosody>`, `<say-as>` | ❌ | ❌ |
| `language_code` | — | ❌ ignorado |
| CAPS para ênfase | ✅ | ✅ |
| reticências (…) e travessão (—) | ✅ | ✅ |

Mito: repetição de vogal ("muuuito") não é técnica, é folclore — produz artefato.

### Sincronia — dois bugs que já custaram retrabalho

Ambos produzem vídeo que **parece certo no começo e desencaixa no fim**, porque o
erro acumula. Nenhum gera exceção.

**1. Não derive os cortes contando caracteres do texto que você enviou.**
O alignment devolve um array `characters` que **não é idêntico** ao enviado (o v2
normaliza: 958 de 992). Usar `narracao.length` como cursor desloca o índice a
cada cena. O `fimDaCena()` procura a cauda de cada frase dentro do texto
devolvido — e remove `[tags]` e `<tags>` antes de casar, porque elas ocupam
índices sem serem faladas.

**2. Ao cortar silêncio, não remapeie por fator linear.**
`depois/antes` só valeria se a compressão fosse uniforme, mas o corte remove
silêncio em pontos específicos. O `remapear()` usa `auto-editor --export v1`, que
devolve os `chunks` como `[inicioFrame, fimFrame, velocidade]` (velocidade
≥99999 = descartado) — dá para saber exatamente quanto saiu antes de cada
instante.

✅ **Validação rápida:** compare o `ffprobe` do áudio final com a duração
calculada da composição. Se divergirem além da `caudaFinal`, o remapeamento está
errado.

### O que já foi tentado e falhou

Não repita:

| Tentativa | Por que falhou |
|---|---|
| Cortar silêncio com `margin 0.05s` | Destrói a prosódia: num TTS os vales **são** a entonação escolhida |
| `speed` 1.14 (e depois 1.10) | Comprime as excursões de entonação — achata a melodia |
| `style: 0.35` | Empilha ênfase artificial sobre a voz PVC; picos desconectados |
| `language_code: 'pt'` | Ignorado no multilingual_v2 |
| Escrever `b r` para `.br` | Lê as letras soltas |
| Escrever `bê érre` | Sai com R de inglês |
| `<break>` no v3 | Não suportado (sai como texto) |
| Seed fixo | Tranca numa única tomada; o modelo é estocástico |
| Ficar no `multilingual_v2` | Teto real: não modela emoção — foi **a** causa do "soa lido" |

## Escrever um roteiro

`projeto/roteiros/<id>.json`. O roteiro diz **o que se fala** e **qual cena toca**:

```json
{
  "titulo": "Dominnus — seu dinheiro se organiza sozinho",
  "vozEleven": "RGymW84CSmfVugnA5tvA",
  "caudaFinal": 0.9,
  "cenas": [
    { "id": "03-bancos", "narracao": "Pelo Open Finance, o Nubank, o Itaú...", "conteudo": { "cena": "bancos" } }
  ]
}
```

⚠️ **O `id` do roteiro precisa existir como chave em `temas` no `marca.js`** — o
`Video.jsx` resolve o tema por `v.id`. Sem a chave, cai no `temaPadrao` em silêncio.

## Cenas — beats curtos com continuidade líquida

**Duas regras definem esta skill.**

**1. Se a narração cita uma coisa, essa coisa aparece e se move.** Falou Open
Finance → os logos dos bancos entram e conectam. Falou pix → o símbolo do PIX.
Falou iFood → o logo do iFood, na cor dele.

**2. Um beat por ideia, ~2,5-3s cada.** Uma frase da narração vira DUAS ou TRÊS
cenas. É a regra que custou mais para aprender.

### Por que beats curtos

A 2ª versão já tinha coreografia boa, mas eram 8 planos para 63s — ~8s cada. Um
plano de 8s **fica parado 5-6s por mais que o conteúdo se mexa**, e acelerar as
animações internas não resolve: o problema é estrutural. Hoje são 18 beats de
~2,8s em 51s.

Escreva o roteiro quebrando a frase:

```json
{ "id": "07", "narracao": "Pelo Open Finance, o Nubank, o Itaú, o Inter,", "conteudo": { "cena": "bancosChegam" } },
{ "id": "08", "narracao": "todos os seus bancos passam a conversar com ele.",  "conteudo": { "cena": "bancosConectam" } }
```

Os cortes saem dos timestamps da fala, então dividir uma cena em duas **reajusta
os tempos sozinho** — não há número para recalcular na mão.

### Continuidade: o que liga um beat ao outro

O corte não pode ser "acabou uma, começou outra". O elemento **atravessa**:

- beats 07→08: os bancos ficam na MESMA órbita; só as linhas de conexão são novas
- beats 09→10: as mesmas marcas do extrato, agora recebendo a etiqueta de categoria
- beats 01→02: a grade entra já preenchida, na posição em que o beat anterior a deixou
- beats 05→06: o núcleo da marca continua onde estava

Por isso as coordenadas `CX`, `CY`, `RAIO` e `ORDEM_BANCOS` são **constantes de
módulo compartilhadas** em `cenas.jsx`. Se um beat calcular a posição por conta
própria, o elemento salta no corte e a ilusão quebra.

### Superfície líquida

`Palco` desenha dois blobs de gradiente radial com `blur(30-40px)` movendo-se em
fases diferentes (períodos 38/44/46/52 — primos entre si, para o ciclo não se
repetir de forma perceptível). O fundo nunca fica chapado.

### Cada vídeo tem coreografia PRÓPRIA

> Esta é a regra mais cara da skill. A primeira leva dos 5 vídeos temáticos foi
> **rejeitada**: eu tinha parametrizado as cenas da abertura e reaproveitado
> trocando título e dados. O resultado foi *"você fez idêntico à abertura só
> mudando a ordem"* — e estava certo.

**Trocar o texto de uma coreografia não cria um vídeo novo, cria o mesmo vídeo
com outra legenda.** A forma visual precisa sair do que a frase PEDE:

| a narração diz | a forma é |
|---|---|
| "some um dinheiro todo mês" | calendário com as cobranças pingando nos dias |
| "acha o que se repete" | radar varrendo; o que se repete acende e fica |
| "a IA confirma o que é de verdade" | peneira: entra tudo, sai só assinatura |
| "quanto dá no ano" | 12 meses empilhando até o total |
| "cadê o dinheiro" | moedas escoando por um ralo |
| "entrou menos saiu" | balança de dois pratos pendendo |
| "taxa de poupança" | cofre enchendo até a marca |
| "em que dia você gasta" | heatmap da semana |
| "em que hora" | relógio de 24h com raios |
| "onde mais gastou" | pódio de marcas |
| "todos os cartões" | leque de cartões abrindo |
| "fecha e vence" | trilho do ciclo com dois marcadores |
| "cada compra na fatura" | compras caindo dentro do cartão |
| "todo app espera você abrir" | ícone cinza com badge crescendo |
| "às 20h ele te procura" | relógio marcando + notificação descendo |
| "celular e e-mail" | canais irradiando do núcleo |

Cada vídeo tem seu arquivo: `cenas-assinaturas.jsx`, `cenas-economia.jsx`,
`cenas-padroes.jsx`, `cenas-cartoes.jsx`, `cenas-relatorios.jsx`. O `cenas.jsx`
guarda a abertura, os helpers compartilhados (`Palco`, `Legenda`, `Assinatura`,
`Nucleo`, `faixa`) e o registro `CENAS`.

**O que PODE repetir entre vídeos:** `virada`, `cta` e `selo` — são a assinatura
da marca, e repeti-las constrói reconhecimento. Todo o resto deve ser próprio.

⚠️ **Nenhuma cena deve aparecer duas vezes no mesmo vídeo.** Confira antes de
renderizar:

```bash
cd projeto && python3 -c "
import json
from collections import Counter
for n in ['abertura','assinaturas','economia','padroes','cartoes','relatorios']:
    cs=[c['conteudo']['cena'] for c in json.load(open(f'roteiros/{n}.json'))['cenas']]
    rep={k:v for k,v in Counter(cs).items() if v>1}
    print(n, 'repetidas:', rep or 'nenhuma')
"
```

### Variantes para os papéis recorrentes

Alguns papéis narrativos aparecem em quase todo roteiro (a pergunta de abertura,
o número de destaque). Para não repetir a mesma forma cinco vezes:

| papel | variantes |
|---|---|
| pergunta de abertura | `pergunta` (balão) · `busca` (campo digitando) |
| número de impacto | `contaQuebrada` (contador) · `numeroQuebra` (estilhaça) · `contadorSobe` (anel fechando) |
| separar o que conta | `peneira` (queda) · `filtroRuido` (colunas) |

### O catálogo completo

`cenas.jsx` — abertura e compartilhadas: `planilha` `planilhaColapso` `caos`
`contaQuebrada` `virada` `conecta` `bancosChegam` `bancosConectam` `fluxo`
`categoriza` `whatsappDigita` `whatsappModos` `importacao` `pergunta` `resposta`
`bancoCentral` `somenteLeitura` `selo` `cta`

`cenas-extras.jsx` — variantes: `busca` `numeroQuebra` `contadorSobe`

`cenas-assinaturas.jsx`: `calendarioSangra` `radar` `peneira` `anoEmpilha`
`cenas-economia.jsx`: `ralo` `balanca` `cofre` `filtroRuido` `historicoSobra`
`cenas-padroes.jsx`: `heatmapSemana` `relogio24h` `podio` `comparaMeses`
`cenas-cartoes.jsx`: `lequeCartoes` `cicloFatura` `comprasNoCartao` `abasFatura`
`cenas-relatorios.jsx`: `appParado` `notificacaoChega` `relatorioMontando` `canais`

Todas aceitam `dados` do `conteudo` do roteiro (título, destaque, e os dados
próprios de cada uma — veja a assinatura de cada componente).

⚠️ **O cadeado (`bancoCentral`) só em segurança.** Ele carrega significado forte
demais para virar pontuação genérica. Para encerrar um bloco, use `selo` com o
ícone do tema (sino, cofrinho, gráfico, fatura).

## Trilha e efeitos

Biblioteca própria em `projeto/public/audio-lib/`, gerada pela **Sound
Generation da ElevenLabs** (`POST /v1/sound-generation` — funciona nesta conta) e
já normalizada. **Reutilizável em qualquer vídeo, sem gastar crédito de novo.**

| Efeito | Uso |
|---|---|
| `pop` | ícone/logo entrando |
| `whoosh` | varredura, transição |
| `trava` | cadeado fechando, confirmação mecânica |
| `chime` | conexão feita, sucesso |
| `impacto` | virada de marca, revelação |
| `digita` | teclado, mensagem sendo escrita |
| `erro` | algo dando errado |
| `papel` | documentos, importação |

Trilha: `trilha/a.mp3` e `trilha/b.mp3` — 22s cada, em loop.

### Níveis — o que separa profissional de amador

```
narração   −14 LUFS   a voz manda, sempre
efeitos    −20 LUFS   6 dB abaixo: percebe-se, não disputa
trilha     −26 LUFS   12 dB abaixo: sente-se, não se escuta
```

Já normalizados nesses valores na biblioteca; no `audio.jsx` o volume é só
ajuste fino. A trilha tem fade de 1,2s na entrada e 2s na saída.

### O som acompanha o EVENTO, não o corte

O mapa `SONS` no `Video.jsx` ancora cada efeito num frame relativo ao início da
cena, batendo com a coreografia de `cenas.jsx`. **Um whoosh em cada uma das 18
transições vira tique nervoso** — o efeito entra onde há algo que o justifique:
o logo que chega, o cadeado que trava, o card que nasce da mensagem.

⚠️ As posições vêm de `posicoesReais()`, não de `cena.inicio` cru: a
`TransitionSeries` sobrepõe as cenas, então cada uma começa antes do que o corte
da narração diz. Usar o valor cru atrasaria os sons progressivamente. **Esse
cálculo é o espelho do que o `Filme` monta — mudou um, muda o outro.**

### Gerar ou regerar a biblioteca

```bash
~/.claude/skills/dominnus-video/gerar-sfx.sh            # só o que falta
~/.claude/skills/dominnus-video/gerar-sfx.sh --forcar   # regera tudo (gasta crédito)
~/.claude/skills/dominnus-video/gerar-sfx.sh pop trava  # só esses
```

O script é idempotente: se o arquivo existe, ele pula. Para um efeito novo,
acrescente uma linha ao array `EFEITOS` (`nome|duração|influência|prompt`).

**Os prompts são em inglês de propósito** — o modelo de som foi treinado nesse
idioma e responde melhor. `premium fintech` e `no reverb tail` são o que mantém
os efeitos discretos: som com cauda longa suja a narração.

⚠️ **A Sound Generation devolve silêncio no início do arquivo.** Sem
`silenceremove` o efeito dispara atrasado e parece dessincronizado — o script já
trata. A trilha NÃO leva `silenceremove`: cortar o início quebraria o loop.

### Como o som entra no vídeo

1. `src/audio.jsx` — componentes `Trilha` (loop com fade) e `FaixaDeEfeitos`
2. `src/Video.jsx` → mapa `SONS`: cada cena lista `{ som, frame, volume }`, com
   o frame relativo ao início da cena
3. `posicoesReais()` converte para a posição absoluta dentro da `TransitionSeries`

Para um vídeo novo, o que se escreve é só o mapa `SONS` — a mecânica já está
pronta.

## Marcas, bancos e ícones

| arquivo | conteúdo |
|---|---|
| `src/bancos.json` | 16 bancos com as cores oficiais (lib `@edusites/bancos-brasil`, 41 disponíveis) |
| `src/marcas.json` | 17 marcas — iFood, Netflix, Uber, Spotify, Mercado Pago, PIX, WhatsApp… |
| `src/icones.json` | 69 ícones de interface (lib `@edusites/icons`, 1088 disponíveis) |

**Marca real vale muito mais que ícone genérico.** "Ícone de carrinho" é
abstrato; o logo do iFood é a conta que chegou. Os ícones de marca da lib vêm
**sem cor** (herdam `currentColor`) — o `marcas.json` os embrulha num quadro com
a cor oficial de cada empresa.

⚠️ Ao gerar banco/marca, use `width="100%" height="100%"`: com dimensão fixa o
SVG estoura a caixa e o logo sai **cortado**. E não envolva em `overflow: hidden`
+ `borderRadius` — o SVG já traz o próprio `rx` e o clip come as bordas.

## Identidade — não inventar

Tudo vem do produto real:

- **Cores**: fundo `#080A10` (o preto oficial — **nunca** `#000000`), superfície `#0E1426`, acento **azul `#0D2EFC`**. De `nuxt-web/css/variaveis.sass` + `identidade/README.md`.
- **Fontes**: Söhne (títulos, a dos criativos do Figma) e Figtree (corpo, a do produto)
- **Logo**: `identidade/svg`, vetorial. `principal` = símbolo azul + wordmark branco (padrão sobre escuro)
- **Ícones**: 67 da lib `@edusites/icons` — os mesmos do produto

⚠️ **O acento é SEMPRE o azul `#0D2EFC`.** O verde `#22C55E` existe na paleta mas
serve **só para receita/entrada de dinheiro** — usá-lo como acento faz o vídeo
parecer do Gestão Dev, não da Dominnus. A diferenciação entre vídeos vem da
**forma** (raio, densidade, superfície) definida em `temas` no `marca.js`.

## Adicionar um ícone

Os 67 embutidos cobrem o roteiro atual. A lib tem **1088**. Para outro:

```bash
P="/Users/eduardolecdt/Empresas/Dominnus/Repositórios/frontend/nuxt-web/node_modules/@edusites/icons/src/icones"
ls "$P" | sed 's/\.js$//' | grep banco     # procurar

cd ~/.claude/skills/dominnus-video/projeto
node -e '
const fs=require("fs"), P=process.argv[1], nome=process.argv[2]
const d=JSON.parse(fs.readFileSync("src/icones.json","utf8"))
const m=fs.readFileSync(`${P}/${nome}.js`,"utf8").match(/`([\s\S]*)`/)
d[nome]=m[1].trim().replace("<svg ",`<svg fill="currentColor" width="100%" height="100%" `)
fs.writeFileSync("src/icones.json", JSON.stringify(d,null,2))
console.log("ok:", nome)
' "$P" NOME
```

⚠️ O `fill="currentColor"` vai **no próprio `<svg>`**, não no `<div>` pai: CSS
`fill` não atravessa a fronteira do SVG e o ícone sai preto.

Há também `@edusites/bancos-brasil` (41 instituições) para logos de banco reais.

## O que o produto REALMENTE faz

Verificado no código — não prometa fora disto:

- **Open Finance** via Pluggy: puxa transações, saldos e faturas, sincroniza todo dia. **Somente leitura.**
- **WhatsApp** (Cloud API oficial): lança por texto, áudio e foto de comprovante; consulta e relatórios
- **Chat IA** (Anthropic/OpenAI): 12 ferramentas de consulta + 13 de ação — cria/edita/apaga **sempre com confirmação**
- **Importação**: PDF, CSV, Excel e print, sem duplicar
- **Gestão**: despesas, receitas, contas, cartões, faturas, metas, assinaturas, transferências, patrimônio
- **PF e PJ** na mesma conta + colaboradores (plano Max)
- **Push** (9 gatilhos), app iOS/Android via Capacitor

❌ **Não** movimenta dinheiro, não faz PIX/pagamento, não emite nota fiscal, não faz corretagem.

### Três armadilhas de copy

1. **Preço divergente**: a landing mostra R$ 59,90/R$ 99,90, mas o checkout cobra R$ 57/R$ 87. **Não cite valor de plano** — use "7 dias grátis" ou "cerca de R$ 1 por dia".
2. **"Só consulta, nunca altera pelo chat"** é FALSO no código — o chat cria, edita e apaga (com confirmação). Diga "nada é gravado sem você confirmar".
3. **Nada é ilimitado no Pro**: 200 mensagens de IA e 20 importações por mês, 3 conexões bancárias.

## Armadilhas que já custaram retrabalho

1. **`remotion.config.js`, nunca `.mjs`.** Com `.mjs` o CLI ignora **em silêncio** e volta ao default — mantém a concorrência em 4x e o render lento.
2. **A fala vai num request só.** Frase gerada isolada é entoada como sentença fechada; oito seguidas soam como leitor de tela.
3. **`silencedetect` do ffmpeg é cego aqui.** O ElevenLabs deixa ruído nas pausas; o corte usa `auto-editor`, que mede envoltória.
4. **Ícone inexistente = quadrado preto.** `icones['inexistente']` é `undefined` e não gera erro.
5. **Zona segura obrigatória**: 260px no topo, 430px na base — a UI do Instagram cobre isso.
6. **Sem overshoot.** Bounce é registro *playful*; premium chega e para.
7. **Reencode no ffmpeg é obrigatório.** O Remotion sai em `yuvj420p` (full range) e alguns players lavam o preto.
8. **A proporção do logo vem do viewBox** (1079.68 × 299.09). Com a razão herdada do Gestão Dev (1007/128) o SVG encolhe dentro da caixa e a marca flutua num bloco vazio.

## Transições — element-driven

`src/transicoes.jsx`. As cenas rodam dentro de um `<TransitionSeries>`, que faz
**as duas coexistirem** durante o corte — é isso que permite uma revelar a
outra. Com `<Sequence>` normal só dá para fade/scale.

A transição é declarada **no próprio roteiro**, por cena:

```json
{ "id": "03", "narracao": "...", "conteudo": {...},
  "transicao": { "tipo": "whip", "frames": 10, "sentido": 1 } }
```

Sem `transicao`, cai num padrão que alterna por posição — um roteiro novo já sai
com corte variado sem configurar nada.

| Transição | Frames @30 | Curva | Onde usar |
|---|---|---|---|
| `iris` | 15 | `0.77, 0, 0.175, 1` | círculo abrindo a partir de um elemento |
| `matchCut` | 16 | `0.65, 0, 0.35, 1` | a câmera entra dentro da marca |
| `whipPan` | 10 | `0.9, 0, 0.1, 1` | troca de assunto, com rastro |
| `sobe` | 12 | M3 `0.05, 0.7, 0.1, 1` | a cena nova empurra a anterior |
| `flash` | 8 | `0.16, 1, 0.3, 1` | virada de tom |
| continuidade (`fade`) | 6 | — | onde o elemento atravessa o corte |

**Quatro detalhes que separam isto de um zoom comum:**

1. **O raio do `iris` começa no tamanho do elemento, nunca em zero.** Em zero, o
   círculo "nasce do nada" num ponto; no raio do ícone, o círculo *é* o ícone se
   expandindo. É o que torna a transição element-driven.
2. **A escala do `matchCut` é exponencial.** Interpolar 1→14 linearmente faz o
   crescimento parecer travar no fim — o olho lê escala em log. E as duas cenas
   escalam juntas em fatores diferentes: esse paralaxe é o que lê como câmera
   avançando, não como "elemento crescendo sobre fundo parado".
3. **`filter: blur()` é isotrópico** e não serve para rastro. O whip pan usa
   `feGaussianBlur` com `stdDeviation="40 0"` (só em X). O blur acompanha a
   **velocidade** (distância percorrida no frame × 0.5, saturando em 55), não o
   progresso — é o que causa rastro fisicamente.
4. **A transição consome frames das duas cenas vizinhas.** Sem ampliar cada cena
   pelo consumo, o vídeo se encurta a cada corte e a fala dessincroniza da
   imagem — erro que acumula ao longo de 18 cortes.

⚠️ Onde há continuidade (bancos permanecendo na órbita, transações ganhando
categoria), a transição é curta e discreta **de propósito**: o elemento atravessa
o corte, e uma transição vistosa ali destruiria a ilusão.

⚠️ **Sem overshoot em marca.** Para fintech, bounce lê como brinquedo; movimento
contido lê como controle, e controle lê como confiança. Onde um settle é
aceitável, o limite é 3% além do alvo resolvendo em ≤120ms — nunca 15%.

## Ajustar o ritmo

`src/motion.js` centraliza os números — mexer ali afeta todos os vídeos.

**Durações base** (`dur`): entrada 260ms, saída 180ms, stagger com orçamento de
260ms. Os 400ms da 1ª versão são referência de *UI web*, onde o usuário controla
o ritmo — num Reels quem controla é o vídeo, e a fala corre a ~200 ppm.

**Regra de saída ≈ 75% da entrada.**

## Vídeos existentes

| id | tema | duração |
|---|---|---|
| `abertura` | guarda-chuva da marca — Open Finance + IA + WhatsApp | 63s |
| `assinaturas` | detecção automática de assinaturas por IA | 35s |
| `economia` | quanto sobrou / taxa de poupança | 35s |
| `padroes` | quando e onde você gasta mais | 32s |
| `cartoes` | cartões e faturas sem surpresa | 38s |
| `relatorios` | os resumos que te procuram | 33s |

Os ângulos dos carrosséis do Figma (`2ruYzQhCyNdMfW0o3kpduR`, `node-id=73-2`)
que ainda faltam: patrimônio, metas, importação de histórico, PF e PJ, acessos
e permissões, casal no mesmo WhatsApp.

## Fazer um vídeo novo — o caminho curto

1. **Roteiro** em `roteiros/<id>.json`: quebre cada frase em beats de ~2,5-3s,
   marque a direção com audio tags, escreva tudo por extenso.
2. **Tema**: acrescente a chave `<id>` em `temas` no `marca.js` (senão cai no
   padrão em silêncio).
3. **Cenas**: escreva as coreografias novas em `cenas.jsx` e registre em `CENAS`.
   Não parametrize uma cena existente — é como se volta ao card estático.
4. **Som**: adicione o mapa em `SONS` no `Video.jsx`, ancorando no evento visual.
5. **Transições**: adicione as entradas em `TRANSICOES`, também no `Video.jsx`.
6. `criar.sh <id>` — e ouça a narração antes de renderizar; trocar de take
   (`SEED=n`) custa 1 minuto, refazer o render custa 10.

## Histórico — como esta skill chegou aqui

Quatro rodadas de correção, cada uma com uma lição que a documentação acima
guarda:

1. **Cards com stagger** (herdados do Gestão Dev) → rejeitado: template de
   slide, o quadro fica parado.
2. **Cenas coreografadas, 8 planos de 8s** → ainda lento: o problema era
   estrutural, não de easing. Acelerar as animações internas não resolve um
   plano longo demais.
3. **18 beats + continuidade + transições element-driven** → visual aprovado.
4. **Narração**: corte de silêncio conservador, sem `speed`/`style`, redação
   encadeada e — o que destravou — **migrar para o `eleven_v3` com direção por
   audio tags**.

Relacionado: [[postiz-dominnus]] para publicação nas redes.
