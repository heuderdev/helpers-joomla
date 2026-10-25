# In-App Purchase e login social nativo (Capacitor + iOS)

Guia completo de implementação de assinatura por IAP e Sign in with Apple/Google
num app Capacitor que embrulha uma URL remota. Validado ponta a ponta em
produção (EduSites, jul/2026) depois de **duas rejeições na guideline 3.1.1**.

Leia junto com o `SKILL.md` (build, archive, upload, API do App Store Connect).

---

## Quando você precisa disso

A Apple exige IAP quando o app **dá acesso a conteúdo digital pago**. Esconder
o botão de assinar não resolve — a carta de rejeição fala em *"accesses paid
digital content"*, não em *"shows prices"*.

Só existem duas saídas legítimas:

1. **Implementar IAP.** Resolve com certeza. Comissão de 15-30% sobre o que for
   vendido pelo iOS (15% no Small Business Program, até US$ 1M/ano).
2. **Reader app (3.1.3(a)).** Restrito a revista, jornal, livro, áudio, música
   e vídeo — consumo passivo. **Não é auto-declarável**: a Apple concede.

⚠️ **Não conte com a via 2 se o app tiver comunidade, chat, IA, quizzes ou
gamificação.** No EduSites a Apple viu o app completamente limpo (zero preço,
zero link externo, linguagem neutra) e **manteve a rejeição** — a redação mudou
de "such as subscription" para "such as subscription plans", sinalizando que o
problema é a natureza do produto, não a UI.

O parágrafo sobre "link out to the default browser" na carta vale **só para a
storefront dos EUA**. Fora dos EUA, link externo de pagamento é violação.

---

## Decisões de arquitetura

### RevenueCat vs StoreKit direto

**RevenueCat** (escolhido): resolve validação de recibo, renovação automática,
período de graça, upgrade/downgrade e reembolso. Gratuito até US$ 2.5k/mês de
receita, depois 1%. Os assinantes continuam **no seu banco** — ele só manda o
webhook; nada fica preso lá.

**StoreKit 2 direto**: zero dependência, mas você escreve validação de recibo
(JWT ES256 contra a App Store Server API), App Store Server Notifications V2
(payload JWS assinado) e todos os casos de borda. É o código mais chato de
acertar, e bug ali significa aluno pagando sem acesso.

### Plugin de login social — cuidado ao escolher

Levantamento real de jul/2026:

| Plugin | Situação |
|---|---|
| `@codetrix-studio/capacitor-google-auth` | **Abandonado.** Peer `^6.0.0` (exclui Cap 7 e 8), última versão é RC, parado desde 2024-05 |
| `@capgo/capacitor-social-login` | Vivo, Cap 8 nativo, Apple+Google numa dep — **mas embarca facebook-ios-sdk** |
| `@capacitor-firebase/authentication` | Vivo (Capawesome), Cap 8, reaproveita Firebase que o projeto já tem pro FCM — **também embarca facebook-ios-sdk** |

**Todos os plugins de login social embarcam o Facebook SDK** e não há opt-out
por configuração — o `providers: [...]` é lista de carregamento em *runtime*,
não de compilação. Ver a seção do patch abaixo.

---

## Backend (Laravel 12)

Stack do backend: Laravel 12 + Sanctum (token do app nativo), rotas em
`routes/api.php`, validação em FormRequest, lógica em Action. Dependências:

```bash
composer require laravel/sanctum firebase/php-jwt
php artisan install:api   # publica Sanctum + routes/api.php
```

```php
// config/services.php
'apple' => [
    // bundle do app E Service ID da web — lista, igualdade exata
    'audiences' => array_filter(explode(',', (string) env('APPLE_AUDIENCES'))),
],
'google' => [
    // client id web E client id iOS — o token nativo vem com o do iOS
    'client_ids' => array_filter(explode(',', (string) env('GOOGLE_CLIENT_IDS'))),
],
'revenuecat' => [
    'webhook_token' => env('REVENUECAT_WEBHOOK_TOKEN'),
],
```

