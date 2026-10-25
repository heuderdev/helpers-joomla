---
name: revisor-saas
description: Audita landing pages e produtos SaaS contra 32 princípios de "viral product" (hero, pricing, OG image, copy, CTA, prova social, posicionamento). Abre a URL no Chrome, captura o PRINT REAL (desktop + mobile emulado), inspeciona meta tags/OG, e percorre os 32 princípios um a um produzindo um relatório longo e acionável por princípio. Use SEMPRE que o usuário pedir "analisa essa LP", "revisa essa landing page", "audita o site/produto SaaS", "review da página de vendas", "essa LP converte?", "o que tá errado nessa landing", ou enviar uma URL de página de produto/vendas pedindo crítica.
---

# Revisor SaaS — auditoria de landing page contra 32 princípios de produto viral

Audita uma landing page / página de vendas contra um framework fixo de **32 princípios
de produto viral**. A fonte de verdade é o **print real do site no browser** — o que o
visitante de fato vê — não o que o código diz que deveria aparecer.

A saída é um **relatório longo, percorrendo os 32 princípios um a um**, cada um com:
veredito (✅ atende / ⚠️ parcial / ❌ falha / ➖ não aplicável), o que foi observado na
página, e a correção concreta quando falha.

## Princípios de execução (não regridir)

1. **Print real, não suposição.** Sempre capture screenshots de verdade (desktop +
   mobile emulado) antes de opinar. Não avalie por imaginação do que a página "deve ter".
2. **Hero primeiro.** 80% não rola além do hero (princípio 20). Avalie o hero com o
   screenshot acima da dobra isolado, antes de tudo.
3. **OG image conta.** Inspecione `og:image`, `og:title`, `twitter:card` de verdade
   (princípio 5). A OG é vista mais que o site.
4. **Mobile de verdade.** Emule iPhone real (não só `--window-size`) para pegar media
   queries, hero cortado, pricing escondido.
5. **Seja específico e brutal.** O usuário quer crítica acionável, não elogio. Aponte o
   ofensor exato e o fix. Numere claims (princípio 3, 26): troque "melhore o hero" por
   "o hero tem 4 CTAs concorrentes; deixe 1".

## Passo a passo

### 1. Capturar a página (print real)

Use o Chrome via MCP `claude-in-chrome` (carregue os tools com ToolSearch primeiro) OU
o puppeteer da skill `site-mockup` (`shot.mjs`) se preferir headless. Capture:

- **Desktop** (1440px): página inteira (full-page scroll) + recorte do hero acima da dobra.
- **Mobile** (iPhone emulado, DPR 3): página inteira + hero.
- **Meta/OG**: leia o `<head>` — `og:image`, `og:title`, `og:description`, `twitter:card`,
  `<title>`, meta description. (Via `get_page_text`/`javascript_tool` no Chrome, ou
  `curl -s URL | grep -i 'og:\|twitter:\|<title'`.)

Se a página tiver paywall/login, capture até onde der e anote o que ficou inacessível.

### 2. Coletar os sinais por princípio

Antes de escrever, extraia os fatos que cada princípio precisa:

- **Cores**: quantas cores de destaque competem? (princípio 2)
- **CTAs**: quantos botões de ação distintos? texto de cada um? (princípios 22, 28)
- **Headline**: qual é? quantas palavras? tem número? é emocional? (3, 7, 17, 18)
- **Pricing**: existe no header? quantos tiers? assinatura ou one-time? tem free plan?
  é mais caro ou mais barato que concorrentes? (1, 8, 12, 16, 27, 32)
- **Prova**: tem testimonials? tabela comparativa? rosto/vídeo do founder? (15, 29, 31)
- **Demo**: mostra o produto antes de explicar? deixa testar de graça? (10, 25)
- **Foco**: o produto faz uma coisa só? dá pra descrever em <10 palavras? (11, 30)
- **OG image**: existe? parece thumbnail de YouTube ou genérica? (5)
- **Footer**: termina forte / compartilhável? (4)

### 3. Escrever o relatório (formato fixo)

Estrutura obrigatória:

```
# Auditoria SaaS — <URL>
Capturado em <data> · desktop + mobile

## Resumo executivo
2-4 frases: o maior acerto e os 3 maiores ofensores que mais custam conversão.

## Hero (a coisa mais importante)
Análise do que aparece acima da dobra, com base no print. Vende sozinho? (princípio 20)

## Os 32 princípios
### 1. Sem free plan — <✅/⚠️/❌/➖>
Observado: ...
Fix: ...
### 2. Três cores — ...
... (todos os 32, em ordem)

## Plano de ação priorizado
Ordem por impacto: hero e pricing primeiro, depois copy, depois prova, depois o resto.
```

Sempre cite o que foi VISTO no print ("o hero atual diz X"), nunca genérico.

### 4. Enviar o relatório para o Discord

