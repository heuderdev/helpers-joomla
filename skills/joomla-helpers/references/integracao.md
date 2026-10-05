# Integrações: HttpHelper, MailHelper, WebhookHelper


## HttpHelper (APIs externas)

```php
$r = HttpHelper::get($url, ['cep' => $cep]);                  // ['ok','status','json','body','headers','error','time_ms','attempts','url']
$r = HttpHelper::post($url, $array);                           // array = JSON; string = corpo cru
HttpHelper::post($url, null, ['form' => [...]]);              // ou 'multipart' => ['arq' => new CURLFile(...)]
$erp = HttpHelper::client(['base_url' => 'https://erp/api', 'bearer' => $token, 'timeout' => 10]);
$erp->get('/clientes', ['documento' => $cpf]);
if (!$r['ok']) return ApiResponseHelper::serviceUnavailable(HttpHelper::errorMessage($r));
```
- **Não lança** (salvo `'throw' => true` → `HttpHelperException`, `getResponse()`); `status` 0 = sem resposta (rede, timeout, URL inválida).
- Padrões: `timeout` 15 s, `connect_timeout` 5, `retries` 2 em rede/429/5xx com espera crescente e `Retry-After`. **POST/PATCH não repetem** (só com `'retry_post' => true` + `Idempotency-Key`).
- Opções: `headers`, `query`, `json`, `form`, `multipart`, `body`, `bearer`, `basic` => [u, s], `follow_redirects` (0), `verify`, `block_private` (SSRF), `log_category`, `log_body`. `download($url, $destino)` grava direto no arquivo.
- URL vinda do usuário: `HttpHelper::isPublicUrl($url)` + `'block_private' => true`. API de tela: `timeout` baixo e `retries` 0–1; integração lenta → fila.

## MailHelper (e-mail transacional)

```php
$r = MailHelper::send([
    'to' => [$email => $nome],                          // texto, lista ou email => nome; também cc, bcc, reply_to
    'subject' => 'Pedido #{{ pedido.numero }}',
    'template' => 'pedido-confirmado',                  // <pasta>/pedido-confirmado.html (+ .txt opcional)
    'data' => ['pedido' => $pedido, 'link' => $url],
    'attachments' => [['path' => $pdf, 'name' => 'NF.pdf']],
]);                                                     // envelope: success/mensagem/errors (não lança)
MailHelper::queue($mail, ['max_tentativas' => 5]);     // pela fila; no bootstrap do worker: MailHelper::registerJob()
MailHelper::fake(); ... MailHelper::sent();            // testes
```
- Template: `{{ x }}` escapado, `{{ total | money }}`, `| date`, `| datetime`, `| nl2br`, `| upper`; `{!! html !!}` sem escapar (só HTML do seu código). Uma passada: valores inseridos não são reprocessados.
- Config: `setTemplateDirectory()` (padrão `JPATH_COMPONENT/emails`), `setLayout('layout')` com `{!! conteudo !!}`, `setDefaultFrom()`. Remetente do próprio domínio (SPF/DKIM).
- `queue()` monta na hora (erros de template aparecem já) e grava só o pronto; anexos precisam existir quando o worker rodar. Job: `fila/jobs/MailJob.php`.

## WebhookHelper (receber webhooks)

```php
WebhookHelper::handle([
    'provider' => 'gateway',
    'secret' => $segredo, 'signature_header' => 'X-Signature', 'prefix' => 'sha256=',   // ou 'token' + 'token_header', ou 'verify' => fn($corpo, $cab)
    'job' => 'processar_pagamento',            // push(['webhook_id' => $id]); no job: WebhookHelper::payload($id) ... markProcessed($id)
]);                                            // responde 200/401/400/500 sozinho
```
- Sem secret/token/verify recusa tudo. Reenvio do mesmo `(provider, id)` → 200 sem reprocessar (exceto se falhou). Corpo cru guardado em `#__helpers_webhooks` (criada sozinha; `tables/webhookHelper.sql`).
- Task sem login e **sem** checkToken; HTTPS; job idempotente ("marcar pago se ainda não pago"); confira valor na API do remetente. `prune(30)` num cron.
