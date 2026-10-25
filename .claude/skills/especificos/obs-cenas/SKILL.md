---
name: obs-cenas
description: Cria e edita cenas do OBS Studio escrevendo direto os JSON de coleção (~/Library/Application Support/obs-studio/basic/scenes), mais overlays HTML/CSS animados como fonte de navegador, máscaras PNG, trilhas e SFX gerados por ffmpeg. Domina as pegadinhas do formato (ordem das camadas invertida, scene_order obrigatório, filtros que exigem opacity, bounds_type, cenas-fonte para reusar a mesma câmera) e do macOS Tahoe (plugin de câmera legacy). Use SEMPRE que o Eduardo pedir "cria uma cena no OBS", "monta um template de live/reels/aula", "arruma minha cena", "faz um overlay pro OBS", "webcam não aparece no OBS", "muda a resolução do OBS", ou qualquer coisa de OBS Studio.
---

# OBS — criar e editar cenas por arquivo

Não há acesso à interface do OBS. Tudo se faz escrevendo os arquivos que ele lê.

## ⛔ Regra zero: FECHAR O OBS ANTES DE ESCREVER

O OBS mantém tudo em RAM e **despeja por cima do disco ao sair**. Escrever com ele
aberto perde o trabalho. Fechar sozinho, sem pedir ao usuário:

```bash
osascript -e 'tell application "OBS" to quit'   # salva e sai limpo, igual Cmd+Q
# esperar o pgrep sumir + ~1s antes de escrever. NUNCA pkill -9.
```

Reabrir com `open -a OBS` no fim.

**Sintoma de ter escrito com ele aberto:** os nomes de cena/fonte no app não batem
com o disco, ou aparecem cenas que ninguém criou.

## Onde ficam as coisas

| O quê | Onde |
|---|---|
| Coleções de cena | `~/Library/Application Support/obs-studio/basic/scenes/*.json` |
| Perfis (RESOLUÇÃO) | `~/Library/Application Support/obs-studio/basic/profiles/<nome>/basic.ini` |
| Qual perfil/coleção está ativo | `~/Library/Application Support/obs-studio/global.ini` |
| Logs (a fonte da verdade) | `~/Library/Application Support/obs-studio/logs/` |
| Scripts (precisa Python 3.11) | `~/Library/Application Support/obs-studio/scripts/` |

## As 8 pegadinhas do formato JSON

### 1. O array `items` vai do FUNDO para o TOPO
O inverso da lista na UI. `items[0]` é a camada de BAIXO. Escrever na ordem visual
e inverter antes de gravar. **Sintoma de errar:** a fonte "não aparece" mas está lá
com tamanho e posição certos — tem um fundo opaco desenhado por cima.

### 2. Overlay de tela cheia com fundo OPACO tapa a webcam
Ordem certa (overlay acima) NÃO basta: se o overlay pinta um fundo em toda a tela,
ele cobre a câmera que está embaixo. **Sintoma idêntico ao da ordem errada** — a
webcam "some" mesmo aparecendo acima na lista de Fontes.

Duas saídas:
- **Recortar um buraco** no fundo do overlay, na área onde a webcam aparece, com
  `mask` de 4 retângulos (acima, abaixo, esquerda, direita da janela). Cada cena
  passa `camx/camy/camw/camh` por URL, então é UMA FONTE DE OVERLAY POR CENA
  (geometrias diferentes = buracos diferentes).
- **Não pintar fundo** no overlay: desenhar só a moldura/borda (foi o caso da
  `abertura.html`, que por isso nunca teve o problema).

Conferir a matemática somando a área das 4 faixas: tem que dar
`largura*altura - buraco`, sem valor negativo em nenhuma faixa.

### 3. `scene_order` é OBRIGATÓRIO
Sem ele o OBS reconstrói a lista do próprio jeito e **inventa cenas fantasma**.
```python
col["scene_order"] = [{"name": s["name"]} for s in col["sources"]
                      if s["id"]=="scene" and not s["settings"].get("custom_size")]
col["version"] = 1
```
Cenas-fonte (com `custom_size`) ficam de fora: são componentes, não cenas.