```php
// routes/api.php
use App\Http\Controllers\Auth\SocialLoginController;
use App\Http\Controllers\Auth\SocialLinkController;
use App\Http\Controllers\Webhooks\RevenueCatWebhookController;

Route::middleware('throttle:auth')->prefix('autenticacao')->group(function () {
    Route::post('apple', [SocialLoginController::class, 'apple']);
    Route::post('apple/callback', [SocialLoginController::class, 'appleWeb']); // form_post
    Route::post('google/nativo', [SocialLoginController::class, 'googleNativo']);
});

Route::middleware(['auth:sanctum', 'throttle:auth'])->prefix('perfil/vincular')->group(function () {
    Route::post('apple', [SocialLinkController::class, 'apple']);
    Route::post('google', [SocialLinkController::class, 'google']);
});

Route::post('webhooks/revenuecat', RevenueCatWebhookController::class)
    ->middleware('throttle:60,1');
```

```php
// app/Providers/AppServiceProvider.php (boot)
RateLimiter::for('auth', fn (Request $request) => [
    Limit::perMinute(10)->by($request->ip()),
    Limit::perMinute(5)->by((string) $request->input('identityToken', $request->input('idToken'))),
]);
```

⚠️ A rota `apple/callback` recebe POST cross-site da Apple: ela fica em
`routes/api.php` (sem sessão/CSRF). Se você colocá-la em `routes/web.php`,
exclua do CSRF em `bootstrap/app.php` (`$middleware->validateCsrfTokens(except: ['autenticacao/apple/callback'])`)
e valide o `state` você mesmo.

### Sign in with Apple

**No app: fluxo por TOKEN, nunca redirect.** O redirect OAuth sai da WebView do
Capacitor e não volta — e a Apple reprova OAuth em WebView.

```
POST /api/autenticacao/apple  { identityToken, nome? }  →  { token }
```

Valide o `identity_token` contra as JWKS da Apple com `firebase/php-jwt`:
algoritmo `RS256` fixado (vem da JWK, nunca do header do token), `iss` =
`https://appleid.apple.com`, `aud` = lista com bundle do app **e** Service ID
da web, comparada por **igualdade exata** (`in_array(..., true)`) — nunca
`str_contains`.

```php
// app/Http/Requests/Auth/AppleLoginRequest.php
final class AppleLoginRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'identityToken' => ['required', 'string', 'max:4096'],
            'nome' => ['nullable', 'string', 'max:120'],
        ];
    }
}
```

```php
// app/Services/Auth/AppleTokenVerifier.php
declare(strict_types=1);

namespace App\Services\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class AppleTokenVerifier
{
    private const JWKS_URL = 'https://appleid.apple.com/auth/keys';
    private const ISSUER = 'https://appleid.apple.com';

    /** @return array{sub: string, email: ?string, email_verified: bool, is_private_email: bool} */
    public function verify(string $identityToken): array
    {
        $jwks = Cache::remember('apple:jwks', now()->addHours(6),
            fn () => Http::timeout(5)->get(self::JWKS_URL)->throw()->json());

        JWT::$leeway = 60;

        try {
            $claims = (array) JWT::decode($identityToken, JWK::parseKeySet($jwks, 'RS256'));
        } catch (\Throwable) {
            throw ValidationException::withMessages(['identityToken' => 'Token Apple inválido.']);
        }

        $aud = (array) ($claims['aud'] ?? []);
        $allowed = config('services.apple.audiences');

        if (($claims['iss'] ?? null) !== self::ISSUER
            || array_intersect($aud, $allowed) === []
            || empty($claims['sub'])) {
            throw ValidationException::withMessages(['identityToken' => 'Token Apple inválido.']);
        }

        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);

        return [
            'sub' => (string) $claims['sub'],
            'email' => $claims['email'] ?? null,
            'email_verified' => $verified,
            'is_private_email' => filter_var($claims['is_private_email'] ?? false, FILTER_VALIDATE_BOOL),
        ];
    }
}
```

```php
// app/Http/Controllers/Auth/SocialLoginController.php
public function apple(AppleLoginRequest $request, AppleTokenVerifier $apple, ResolveSocialUser $resolve): JsonResponse
{
    $identity = $apple->verify($request->validated('identityToken'));
    $user = $resolve->handle('apple', $identity, $request->validated('nome'));

    return response()->json(['token' => $user->createToken('app-ios')->plainTextToken]);
}
```

