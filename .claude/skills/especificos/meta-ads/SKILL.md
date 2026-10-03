---
name: meta-ads
description: Opera o Meta Ads (Facebook/Instagram) via Marketing API direto por curl, sem depender do MCP do Facebook (que está fora do ar / rollout gradual). Lista contas, campanhas, conjuntos e anúncios; diagnostica por que um anúncio não entrega; cria campanha, conjunto, criativo e anúncio; sobe imagem e vídeo; puxa métricas do Insights; pausa e ativa. Autentica via System User token em ~/.meta-ads-token (nunca exposto). Use SEMPRE que o usuário pedir "sobe um anúncio", "cria campanha no meta ads", "analisa minha conta de ads", "por que meu anúncio não está rodando", "pausa/ativa a campanha", "quanto gastei", "qual o CPL/CPA", ou qualquer operação em Facebook/Instagram Ads.
---

# Meta Ads via Marketing API

Controle direto da Marketing API do Meta por `curl`. Criada porque o MCP oficial
(`mcp.facebook.com/ads`) não funciona nesta conta — ele conecta de forma intermitente
mas devolve catálogo de tools vazio, sintoma de rollout gradual não liberado.
A Graph API é independente disso e funciona normalmente.

## Autenticação

Token em `~/.meta-ads-token` (chmod 600). É um **System User token** do Business
Manager: não expira, o que elimina renovação.

**O token nunca deve ser impresso no output.** Todos os scripts o leem internamente.
Nunca faça `cat ~/.meta-ads-token`, nunca ecoe o valor, nunca o inclua em mensagem
ao usuário.

Se o token estiver ausente ou inválido, oriente o usuário a gerar outro:
Business Manager → Configurações do Negócio → Usuários → **Usuários do sistema** →
Adicionar (Admin) → **Adicionar ativos** (conta de anúncios + página, controle total) →
**Gerar novo token** (app `Team Lecdt`, nunca expira, com `ads_management`,
`ads_read`, `business_management`).

Salvar sem passar pelo chat:
```
echo "TOKEN" > ~/.meta-ads-token && chmod 600 ~/.meta-ads-token
```

## Ambiente conhecido

- **App**: `Team Lecdt` — App ID `568417312528230` (Dev Mode; suficiente para contas próprias)
- **Business / portfólio**: `Edu Sites` — ID `1412098216041019`
- **API**: `v26.0` (`https://graph.facebook.com/v26.0/`)

Contas de anúncios sob esse Business:

| Conta | ID | Observação |
|---|---|---|
| MF Gestão (Delivery do Futuro) | `act_1387009996684912` | BRL · São Paulo |
| Edu Sites | `act_375936614875629` | BRL · conta principal |
| Gestão Dev | `act_1063711429649094` | BRL |

Delivery do Futuro: pixel `1922489835807641`, página `1175307119005847`,
site `https://deliverydofuturo.com/`.

Confirme os IDs com `me/adaccounts` antes de operar — contas podem ser adicionadas.

## Regras de operação — LER ANTES DE ESCREVER

O usuário é **leigo em ads** e aqui se gasta dinheiro real. Estas regras não são
sugestões:

1. **Todo objeto criado nasce `status=PAUSED`.** Sem exceção. Ativar é decisão
   dele, num passo separado e explícito.
2. **Nunca ative nada sem pedido literal.** "Sobe a campanha" significa *criar*,
   não *ativar*. Se houver qualquer dúvida, pergunte.
3. **Orçamento vai em centavos.** `daily_budget=10000` são R$ 100,00. É onde um
   zero a mais vira estrago — confirme o valor em reais com o usuário antes de enviar.
4. **Leia antes de alterar.** Antes de mexer numa campanha existente, liste o que
   já existe. Já aconteceu de haver estrutura pronta e a ação certa ser ajustar,
   não duplicar.
5. **Não invente targeting, público ou copy.** Se faltar informação, pergunte.
6. **Escrita exige `CONFIRM=1`** no `fb.sh` (guarda contra criação acidental).

