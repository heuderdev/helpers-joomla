# Entrada e saída: InputHelper, ValidationHelper, ApiResponseHelper, PermissionHelper, Vigia.js, Vitrine.js

## InputHelper (ler a requisição já no tipo certo)

Assinatura comum: `metodo($campo, $padrao, $fonte = 'request')`. Fonte: `'get'`, `'post'`, `'request'`, `'json'` (corpo JSON). Campo ausente ou inválido → `$padrao`.

| Método | Devolve |
|---|---|
| `string` (sem tags) · `text` · `raw` · `html` (filtrado pelo Joomla) | string |
| `int` · `uint` (≥ 0) · `float` · `decimal` (aceita "1.234,56") · `bool` ("1","sim","on","true") | número/bool |
| `cmd` · `word` · `alnum` · `username` · `path` · `email` | string filtrada |
| `oneOf($campo, ['a','b'], $padrao)` | valor permitido ou padrão |
| `cpf` · `cnpj` · `phone` (só dígitos) · `date` (Y-m-d) · `dateBr` (dd/mm/aaaa → Y-m-d) | string ou padrão |
| `array` · `arrayOfInt` (ids > 0, sem repetir) · `arrayOfString` | array |
| `only(['a','b'], $fonte)` | vários campos com filtro do Joomla |
| `map(['nome' => 'string', 'idade' => 'int'], $fonte)` · atalhos `post($mapa)`, `query($mapa)`, `request($mapa)` | array; filtros do Joomla (string, int, uint, float, bool, cmd, word, alnum, html, raw, array…) |
| `filters($mapa)` | como `map`, sem os vazios (filtros de tela) |
| `pagination(20, 100)` | `['page','limit','offset']` |
| `sorting(['nome','criado_em'], 'nome', 'ASC')` | `['field','direction']` só de colunas permitidas |
| `json($campo = null)` · `file($campo)` · `files($campo)` | corpo JSON · arquivo(s) enviado(s) |
| `has` · `method` · `isPost` · `isGet` · `isAjax` · `expectsJson` · `ip` · `userAgent` · `referer` · `server($nome)` | |

## ValidationHelper

```php
$v = ValidationHelper::validate($dados, [
    'nome'   => 'required|min_length:3|max_length:150',
    'email'  => ['required', 'email', 'unique' => function ($valor, $dados, $campo) {
        return !OrmTables::table('#__clientes')->where('email', $valor)->exists();
    }],
    'tipo'   => ['required', 'in' => ['pf', 'pj']],
    'doc'    => 'required|cpf_cnpj',
    'nasc'   => 'nullable|date|before:today',
], ['email.unique' => 'E-mail já cadastrado.']);
// ['valid' => bool, 'errors' => ['campo' => ['msg', ...]], 'first_error' => 'msg']
if (!$v['valid']) return ApiResponseHelper::fromValidation($v);
```
Também: `passes()`, `fails()`, `firstError($errors)`, `setLabels(['doc' => 'CPF/CNPJ'])`, `setMessages()`, validadores avulsos `cpf()`, `cnpj()`, `email()`, `date($v, 'Y-m-d')`, `file()`, `image()`.

**Regras:** required, required_if, required_with, nullable, accepted, string, int/integer, numeric, decimal, bool/boolean, array, json, uuid, url, ip, email, alpha, alpha_num, alpha_dash, min / max (número), min_length / max_length / length (texto), between, in, not_in, same, different, regex (última na string), date, date_br, datetime, time, after, after_or_equal, before, before_or_equal, cpf, cnpj, cpf_cnpj, cep, phone/telefone, file, image, file_extension, file_mime, file_size, image_dimensions, unique/exists (closure), callback (closure ou `'callback:Classe::metodo'`).

## ApiResponseHelper

Detecta sozinho se a requisição espera JSON (`format=json`, cabeçalho Accept ou AJAX).
- **JSON:** envia `{success, status, mensagem, data, errors, meta}` com o código HTTP certo e encerra a aplicação.
- **HTML:** mostra a mensagem do Joomla (`enqueueMessage`) e redireciona para `setDefaultRedirect($url)` ou para a opção `redirect`.

