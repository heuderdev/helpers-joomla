# Entrada e saída: InputHelper, ValidationHelper, ApiResponseHelper, PermissionHelper

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