## Uso dos scripts

`scripts/fb.sh` — helper único. Falha alto se a Graph API retornar erro.

```bash
S=~/.claude/skills/meta-ads/scripts

# LEITURA (livre)
$S/fb.sh get me/adaccounts fields=id,account_id,name,account_status,currency,balance,amount_spent
$S/fb.sh get act_1387009996684912/campaigns fields=id,name,status,effective_status,objective,daily_budget limit=50
$S/fb.sh get act_1387009996684912 fields=name,account_status,balance,amount_spent,spend_cap,funding_source_details

# ESCRITA (exige CONFIRM=1)
CONFIRM=1 $S/fb.sh post <ID> status=PAUSED
```

`scripts/diagnostico.sh <CAMPAIGN_ID>` — varre conta → campanha → conjuntos →
anúncios → insights e aponta onde a entrega trava.

## Diagnóstico: "meu anúncio não está rodando"

Sempre rode `diagnostico.sh` antes de teorizar. O campo decisivo é
**`effective_status`**, não `status`:

| effective_status | Significado |
|---|---|
| `ACTIVE` | entregando de fato |
| `ADSET_PAUSED` | o conjunto está pausado — o anúncio está ok |
| `CAMPAIGN_PAUSED` | a campanha está pausada |
| `PAUSED` | o próprio objeto está pausado |
| `DISAPPROVED` | criativo reprovado — ler `issues_info` |
| `PENDING_REVIEW` | em análise |

Armadilha comum: **campanha `ACTIVE` não significa entrega.** Se os conjuntos
estiverem pausados, nada roda e nada gasta. Verifique a cadeia inteira antes de
afirmar que algo está gastando dinheiro.

Se tudo estiver ativo e ainda assim não entrega, cheque a conta:
`balance`, `account_status`, `funding_source_details`, `spend_cap`. Saldo baixo
com orçamento diário alto interrompe a entrega sem aviso claro.

## Criar campanha completa

Quatro passos, nesta ordem. Cada um devolve um ID usado no seguinte.

**1. Campanha**
```bash
CONFIRM=1 $S/fb.sh post act_<CONTA>/campaigns \
  name='[DF] Vendas | Purchase — Ingressos' \
  objective=OUTCOME_SALES \
  status=PAUSED \
  special_ad_categories='[]' \
  daily_budget=10000 \
  bid_strategy=LOWEST_COST_WITHOUT_CAP
```
Objetivos: `OUTCOME_SALES` (compra), `OUTCOME_LEADS` (cadastro),
`OUTCOME_TRAFFIC`, `OUTCOME_ENGAGEMENT`, `OUTCOME_AWARENESS`,
`OUTCOME_APP_PROMOTION`.

`daily_budget` na campanha = CBO (Meta distribui entre os conjuntos).
Para orçamento por conjunto (ABO), omita aqui e ponha no adset.

**2. Conjunto**
```bash
CONFIRM=1 $S/fb.sh post act_<CONTA>/adsets \
  name='[F] Frio · Prudente 80km — Compra' \
  campaign_id=<CAMPAIGN_ID> \
  status=PAUSED \
  billing_event=IMPRESSIONS \
  optimization_goal=OFFSITE_CONVERSIONS \
  promoted_object='{"pixel_id":"1922489835807641","custom_event_type":"PURCHASE"}' \
  targeting='{"geo_locations":{"custom_locations":[{"latitude":-22.1256,"longitude":-51.3889,"radius":80,"distance_unit":"kilometer","country":"BR"}],"location_types":["frequently_in","home"]},"age_min":18,"age_max":65,"publisher_platforms":["facebook","instagram"]}'
```
`custom_event_type`: `PURCHASE`, `LEAD`, `COMPLETE_REGISTRATION`,
`INITIATE_CHECKOUT`, `ADD_TO_CART`, `VIEW_CONTENT`.

Raio geográfico (`custom_locations`) é o padrão para evento local — mais preciso
que cidade. Presidente Prudente: `-22.1256, -51.3889`.

