---
name: mcp-builder
description: Construir, melhorar ou diagnosticar servidores MCP (Model Context Protocol) em Laravel 12 com o pacote oficial laravel/mcp (Server, Tools, Resources, Prompts, rotas Mcp::web / Mcp::local, OAuth 2.1 via Passport ou Sanctum). Cobre desde criar tools com nomes bonitos na sidebar do claude.ai (title + annotations via atributos PHP), agrupar em "Ferramentas somente leitura" vs "Outras ferramentas", até testes com Pest/MCP Inspector e deploy via Docker BuildKit no GitLab. Use SEMPRE que o usuário pedir "criar um MCP", "melhorar o MCP", "adicionar tool no MCP", "deixar o MCP bonito no claude.ai", "agrupar tools por categoria", "renomear tools", ou qualquer coisa relacionada a Model Context Protocol server.
---

# MCP Builder (Laravel)

Constrói e melhora servidores MCP (Model Context Protocol) dentro de apps **Laravel 12** usando o pacote oficial **`laravel/mcp`**. As lições de design (nomes, annotations, granularidade, instructions) vêm de experiência real construindo o MCP da UnicPages; a implementação é Laravel idiomático.

> **Antes de escrever código**, confira a versão instalada: `composer show laravel/mcp` e leia `vendor/laravel/mcp/src/Server.php` e `vendor/laravel/mcp/src/Server/Tool.php`. O pacote evolui rápido — propriedades como `$title`, e helpers de `Response`, podem variar entre versões. Siga o que o vendor mostra, não a memória.
>
> Conferido contra a **v1.0.1**: as propriedades usadas aqui (`$name`, `$title`, `$description`, `$version`, `$instructions`, `$tools`…) e as annotations `IsReadOnly`/`IsDestructive`/`IsIdempotent`/`IsOpenWorld` (com `bool`, default `true`) existem. A v1 também aceita metadados como atributos — `#[Name]`, `#[Version]`, `#[Instructions]`, `#[Title]`, `#[Description]` em `Laravel\Mcp\Server\Attributes\*` — que é o estilo dos stubs do `php artisan make:mcp-tool`. Os dois estilos funcionam; siga o que o projeto já usa.

## Instalação

```bash
composer require laravel/mcp
php artisan vendor:publish --tag=ai-routes     # cria routes/ai.php (carregado automaticamente)
```

Geradores:

```bash
php artisan make:mcp-server PaginasServer
php artisan make:mcp-tool EditarElementoTool
php artisan make:mcp-resource GuiaDeEstiloResource
php artisan make:mcp-prompt RevisarCopyPrompt
```

## Decisões críticas que distinguem MCP bom de ruim

### 1. Um Server por domínio, tools registradas explicitamente

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\RevisarCopyPrompt;
use App\Mcp\Resources\GuiaDeEstiloResource;
use App\Mcp\Tools\Elementos\BuscarElementosTool;
use App\Mcp\Tools\Elementos\EditarElementoTool;
use App\Mcp\Tools\Elementos\EditarElementosEmLoteTool;
use App\Mcp\Tools\Paginas\ResumoDoDraftTool;
use Laravel\Mcp\Server;

class PaginasServer extends Server
{
    protected string $name = 'UnicPages';

    protected string $version = '2.0.0';

    protected string $instructions = <<<'MARKDOWN'
        # UnicPages MCP Server
        (texto longo pro LLM — ver reference/instructions-template.md)
        MARKDOWN;

    protected array $tools = [
        ResumoDoDraftTool::class,
        BuscarElementosTool::class,
        EditarElementoTool::class,
        EditarElementosEmLoteTool::class,
    ];

    protected array $resources = [
        GuiaDeEstiloResource::class,
    ];