| Método | HTTP |
|---|---|
| `success($msg, $dados)` · `info` · `warning` | 200 |
| `created` (201) · `updated` (200) · `deleted` (200) | |
| `paginated($resultadoDoPaginate, $msg)` | 200, itens em `data` e paginação em `meta.paginacao` |
| `error($msg, $dados, $erros)` | 400 |
| `badRequest` 400 · `unauthorized` 401 · `forbidden` 403 · `notFound` 404 · `methodNotAllowed` 405 · `conflict` 409 · `tooManyRequests` 429 · `serviceUnavailable` 503 | |
| `fromValidation($v)` | 422 com `errors` por campo |
| `exception(Throwable $e, $msgPublica)` | sempre 500 com mensagem genérica; a técnica vai só para o log (com `JDEBUG`, também em `meta.debug`). Para `findOrFail`, capture antes: `if ($e->getCode() === 404) return ApiResponseHelper::notFound();` |

Opções (último parâmetro): `http_status`, `redirect` (`false` desliga), `json` (força), `close`, `headers`, `meta`, `log`. Configuração global: `setDefaultRedirect`, `setCloseApplication`, `setLogErrors`.

## PermissionHelper (ACL do Joomla)

Token CSRF em toda escrita: `if (!PermissionHelper::requireToken()) { return; }` aceita o campo do `form.token`, o cabeçalho `X-CSRF-Token` (Vigia.js, fetch) e JSON `{"<token>": 1}` no Joomla 3, 4 e 5 (o `JSession::checkToken()` do 3 só lê o campo); responde 403 e registra. Consulta sem responder: `hasValidToken()`.

Consultas (bool): `can('core.edit', 'com_x')`, `canComponent('core.create')` (usa `setDefaultComponent('com_x')`), `canEntity('core.edit', 'pedido', $id)`, `canAny([...])`, `canAll([...])`, `isAdmin()`, `isManager()`, `isLoggedIn()`, `isGuest()`, `hasGroup($id)`, `hasAnyGroup([...])`, `canView($nivelDeAcesso)`, `userId()`, `groups()`, `viewLevels()`, `isUserIn([ids])`, `evaluate(function ($user) {...})`. Regra própria por entidade: `registerEntityResolver('pedido', function ($id, $user, $contexto) { return ...; })` (`$contexto['action']` traz a ação) + `canAccessEntity('pedido', $id)`.

Exigências (`require*`): `requireLogin()`, `require($acao, $asset)`, `requireComponent`, `requireEntity`, `requireAccessEntity`, `requireAny`, `requireAll`, `requireGroup`, `requireAnyGroup`, `requireUserIn`, `requireCallback`.
- **JSON:** respondem 401/403 e encerram.
- **HTML:** mostram o erro e redirecionam se você passar `$redirect`. **Sem `$redirect`, só devolvem `false` e o código continua**, então escreva:
```php
if (!PermissionHelper::require('core.edit', 'com_loja', 'Sem permissão.', JRoute::_('index.php?option=com_loja'))) {
    return;
}
```
`setUseApiResponse(true)` faz as negações responderem pelo ApiResponseHelper.

## Vigia.js (js/vigia.js: formulário no navegador)

Valida no navegador com as **mesmas regras e mensagens** do ValidationHelper, mostra os erros 422 do `ApiResponseHelper::fromValidation()` embaixo de cada campo (`itens.1.qtd` → `name="itens[1][qtd]"`; sem campo/`_general` → mensagem geral), aplica máscaras e envia por axios com o token CSRF. Requer axios antes; Alpine opcional. Copie `js/vigia.js` (+ `vigia.css`, opcional com Bootstrap) para `media/com_x/js/`.

