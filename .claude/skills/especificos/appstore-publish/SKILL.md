---
name: appstore-publish
description: Publica apps iOS (Capacitor/Tauri/nativo) na App Store do zero ao envio — build, archive, export, upload via altool e manipulação da submissão pela API do App Store Connect (trocar build, ler estado, gravar notas de revisão). Cobre também o teste local no iPhone físico (server.url + ATS), a implementação completa de In-App Purchase com RevenueCat/StoreKit e de Sign in with Apple + Google nativo (ver IAP-E-LOGIN-SOCIAL.md), e o combate às rejeições mais comuns — com foco na 3.1.1. Use SEMPRE que o usuário pedir "sobe o app pra App Store", "manda o build pro TestFlight", "a Apple rejeitou o app", "responde a Apple", "roda o app no meu iphone", "troca o build selecionado", "exporta o ipa", "implementa compra no app", "assinatura dentro do app", "login com apple", "in-app purchase", "revenuecat", "storekit", ou enviar carta de rejeição do App Review.
---

# Publicar app iOS na App Store

Fluxo completo validado em produção (EduSites, 2026-07). Cobre desde rodar o app local no iPhone físico até enviar o build e manipular a submissão pela API quando a interface web travar.

> **In-App Purchase e login social nativo:** leia **`IAP-E-LOGIN-SOCIAL.md`**
> nesta mesma pasta. Guia completo de assinatura por RevenueCat/StoreKit e de
> Sign in with Apple + Google num app Capacitor — backend, app, casco iOS,
> configuração nos painéis e as armadilhas que custam horas (Proxy do
> `registerPlugin`, `Alpine.raw` na bridge, client id do iOS no `aud`, Facebook SDK
> embarcado, stub de 90K em build Debug). Consulte ANTES de começar qualquer
> implementação de compra ou login social — vários desses erros não dão mensagem
> clara e levam a diagnósticos errados.

## Credenciais

**API Key do App Store Connect** (`~/.credenciais/apple/AuthKey_<KEY_ID>.p8`) — permite upload e manipulação da submissão sem senha interativa.

Precisa de TRÊS coisas juntas; a chave sozinha dá 401:
- o arquivo `.p8`
- o **Key ID** (está no nome do arquivo: `AuthKey_6N66AJ7YYY.p8` → `6N66AJ7YYY`)
- o **Issuer ID** — UUID que existe SÓ no App Store Connect: **Usuários e acesso → Integrações/Chaves**, no topo da página. Não está em nenhum arquivo local; peça ao usuário.

Nunca copie o `.p8` para fora de `~/.credenciais/apple/` de forma permanente. Se copiar para `~/.appstoreconnect/private_keys/` durante um teste, remova depois.

## Gerar JWT para a API do App Store Connect

O `altool` aceita `--apiKey`/`--apiIssuer` direto, mas a **API REST** exige JWT ES256 assinado. Node não produz o formato certo por padrão: `crypto.sign` devolve DER e o ES256 exige `r|s` cru. Sem essa conversão, todo request volta 401.

```js
// jwt.mjs — gera token válido por 10 min
import { readFileSync } from 'fs'
import crypto from 'crypto'
const KEY_ID = '<KEY_ID>', ISS = '<ISSUER_ID>'
const pk = readFileSync(process.env.HOME + `/.credenciais/apple/AuthKey_${KEY_ID}.p8`, 'utf8')
const now = Math.floor(Date.now() / 1000)
const b64 = o => Buffer.from(JSON.stringify(o)).toString('base64url')
const head = b64({ alg: 'ES256', kid: KEY_ID, typ: 'JWT' })
const body = b64({ iss: ISS, iat: now, exp: now + 600, aud: 'appstoreconnect-v1' })
const sig = crypto.sign('sha256', Buffer.from(head + '.' + body), { key: pk, dsaEncoding: 'der' })
function derToRaw (der) {
  const b = [...der]; let i = 2
  if (b[1] & 0x80) i = 2 + (b[1] & 0x7f)
  i++; const rl = b[i++]; let r = b.slice(i, i + rl); i += rl
  i++; const sl = b[i++]; let s = b.slice(i, i + sl)
  const p = a => { while (a.length > 32 && a[0] === 0) a = a.slice(1); while (a.length < 32) a = [0, ...a]; return a }
  return Buffer.from([...p(r), ...p(s)])
}
console.log(head + '.' + body + '.' + derToRaw(sig).toString('base64url'))
```

Uso: `T=$(node jwt.mjs) && curl -s -H "Authorization: Bearer $T" "<endpoint>"`

Salve a resposta em arquivo (`-o resp.json`) e leia com `node -e`. Fazer pipe do curl direto pro `node -e` com JSON.parse quebra em erro de API.

## Rodar o app local no iPhone físico

