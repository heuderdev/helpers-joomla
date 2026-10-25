---
name: after-effects
description: Cria vídeos e animações no Adobe After Effects 100% por código, sem abrir a interface — monta o projeto via ExtendScript e renderiza pelo aerender. Um vídeo é um roteiro JSON de cenas (título, frase, lista, mídia, preset animado); o sistema gera o .aep e o .mp4. Usa os 621 presets nativos da Adobe (300 só de texto), efeitos por matchName, câmera 3D, shape layers vetoriais e expressions. Use SEMPRE que o Eduardo pedir "faz um vídeo no after", "cria uma animação no AE", "monta um motion no after effects", "abre o after e faz X", "renderiza essa comp", "gera variações desse vídeo", ou quiser motion mais pesado do que o Remotion entrega (blur real, 3D, partículas, presets da Adobe).
---

# after-effects

Vídeos no After Effects sem tocar na interface. Um roteiro JSON vira `.aep` + `.mp4`.

```bash
~/.claude/skills/after-effects/criar.sh exemplo     # um vídeo
~/.claude/skills/after-effects/criar.sh --todos     # todos
~/.claude/skills/after-effects/criar.sh exemplo --so-aep   # só o projeto, sem render
```

Saída em `~/Downloads/ae-videos/`. Um Reels de 14s renderiza em ~7s.

## Como funciona

```
roteiro.json → gerar.mjs (Node monta o ExtendScript)
             → rodar.sh → AppleScript → After Effects → .aep
             → render.sh → aerender (headless) → .mp4
```

Node monta o `.jsx` porque lá dá pra usar JS moderno; o AE só executa. A lógica fica testável e o ExtendScript ES3 vira detalhe de saída.

## Escrever um roteiro

`roteiros/<id>.json`:

```json
{
  "id": "meu-video",
  "titulo": "Meu Vídeo",
  "formato": "reels",
  "tema": "pagzero",
  "cenas": [
    { "tipo": "titulo", "titulo": "PagZero", "subtitulo": "checkout que converte", "duracao": 3 },
    { "tipo": "frase", "texto": "Vender online\nnão precisa ser difícil.", "duracao": 3 },
    { "tipo": "lista", "titulo": "O que você ganha",
      "itens": [
        { "titulo": "PIX na hora", "detalhe": "sem espera" },
        { "titulo": "Cartão em 12x", "detalhe": "com ou sem juros" }
      ], "duracao": 4.5 },
    { "tipo": "preset", "texto": "Comece hoje", "preset": "Deslizar", "duracao": 3 }
  ]
}
```

### Tipos de cena

| `tipo` | mostra | campos |
|---|---|---|
| `titulo` | título grande + linha + subtítulo | `titulo`, `subtitulo`, `cor`, `brilho`, `linha` |
| `frase` | frase forte centralizada (aceita `\n`) | `texto`, `tamanho`, `cor` |
| `lista` | itens entrando em cascata | `titulo`, `itens[]` (`titulo`+`detalhe`), `espacamento` |
| `midia` | imagem/vídeo cheio com Ken Burns | `arquivo`, `legenda`, `zoom` |
| `preset` | texto animado por preset da Adobe | `texto`, `preset`, `categoria` |

Comum a todas: `duracao` (segundos).

### Formatos e temas

`formato`: `reels`/`vertical` (1080×1920), `quadrado` (1080×1080), `wide`/`horizontal` (1920×1080).

