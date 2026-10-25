# Coisas tops que dá pra fazer no OBS (pesquisado 2026)

## Já dá pra fazer com o que está montado

**1. Stinger — transição em vídeo entre cenas**
Um `.webm` com transparência passa por cima na troca de cena. O corte acontece
quando a animação cobre a tela toda (ponto entre 500–1000ms). Se o vídeo tiver som,
pôr Monitoramento em "Monitorar e Emitir" + Crossfade.
→ Dá pra gerar com Remotion/After Effects na identidade roxa.

**2. Transition Override por cena**
Botão direito na cena → "Substituir transição". Cada cena entra com uma transição
diferente: Abertura com stinger, Pausa com fade lento.

**3. Modo Estúdio**
Preview à esquerda, no ar à direita. Monta a próxima cena sem ela ir ao ar.
Essencial pra live: posiciona tudo antes de mostrar.

**4. Filtro "Fonte de gravação" (source-record — já instalado)**
Grava uma fonte isolada em arquivo separado, ao mesmo tempo que a live.
Ex: gravar só a webcam em 1080p limpo enquanto transmite a cena montada.
→ Sai material pronto pra cortar Reels depois, sem overlay.

**5. Canvas vertical (vertical-canvas — já instalado)**
Transmite 16:9 e 9:16 AO MESMO TEMPO, do mesmo OBS. Live no YouTube e
no TikTok/Reels simultaneamente, cada um no seu formato.

**6. Multi-RTMP (obs-multi-rtmp — já instalado)**
Transmite pra YouTube + Instagram + Twitch de uma vez.

## Precisa instalar

**7. Move Transition** — o plugin mais transformador
Anima QUALQUER coisa entre cenas: a webcam desliza do canto pro centro, a tela
cresce, um overlay entra voando. Faz o template parecer motion graphics de TV.

**8. Advanced Scene Switcher**
Troca de cena por regra: janela ativa (VS Code aberto → cena de código), horário,
nível de áudio (falou → cena de câmera), ociosidade.

**9. Freeze Filter / Scroll / Shake**
Congela uma fonte, faz texto rolar, treme a imagem no impacto.

**10. Dynamic Delay / Instant Replay**
Replay do que acabou de acontecer — bom pra demo que deu certo.

## Com código (o que mais rende)

**11. obs-websocket — controlar o OBS por script**
Já vem no OBS 28+. Ativar em Ferramentas → Configurações do WebSocket.
Com ele dá pra: trocar cena por atalho/Stream Deck, atualizar texto de overlay
ao vivo, ligar/desligar fontes, iniciar gravação — tudo de fora, por Node/Python.
→ Ex.: um script que muda o "Capítulo" da aula conforme você avança no roteiro.

**12. Overlay lendo dados ao vivo**
Fonte de navegador tem acesso total às APIs web: `fetch`, WebSocket, canvas.
Ideias na sua régua:
  - contador de inscritos/vendas puxando da sua própria API
  - "últimos alunos que entraram" rolando na tela durante a live
  - meta de vendas com barra enchendo em tempo real
  - chat REAL do YouTube no seu HTML (hoje é demo) via YouTube Data API

**13. Alertas próprios**
Nada de StreamElements: um HTML seu que anima quando alguém compra/comenta,
na identidade roxa, disparado por webhook.

**14. Cena que reage à voz**
Filtro de nível de áudio + websocket: quando você fala, a webcam cresce;
quando para, volta. Dá vida sem tocar em nada.

## Ideias mais fora da caixa

**15. Teleprompter na cena** — overlay com o roteiro rolando, visível só pra você
(numa janela de projeção separada, não no que vai ao ar).

**16. Cronômetro de bloco** — na aula, quanto tempo falta pro intervalo.

**17. Lower third automático por seção** — o nome muda sozinho conforme a cena.

**18. Marca d'água com hora** — pra provar que a gravação é ao vivo.

**19. Zoom animado no código** — Move Transition + duas cenas da mesma tela em
escalas diferentes = zoom suave em vez de corte seco.

**20. Contagem regressiva que dispara a live** — websocket inicia a transmissão
quando o cronômetro zera.