### 4. Todo `items[].name` precisa existir em `sources[]`
Senão a cena abre vazia. E cada item precisa de `id` único, com
`id_counter` > maior id — senão o OBS embaralha ao editar.

### 5. `color_filter` sem `opacity` = imagem INVISÍVEL
O OBS assume 0. Sempre `opacity: 1.0` e `color_multiply: 4294967295`.
**Sintoma:** retângulo de seleção no lugar certo, porém vazio, e o preview das
Propriedades da fonte mostra a imagem normal (prova de que o device está OK).

### 6. `mask_filter` (v1) NÃO faz forma geométrica
Exige PNG com alpha: `{"type":"mask_alpha_filter.effect","image_path":"...","stretch":true}`.
Gerar o PNG com zlib+struct puro (sem PIL) — ver `gerar_mascara.py` nesta skill.

### 7. Nunca duas fontes na MESMA câmera física
No macOS a primeira trava o device e a outra fica preta. Para variações (círculo,
recorte), criar uma **cena-fonte**: `custom_size:true` + `cx`/`cy`, contendo a webcam
com crop, e a máscara na cena. Cena aninhada REFERENCIA a fonte, não reabre o device.

### 8. `bounds_type: 2` encaixa numa caixa sem calcular escala
Melhor que escala na mão: o OBS reajusta se a fonte mudar de resolução.
```python
it["bounds_type"]=2; it["bounds"]={"x":w,"y":h}; it["bounds_align"]=0
```
0=nenhum, 1=esticar, 2=caber interno (mantém proporção).

## Fontes: escrever o MÍNIMO

Empilhar parâmetros quebra. Copiar o formato de uma coleção que já funciona
(`grep` nos `.json`/`.bak` da pasta scenes) em vez de deduzir campos.

| Fonte | id | settings mínimos |
|---|---|---|
| Câmera (macOS 26+) | `av_capture_input` | `{device, device_name}` — **plugin LEGACY** |
| Câmera (moderno) | `macos-avcapture` | ⚠️ bug no Tahoe: não inicializa até abrir Propriedades |
| Tela | `screen_capture` | `{display_uuid, type:0, window:0}` |
| Microfone | `coreaudio_input_capture` | `{device_id:"default"}` + `mixers:255` |
| Navegador | `browser_source` | `{url, width, height, css}` |
| Mídia | `ffmpeg_source` | `{local_file, looping, is_local_file:true}` + `mixers:255` |

**Como saber se a câmera abriu:** o log tem que dizer `Using preset 1280x720`.
Só "Selected device" sem linha de preset = negociação de formato falhou (cena preta).
`settings:{}` NÃO faz o OBS escolher sozinho — dá "No device selected".

## RESOLUÇÃO é do PERFIL, não da coleção

⚠️ **Trocar de perfil REAPLICA o layout da janela** — já fez os painéis
(Cenas/Fontes/Mixer) sumirem, deixando só o preview. Mitigações:
- No script, só trocar quando o perfil alvo for DIFERENTE do atual.
- Para restaurar: menu **Painéis › Redefinir painéis** (o AppleScript consegue
  clicar nisso: `click menu item "Redefinir painéis" of menu 1 of menu bar item
  "Painéis" of menu bar 1`). Apagar `geometry`/`DockState` do `[BasicWindow]`
  no `global.ini` NÃO basta sozinho.

⚠️ **O nome do perfil é o campo `Name=` do `basic.ini`**, não o nome da pasta
nem `ProfileName=`. Ao criar um perfil copiando outro, corrigir o `Name=` —
senão dois perfis ficam com o mesmo nome interno e a API não acha o alvo
("perfil X não existe").

## Scripting Python (macOS)

