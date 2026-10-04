# Skill joomla-helpers (Claude Code, Codex, Orca e qualquer agente com Agent Skills)

Faz o agente criar, refatorar e revisar código Joomla com os helpers deste repositório: pouco texto, código certo.

**Não depende de pasta nenhuma.** Cada colaborador pode ter os projetos onde quiser, e cada worktree do Orca pode estar num caminho diferente. Os scripts se orientam pela pasta do projeto atual e pela própria skill.

## Instalar (cada colaborador, uma vez)

```bash
# no seu usuário (vale para todos os projetos)
npx skills add heuderdev/helpers-joomla --skill joomla-helpers -g

# ou só num projeto (versionável junto do código)
cd <seu-projeto> && npx skills add heuderdev/helpers-joomla --skill joomla-helpers
```
Sempre passe `--skill joomla-helpers`: o repositório tem outras skills que não são para isso. Escolha os agentes no menu (ou `--agent claude-code codex`).

| Agente | Onde a skill fica | Como ela é usada |
|---|---|---|
| **Claude Code** | `~/.claude/skills/` ou `<projeto>/.claude/skills/` | Detecta pelo pedido; para forçar: "use a skill joomla-helpers" |
| **Codex** | `~/.codex/skills/` ou `<projeto>/.agents/skills/` | Detecta pelo pedido; para forçar: `$joomla-helpers` |
| **Orca** | Não tem pasta própria: roda o Claude Code, o Codex etc. em worktrees, e cada um lê a sua pasta (acima) | Instale para os agentes que você usa no Orca. Instalado **no projeto** (commitado), vale para todos os worktrees e colaboradores |
| **Sem suporte a skills** | — | Cole `AGENTS.snippet.md` no `AGENTS.md` do projeto |

Para o time inteiro de uma vez: instale **no projeto**, commite `.agents/skills/joomla-helpers` (e o link de `.claude/skills`) e pronto, quem clonar já tem a skill.

Sem `npx` (offline, de um clone): `skills/joomla-helpers/instalar-skill.sh` (global), `--projeto <dir>` ou `--destino <pasta>`.

## Como os scripts acham os arquivos

| Precisa de | Procura em |
|---|---|
| Helpers para **consultar** a API | `$HELPERS_JOOMLA_DIR` → helpers dentro do projeto atual (a versão que o projeto usa) → repositório onde a skill está → `~/.cache/helpers-joomla` (clonado do GitHub sozinho, atualizado uma vez por dia) |
| Helpers para **instalar** num componente | Igual, mas nunca a cópia do projeto (sempre a versão oficial) |
| O projeto | A raiz do git da pasta atual (o worktree), ou a pasta atual |

Rode os scripts **de dentro da pasta do projeto**. `scripts/onde.sh` mostra o que ele encontrou (raiz do Joomla, versão, seus componentes, helpers instalados e quais estão desatualizados).

Fixar uma versão própria dos helpers (fork, rede sem GitHub): `export HELPERS_JOOMLA_DIR=/caminho/do/clone`.

## Conteúdo

```
SKILL.md             núcleo (sempre carregado): princípios, fluxos, mapa, armadilhas, esqueleto
references/          fichas por área + receitas + refatoração (carregadas só quando preciso)
scripts/onde.sh      mapa do projeto atual
scripts/api.sh       API real de um helper sem ler o arquivo inteiro
scripts/instalar.sh  copia helpers + dependências para o componente (não sobrescreve alterados)
scripts/checar.sh    php -l, PHP 7.0, SQL concatenado, token, trechos que os helpers substituem
scripts/_repo.sh     localiza os helpers (tabela acima)
```

## Manutenção

Mudou um helper? Atualize a ficha da área (e o `SKILL.md` se mudar o uso comum), commite e dê push. Quem instalou com `npx skills` atualiza com `npx skills update`. O `api.sh` lê o código, então assinaturas novas aparecem sozinhas. As fichas são as mesmas de `skill/` (Perplexity): ao editar uma, copie para a outra.