Para testar antes de submeter. **Tudo aqui é temporário e precisa ser revertido antes do build de produção.**

### 1. Descobrir o dispositivo — dois IDs diferentes!

```bash
xcrun devicectl list devices          # UUID do CoreDevice (para install/launch)
xcrun xctrace list devices            # UDID de hardware (para xcodebuild -destination)
```

**Pegadinha:** `xcodebuild -destination 'id=...'` exige o **UDID de hardware** (`00008120-001E...`), não o UUID do devicectl. Passar o errado faz o xcodebuild listar só simuladores — parece que o iPhone não está conectado.

`devicectl` mostrar `available (paired)` com `state: disconnected` significa que o pareamento está registrado mas não há conexão ativa. Confirme com `system_profiler SPUSBDataType | grep -i iphone` antes de concluir que o cabo está fora — e prefira `xctrace`, que só lista o que está de fato acessível.

### 2. Apontar o app e a API para o IP da máquina

`localhost` dentro do WebView é o **próprio celular**, não o Mac. Login falha sem erro claro.

```bash
ipconfig getifaddr en0   # ex: 192.168.1.20
```

- `capacitor.config.json`: `server.url` → `http://<IP>:<porta>`, adicionar `"cleartext": true` e o IP em `allowNavigation`
- `ios/App/App/Info.plist`: adicionar exceção de ATS (iOS bloqueia `http://` sem isso)
  ```xml
  <key>NSAppTransportSecurity</key>
  <dict><key>NSAllowsArbitraryLoads</key><true/></dict>
  ```
- Front que chama a API: trocar `localhost` pelo IP. **No Laravel a URL vem de dois lugares**: `APP_URL` no `.env` (usado por `url()`/`route()`/`asset()` no Blade) e `VITE_*` (ex.: `VITE_REVERB_HOST` do websocket, embutido no bundle em build) — trocar só um faz o login funcionar e o chat não conectar. Depois de mudar `VITE_*`, rode `npm run build` de novo; depois de mudar `.env`, `php artisan config:clear`.
- Backend: precisa escutar em `*:porta` (não só localhost — `php artisan serve --host=0.0.0.0 --port=<porta>`) e o CORS liberar faixa privada (`config/cors.php` → `allowed_origins_patterns`). Valide antes de culpar o app:
  ```bash
  curl -s -i -X OPTIONS "http://<IP>:<porta>/rota" -H "Origin: http://<IP>:<portaFront>" \
    -H "Access-Control-Request-Method: POST" | grep -iE "^HTTP|access-control"
  ```
  204 + `Access-Control-Allow-Origin` = CORS ok.

### 3. Propagar, compilar e instalar

```bash
pnpm exec cap copy ios   # OBRIGATÓRIO: o config EMBARCADO em ios/App/App/ é quem manda em runtime
cd ios/App
xcodebuild -project App.xcodeproj -scheme App -configuration Debug \
  -destination 'id=<UDID_HARDWARE>' -derivedDataPath <dir> -allowProvisioningUpdates build
xcrun devicectl device install app --device <UUID_DEVICECTL> "<dir>/Build/Products/Debug-iphoneos/App.app"
xcrun devicectl device process launch --device <UUID_DEVICECTL> --terminate-existing <bundleId>
```

Projetos Capacitor 8 usam **SPM**, não CocoaPods — use `-project App.xcodeproj`, não `-workspace`.

### 4. Reverter TUDO antes de submeter

Faça backup do config de produção ANTES de alterar (`cp capacitor.config.json .../scratchpad/capacitor.config.PROD.json`). Reverta: `server.url`, `cleartext`, ATS, URLs do front. Rode `cap copy ios` de novo e **verifique o config embarcado**.

## Build de produção e envio

### 1. Bump do build number

A App Store **recusa upload com número de build já usado**. Confira qual o revisor viu e suba acima.

```bash
sed -i '' 's/CURRENT_PROJECT_VERSION = N;/CURRENT_PROJECT_VERSION = N+1;/g' App.xcodeproj/project.pbxproj
```

### 2. Archive

```bash
cd ios/App
xcodebuild -project App.xcodeproj -scheme App -configuration Release \
  -destination 'generic/platform=iOS' -archivePath <dir>/App.xcarchive \
  -allowProvisioningUpdates archive
```

### 3. Auditar o archive ANTES de exportar

```bash
A=<dir>/App.xcarchive/Products/Applications/App.app
/usr/libexec/PlistBuddy -c "Print CFBundleShortVersionString" -c "Print CFBundleVersion" "$A/Info.plist"
/usr/libexec/PlistBuddy -c "Print NSAppTransportSecurity" "$A/Info.plist"   # deve dar "Does Not Exist"
grep -A4 '"server"' "$A/capacitor.config.json"                              # deve ser https de produção
grep -rl "192.168" "$A"                                                     # deve vir vazio
```