**Na web: form_post.** A Apple faz POST direto no seu backend com
`{ code, id_token, user, state }`; o controller valida o `state` (guardado no
cache com TTL curto ao montar a URL), verifica o `id_token` com o mesmo
`AppleTokenVerifier`, e redireciona pro front (`redirect()->away(...)`) com um
código de troca de uso único — não coloque o token Sanctum na query string.
O front só monta a URL de autorização e redireciona — não precisa do SDK JS
(`response_mode=form_post` exige redirect de página inteira, então o modo popup
do SDK não serve).

⚠️ **ORDEM DE BUSCA IMPORTA.** A Apple só manda nome/e-mail no **primeiro
consentimento**. Buscar por `apple_id` (o `sub`, estável) **antes** do e-mail —
senão o segundo login de quem usou "Ocultar meu e-mail" cria conta duplicada.

O e-mail pode ser relay `@privaterelay.appleid.com`. É único por usuário, então
não colide, mas guarde o flag `email_privado` (cast `boolean` no model).

### Google nativo

```
POST /api/autenticacao/google/nativo  { idToken }  →  { token }
```

⚠️ **`services.google.client_ids` precisa ser LISTA.** O client id do iOS é
diferente do web, e o token nativo é emitido com o do iOS. Sem isso o `aud`
não bate e todo login nativo falha com "audience inválida".

Valide com as JWKS do Google (`https://www.googleapis.com/oauth2/v3/certs`)
reutilizando o mesmo padrão do `AppleTokenVerifier` (cache + `JWK::parseKeySet`),
`iss` ∈ `['accounts.google.com', 'https://accounts.google.com']` e `aud` ∈
`client_ids`. Alternativa: `google/apiclient` (`$client->verifyIdToken()`), mas
ele aceita um único client id por instância — itere a lista.

⚠️ **Rejeite `email_verified === false`** nos DOIS fluxos (nativo e web). Se o
vínculo de conta é por e-mail, aceitar não-verificado permite criar uma conta
Google com o e-mail de outra pessoa e assumir a conta dela. Fácil de esquecer no
fluxo web (Socialite), que usa `oauth2/v2/userinfo` (campo `verified_email` em
`$googleUser->user['verified_email']`).

**Extraia a lógica de criar/vincular para uma Action compartilhada**
(`App\Actions\Auth\ResolveSocialUser`) usada pelos dois fluxos — duas cópias
divergem, e foi exatamente assim que o `email_verified` ficou só num lado.

```php
// app/Actions/Auth/ResolveSocialUser.php
final class ResolveSocialUser
{
    public function handle(string $provider, array $identity, ?string $nome = null): User
    {
        if (! $identity['email_verified'] && $identity['email'] !== null) {
            throw ValidationException::withMessages(['email' => 'E-mail não verificado pelo provedor.']);
        }

        return DB::transaction(function () use ($provider, $identity, $nome) {
            $column = "{$provider}_id";

            // 1º pelo id estável do provedor, só depois pelo e-mail
            $user = User::where($column, $identity['sub'])->lockForUpdate()->first()
                ?? ($identity['email'] ? User::where('email', $identity['email'])->lockForUpdate()->first() : null);

            if ($user) {
                $user->forceFill([$column => $identity['sub']])->save();
                return $user;
            }

            return User::create([
                'name' => $nome ?: 'Usuário',
                'email' => $identity['email'],
                $column => $identity['sub'],
                'email_privado' => $identity['is_private_email'] ?? false,
            ]);
        });
    }
}
```

### Webhook do IAP

```
POST /api/webhooks/revenuecat   (header Authorization = token estático)
```

Três invariantes obrigatórias:

1. **Nunca confie no client.** O webhook é a fonte. Compare o token com
   `hash_equals` (tempo constante) e seja **fail-closed**: sem config, rejeite.
2. **Idempotência** por `transaction_id` (não `original_transaction_id`, que não
   muda entre renovações). Guarde em `ultimo_pagamento_consumido` com índice
   único, dentro de transação.
3. **Prefira a data da loja** (`expiration_at_ms`) a acumular período — senão
   evento reenviado estende a assinatura indefinidamente.

