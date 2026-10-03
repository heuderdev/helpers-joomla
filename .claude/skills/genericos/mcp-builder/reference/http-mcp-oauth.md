# MCP HTTP com OAuth — Esqueleto Completo (Laravel)

Para MCP remoto (não stdio), o cliente (claude.ai) faz `POST /mcp/<servidor>` com `Authorization: Bearer <token>`. No Node você precisava montar à mão: rotas OAuth, transporte streamable, store de sessões e limpeza de TTL. No Laravel o pacote **`laravel/mcp`** já entrega o transporte HTTP e as rotas OAuth; você só precisa:

1. **Passport** como servidor OAuth 2.1 (authorization code + PKCE + dynamic client registration)
2. `Mcp::oauthRoutes()` + `Mcp::web(...)` em `routes/ai.php`
3. Guard `api` com driver `passport`
4. Tela de consentimento (view Blade)

O estado por sessão **não** fica em memória do processo (como no `Map()` do Node, que quebrava com 2+ réplicas): cada request é autenticado pelo token e o usuário vem de `$request->user()`.

## 1. Instalação

```bash
composer require laravel/mcp laravel/passport
php artisan vendor:publish --tag=ai-routes
php artisan install:api --passport          # migrations do Passport + guard api
php artisan passport:keys                   # só local; em prod injete as chaves via env/secret
php artisan vendor:publish --tag=mcp-views  # tela de autorização usada pelo fluxo MCP
php artisan migrate
```

## 2. `config/auth.php`

```php
'guards' => [
    'web' => ['driver' => 'session', 'provider' => 'users'],
    'api' => ['driver' => 'passport', 'provider' => 'users'],
],
```

`App\Models\User` precisa do trait `Laravel\Passport\HasApiTokens` (e implementar `Laravel\Passport\Contracts\OAuthenticatable` na versão 13+ do Passport).

## 3. `app/Providers/AppServiceProvider.php`

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Tela de consentimento (publicada por --tag=mcp-views)
        Passport::authorizationView(fn (array $parametros) => view('mcp.authorize', $parametros));

        // Tokens de MCP: vida curta + refresh
        Passport::tokensExpireIn(now()->addHours(8));
        Passport::refreshTokensExpireIn(now()->addDays(30));

        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip()));
    }
}
```

Chaves em produção (nunca dentro da imagem Docker):

```dotenv
PASSPORT_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----\n..."
PASSPORT_PUBLIC_KEY="-----BEGIN PUBLIC KEY-----\n..."
```

## 4. `routes/ai.php`

```php
<?php

use App\Mcp\Servers\PaginasServer;
use Laravel\Mcp\Facades\Mcp;

// /.well-known/oauth-protected-resource, /.well-known/oauth-authorization-server,
// registro dinâmico de cliente, authorize e token — tudo via Passport
Mcp::oauthRoutes();

Mcp::web('/mcp/paginas', PaginasServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);
```

Request sem token recebe `401` com o header `WWW-Authenticate: Bearer resource_metadata="<APP_URL>/.well-known/oauth-protected-resource"` — é isso que dispara o fluxo OAuth no claude.ai. Confira:

```bash
curl -si -X POST "$APP_URL/mcp/paginas" -H 'Content-Type: application/json' \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}' | head -20
curl -s "$APP_URL/.well-known/oauth-protected-resource" | jq
```

## 5. Usando o usuário autenticado nos tools

```php
public function handle(Request $request, ListarProjetos $listar): Response
{
    $usuario = $request->user();          // vem do token Passport

    return $this->ok('Projetos:', $listar($usuario));
}
```

Autorize **cada** recurso com Policy (`Gate::forUser($request->user())->authorize('view', $projeto)`) — o token prova quem é o usuário, não que o id pedido pelo LLM pertence a ele.

Escopos OAuth (opcional): registre com `Passport::tokensCan(['paginas:ler' => '...', 'paginas:editar' => '...'])` e cheque em tools de escrita com `$request->user()->tokenCan('paginas:editar')`.

## 6. Alternativa sem OAuth (uso interno / Claude Code)

Para clientes que aceitam header fixo (Claude Code, scripts), Sanctum basta:

```php
Mcp::web('/mcp/paginas', PaginasServer::class)
    ->middleware(['auth:sanctum', 'throttle:mcp']);
```

```bash
claude mcp add --transport http paginas "$APP_URL/mcp/paginas" \
  --header "Authorization: Bearer <token Sanctum>"
```

O conector remoto do **claude.ai exige OAuth** — para ele, use Passport (seções 1-4).

## 7. Servidor local (stdio)

```php
Mcp::local('paginas', PaginasServer::class);
```

```bash
claude mcp add paginas -- php /caminho/do/app/artisan mcp:start paginas
```

No modo local não há request HTTP autenticado: se os tools dependem de `$request->user()`, resolva o usuário por config (ex: `config('mcp.usuario_local')`) só quando `app()->isLocal()`.

## 8. Streaming e proxy

- nginx na frente de php-fpm: `fastcgi_buffering off;` na `location /mcp/` e `fastcgi_read_timeout 300;`.
- nginx como proxy de FrankenPHP/Octane: `proxy_buffering off;` e `proxy_read_timeout 300;`.
- Cloudflare: desative "Rocket Loader"/buffering para o path `/mcp/*`.

## 9. Testes de autenticação (Pest)

```php
it('exige autenticação', function () {
    $this->postJson('/mcp/paginas', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])
        ->assertUnauthorized()
        ->assertHeader('WWW-Authenticate');
});

it('publica o metadata OAuth', function () {
    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonStructure(['resource', 'authorization_servers']);
});
```

Padrão completo de referência: MCP da UnicPages (implementado em Node — `backend/mcp/routes/oauth.js`); a versão Laravel substitui todo aquele código pelas seções 1-4 acima.