### 4. Export

O **Team ID do archive** pode diferir do da assinatura de desenvolvimento. Leia, não presuma:

```bash
/usr/libexec/PlistBuddy -c "Print ApplicationProperties:Team" <dir>/App.xcarchive/Info.plist
```

ExportOptions.plist:
```xml
<dict>
  <key>method</key><string>app-store-connect</string>
  <key>destination</key><string>export</string>
  <key>teamID</key><string>&lt;TEAM_ID_DO_ARCHIVE&gt;</string>
  <key>uploadSymbols</key><true/>
  <key>manageAppVersionAndBuildNumber</key><false/>
</dict>
```

```bash
xcodebuild -exportArchive -archivePath <dir>/App.xcarchive -exportPath <dir>/ipa \
  -exportOptionsPlist <dir>/ExportOptions.plist -allowProvisioningUpdates
```

### 5. Validar e enviar

```bash
xcrun altool --validate-app -f <dir>/ipa/App.ipa -t ios --apiKey <KEY_ID> --apiIssuer <ISSUER_ID>
xcrun altool --upload-app   -f <dir>/ipa/App.ipa -t ios --apiKey <KEY_ID> --apiIssuer <ISSUER_ID>
```

Sempre valide primeiro. Processamento leva 10-30 min após `UPLOAD SUCCEEDED`.

## Manipular a submissão pela API (quando a web travar)

Estados de versão bloqueiam edição na interface. Se o usuário não acha o botão de trocar build, **provavelmente não existe** — cheque o estado antes de mandar procurar.

```bash
# App ID: aparece na URL do App Store Connect (/apps/<APP_ID>/...)

# Estado da versão
curl -s -H "Authorization: Bearer $T" \
  "https://api.appstoreconnect.apple.com/v1/apps/<APP_ID>/appStoreVersions?limit=3" -o v.json

# Builds e seus ids
curl -s -H "Authorization: Bearer $T" \
  "https://api.appstoreconnect.apple.com/v1/builds?filter%5Bapp%5D=<APP_ID>&limit=5&sort=-uploadedDate" -o b.json

# Build atualmente selecionado
curl -s -H "Authorization: Bearer $T" \
  "https://api.appstoreconnect.apple.com/v1/appStoreVersions/<VERSION_ID>/build" -o sel.json

# TROCAR o build (funciona mesmo com a web bloqueada) -> HTTP 204
curl -s -X PATCH -H "Authorization: Bearer $T" -H "Content-Type: application/json" \
  "https://api.appstoreconnect.apple.com/v1/appStoreVersions/<VERSION_ID>/relationships/build" \
  -d '{"data":{"type":"builds","id":"<BUILD_ID>"}}' -w "\nHTTP %{http_code}\n"
```

Estados e o que significam:
- `DEVELOPER_REJECTED` — retirado da fila pelo dev (ou após rejeição). **Interface web NÃO deixa trocar build**; use a API.
- `WAITING_FOR_REVIEW` — na fila. Campo de resposta do envio anterior fecha.
- `IN_REVIEW`, `PENDING_DEVELOPER_RELEASE`, `READY_FOR_SALE`, `REJECTED`, `METADATA_REJECTED`

`processingState: VALID` num build = processado e pronto para seleção.

## Rejeições comuns

> Para **interpretar uma carta de rejeição**, auditar metadados/paywall antes do envio ou conduzir o reenvio, use o agent **`revisor-apple`** — ele tem o catálogo completo (3.1.2, cascata de IAP, exigências de descrição de assinatura, fluxo do App Store Connect) e o histórico dos apps do Eduardo. Esta seção cobre só o essencial da 3.1.1.

### 3.1.1 — In-App Purchase (a mais comum em app que embrulha SaaS pago)

Texto típico: *"the app accesses digital content purchased outside the app, such as subscription, but that content isn't available to purchase using In-App Purchase."*

**Entenda a regra antes de agir:** o problema não é *mostrar* preço — é o app **dar acesso a conteúdo pago** sem vender por IAP. Esconder botões não muda esse fato.

Só existem dois caminhos legítimos:
1. **Implementar IAP** (StoreKit) — resolve com certeza, custa 15-30% de comissão sobre vendas via iOS. A 3.1.3(b) permite explicitamente que o usuário acesse no app o que comprou fora, desde que também possa comprar dentro.
2. **Reader app (3.1.3(a))** — sem nenhuma compra no app. Restrito a revista, jornal, livro, áudio, música e **vídeo**. **Não é auto-declarável**: a Apple concede. Comunidade/chat, IA, quizzes e gamificação pesam CONTRA o encaixe, porque descaracterizam "consumo passivo".

O parágrafo sobre "link out to the default browser" vale **só para a storefront dos EUA**. Fora dos EUA, link externo de pagamento é violação.