O usuário recebe as análises num webhook do Discord, lido da env `REVISOR_SAAS_DISCORD_WEBHOOK`
(ou de um arquivo `.webhook` ao lado desta skill, **ignorado no git e nunca copiado junto** — a URL
do webhook é segredo). Se nenhum dos dois existir, peça ao usuário para configurar. Ao terminar QUALQUER análise:

1. Salve o relatório completo num `.md` no scratchpad.
2. **Pergunte ao usuário se ele quer enviar pro Discord** (postar num webhook é publicar
   conteúdo num serviço externo — confirme por envio; não poste rascunhos/placeholders).
3. Após o "sim", envie:
   ```bash
   node "$SKILL_DIR/send-discord.mjs" /caminho/relatorio.md "🔍 Auditoria SaaS — <URL>"
   ```
   O script lê o webhook de `.webhook` (ou da env `REVISOR_SAAS_DISCORD_WEBHOOK`), quebra
   o relatório em mensagens de ≤1900 chars e posta em ordem, respeitando o rate limit.

Se o usuário pedir explicitamente "manda direto pro Discord / sempre envia", pode pular a
pergunta naquela análise — mas o default é confirmar antes de publicar.

## Os 32 princípios (framework de referência)

1. **Sem free plan.** Free users <3% convertem; aumentam custo/suporte. Remova o free.
2. **Três cores.** Texto preto, fundo branco, uma cor só pro botão de compra.
3. **Números, não adjetivos.** "Rápido" é esquecível. "Economize 4h/semana" não.
4. **Footer compartilhável.** 97% não compram, mas compartilham. Termine forte.
5. **OG image = thumbnail de YouTube.** É mais vista que o site. Se não clicam, não veem.
6. **Uma ideia por tela.** Cada tela comunica uma coisa só. Como o feed do Instagram.
7. **Headline que uma criança de 10 anos entende.** Palavras simples. Sua mãe entende.
8. **Hard paywall.** Cobre antes de pedir dados. Cadastro não paga conta.
9. **Copy que só você poderia escrever.** Se o concorrente pode copiar-colar, é genérica.
10. **Mostre o produto antes de explicar.** Demo > parágrafos. Show, don't tell.
11. **Faça uma coisa só.** Ninguém lembra do canivete suíço. Seja conhecido por uma coisa.
12. **Popcorn pricing.** Três opções: Good / Better / Best. Cada tier extra = decisão a mais.
13. **Pegue uma onda.** Construa em cima de tendências/tech que já estão sendo discutidas.
14. **Roube a melhor copy dos clientes.** Escreva como o cliente fala.
15. **Founder visível e audível.** Pessoas compram de pessoas. Mostre o rosto.
16. **Pricing impossível de não ver.** "Pricing" no header. É onde olham primeiro.
17. **Headline que lembram no dia seguinte.** Escreva 5, teste, fique com a que gruda.
18. **Headline emocional.** Lembram de sentimentos, não features. Faça rir, "wow" ou "wtf".
19. **Algo que nunca viram.** Ninguém compartilha mais um clone. Surpreenda.
20. **Vendável só pelo hero.** 80% não rolam além. Entenderam e quiseram em segundos? Senão, perdeu.
21. **Empatia antes de vender.** Descreva o problema melhor do que o cliente descreveria.
22. **Um CTA.** Cada botão extra = hesitação. Múltiplos caminhos → muitos escolhem nenhum.
23. **Nome memorável.** Palavras que já conhecem. Evite trocadilho/palavra inventada.
24. **Venda desejo humano, não feature.** Mais dinheiro/tempo/saúde/status, menos dor. Feature é veículo.
25. **Deixe testar antes de comprar.** Não esconda o melhor atrás do paywall. Play before pay.
26. **Sem palavras fracas.** "a maioria", "muitos", "raramente" enfraquecem. Afirme, não estime.
27. **Sem assinatura.** Já pagam assinatura demais. One-time é 10x mais fácil de vender.
28. **CTA que diz o que acontece a seguir.** "Get Started" não diz nada. "Analise meu site" diz.
29. **Não lance sem testimonials.** Página sem prova = pedir confiança cega. Colete proof antes do tráfego.
30. **Descrevível em <10 palavras.** Se você não consegue, o usuário também não.
31. **Compare-se com concorrentes.** Tabela simples com as features que importam. Decisão óbvia.
32. **Mais caro que o concorrente.** Ninguém comenta da segunda opção mais barata. Cobre mais.

## Notas de uso

- Se o usuário pedir só "os top problemas", reduza para os 5-8 maiores ofensores em vez
  do relatório completo — mas a profundidade longa é o default desta skill.
- Se for projeto UnicPages e ele pedir, depois da auditoria você pode sugerir as edições
  já no formato do editor (use a skill `unicpages-edit`).
- Nunca invente testimonials/números pra "consertar" — aponte que faltam e oriente o usuário a coletar.
