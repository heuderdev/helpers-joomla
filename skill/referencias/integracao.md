# Integrações: HttpHelper


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