```php
// app/Http/Controllers/Webhooks/RevenueCatWebhookController.php
declare(strict_types=1);

namespace App\Http\Controllers\Webhooks;

use App\Actions\Billing\ApplyRevenueCatEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class RevenueCatWebhookController
{
    public function __invoke(Request $request, ApplyRevenueCatEvent $apply): JsonResponse
    {
        $expected = (string) config('services.revenuecat.webhook_token');
        $given = (string) $request->header('Authorization');

        // fail-closed: sem token configurado, ninguém entra
        abort_if($expected === '' || ! hash_equals($expected, $given), 401);

        $event = $request->validate([
            'event.type' => ['required', 'string'],
            'event.app_user_id' => ['required', 'string'],
            'event.product_id' => ['required', 'string'],
            'event.transaction_id' => ['nullable', 'string'],
            'event.expiration_at_ms' => ['nullable', 'integer'],
            'event.cancel_reason' => ['nullable', 'string'],
        ])['event'];

        $apply->handle($event);

        return response()->json(['ok' => true]);
    }
}
```

Processe síncrono (é rápido) ou despache um Job com `ShouldBeUnique` por
`transaction_id` — mas responda 2xx só depois de persistir o evento, senão um
worker parado perde a compra.

**Eventos e o que fazer:**

| Evento | Ação |
|---|---|
| `INITIAL_PURCHASE`, `RENEWAL`, `PRODUCT_CHANGE`, `UNCANCELLATION` | ativa/estende |
| `EXPIRATION` | revoga |
| `CANCELLATION` + `cancel_reason: UNSUBSCRIBE`/`BILLING_ERROR`/ausente | **mantém** até o fim do período pago |
| `CANCELLATION` + `cancel_reason: CUSTOMER_SUPPORT` | **revoga na hora** (é reembolso) |
| `BILLING_ISSUE` | loga, não revoga (há período de graça) |

Modele o tipo como enum PHP (`enum RevenueCatEvent: string`) e use `match` —
um tipo novo cai no `default` e só loga.

⚠️ **O RevenueCat NÃO tem evento `REFUND`.** Reembolso chega como `CANCELLATION`
com `cancel_reason: CUSTOMER_SUPPORT`. Se você simplesmente ignorar
`CANCELLATION` para respeitar o período pago, **reembolso vira acesso grátis**.

`product_id` desconhecido **não deve liberar nada** (mapa fechado em
`config/billing.php`).

O `app_user_id` do evento precisa casar com o id do usuário no seu banco — ver
`Purchases.logIn` na seção do app.

### Cron de expiração

⚠️ Se você tem comando agendado que expira assinatura por data
(`Schedule::command('assinaturas:expirar')->hourly()` em `routes/console.php`),
**inclua a origem nova na query** — senão o acesso vira vitalício depois do
vencimento.

Mas atenção à corrida: a loja renova sozinha e o webhook pode atrasar. Use
**carência** (ex.: 48h) só para a origem de IAP; o webhook `EXPIRATION` é o
caminho normal, o cron é rede de segurança.

```php
Assinatura::query()
    ->where('origem', 'iap')
    ->where('expira_em', '<', now()->subHours(48))
    ->where('ativa', true)
    ->update(['ativa' => false]);
```

### Vincular provedor a conta existente

Rotas de **vínculo** são diferentes das de **login**: operam sobre o usuário já
autenticado (`auth:sanctum`) e **não emitem token** (usar a rota de login aqui
trocaria a sessão).

```
POST /api/perfil/vincular/apple    { identityToken }
POST /api/perfil/vincular/google   { idToken }
```

Dois cuidados:
- **Recusar** (422) se aquele `apple_id`/`google_id` já pertence a outro usuário.
- **Não deixar remover o último método de acesso** de quem não tem senha — a
  pessoa fica trancada fora da conta. Conte os métodos (senha + provedores) e
  só libere se sobrar pelo menos um. Coloque essa regra numa Policy
  (`UserPolicy::unlinkProvider`) para ela valer em qualquer rota.

---

## App (Capacitor + front Blade/Alpine)

O app é um casco Capacitor que carrega o site Laravel (Blade + Alpine.js, bundle
via Vite). O código que fala com os plugins nativos fica num módulo ES em
`resources/js/native/` importado pelo `resources/js/app.js` e exposto ao Alpine
como store (`Alpine.store('nativo', ...)`).

### Armadilhas que custam horas

**1. O Proxy do `registerPlugin`**

