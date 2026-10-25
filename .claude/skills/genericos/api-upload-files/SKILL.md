---
name: api-upload-files
description: Sobe arquivos (imagens ou arquivos genéricos) pro DigitalOcean Spaces usando o endpoint de storage da API Laravel do projeto e retorna as URLs públicas no CDN. Detecta o app Laravel (pasta com `artisan`), garante o `vendor/`, sobe `php artisan serve` numa porta livre se a API não estiver no ar, gera um token Sanctum temporário (ability `storage:upload`) e faz o upload via POST /api/storage/imagem|arquivo. Use SEMPRE que o usuário pedir "sobe essa imagem", "faz upload pra pegar a URL", "manda pro CDN/Spaces", "sobe com a api de upload", "preciso da URL dessa imagem", "upload desse arquivo", ou enviar imagens/arquivos pedindo a URL pública — em qualquer projeto Laravel que exponha o endpoint de storage.
---

# api-upload-files

Sobe arquivos para o **DigitalOcean Spaces** através do **endpoint de storage da API Laravel** do projeto e devolve as **URLs públicas** (CDN). Funciona em qualquer app Laravel que siga o padrão abaixo (rotas `POST /api/storage/imagem` e `POST /api/storage/arquivo`, auth via **Sanctum** no header `Authorization: Bearer <token>`).

## Quando usar

- "sobe essa imagem", "faz o upload", "preciso da URL dessa imagem/arquivo"
- "manda pro Spaces / pro CDN", "sobe com a api de upload"
- Qualquer fluxo onde você precisa de uma URL pública pra usar em `imagem_capa`, capa de oferta, asset de página etc.
- Vale pra qualquer projeto Laravel — só muda o caminho do app (a skill recebe via `--app-dir`).

## Como funciona (o que a skill automatiza)

O endpoint **não aceita token estático compartilhado** — cada chamada usa um **personal access token do Sanctum** com ability `storage:upload` e expiração curta. O controller converte imagens pra **.webp** automaticamente. O script `upload.sh` faz tudo:

1. Localiza o app Laravel (recebe via `--app-dir`, precisa ter `artisan`).
2. Garante `vendor/` (roda `composer install` se faltar).
3. Se a API não responder em `APP_URL/up` (health check nativo do Laravel 11+), sobe `php artisan serve` numa **porta livre** (evita conflito com a porta fixa do projeto que pode estar ocupada por outra coisa).
4. Gera um token Sanctum de 15 min via `php artisan tinker --execute` para o usuário de `--email` (ou `UPLOAD_USER_EMAIL` do `.env`) — ou usa `$UPLOAD_TOKEN` se você já tiver um. O token **nunca é impresso**.
5. Faz `POST /api/storage/imagem` (ou `/arquivo`) com `multipart/form-data`, campo **`file`**, header `Accept: application/json`.
6. Derruba o `artisan serve` que subiu e imprime JSON com as URLs.

## Fluxo de execução

### Passo 1 — Descobrir o caminho do app Laravel

Tipicamente: `<raiz-do-projeto>/backend/api` (API separada) ou a própria raiz do monólito. Confirme com `ls <caminho>/artisan` e `php artisan route:list --path=api/storage` (as duas rotas precisam aparecer). Se não existirem, implemente o endpoint conforme a seção **Endpoint esperado** (use o agent **especialista-laravel**).

### Passo 2 — Rodar o script

```bash
~/.claude/skills/api-upload-files/upload.sh \
  --app-dir <caminho/app-laravel> \
  --type imagem \
  --email admin@projeto.com \
  <arquivo1> [arquivo2 ...]
```

- `--type imagem` (default) → `POST /api/storage/imagem`, vira `.webp`. Mimetypes: png, jpg, jpeg, webp, svg, ico.
- `--type arquivo` → `POST /api/storage/arquivo` (PDF, zip, etc., limite 500 MB).
- `--max-width 1600` → (só imagem) redimensiona mantendo proporção antes de converter (campo `maxWidth`).
- `--email` → usuário dono do token (precisa existir e o model `User` usar `HasApiTokens`). Alternativa: `UPLOAD_USER_EMAIL=` no `.env` ou `UPLOAD_TOKEN=<token>` no ambiente.
- Aceita **vários arquivos** de uma vez (sobe todos com o mesmo token).