    protected array $prompts = [
        RevisarCopyPrompt::class,
    ];
}
```

Textos longos de `instructions` ficam melhores num arquivo (`resources/mcp/paginas-instructions.md`) carregado no construtor ou num método — não polua a classe com 300 linhas de heredoc.

### 2. NOMES DOS TOOLS — sem prefixo do servidor, sempre explícitos

❌ ERRADO: `unicpages_clear_draft`, `figma_get_design_context`
✅ CERTO: `clear_draft`, `get_design_context`

O claude.ai já adiciona o namespace do servidor automaticamente (`mcp__claude_ai_UnicPages__clear_draft`). Quando o tool já tem prefixo redundante, o claude.ai mostra o name CRU na sidebar (`unicpages_clear_draft`). Sem prefixo, ele converte snake_case em "Title Case" ou usa o `title` que você passou.

No Laravel o nome padrão é **derivado do nome da classe** — não confie nisso (renomear a classe quebra clientes). Declare sempre:

```php
protected string $name = 'element_patch';
protected string $title = 'Editar Elemento';   // display name na sidebar do claude.ai
```

### 3. Annotations fazem o claude.ai agrupar visualmente

Resultado real na sidebar do claude.ai:

```
📂 Ferramentas somente leitura (14)
   • Resumo do Draft
   • Buscar Elementos
   • Ler Elemento
   ...

📂 Outras ferramentas (19)
   • Editar Elemento
   • Criar Projeto
   ...
```

Esse agrupamento NÃO É manual — vem de `readOnlyHint: true`. No `laravel/mcp` as annotations são **atributos PHP** na classe do tool:

```php
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld]
class BuscarElementosTool extends Tool { /* ... */ }
```

**Mapa de annotations por tipo de operação:**

| Operação | Atributos |
|---|---|
| GET / list / find / tree / summary | `#[IsReadOnly] #[IsOpenWorld]` |
| CREATE (novo recurso) | `#[IsOpenWorld]` (sem `IsDestructive`) |
| UPDATE / PATCH / set | `#[IsIdempotent] #[IsOpenWorld]` |
| REPLACE (overwrite) | `#[IsDestructive] #[IsIdempotent] #[IsOpenWorld]` |
| DELETE / clear | `#[IsDestructive] #[IsIdempotent] #[IsOpenWorld]` |
| DUPLICATE | `#[IsOpenWorld]` |
| Operação SEM rede/banco externo (templates embutidos) | sem `IsOpenWorld` (ou `#[IsOpenWorld(false)]`) |

**Significado das flags:**
- `readOnlyHint` — só observa, nunca modifica. Cliente pode chamar livre.
- `destructiveHint` — irreversível. Cliente pode pedir confirmação extra.
- `idempotentHint` — rodar N vezes = rodar 1 vez. Habilita retry seguro.
- `openWorldHint` — interage com sistema externo (API, banco compartilhado). false = self-contained.

> O spec MCP trata `destructiveHint` como **true por padrão** quando `readOnlyHint` é false. Tool de escrita que não é destrutiva deve deixar isso explícito (`#[IsDestructive(false)]`) se a versão instalada serializar o default. Confira o JSON real com o Inspector (seção **Testes**).

### 4. Descrição rica do SERVIDOR (não só do tool)

`$name` + `$version` + `$instructions` aparecem no handshake `initialize`. O nome aparece na página de detalhes do MCP no claude.ai — use o nome da marca (`UnicPages`), não `PaginasServer`.

`instructions` é pro LLM (longo, com regras, exemplos, schema). A descrição humana (key features) vai no cadastro do conector / README do MCP.

### 5. Granularidade economiza 99% de tokens

Não force o LLM a baixar JSON gigante pra editar 1 texto. Forneça tools granulares:

| Custo | Tool antigo | Tool granular |
|---|---|---|
| Editar 1 texto | `section_replace` → 30-80 KB IN/OUT | `element_patch` → 200B IN, 400B OUT |
| Inspecionar | `get_draft` (full) → 80 KB+ | `section_tree` (maxDepth=3) → 2-3 KB |
| Achar elemento | baixar tudo + scan local | `element_find` query→matches |
| 50 patches | 50 round-trips | `element_patch_bulk` → 1 UPDATE |

Padrão "3 níveis de edição": pointwise (element_*) → estrutural (section_*) → full rewrite (update_draft). Detalhes em `reference/granularidade.md`.

### 6. Validação só do que mudou, não do draft inteiro

Drafts reais podem ter "lixo" tolerado pelo renderer mas rejeitado pelo schema (ex: component instances). Se validar o draft inteiro a cada patch, qualquer escrita morre. Valide só o NÓ tocado — numa Action, não no tool:

```php
// ERRADO
$this->validador->draft($draft);   // bloqueia qualquer save se tiver legado

// CERTO
foreach ($tocados as $no) {
    if ($no['tag'] === 'section') {
        $this->validador->secao($no);  // lança ValidationException só do que importa
    }
}
```

### 7. Bulk operations sempre que possível

Tool que recebe `patches: [{...}, {...}]` faz 1 SELECT + 1 UPDATE (dentro de `DB::transaction`) pra N edições. Em 78 edições reais que fizemos, economizou ~70% de latência vs 78 round-trips.

### 8. Tool fino, regra de negócio em Action

O tool é só **adaptador de protocolo** (igual controller fino): valida input, autoriza, chama a Action, formata a resposta. A mesma Action serve o controller HTTP, o job de fila e o tool MCP.

## Estrutura recomendada de pastas

```
app/
├── Mcp/
│   ├── Servers/
│   │   └── PaginasServer.php          # 1 server por domínio/produto
│   ├── Tools/
│   │   ├── Perfil/
│   │   ├── Projetos/                  # 1 pasta por domínio
│   │   ├── Paginas/
│   │   └── Elementos/
│   │       ├── BuscarElementosTool.php
│   │       ├── EditarElementoTool.php
│   │       └── EditarElementosEmLoteTool.php
│   ├── Resources/
│   │   └── GuiaDeEstiloResource.php
│   ├── Prompts/
│   │   └── RevisarCopyPrompt.php
│   └── Concerns/
│       └── RespondeComJson.php        # helpers ok()/erro() compartilhados
├── Actions/
│   └── Paginas/
│       ├── AplicarPatchNoElemento.php # regra de negócio (reusada por HTTP/fila/MCP)
│       └── AplicarPatchesEmLote.php
└── Policies/
    └── PaginaPolicy.php               # autorização (o tool chama Gate/authorize)
resources/
└── mcp/
    └── paginas-instructions.md        # texto longo de instructions
routes/
└── ai.php                             # Mcp::web / Mcp::local / Mcp::oauthRoutes
tests/
└── Feature/Mcp/
    └── EditarElementoToolTest.php
```

## Rotas (`routes/ai.php`)

```php
<?php

use App\Mcp\Servers\PaginasServer;
use Laravel\Mcp\Facades\Mcp;

// MCP remoto (HTTP streamable) — o que o claude.ai conecta
Mcp::oauthRoutes();   // endpoints OAuth 2.1 (well-known, register, authorize, token) via Passport

Mcp::web('/mcp/paginas', PaginasServer::class)
    ->middleware(['auth:api', 'throttle:mcp']);

// MCP local (stdio) — Claude Code / Desktop rodando `php artisan mcp:start paginas`
Mcp::local('paginas', PaginasServer::class);
```

O rate limiter `mcp` fica em `AppServiceProvider::boot()`:

```php
RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
```

Para uso interno sem OAuth (só tokens pessoais), troque `auth:api` por `auth:sanctum` e remova `Mcp::oauthRoutes()`. O claude.ai (conector remoto) **exige OAuth** — ver `reference/http-mcp-oauth.md`.

## Padrão de tool (cole isso ao criar um novo)

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Elementos;

use App\Actions\Paginas\AplicarPatchNoElemento;
use App\Models\Pagina;
use Illuminate\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsIdempotent]
#[IsOpenWorld]
class EditarElementoTool extends Tool
{
    protected string $name = 'element_patch';           // SEM prefixo do servidor

    protected string $title = 'Editar Elemento';