```js
// ERRADO — o await procura .then() no Proxy e a bridge responde
// "FirebaseAuthentication.then() is not implemented on ios"
const modulo = await import('@capacitor-firebase/authentication')
return modulo.FirebaseAuthentication

// CERTO — devolve o módulo, mantém o Proxy fora do caminho do await
return modulo.FirebaseAuthentication ? modulo : null
// e depois: plugin.FirebaseAuthentication.signInWithApple(...)
```

**2. Proxy reativo do Alpine na bridge**

O Alpine usa o motor de reatividade do Vue: objeto vindo de `x-data` ou de
`Alpine.store()` chega ao SDK como Proxy e o plugin recusa com
`"Must provide aPackage parameter"`. Use `Alpine.raw(obj)` (ou
`structuredClone(Alpine.raw(obj))`) antes de passar.

**3. `Purchases.logIn` no boot**

O SDK volta a **anônimo a cada abertura da WebView**. Sem chamar `logIn` no boot
(não só no login), uma compra feita depois de reabrir o app cai num `app_user_id`
anônimo e o webhook nunca acha a conta. `logOut` no logout, senão a próxima
conta no aparelho herda os entitlements.

**4. Preço SEMPRE do StoreKit**

`product.priceString` — moeda e formatação da loja. Nunca hardcode. Preço em
real hardcoded no app iOS é o que dispara a 3.1.1.

**5. Espera do webhook**

A liberação é assíncrona. Faça polling do perfil (ex.: 12× a cada 2.5s) com
mensagem honesta. Três desfechos distintos: sucesso, "pago mas webhook atrasou"
(aponte pro Restaurar), e pendente (Ask to Buy — não libera nada).

**6. Cancelamento pelo usuário não é erro**

`purchasePackage` rejeita quando a pessoa cancela. **Não mostre alerta de falha**
— a Apple e o usuário odeiam isso.

### Exigências da Apple na tela de compra

- **Botão "Restaurar compras"** — obrigatório. Ausência é causa conhecida de
  rejeição. Pode ser discreto (link no rodapé), mas tem que existir e funcionar.
- **Divulgação de renovação automática**: duração, preço, que renova até
  cancelar, e onde gerenciar (Ajustes > Apple ID > Assinaturas).
- **Links de Termos de Uso e Política de Privacidade**.
- **Sign in with Apple** obrigatório se houver outro login social, com destaque
  igual ou maior. Botão seguindo a HIG (preto ou branco, logo Apple).

### O que NÃO pode existir no app iOS

- Link/botão que leve a checkout web ou site de vendas
- Preço em moeda local hardcoded
- Produto avulso que não existe na App Store (ex.: ingresso de evento vendido
  só no site) — mantenha web-only mesmo com IAP ativo
- Texto do CMS que direcione a pagamento externo. Filtre por domínio, URL solta
  (regex), `pix`, `boleto`, `R$`. Distinga: *mencionar assinatura* é OK quando
  existe IAP; *direcionar pra fora* nunca é.

Centralize num store Alpine (`Alpine.store('nativo')` com `mostrarCompra` /
`ehAppNativo` / `podeComprar`) e condicione tudo a `ehNativo()` — a experiência
web não pode mudar. Preço e link de checkout que **não podem** existir no iOS
devem ficar fora do HTML servido ao app (componente Blade renderizado só na
web, ou carregado por `fetch` depois da checagem) — `x-show` só esconde, o
HTML continua no DOM.

---

## Casco iOS

### Facebook SDK: remover exige patch

Todos os plugins de login social declaram `facebook-ios-sdk` no `Package.swift`
**incondicionalmente**. O `providers: [...]` da config **não impede a
compilação** (só o carregamento em runtime) e o `packageOptions` do Capacitor CLI
não filtra produtos.

Sem remover, o binário embarca `FBSDKCoreKit`, `FBSDKLoginKit`,
`FBSDKCoreKit_Basics` e `FBAEMKit` — SDK de rastreamento da Meta que você não
usa e vai ter que declarar no App Privacy.

```bash
# patch-package NÃO funciona com pnpm ("No package-lock.json ... file")
pnpm patch @capacitor-firebase/authentication
# editar o Package.swift: remover o .package do facebook, os 2 .product
# (FacebookCore/FacebookLogin) e o .define("RGCFA_INCLUDE_FACEBOOK")
pnpm patch-commit <dir-temporario>
```

