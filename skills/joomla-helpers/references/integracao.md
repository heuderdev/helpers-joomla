# Integrações: HttpHelper, MailHelper, EmailSendHelper, WebhookHelper


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

## EmailSendHelper (e-mail sem o mailer do Joomla: SMTP próprio, ElasticEmail, várias contas)

```php
IncludeHelper::load('EmailSendHelper');                                 // traz o LogHelper (log "mail")
EmailSendHelper::register('gmail', ['username' => 'x@gmail.com', 'password' => $senhaDeApp]);   // nome = provedor pronto
EmailSendHelper::register('vendas', ['provider' => 'office365', 'username' => ..., 'password' => ...]);
EmailSendHelper::register('elasticemail', ['api_key' => $key, 'from' => 'nao-responda@dominio.com']);
EmailSendHelper::setViewDirectory(JPATH_COMPONENT . '/emails'); EmailSendHelper::setDefaultLayout('layout.php');

$r = EmailSendHelper::make(['elasticemail', 'gmail'])                    // lista = failover; null = padrão; array assoc = config avulsa
    ->to($email, $nome)->cc(...)->bcc([...])->replyTo(...)             // 'a@x, b@x' · 'Ana <a@x>' · [email => nome]
    ->subject('Pedido {{ pedido.numero }}')
    ->view('pedido', ['pedido' => $pedido])                             // .php: variáveis + $e() escapa · .html: {{ }} / {!! !!} / | money | date
    ->attach($pdf, 'Boleto.pdf')->attachData($csv, 'itens.csv')->attachUpload($arquivo)->embed($logo, 'logo')   // <img src="cid:logo">
    ->send();                                                           // não lança; ->sendOrFail() lança RuntimeException
EmailSendHelper::sendMail(['to' => ..., 'subject' => ..., 'html' => ..., 'attachments' => [...]], 'gmail');
EmailSendHelper::fake(); ... EmailSendHelper::sent();  ->preview() · ->toMime() · EmailSendHelper::testConnection('gmail')
```
- Provedores prontos: gmail, google_workspace, outlook/hotmail/live, office365, yahoo, icloud, zoho, sendgrid, brevo, mailgun, mailtrap, locaweb, hostinger, elasticemail (API) / elasticemail_smtp, mailpit/mailhog, mail, log. Drivers: `smtp`, `elasticemail`, `mail`, `log` (.eml; informe `directory` fora da pasta pública).
- Gmail/Yahoo/iCloud: senha de app; Outlook/Office 365: SMTP AUTH liberado ou `oauth_token`. Remetente = a conta (ou alias). Sem `from()`/`from_email`, usa o `username` se for e-mail.
- Failover tenta a próxima só em erro de entrega (rede, login, servidor); erro da mensagem (e-mail inválido, sem assunto, anexo/view não encontrados, > `max_size` 25 MB) para na hora.
- Credenciais fora do código (config fora do git, `getenv`, SettingsHelper). Nunca view/`attach()` com caminho da requisição. Sem fila própria: job com `sendOrFail()` (veja docs/email-send-helper.html#receita-fila). Use MailHelper quando o e-mail do Joomla basta.

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