    protected string $description = <<<'TEXT'
        Edita UM elemento do draft por id (deep-merge de text/content/style).
        Use para trocar texto, cor ou link pontual. Para várias edições use element_patch_bulk.
        Para descobrir o id use element_find. Retorna o elemento atualizado (modo summary).
        TEXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('ID da página')->required(),
            'element_id' => $schema->string()->description('ID do elemento (ex: el_hero_title)')->required(),
            'patch' => $schema->object()->description('Campos a mesclar: text, content, style')->required(),
        ];
    }

    public function handle(Request $request, AplicarPatchNoElemento $aplicar): Response
    {
        $dados = $request->validate([
            'page_id' => ['required', 'string'],
            'element_id' => ['required', 'string', 'max:120'],
            'patch' => ['required', 'array'],
        ]);

        $pagina = Pagina::query()->find($dados['page_id']);

        if (! $pagina || Gate::forUser($request->user())->denies('update', $pagina)) {
            return Response::error('Página não encontrada ou sem permissão.');
        }

        $elemento = $aplicar($pagina, $dados['element_id'], $dados['patch']);

        return Response::text("Elemento atualizado.\n\n".json_encode($elemento, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
```

Regras:
- **Dependências** entram por injeção no `handle()` (container do Laravel), igual a controller.
- **Validação** com `$request->validate()` — falha vira erro de tool legível pro LLM (não 500).
- **Autorização** sempre via Policy/Gate com `$request->user()` — o tool nunca confia em ids vindos do LLM.
- **Mesma mensagem** para "não existe" e "sem permissão" (não vaze existência de recursos de outros tenants).
- Tools que só fazem sentido para alguns usuários: implemente `shouldRegister(Request $request): bool` para escondê-los da listagem.

## Padrão de resposta (ok/erro)

```php
<?php

declare(strict_types=1);

namespace App\Mcp\Concerns;

use Laravel\Mcp\Response;

trait RespondeComJson
{
    protected function ok(string $texto, mixed $dados = null): Response
    {
        $corpo = $dados === null
            ? $texto
            : $texto."\n\n".json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Response::text($corpo);
    }

    protected function erro(string $mensagem): Response
    {
        return Response::error($mensagem);   // isError: true no protocolo
    }
}
```

Nunca devolva stack trace ou mensagem de exceção crua (`$e->getMessage()` de QueryException vaza SQL). Logue com `report($e)` e responda uma frase útil.

## Resources e Prompts

```php
class GuiaDeEstiloResource extends Resource
{
    protected string $uri = 'unicpages://guias/estilo';
    protected string $mimeType = 'text/markdown';
    protected string $description = 'Regras de tipografia, cores e espaçamento dos templates.';

    public function handle(Request $request): Response
    {
        return Response::text(file_get_contents(resource_path('mcp/guia-estilo.md')));
    }
}
```

```php
class RevisarCopyPrompt extends Prompt
{
    protected string $description = 'Revisa a copy de uma seção no tom da marca.';

    public function arguments(): array
    {
        return [new Argument(name: 'tom', description: 'formal | descontraído', required: true)];
    }

    public function handle(Request $request): Response
    {
        $tom = $request->validate(['tom' => ['required', 'in:formal,descontraído']])['tom'];

        return Response::text("Revise a copy abaixo mantendo tom {$tom}...");
    }
}
```

## Testes

### Pest (unitário do tool, sem HTTP)

```php
use App\Mcp\Servers\PaginasServer;
use App\Mcp\Tools\Elementos\EditarElementoTool;
use App\Models\Pagina;
use App\Models\User;

it('edita o texto de um elemento', function () {
    $user = User::factory()->create();
    $pagina = Pagina::factory()->for($user)->comElemento('el_hero_title', 'Antigo')->create();

    PaginasServer::actingAs($user)
        ->tool(EditarElementoTool::class, [
            'page_id' => $pagina->id,
            'element_id' => 'el_hero_title',
            'patch' => ['text' => 'Novo'],
        ])
        ->assertOk()
        ->assertSee('Novo');
});

it('não edita página de outro usuário', function () {
    $pagina = Pagina::factory()->create();

    PaginasServer::actingAs(User::factory()->create())
        ->tool(EditarElementoTool::class, ['page_id' => $pagina->id, 'element_id' => 'x', 'patch' => []])
        ->assertHasErrors();
});
```

### MCP Inspector (visual, mostra o JSON real de `tools/list`)

```bash
php artisan mcp:inspector mcp/paginas     # servidor web
php artisan mcp:inspector paginas         # servidor local
```

Confira no `tools/list`: `name` sem prefixo, `title` preenchido, `annotations` corretas. É o que o claude.ai vai ler.

## Deploy via Docker BuildKit

O MCP web é **uma rota do app Laravel** — vai na mesma imagem do app (use a skill `docker-deploy`, que tem o Dockerfile Laravel de referência com FrankenPHP/php-fpm). Pontos específicos de MCP:

- **Streaming**: respostas streamed (SSE) não podem ser bufferizadas. Com nginx na frente: `proxy_buffering off;` e `fastcgi_buffering off;` na location do MCP, e `proxy_read_timeout` alto. FrankenPHP não bufferiza por padrão.
- **Passport**: as chaves OAuth (`storage/oauth-*.key`) **não** vão na imagem — injete via env (`PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY`) ou secret do Swarm.
- **Cache**: `php artisan optimize` no entrypoint cacheia `routes/ai.php` junto com as demais rotas — depois de adicionar um server, a imagem nova precisa subir (não há hot reload em prod).

```bash
~/gitlab-login.sh <conta>
export GITLAB_TOKEN=$(grep '^GITLAB_TOKEN=' .env | cut -d= -f2-) && \
  docker builder prune -a -f && \
  DOCKER_BUILDKIT=1 docker build --platform linux/amd64 \
    --provenance=false --sbom=false \
    --secret id=gitlab_token,env=GITLAB_TOKEN \
    -t registry.gitlab.com/<org>/backend/api:latest . && \
  docker push registry.gitlab.com/<org>/backend/api:latest
```

Reiniciar prod NÃO é automático — alguém precisa puxar a imagem nova.

## Iteração no claude.ai

Depois de cada deploy:
1. Reiniciar container em prod (puxa `:latest`)
2. No claude.ai: comando `/mcp` → desconectar → reconectar (refresh OAuth + capabilities)
3. **NÃO precisa desinstalar/reinstalar** o MCP — só desconectar/reconectar

## Armadilhas que custaram horas

1. **Schema estrito rejeita drafts reais** — sempre permita "component instances" (tag div no root) se o renderer aceita. Caso contrário qualquer save morre.
2. **Nome derivado da classe** — renomear `EditarElementoTool` muda o nome público. Declare `$name` sempre.
3. **Tool name com prefixo** = display ruim. Tirar.
4. **Cache do schema no claude.ai** — só `/mcp` desconectar/reconectar refresca.
5. **Mobile vs desktop com ids divergentes** — editores visuais às vezes criam `_copy_XXX` em vez de `_m`. Adicione um tool `find_orphan_mobile` que detecta e sugere pares.
6. **Validar o draft inteiro a cada patch** = bloqueia escrita por qualquer legado. Validar só o nó tocado.
7. **`route:cache` antigo** — rota `/mcp/...` 404 em prod após deploy? O cache de rotas veio de uma imagem velha; rode `php artisan optimize:clear && php artisan optimize`.
8. **CSRF** — rotas de `routes/ai.php` não passam pelo grupo `web`, então não exigem token CSRF. Não mova o MCP para `routes/web.php`.
9. **N+1 dentro de tool** — um `element_find` que carrega relações em loop derruba a latência. Use `with()` e ative `Model::preventLazyLoading(! app()->isProduction())`.

## Mais detalhes

- `reference/annotations-decision-tree.md` — fluxograma pra escolher annotations (atributos PHP)
- `reference/granularidade.md` — patterns de tools granulares vs full-replace
- `reference/http-mcp-oauth.md` — MCP HTTP com OAuth 2.1 via Passport (e alternativa Sanctum)
- `reference/instructions-template.md` — template do texto longo de `instructions`

## Fluxo recomendado quando o usuário pede um MCP novo

1. **Entender o domínio**: que ações o LLM precisa fazer? Listar, ler, criar, editar, deletar.
2. **Mapear granularidade**: tem operações que afetam JSON grande? Crie tool granular (patch por id).
3. **Listar tools**: 1 pasta por domínio (`app/Mcp/Tools/<Dominio>/`), regra de negócio em `app/Actions/`.
4. **Aplicar annotations** (atributos) seguindo a tabela acima.
5. **Escrever `$description` do tool** com gancho para o LLM saber quando usar e quando NÃO usar (preferir outro tool).
6. **Escrever `instructions`** do servidor com regras de schema, exemplos, armadilhas. Esse texto vai pro contexto do LLM em toda chamada.
7. **Testar**: Pest por tool (inclusive autorização) + `php artisan mcp:inspector`.
8. **Empacotar** (skill `docker-deploy`) + deploy.
9. **Validar visualmente** no claude.ai: agrupamento por annotations, nomes bonitos, descrição rica.