O patch fica em `patches/` e o registro em `pnpm-workspace.yaml`
(`patchedDependencies`) — **não** precisa de `postinstall`: o próprio instalador
do pnpm reaplica.

O código Swift do Facebook costuma estar todo guardado por `#if`, então remover
a flag basta; referências sem guarda ainda compilam porque a classe existe com
o corpo vazio.

**Bug do pnpm com acento no caminho:** se o diretório tiver caracteres não-ASCII
(ex.: "Repositórios" em NFD), o `patch-commit` gera diff inválido
(`Bad diff line`). Normalize os headers para `a/Package.swift` / `b/Package.swift`.

### Verificar que os plugins entraram — do jeito certo

⚠️ Em build **Debug**, `App.app/App` é um **stub de ~90K**; o código real está
em `App.app/App.debug.dylib` ao lado. Conferir símbolos no stub leva à conclusão
errada de que nada foi linkado.

```bash
# Debug: olhe o dylib
nm App.app/App.debug.dylib | grep -c FirebaseAuthenticationPlugin

# Release: o binário é o próprio App (dezenas de MB)
nm App.app/App | grep -c GIDSignIn

# Facebook fora (deve vir vazio)
ls App.app/Frameworks/ | grep -i -E "fbsdk|facebook|fbaem"
grep -i facebook ios/App/CapApp-SPM/Package.resolved
```

Frameworks embarcados só com `Capacitor` e `Cordova` é **normal** — as demais
deps são bibliotecas estáticas linkadas dentro do binário.

### Entitlements e AppDelegate

- `com.apple.developer.applesignin` = `[Default]`
- ⚠️ Se existir `ios-templates/aplicar.sh` (ou similar) que sobrescreve
  `AppDelegate.swift` e `App.entitlements`, **aplique nos DOIS arquivos** —
  senão a mudança some no próximo build de dev. Mantenha o template sincronizado.
- Google Sign-In precisa de `CFBundleURLTypes` com o `REVERSED_CLIENT_ID`
  (leia do `GoogleService-Info.plist`, não invente placeholder) e, no
  `AppDelegate`, `Auth.auth().canHandle(url)` **antes** do proxy do Capacitor —
  depois dele o callback vira deep link comum e o login não conclui.
- O `GoogleService-Info.plist` gerado só pro FCM **não tem** `CLIENT_ID` nem
  `REVERSED_CLIENT_ID`. Habilite o Google no Firebase Console e baixe de novo.

### Efeito colateral comum

O plugin de auth pode exigir Firebase SDK mais novo que o do FCM (11 → 12 no
EduSites). **Teste push em device real** depois — as APIs de `Messaging`
costumam ser estáveis, mas push é caminho crítico.

---

## Configuração externa (ordem importa)

### 1. Apple Developer Portal

1. App ID → habilitar **Sign in with Apple** e **In-App Purchase**
   - Na tela do Sign in with Apple: "Enable as a primary App ID";
     "No Primary App ID is available" **não é erro**;
     Server-to-Server Notification Endpoint pode ficar vazio
2. **Profiles**: se o projeto usa assinatura automática
   (`-allowProvisioningUpdates`), a lista fica **vazia** e não há nada a regerar
   — o Xcode gerencia. **Não crie perfil manual**, conflita.
3. **Services ID** (só pro Sign in with Apple na web): Domain e Return URL
   **idênticos** ao que o backend usa, sem barra final
4. **Key `.p8`** com Sign in with Apple → baixa **uma vez só**, anote o Key ID

### 2. App Store Connect

1. **Paid Apps Agreement** (Negócios → Contratos). ⚠️ **Trava tudo**: sem ele o
   IAP não aparece nem em sandbox. Exige atualizar a **pessoa jurídica** antes
   (CNPJ, representante legal, dados bancários e fiscais).
2. **Subscription Group** + os produtos, todos no **mesmo grupo** (é o que
   permite upgrade/downgrade)
3. Em cada produto: **Localização** (nome + descrição, limites de 35 e 55 chars)
   e **Informações para a equipe de revisão** com **captura de tela** — sem isso
   fica "Missing Metadata" e não entra na submissão
4. **In-App Purchase Key** (`.p8`): Usuários e acesso → Integrações →
   **Compras dentro do app**. ⚠️ **NÃO** é a mesma coisa que a API Key do
   App Store Connect (essa é pra upload de build). São duas chaves diferentes.