```php
// view: regras escritas uma vez, no PHP (closures, unique, exists, callback ficam de fora)
$vigia = ValidationHelper::clientConfig($regras, $mensagens, $rotulos);
```
```html
<form data-vigia='<?php echo htmlspecialchars(json_encode($vigia), ENT_QUOTES, 'UTF-8'); ?>'
      action="<?php echo JRoute::_('index.php?option=com_x&task=item.salvar&format=json'); ?>" method="post">
    <div data-vigia-message></div>
    <input name="cpf" data-mask="cpf" data-rules="required|cpf">          <!-- data-rules soma às do PHP -->
    <input name="valor" data-mask="money" data-unmask>                     <!-- envia 1234.56 -->
    <input name="itens[0][qtd]" data-mask="integer">
    <?php echo JHtml::_('form.token'); ?>
    <button data-loading-text="Salvando…">Salvar</button>
</form>
```
- Controller: `JSession::checkToken()` falhou → `ApiResponseHelper::forbidden(...)` (nunca `jexit`: vira "resposta inesperada"); inválido → `return ApiResponseHelper::fromValidation($validacao);`; sucesso → `created/success($msg, ['id' => ..., 'redirect' => opcional])`.
- Máscaras: `cpf cnpj cpf_cnpj phone celular cep date time datetime money decimal integer percent placa credit_card pis cnh uppercase digits` ou padrão (`9` dígito, `a` letra, `*` ambos).
- Regras só do navegador (o PHP ignora): `remote:url` (POST field/value; 422 = inválido), `confirmed`, `password:8`, `digits`, `starts_with`, `credit_card`, `placa`, `pis`, `cnh`.
- Atributos: `data-label`, `data-msg-<regra>`, `data-error-for="campo"`, `data-vigia-submit="native"` (valida e recarrega), `data-vigia-reset`, `data-vigia-redirect`, `data-vigia-confirm`, `data-vigia-errors='{...}'` (erros ao carregar).
- JS: `Vigia.form(el, {rules, messages, labels, onSuccess, onError})` → `validate()`, `submit()`, `showErrors(errors)`; `Vigia.validate(dados, regras)` (igual ao PHP); `Vigia.http.post(url, dados)` → `{success, message, data, errors, meta, httpStatus}`, 4xx/5xx rejeita `VigiaError` (`httpStatus`, `errors`, `isValidation`).
- Eventos no form: `vigia:invalid`, `vigia:before` (cancelável), `vigia:success`, `vigia:error`, `vigia:complete`.
- Alpine: `x-data="vigia({ rules: {...}, render: false }, { meuEstado: '' })"` → `error('campo')`, `hasError()`, `loading`, `message`; `x-mask:cpf`. Sem `@submit.prevent`.

## Vitrine.js (telas de listagem)

```html
<div data-vitrine="<?php echo JRoute::_('index.php?option=com_x&task=itens.listar&format=json', false) ?>" data-vitrine-limit="20" data-vitrine-sort="id">
  <form data-vitrine-filters><input type="search" name="busca"><select name="status">...</select></form>
  <table><thead><tr><th><input type="checkbox" data-select-all></th><th data-sort="nome">Nome</th></tr></thead><tbody data-vitrine-items></tbody></table>
  <template data-vitrine-template><tr><td><input type="checkbox" data-select></td><td data-field="nome"></td><td data-field="total" data-format="money"></td>
    <td><button data-action="excluir" data-url="index.php?option=com_x&task=itens.excluir&id={id}&format=json" data-confirm="Excluir {nome}?">x</button></td></tr></template>
  <p data-vitrine-empty hidden>Nada.</p><small data-vitrine-summary></small><nav data-vitrine-pagination></nav>
  <div data-vitrine-batch hidden><button data-batch-action="excluir" data-url="...excluirLote...">Excluir :count</button></div>
</div>
```
- Servidor: `InputHelper::pagination(20, 100)` + `sorting($listaBranca, 'id', 'ASC')` + `filters([...])` → `->paginate($p['limit'], $p['page'])` → `ApiResponseHelper::paginated($r)`. Lote recebe `ids[]` (`InputHelper::arrayOfInt('ids')`); confira dono de cada id.
- Paginação estilo jQuery Paginate: `<nav data-vitrine-pagination="jpaginate" data-vitrine-display="7">` (faixa que desliza sem requisição, Primeira/Última; cada número = 1 requisição de `limit` linhas).
- Recursos: páginas em memória + pré-carregamento da próxima (padrão, `data-vitrine-cache="60"`), `data-vitrine-pagination="more|infinite"` (carregar mais / rolagem infinita), "ir para a página" no jpaginate, `<a data-vitrine-export href="...exportar">` (leva filtros e ordem), `data-vitrine-refresh="30"`, linhas fantasma, `data-vitrine-remember="nome"` (limite e ordem no navegador). Depois de editar fora da vitrine: `Vitrine.of(el).clearCache()`.
- Requer `js/vigia.js` antes (usa `Vigia.http`: token, cancelamento). Estado na URL (`page`, `sort`, `f_<filtro>`); busca com espera de 350 ms e cancelamento; tudo preenchido como texto (XSS-safe). API: `Vitrine.of(el).reload()/filter({...})/page(n)/sort(c)`; eventos `vitrine:loaded`, `vitrine:actionDone`.
