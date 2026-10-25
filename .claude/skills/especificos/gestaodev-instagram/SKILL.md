---
name: gestaodev-instagram
description: Publica e agenda carrosséis no Instagram @gestao.dev usando a Graph API oficial, sem depender de Postiz ou qualquer ferramenta paga. Exporta os criativos direto do Figma, converte para JPEG, sobe no Spaces do próprio Gestão Dev e publica (na hora ou em horário agendado via cron). Use SEMPRE que o Eduardo pedir "publica no instagram do gestão dev", "posta o carrossel X", "agenda esse post pra terça", "sobe o criativo no IG", "programa os posts da semana", ou quiser ver/mexer na fila de publicações agendadas.
---

# gestaodev-instagram

Publicação e agendamento no **@gestao.dev** pela Graph API oficial da Meta. Substitui Postiz/Later: a API de Content Publishing é gratuita, o que essas ferramentas cobram é a interface e o agendador — e o agendador aqui é um cron local.

Conta: `gestao.dev` · IG ID `17841473263427716` · limite de **50 posts/dia**.

## 🚫 Regra inegociável de legenda

**Nunca use travessão (—), meia-risca (–), seta (→) ou bullet (•) na legenda.** É decisão explícita do Eduardo. O `publicar.mjs` valida e **aborta** o post antes de subir imagem se encontrar qualquer um deles.

Isso importa porque **a Graph API não edita legenda de post publicado** — se passar, só dá pra corrigir na mão pelo app do Instagram. Em vez desses caracteres:

| Em vez de | Use |
|---|---|
| `—` travessão | vírgula, dois-pontos, ou reescrever a frase |
| `–` intervalo | "a" / "até" |
| `→` lista | quebra de linha simples, sem marcador |
| `•` bullet | quebra de linha simples |

Limite de 2200 caracteres (também validado).

## Credenciais

Já configuradas, `chmod 600`, **nunca imprimir o conteúdo**:

| Arquivo | O que é |
|---|---|
| `~/.gestaodev-ig-token` | **Page token — não expira.** É o que autentica tudo. |
| `~/.gestaodev-ig-id` | ID da conta IG Business |
| `~/.gestaodev-app-secret` | App Secret (só serve pra trocar token de usuário por long-lived) |

Se algum dia der erro `190` (token inválido), o token foi revogado: gerar novo user token no Graph API Explorer do app "Gestão Dev" (`1018667024281391`), trocar por long-lived e pegar o page token de novo — ver "Renovar token" no fim.

## Fluxo completo

### 1. Preparar os criativos

```bash
~/.claude/skills/gestaodev-instagram/scripts/preparar.sh carrossel-2 "Carrossel 2"
```

Exporta a section do Figma (`bZNvNdwWZrWeD3bYl9e3cH`, página "Identidade"), converte pra **JPEG q92** e deixa em `~/.gestaodev-social/criativos/<slug>/`.

⚠️ **JPEG é obrigatório.** O Instagram rejeita PNG e WebP no Content Publishing. Por isso o upload **não** usa a rota `/storage/imagem` da api-upload: ela passa pelo middleware `converterWebp` e sempre devolve `.webp`. O script sobe direto no S3 com as mesmas credenciais e o mesmo bucket (`gestaodev-upload`), na pasta `social/gestaodev/`.

### 2. Escrever o post

Um `.json` em `~/.gestaodev-social/posts/`:

```json
{
  "quando": "2026-08-16T09:00",
  "pasta": "/Users/eduardolecdt/.gestaodev-social/criativos/carrossel-2",
  "legenda": "Primeira linha que aparece no feed.\n\nCorpo do texto.\n\nLink na bio.\n\n#desenvolvedor #freelancer #devbrasil"
}
```

- `quando`: horário **local**. O cron publica a partir dele (não antes).
- `pasta`: 2 a 10 JPEGs, publicados em **ordem alfabética** (`01.jpg`, `02.jpg`...).
- `legenda`: sem os caracteres proibidos acima.

### 3. Publicar

```bash
cd ~/.claude/skills/gestaodev-instagram/scripts

node publicar.mjs --listar              # ver a fila
node publicar.mjs --agora <post.json>   # publica ignorando o horário
node publicar.mjs                       # processa a fila (é o que o cron chama)
```

