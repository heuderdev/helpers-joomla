# Otimização de Performance

## Estratégia de Seleção de Modelo (2026)

Regra de ouro (consenso da comunidade Claude Code):

> **Opus pensa, Sonnet faz, Haiku busca.**

| Modelo | Custo relativo | Quando usar |
|--------|----------------|-------------|
| **Haiku 4.5** | 1x | Agentes de coleta de informação, buscas, conversões determinísticas, inspeção read-only, análise de `laravel.log` |
| **Sonnet 4.6** | ~5x Haiku | Escrita de código, refactors, code review, lógica de aplicação |
| **Opus 4.7** | ~3x Sonnet | Orquestração, decisões arquiteturais, raciocínio multi-passo, tarefas que ninguém mais resolve |

Tier de três níveis em ação pode reduzir custo de sessão em ~50% sem perda de qualidade.

## Quando Delegar para Subagent

**SEMPRE delegue** se a tarefa for:
- Determinística (script bash ou comando `php artisan` basta) → use agent Haiku que invoca script
- Read-only de muitos arquivos (busca, grep, lista, análise de log)
- Conversão sintática (case, format, slug — ex: coluna `snake_case` ↔ classe `StudlyCase`)
- Inspeção (git status, `composer outdated`, `php artisan route:list`, file listing)

**NÃO delegue** se a tarefa exigir:
- Conhecer arquitetura ou convenções do projeto (camadas, Actions, Policies)
- Tomar decisão de design
- Refatorar com entendimento semântico
- Coordenar múltiplos agents

## Agents Disponíveis (~/.claude/agents/)

### Tier Haiku (rápido + barato)

| Agent | Função | Script base |
|-------|--------|-------------|
| `file-finder` | Localiza arquivos por nome/glob/extensão (ignora `vendor/`, `storage/`, `bootstrap/cache`) | `find-files.sh` |
| `batch-renamer` | Renomeia arquivos em lote (dry-run obrigatório) | `batch-rename.sh` |
| `log-analyzer` | Resumo de `storage/logs/laravel.log` com top-N exceptions | `analyze-logs.sh` |
| `format-converter` | Case conversion + JSON/YAML/CSV | `convert-format.sh` |
| `git-inspector` | Estado read-only do repo | `git-summary.sh` |

### Tier Sonnet (interpretação + análise)

| Agent | Função | Script base |
|-------|--------|-------------|
| `code-searcher` | Busca semântica de código PHP/Blade (definições, usos, rotas, bindings) | `grep-code.sh` |
| `dependency-checker` | Audit + outdated de deps (Composer principal, npm só p/ Vite) | `dep-check.sh` |

### Tier Opus (especialistas existentes)

`especialista-laravel`, `especialista-blade`, `especialista-css`, `especialista-html`, `especialista-js`, `especialista-seo`, `especialista-ui`, `especialista-desempenho`, `especialista-seguranca`, `especialista-unicpages` — todos para escrita/auditoria de código no domínio respectivo.

## Padrão de Delegação Recomendado

```
Opus (orquestrador, contexto principal)
  ├─ "encontre arquivos X"              → file-finder (Haiku)
  ├─ "renomeie Controller → Action"     → batch-renamer (Haiku)
  ├─ "analise o laravel.log"            → log-analyzer (Haiku)
  ├─ "converta para snake_case"         → format-converter (Haiku)
  ├─ "qual o estado do git?"            → git-inspector (Haiku)
  ├─ "onde a Policy X é usada?"         → code-searcher (Sonnet)
  ├─ "tem vulnerabilidade no composer?" → dependency-checker (Sonnet)
  ├─ "crie o CRUD de pedidos"           → especialista-laravel (Opus)
  ├─ "escreva o componente Blade"       → especialista-blade/css/etc (Opus)
  └─ decisões + raciocínio              → Opus direto
```

## Scripts Utilitários (~/.claude/scripts/)

Os agents Haiku invocam scripts bash em vez de "pensar". Isso garante:
1. **Custo zero de inferência** na parte mecânica
2. **Determinismo** (mesmo input → mesmo output)
3. **Velocidade** (bash > IA para `find`/`grep`/`sed`)

Lista atual:
- `find-files.sh` — busca por nome (usa `fd` se disponível)
- `grep-code.sh` — busca por conteúdo em `.php`/`.blade.php` (usa `rg` se disponível)
- `batch-rename.sh` — renomeio em lote com dry-run
- `analyze-logs.sh` — análise estatística de logs (entende o formato do `laravel.log`)
- `convert-format.sh` — case + JSON/YAML/CSV
- `git-summary.sh` — estado do repo
- `dep-check.sh` — inspeção de composer.json/composer.lock (+ package.json do Vite)

## Gerenciamento da Janela de Contexto

Evite os últimos 20% da janela de contexto para:
- Refatoração em larga escala
- Implementação multi-arquivo (ex: model + migration + FormRequest + Policy + controller + views + testes)
- Depuração complexa

**Truque**: delegar buscas/listagens para subagents Haiku protege o contexto do Opus — eles consomem o output enorme (`route:list`, `laravel.log`, `composer show`) e retornam só o resumo.

## Ultrathink + Modo de Planejamento

Para tarefas complexas:
1. Use `ultrathink` para raciocínio aprimorado
2. Habilite **Modo de Planejamento** para abordagem estruturada
3. Múltiplas rodadas de crítica antes de implementar
4. Sub-agentes com papéis distintos para análise multi-perspectiva

## Resolução de Problemas de Build

Build falhou (`composer install`, `npm run build`, `php artisan test`, Pint, Larastan):
1. Analise mensagens de erro
2. Corrija incrementalmente
3. Verifique após cada correção
4. Se a mudança for em config/rotas/views e nada muda: `php artisan optimize:clear` antes de concluir que é bug