Imagens enviadas pelo usuário no chat ficam em `~/.claude/image-cache/<session>/<n>.png` — use esse caminho como input.

### Passo 3 — Ler a saída

O script imprime JSON:
```json
{
  "results": [
    { "input": ".../11.png", "nome": "11.png", "peso": "29.62 KB",
      "url": "https://projeto-assets.nyc3.cdn.digitaloceanspaces.com/imagens/<uuid>-11.webp" }
  ],
  "errors": []
}
```

Use o campo `url` de cada resultado. Reporte as URLs ao usuário (ou já aplique onde ele pediu — capa de produto/oferta, etc.).

## Endpoint esperado (referência Laravel)

### Config do disco (`config/filesystems.php` + `.env`)

DigitalOcean Spaces é compatível com S3 — usa o driver `s3` (`composer require league/flysystem-aws-s3-v3 "^3.0"`):

```php
's3' => [
    'driver' => 's3',
    'key' => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => env('AWS_DEFAULT_REGION', 'nyc3'),
    'bucket' => env('AWS_BUCKET'),
    'url' => env('AWS_URL'),                    // https://<bucket>.nyc3.cdn.digitaloceanspaces.com
    'endpoint' => env('AWS_ENDPOINT'),          // https://nyc3.digitaloceanspaces.com
    'use_path_style_endpoint' => false,
    'visibility' => 'public',
    'throw' => true,
],
```

### Rotas (`routes/api.php`)

```php
use App\Http\Controllers\Api\StorageController;

Route::middleware(['auth:sanctum', 'abilities:storage:upload', 'throttle:uploads'])
    ->prefix('storage')
    ->controller(StorageController::class)
    ->group(function () {
        Route::post('imagem', 'imagem')->name('storage.imagem');
        Route::post('arquivo', 'arquivo')->name('storage.arquivo');
    });
```

O middleware `abilities` precisa estar registrado em `bootstrap/app.php` (`$middleware->alias(['abilities' => CheckAbilities::class])`) e o limiter em `AppServiceProvider::boot()` (`RateLimiter::for('uploads', fn ($r) => Limit::perMinute(30)->by($r->user()->id))`).

### Validação (`app/Http/Requests/Storage/UploadImagemRequest.php`)

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Storage;

use Illuminate\Foundation\Http\FormRequest;

class UploadImagemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->tokenCan('storage:upload') ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,svg,ico', 'max:20480'],
            'maxWidth' => ['nullable', 'integer', 'min:100', 'max:4000'],
        ];
    }
}
```

`UploadArquivoRequest` é igual, com `'file' => ['required', 'file', 'max:512000']` (500 MB — ajuste também `upload_max_filesize`/`post_max_size` do PHP e o `client_max_body_size` do nginx).

### Controller (`app/Http/Controllers/Api/StorageController.php`)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Storage\EnviarArquivoParaSpaces;
use App\Http\Controllers\Controller;
use App\Http\Requests\Storage\UploadArquivoRequest;
use App\Http\Requests\Storage\UploadImagemRequest;
use Illuminate\Http\JsonResponse;

class StorageController extends Controller
{
    public function imagem(UploadImagemRequest $request, EnviarArquivoParaSpaces $enviar): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'body' => $enviar->imagem($request->file('file'), $request->integer('maxWidth') ?: null),
        ], 201);
    }

    public function arquivo(UploadArquivoRequest $request, EnviarArquivoParaSpaces $enviar): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'body' => $enviar->arquivo($request->file('file')),
        ], 201);
    }
}
```

### Action (`app/Actions/Storage/EnviarArquivoParaSpaces.php`)

Conversão pra webp com `intervention/image` v3 (`composer require intervention/image`); SVG/ICO sobem como estão.