**Se for pela via reader app, o app nativo precisa ficar sem:**
- links/botões que levem ao site de vendas (é o bloqueador mais objetivo)
- páginas de plano/preço acessíveis
- preços e URLs de checkout **no bundle** (esconder com `x-show`/`x-if` não basta — o HTML/JS é servido; não renderize no Blade quando for app nativo)
- verbos de compra ("assine", "adquira", "já assinei") → linguagem neutra de disponibilidade
- tela que prenda usuário sem acesso num paywall
- tela de login com "não tem conta? assine em ..."

**Padrão em Laravel (Blade + Alpine)/Capacitor** — decida no **servidor**, assim o conteúdo de venda nem entra no HTML servido ao app. Marque o WebView com um user agent próprio e leia no Laravel:

```json
// capacitor.config.json
{ "ios": { "appendUserAgent": "EduSitesApp/iOS" } }
```

```php
// app/Http/Middleware/DetectNativeApp.php — registrado no grupo web em bootstrap/app.php
public function handle(Request $request, Closure $next): Response
{
    $nativo = str_contains((string) $request->userAgent(), 'EduSitesApp/iOS');
    $request->attributes->set('app_nativo', $nativo);
    View::share('appNativo', $nativo);

    return $next($request);
}
```

```blade
{{-- resources/views/components/cta-compra.blade.php --}}
@unless($appNativo)
    <a href="{{ route('checkout') }}" class="btn-primario">Assinar por {{ $preco }}</a>
@endunless
```

Rotas de checkout/planos também bloqueiam no servidor (`abort_if($request->attributes->get('app_nativo'), 404)`), não só escondem o link. Para o que depende do runtime do Capacitor (plugins), use um store Alpine alimentado por `window.Capacitor?.isNativePlatform()`:
```js
// resources/js/native/store.js — importado por resources/js/app.js antes de Alpine.start()
// Capacitor injeta window.Capacitor antes do bundle rodar, então o valor já está pronto.
Alpine.store('nativo', {
  ehAppNativo: !!window.Capacitor?.isNativePlatform?.(),
  get mostrarCompra() { return !this.ehAppNativo },
})
```
`x-show="$store.nativo.mostrarCompra"` só esconde — o HTML continua no DOM e o revisor pode achar. Tudo que **não pode existir** no app tem que sair pelo `@unless($appNativo)` do Blade; o store Alpine é só para comportamento.

**A experiência web não pode mudar.** Se algo não separa nativo de web, não faça e reporte.

### Checklist antes de reenviar

1. **Deploy do site em produção.** Wrapper de URL carrega o site em runtime — sem deploy o revisor vê a versão antiga. Confirme com grep num chunk servido:
   ```bash
   H=$(curl -s https://<dominio>/); for c in $(echo "$H" | grep -oE '/build/assets/[A-Za-z0-9._-]+\.js' | sort -u | head -6); do
     curl -s "https://<dominio>$c" | grep -qE "<simboloNovo>" && echo "ACHOU em $c"; done
   ```
2. **Conta de teste com acesso ativo.** Se o revisor logar e vir "nenhum conteúdo disponível", reprova por outro motivo sem avaliar o argumento. Confirme no banco antes.
3. **Screenshots sem preço nem "Assinar"** — o revisor vê antes de abrir o app; contradizem o argumento de reader app.
4. **Build number acima do já revisado.**
5. **Notas de revisão preenchidas.**

### Onde escrever para o revisor

- **Resolution Center** — responder a mensagem do envio. **Fecha** quando a submissão entra em `WAITING_FOR_REVIEW`.
- **App Review Information → Notes** — editável mesmo na fila, e o revisor lê antes de abrir o app. **É o caminho mais confiável.**
- **Contact Us** (developer.apple.com/contact → App Review) — referencie o Submission ID.

Ordem correta: responder **antes** de enviar para revisão. Depois de enviado, só as Notes.

### Prazos

Primeira revisão: 24h em 90% dos casos, na prática 1-3 dias. Reenvio após rejeição costuma ser similar. Casos que exigem julgamento (como reader app) podem escalar e demorar mais. Acima de 5 dias sem mudança, acione o Contact Us.

## Regras

- Nunca prometa aprovação. Esconder elementos de compra remove a evidência, não muda a natureza do app. Diga a probabilidade honestamente e siga a decisão do usuário sem repetir o alerta.
- Não redija resposta oferecendo IAP como alternativa se o usuário não quer IAP — afirme o enquadramento em reader app ("the app qualifies as"), não peça ("we would like").
- Textos para colar em formulário web: mande sem blockquote/markdown — a formatação suja o copiar-colar.
- Leia o Team ID, o build number e o config embarcado em vez de presumir.
- Nunca envie sem antes auditar o archive contra vestígios de teste local.
