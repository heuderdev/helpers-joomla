# Annotations Decision Tree

Pra cada tool novo, responda essas perguntas em ordem. No `laravel/mcp` cada hint vira um **atributo PHP** na classe do tool (`Laravel\Mcp\Server\Tools\Annotations\IsReadOnly`, `IsDestructive`, `IsIdempotent`, `IsOpenWorld`).

## 1. O tool MODIFICA estado externo?

- **NÃO** (só lê / lista / busca / inspeciona) → `#[IsReadOnly]` (`readOnlyHint: true`)
  - Pronto. Adicione `#[IsOpenWorld]` se consulta banco/API externa.
  - Exemplos: `list_projects`, `get_page`, `element_find`, `section_tree`, `templates_list`

- **SIM** (cria / edita / deleta / move) → continue

## 2. A mudança é IRREVERSÍVEL ou destrutiva?

- **SIM** (delete, clear, replace que sobrescreve dados anteriores) → `#[IsDestructive]`
  - O claude.ai pode pedir confirmação extra.
  - Exemplos: `delete_project`, `clear_draft`, `update_draft` (replace inteiro), `section_remove`, `page_create_from_template` (substitui o draft inteiro por default)

- **NÃO** (cria recurso novo sem sobrescrever, duplica) → `#[IsDestructive(false)]` (explícito: o spec assume destrutivo quando não é read-only)
  - Exemplos: `create_project`, `create_page`, `duplicate_project`, `section_add`, `section_add_from_template`

## 3. Rodar o tool VÁRIAS VEZES com o mesmo input = rodar 1 vez?

- **SIM** (idempotente: set, replace, patch que faz deep-merge) → `#[IsIdempotent]`
  - Habilita retry seguro em caso de timeout.
  - Exemplos: `element_patch`, `section_replace`, `update_page`, `set_home_page`, `clear_draft`

- **NÃO** (cada chamada adiciona algo: create, append, log) → não usar o atributo
  - Exemplos: `create_project`, `duplicate_*`, `section_add`

## 4. Interage com mundo externo (API, rede, FS fora do servidor)?

- **SIM** → `#[IsOpenWorld]` (default seguro)
- **NÃO** (só toca o próprio servidor, ex: ler template estático em `resources/`) → sem `IsOpenWorld` (ou `#[IsOpenWorld(false)]`)
  - Exemplos: `templates_list`, `template_get` (templates ficam no próprio MCP)

## Mapa final por padrão de nome

| Padrão de nome | Atributos PHP |
|---|---|
| `list_*` / `get_*` / `*_get` | `#[IsReadOnly] #[IsOpenWorld]` |
| `*_find` / `*_search` / `*_tree` / `*_summary` | `#[IsReadOnly] #[IsOpenWorld]` |
| `create_*` | `#[IsDestructive(false)] #[IsOpenWorld]` |
| `duplicate_*` | `#[IsDestructive(false)] #[IsOpenWorld]` |
| `update_*` / `set_*` / `edit_*` | `#[IsDestructive(false)] #[IsIdempotent] #[IsOpenWorld]` |
| `*_patch` / `*_patch_bulk` | `#[IsDestructive(false)] #[IsIdempotent] #[IsOpenWorld]` |
| `*_replace` | `#[IsDestructive] #[IsIdempotent] #[IsOpenWorld]` |
| `delete_*` / `*_remove` / `clear_*` | `#[IsDestructive] #[IsIdempotent] #[IsOpenWorld]` |
| `*_reorder` / `*_move` | `#[IsDestructive(false)] #[IsIdempotent] #[IsOpenWorld]` |
| `*_from_template` (substitui) | `#[IsDestructive] #[IsIdempotent] #[IsOpenWorld]` |
| `*_from_template` (adiciona) | `#[IsDestructive(false)] #[IsOpenWorld]` |
| Templates embutidos (sem banco/API) | sem `IsOpenWorld` |

**SEMPRE** declare `$name` e `$title` na classe (o nome derivado da classe muda se ela for renomeada):

```php
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsOpenWorld]
class ResumoDoDraftTool extends Tool
{
    protected string $name = 'draft_summary';
    protected string $title = 'Resumo do Draft';
}
```

Confirme o resultado no `tools/list` do `php artisan mcp:inspector <rota>` — se a versão do pacote não serializar algum hint, ele não aparece lá e o claude.ai não agrupa.