`tema`: `pagzero` (escuro + verde #22e59a), `escuro`, `claro`. Sobrescreva pontualmente com `cores`:

```json
"cores": { "primaria": "#ff6b35", "fundo": "#111111" }
```

### Narração e trilha

```json
"narracao": "/caminho/narracao.mp3",
"trilha": "/caminho/trilha.mp3",
"volumeTrilha": -18
```

Para sincronizar com a fala, use os timestamps do ElevenLabs (como em `gestaodev-video`) e escreva o resultado em `duracaoNarracao` de cada cena — a duração passa a vir da fala real, não de número escolhido à mão.

## Os 621 presets da Adobe

O maior salto de qualidade disponível: animações feitas por motion designers da Adobe, não geradas por script.

```
Text: 300     Legacy: 65    Shapes: 58    Image-Creative: 30
Behaviors: 27 Adobe Express: 27  Backgrounds: 24
Transitions Movement: 18  Wipes: 17  Dissolves: 16
```

Índice completo em `lib/presets.txt`. Na cena `preset`, o campo busca por **termo contido no nome** (não caminho exato), porque o nome do arquivo muda com o idioma do AE:

```json
{ "tipo": "preset", "texto": "Comece hoje",
  "categoria": "Text/Animate In", "preset": "máquina de escrever" }
```

Bons termos em `Text/Animate In`: `Deslizar`, `máquina de escrever`, `Decodificar`, `Embaralhamento`, `Atenuar`.

## Escrever cenas novas

Cada tipo é uma função em `lib/gerar.mjs` que devolve linhas de ExtendScript, usando os helpers de `lib/ae.jsx`:

| helper | faz |
|---|---|
| `texto(comp, txt, {tamanho,cor,fonte,pos})` | camada de texto estilizada |
| `retangulo(comp, {largura,altura,cor,raio,pos})` | shape vetorial (escala sem perder nitidez) |
| `midia(comp, caminho, {preencherLargura})` | importa imagem/vídeo |
| `anim(prop, [[t,valor],...])` | keyframes **já com easing** |
| `entrar/sair/pop(camada, t)` | entradas prontas |
| `janela(camada, ini, fim)` | recorta no tempo (define a cena) |
| `preset(camada, caminho)` / `acharPreset(cat, termo)` | presets `.ffx` |
| `brilho(camada)` / `efeito(camada, chave, valores)` | efeitos |
| `camera(comp)` / `pushIn(cam, de, ate, dur)` | 3D |

## Armadilhas (todas já custaram caro aqui)

**1. Diálogo modal trava o AE por completo.** Qualquer alerta na tela faz o AE ignorar todo script — a automação pendura até dar timeout. Por isso `novoProjeto()` chama `app.beginSuppressDialogs()`. Se `rodar.sh` acusar timeout, olhe a tela do AE.

**2. Nome de efeito é traduzido; matchName não.** O AE aqui está em português: `"Glow"` não existe, `"ADBE Glo2"` sim. Use sempre matchName — vale para efeitos e propriedades.

**3. Templates de render localizados.** `-RStemplate "Best Settings"` e `-OMtemplate "Lossless"` falham por isso. `render.sh` omite os dois de propósito e usa o padrão.

**4. `aerender` e caminho relativo.** Ele resolve a partir da pasta do binário, não do seu `cwd`. `render.sh` absolutiza tudo.

**5. AppleEvent expira em 60s.** Script pesado precisa de `with timeout` — `rodar.sh` usa 900s.

**6. Preferência obrigatória.** *Preferências → Script e expressões → "Permitir que scripts gravem arquivos e acessem a rede"* precisa estar marcada, senão o script não grava o relatório e o erro morre calado. É por versão do AE.

**7. ExtendScript é ES3.** Sem arrow function, `let`/`const`, template literal ou `JSON` moderno. Escreva JS moderno em `gerar.mjs` e emita ES3.

## Quando usar AE e quando usar Remotion

O Eduardo já tem pipeline em Remotion (`gestaodev-video`, `dominnus-video`, `pagzero-video`). **Não substitua.**

- **Remotion**: iteração rápida, versionamento em git, texto/layout, lote grande. É o padrão.
- **After Effects**: quando o motion precisa de blur real, 3D, partículas, tracking, ou quando existe um `.aep` pronto (comprado/feito por designer) e a tarefa é trocar conteúdo em escala.

## Requisitos

- After Effects 2026 em `/Applications` (mude com `AE_APP`/`AERENDER`)
- Node 18+
- AE **aberto** antes de rodar (`rodar.sh` abre se preciso, mas leva ~1min)