**3. Criativo** (precisa de `image_hash` ou `video_id`)
```bash
# imagem
CONFIRM=1 $S/fb.sh file act_<CONTA>/adimages bytes=@/caminho/arte.jpg
# → devolve o hash

CONFIRM=1 $S/fb.sh post act_<CONTA>/adcreatives \
  name='[DF-V] Story · Urgência Lote 1' \
  object_story_spec='{"page_id":"1175307119005847","link_data":{"image_hash":"<HASH>","link":"https://deliverydofuturo.com/","message":"Texto principal","name":"Título","description":"Subtítulo","call_to_action":{"type":"BUY_TICKETS","value":{"link":"https://deliverydofuturo.com/"}}}}'
```
CTA úteis: `BUY_TICKETS`, `SIGN_UP`, `LEARN_MORE`, `SHOP_NOW`, `BOOK_NOW`, `SUBSCRIBE`.

Vídeo: suba com `advideos` (`file_url=` ou `source=@arquivo`), aguarde o
transcode terminar e use `video_data` com `video_id` + `image_hash` (thumbnail)
no lugar de `link_data`.

**Criativos são imutáveis.** Para trocar arte ou copy, crie um novo criativo e
aponte o anúncio para ele — não existe update.

**4. Anúncio**
```bash
CONFIRM=1 $S/fb.sh post act_<CONTA>/ads \
  name='[DF] Story · Urgência Lote 1 — Compra' \
  adset_id=<ADSET_ID> \
  creative='{"creative_id":"<CREATIVE_ID>"}' \
  status=PAUSED
```

## Métricas

```bash
$S/fb.sh get act_<CONTA>/insights \
  level=campaign \
  fields=campaign_name,spend,impressions,clicks,ctr,cpc,cpm,actions,cost_per_action_type,purchase_roas \
  date_preset=last_7d
```
`level`: `account` | `campaign` | `adset` | `ad`.
`date_preset`: `today`, `yesterday`, `last_7d`, `last_30d`, `maximum`,
ou `time_range={"since":"2026-08-01","until":"2026-08-10"}`.

CPL/CPA saem de `cost_per_action_type`; conversões de `actions`.
Para volumes grandes use relatório assíncrono (`POST .../insights` → `report_run_id`).

## Pausar e ativar

```bash
CONFIRM=1 $S/fb.sh post <OBJECT_ID> status=PAUSED
CONFIRM=1 $S/fb.sh post <OBJECT_ID> status=ACTIVE   # só com pedido explícito
```
Funciona em campanha, conjunto e anúncio. Ativar a campanha **não** ativa os
conjuntos — cada nível é independente.

## Limites conhecidos

- **Advantage+ Shopping/App não pode ser criada via API** (desde 2026) — só no
  Ads Manager. Demais campanhas, sem restrição.
- **Rate limit**: `300 + 40 × anúncios ativos` chamadas/hora no tier inicial.
  Após ~500 chamadas com taxa de erro < 15%, sobe automaticamente para
  `100.000 + 40 × ads`. Não requer App Review.
- **App Review não é necessário** para operar contas do próprio Business.
- Header `X-Business-Use-Case-Usage` mostra consumo do limite.

## Convenção de nomes do usuário

Observada nas campanhas existentes — mantenha ao criar:

- Campanha: `[DF] Vendas | Purchase — Ingressos LP`
- Conjunto: `[F] Frio · Prudente 80km — Compra` / `[Q] Remarketing · Engajados — Compra`
- Anúncio: `[DF] Story · Urgência Lote 1 — Compra`

`[DF]` = projeto · `[F]` = frio · `[Q]` = quente/remarketing.

## Nota sobre o Delivery do Futuro

Eventos do pixel na LP: `Lead`/`CompleteRegistration` marcam **envio do
formulário**; abertura do modal é `InitiateCheckout`. Otimizar pelo evento errado
já custou ~R$ 105 por lead real. A venda direta vive em `/ingresso` (Start/Black,
checkout PagZero por lote); a home capta lead por formulário → Discord.