Trocar de coleção NÃO muda o canvas. Para 9:16, criar um perfil próprio:
```bash
cp -r ".../profiles/Sem nome" ".../profiles/Vertical 9x16"
# no basic.ini: BaseCX=1080 BaseCY=1920 OutputCX=1080 OutputCY=1920
```
Trocar perfil junto com a coleção (menu Perfil), ou automatizar com
`perfil-auto.py`. Exige **Python 3.11** (só essa versão).

O OBS monta o caminho como `<informado>/Python.framework/Versions/3.11/lib/
libpython3.11.dylib` — então informar **a pasta que CONTÉM** `Python.framework`,
não o caminho completo (erro fácil de cometer). O `/opt` do Homebrew é oculto
no seletor do Finder: copiar o framework pra `~/OBS-Python` resolve.
Depois: Ferramentas › Scripts › aba "Configurações do Python" › apontar a pasta.
O Python só inicializa quando essa janela é aberta — não dá pra forçar por
arquivo, e o AppleScript não enxerga os campos (são Qt).

## Overlays HTML como fonte de navegador

- Fundo **transparente** (`background:transparent`) — o OBS compõe por cima.
- **Texto editável dentro do OBS = campo "CSS personalizado"** das Propriedades.
  O OBS injeta esse CSS na página. Ler com `getComputedStyle` de `:root`:
  ```css
  :root { --titulo: "Bora codar"; --handle: "@edusites"; }
  ```
  ⚠️ NÃO existe `obsCustomText` nem API de texto — só o CSS.
- Reavaliar no `load` + `setTimeout(60)`: o CSS do OBS entra depois do script.
- Convenções: `*palavra*` = destaque em gradiente, `|` = quebra de linha.
- Animação CSS/canvas roda ao vivo. Para vídeo renderizado (vinheta, stinger),
  usar Remotion/After Effects e entrar como `ffmpeg_source`.

## Zonas seguras do vertical (Reels/TikTok/Shorts)

Topo ~230px (perfil, nome) e base ~430px (legenda, botões, áudio) ficam COBERTOS.
Todo texto entre essas faixas. Em 1080x1920 sobram **1260px** de área útil.

## Áudio gerado por ffmpeg (sem baixar nada)

**Loop perfeito:** gerar mais longo SEM fade e fazer `acrossfade` do miolo com a
cauda — fade nas pontas quebra o loop.
```bash
ffmpeg -i _raw.wav -filter_complex \
 "[0]atrim=2:34,asetpts=PTS-STARTPTS[a];[0]atrim=34:38,asetpts=PTS-STARTPTS[b];\
  [a][b]acrossfade=d=4:c1=tri:c2=tri,loudnorm=I=-28:TP=-3" saida.mp3
```
Validar a emenda comparando `volumedetect` dos primeiros vs últimos 0.6s
(diferença < 1 dB = imperceptível). Trilha de fundo a −28 dB deixa espaço pra voz.
`astats` não imprime sozinho — usar `volumedetect`.

Acordes: **Dm add9** = melancólico/épico (espera); **Fmaj9** = otimista discreto (live).

## Fluxo recomendado

1. Fechar o OBS (AppleScript).
2. Ler uma coleção que funciona pra copiar formatos de settings/filtros.
3. Gerar o JSON com script Python (ver `exemplo_gerador.py`).
4. **Validar antes de abrir**: refs existem, ids únicos, scene_order presente,
   arquivos de mídia/overlay/máscara existem no disco.
5. Reabrir o OBS e conferir o log: `Using preset`, `initialized`, sem `error`.

## Testar overlay sem abrir o OBS

```bash
"/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless \
  --disable-gpu --hide-scrollbars --virtual-time-budget=3000 \
  --window-size=1920,1080 --screenshot=out.png "file://$PWD/overlay.html"
```
O primeiro frame de animação pode sair cortado — aumentar o virtual-time-budget.
Se o Chrome travar, `pkill -f "Chrome.*headless"` e usar `--user-data-dir` novo.

## Referências
- Formato/API: https://docs.obsproject.com/reference-scenes
- Bug de câmera no Tahoe: obsproject/obs-studio issues #11487