5. **Segredo compartilhado** (mesma seção) — necessário se o deployment target
   for iOS 15 ou inferior
6. **Sandbox tester** (Usuários e acesso → Sandbox): crie com e-mail que não
   seja seu Apple ID, e **ajuste a região** — a moeda do StoreKit segue a região
   da conta, não a do aparelho

### 3. RevenueCat

1. Projeto → **New app configuration** → App Store (não use a "Test Store")
2. **In-App Purchase Key** (`.p8` + Key ID) e **App Store Connect API Key**
   (`.p8` diferente, formato `AuthKey_*.p8`) — a segunda é o que permite
   importar produtos
3. **Products** → importar da loja
4. **Entitlement** (ex.: `assinatura`) com os dois produtos
5. **Offering** marcado como **Current** ⚠️ — é daqui que o app lê. Packages com
   identifier `Monthly`/`Annual` (o SDK usa isso pra `pricePerMonthString`)
6. **Webhook** com URL do seu backend + Authorization header (token forte,
   ≥32 bytes)
7. **API key pública** (`appl_...`) → vai no app
8. Cole a **Apple Server Notification URL** do RevenueCat no App Store Connect
   (Informações do app → App Store Server Notifications), produção e sandbox

### 4. Firebase Console (se usar Firebase Auth)

Authentication → Sign-in method → habilitar **Google** e **Apple**. Depois baixe
o `GoogleService-Info.plist` novo.

Para o Apple, o Firebase pede Services ID — mas **é opcional se o login Apple
for nativo** (`ASAuthorization`), que não passa por OAuth web.

---

## Submissão

A Apple exige o pacote **completo num único envio**: versão do app + grupo de
assinaturas + os produtos. Mensagens que você vai ver:

- *"adicione uma versão do app para a plataforma selecionada"* → falta o build
- *"Novos grupos de assinatura devem ser enviados com uma assinatura"* → o grupo
  não pode ir sozinho
- Se o envio antigo (rejeitado) ainda contém a versão, **remova-a de lá** antes
  de adicionar ao rascunho novo

O painel tem que mostrar os **4 itens** antes do botão habilitar.

**Teste em sandbox ou TestFlight antes de submeter.** Compra em produção só
funciona depois do app aprovado — os produtos não ficam ativos antes.

---

## Segurança — o que auditar

Achados reais da auditoria pré-deploy do EduSites:

- **Rate limiting nas rotas de auth** (o ratelimit global de proxy é por IP e
  não distingue login de listagem — não segura brute force). Em Laravel:
  `RateLimiter::for('auth', ...)` + `->middleware('throttle:auth')`
- **Token de sessão na query string** do redirect de login social — vaza em log
  de acesso, histórico e `Referer`. Prefira cookie `HttpOnly` ou código de uso
  único
- **`state` anti-CSRF**: no fluxo `form_post` o front nunca vê o `state` de
  volta (a Apple entrega no backend), então validação no front é só defesa em
  profundidade. Para fechar login-CSRF, o backend precisa gerar e conferir
  (ex.: `Str::random(40)` guardado em `Cache::put("apple:state:{$state}", ..., 600)`
  e consumido com `Cache::pull`)
- **Sanitizar o `nome`** vindo do provedor: diferente do e-mail, ele **não vem
  assinado** no token (a Apple manda `user` como campo solto do form_post).
  Valide no FormRequest (`string|max:120`) e exiba sempre com `{{ }}`, nunca
  `{!! !!}`

---

## Checklist antes de submeter

- [ ] Backend deployado **antes** do app (se for wrapper de URL, o revisor vê o
      site de produção)
- [ ] Webhook respondendo em produção (teste: POST sem token deve dar 401)
- [ ] Envs do IAP e do Apple no stack de produção, não só no `.env` local
- [ ] Reverter ajustes de teste local: `server.url`, `cleartext`, ATS, URLs da
      API no front
- [ ] Build number acima do já revisado
- [ ] Auditar o archive: sem ATS, sem IP local, `server.url` de produção
- [ ] Produtos sem "Missing Metadata"
- [ ] Conta de teste com assinatura ativa
- [ ] Compra testada em sandbox/TestFlight de ponta a ponta
- [ ] Notas de revisão explicando o que mudou desde a rejeição