Publicado, o `.json` ganha o campo `publicado` (id, permalink, timestamp) e é **movido** para `~/.gestaodev-social/publicados/`. É isso que impede post duplicado se o cron reentrar.

### 4. Ligar o agendamento (uma vez só)

```bash
crontab -l 2>/dev/null | grep -q gestaodev-social || \
  (crontab -l 2>/dev/null; echo '*/10 * * * * cd ~/.claude/skills/gestaodev-instagram/scripts && /usr/local/bin/node publicar.mjs >> ~/.gestaodev-social/logs/cron.log 2>&1') | crontab -
```

Roda de 10 em 10 minutos e publica **um post por execução** — dois agendados pro mesmo horário saem com 10 min de intervalo, o que é melhor pro alcance do que despejar junto.

⚠️ **O Mac precisa estar ligado e acordado** no horário. Post agendado pra madrugada com a máquina dormindo só sai quando ela acordar. Confirme o caminho do node com `which node` antes de instalar o cron.

## Como funciona por dentro

A Graph API exige três passos para carrossel, nessa ordem:

1. um container **por imagem**, com `is_carousel_item=true`
2. um container **pai** `media_type=CAROUSEL` listando os filhos
3. `media_publish` do pai

O pai não fica pronto na hora: a Meta baixa e processa as imagens em background. Publicar antes de `status_code=FINISHED` dá erro — por isso o polling de até 2 minutos.

O nome do arquivo no bucket leva um hash do conteúdo, então republicar o mesmo criativo reaproveita a URL em vez de acumular duplicata.

## Verificar

```bash
TOKEN=$(cat ~/.gestaodev-ig-token); IGID=$(cat ~/.gestaodev-ig-id)

# últimos posts
curl -s "https://graph.facebook.com/v21.0/$IGID/media?fields=id,permalink,media_type,timestamp&limit=5&access_token=$TOKEN"

# cota do dia (limite 50)
curl -s "https://graph.facebook.com/v21.0/$IGID/content_publishing_limit?access_token=$TOKEN"

# log
tail -30 ~/.gestaodev-social/logs/publicacoes.log
```

## Erros comuns

| Erro | Causa | Solução |
|---|---|---|
| `190` OAuth | Token revogado | Renovar (abaixo) |
| `9004` media não baixável | URL não pública ou não é JPEG | Conferir `curl -I` na URL: precisa 200 + `image/jpeg` |
| `2207026` formato não suportado | PNG/WebP | Reconverter com `preparar.sh` |
| Container fica `ERROR` | Imagem fora do aspecto aceito | Instagram aceita 4:5 a 1.91:1; os criativos são 1080×1350 (4:5) |
| `25` limite | Passou de 50 posts em 24h | Esperar |

## Renovar token

Só se der erro 190. O page token normalmente não expira.

```bash
# 1. Gerar user token no Graph API Explorer (app "Gestão Dev" 1018667024281391)
#    com escopos: instagram_basic, instagram_content_publish, pages_show_list,
#    pages_read_engagement, business_management

# 2. Trocar por long-lived (60 dias)
SECRET=$(cat ~/.gestaodev-app-secret)
curl -s "https://graph.facebook.com/v21.0/oauth/access_token?grant_type=fb_exchange_token&client_id=1018667024281391&client_secret=$SECRET&fb_exchange_token=<USER_TOKEN>"

# 3. Pegar o page token (esse não expira) e salvar
curl -s "https://graph.facebook.com/v21.0/me/accounts?access_token=<LONG_LIVED>"
# → grava o access_token da página em ~/.gestaodev-ig-token (chmod 600)
```

## Identidade dos posts

Os criativos vêm do Figma **Gestão Dev Criativos** (15 carrosséis × 6 telas). Tokens, IDs de biblioteca e armadilhas de layout estão na memória `gestaodev-criativos-figma`.

Na copy, o modelo de negócio é **grátis + taxa por transação** (igual banco): nunca falar em mensalidade, assinatura ou teste grátis. CTA é sempre "link na bio", sem citar valor de taxa.