```php
<?php

declare(strict_types=1);

namespace App\Actions\Storage;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Intervention\Image\Laravel\Facades\Image;

class EnviarArquivoParaSpaces
{
    /** @return array{nome: string, peso: string, url: string} */
    public function imagem(UploadedFile $file, ?int $maxWidth = null): array
    {
        $base = Str::uuid().'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME));

        if (in_array($file->extension(), ['svg', 'ico'], true)) {
            $path = Storage::disk('s3')->putFileAs('imagens', $file, "{$base}.{$file->extension()}", 'public');
        } else {
            $path = "imagens/{$base}.webp";
            $imagem = Image::read($file);
            if ($maxWidth) {
                $imagem->scaleDown(width: $maxWidth);
            }
            Storage::disk('s3')->put($path, (string) $imagem->toWebp(85), 'public');
        }

        return $this->resposta($file, $path);
    }

    /** @return array{nome: string, peso: string, url: string} */
    public function arquivo(UploadedFile $file): array
    {
        $nome = Str::uuid().'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->extension();
        $path = Storage::disk('s3')->putFileAs('arquivos', $file, $nome, 'public');

        return $this->resposta($file, $path);
    }

    private function resposta(UploadedFile $file, string $path): array
    {
        return [
            'nome' => $file->getClientOriginalName(),
            'peso' => Number::fileSize($file->getSize(), precision: 2),
            'url' => Storage::disk('s3')->url($path),
        ];
    }
}
```

### Teste (`tests/Feature/StorageUploadTest.php`)

```php
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

it('sobe imagem convertida para webp', function () {
    Storage::fake('s3');
    Sanctum::actingAs(User::factory()->create(), ['storage:upload']);

    $this->postJson('/api/storage/imagem', ['file' => UploadedFile::fake()->image('capa.png')])
        ->assertCreated()
        ->assertJsonPath('status', 'ok');

    expect(Storage::disk('s3')->allFiles('imagens'))->toHaveCount(1);
});

it('bloqueia token sem a ability', function () {
    Sanctum::actingAs(User::factory()->create(), ['outra']);

    $this->postJson('/api/storage/imagem', ['file' => UploadedFile::fake()->image('x.png')])
        ->assertForbidden();
});
```

## Detalhes técnicos (resumo)

- **Endpoint:** `POST /api/storage/imagem` | `POST /api/storage/arquivo`
- **Auth:** `Authorization: Bearer <token Sanctum>` com ability `storage:upload`, expiração 15 min
- **Campo do arquivo:** `file` (multipart/form-data) + header `Accept: application/json` (sem ele, erro de validação vira redirect 302)
- **Resposta:** `201 { status: 'ok', body: { nome, peso, url } }`
- **CDN base:** `AWS_URL` do `.env` (`https://<bucket>.<região>.cdn.digitaloceanspaces.com`)
- **Segredos:** `AWS_*` no `.env` do app, lidos só em `config/filesystems.php` (nunca `env()` fora de `config/`). O script não lê nem imprime segredos — só `APP_URL` e `UPLOAD_USER_EMAIL`.

## Erros comuns

- **`401 Unauthenticated.`** → token expirado/inválido, ou você mandou algo que não é um token Sanctum (`<id>|<hash>`). Deixe o `upload.sh` gerar.
- **`403 This action is unauthorized.`** → token sem a ability `storage:upload`, ou o alias `abilities` não está registrado em `bootstrap/app.php`.
- **`302` / HTML no lugar de JSON** → faltou `Accept: application/json` (o script já envia).
- **`422 The file field must be a file of type...`** → mimetype fora da lista; use `--type arquivo`.
- **`413 Request Entity Too Large`** → aumente `client_max_body_size` (nginx) e `upload_max_filesize`/`post_max_size` (php.ini).
- **`Class "League\Flysystem\AwsS3V3\PortableVisibilityConverter" not found`** → faltou `composer require league/flysystem-aws-s3-v3 "^3.0"`.
- **`não foi possível gerar o token Sanctum`** → usuário do `--email` não existe, ou `User` não usa o trait `Laravel\Sanctum\HasApiTokens` (rode `php artisan install:api` se o Sanctum não estiver instalado).
