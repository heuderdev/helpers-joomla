# Skill joomla-helpers (Claude Code, Codex, Orca e afins)

Faz o agente criar, refatorar e revisar código Joomla usando os helpers deste repositório, com o mínimo de texto e o máximo de acerto.

```
skill-local/
├── SKILL.md              núcleo (sempre carregado, ~2,5 mil tokens): princípios, fluxos, mapa, armadilhas, esqueleto
├── references/           fichas por área, carregadas só quando o pedido precisa
│   ├── banco.md  entrada-saida.md  arquivos.md  fila.md  infra.md
│   ├── receitas.md       combinações prontas
│   └── refatoracao.md    legado Joomla → helpers, passo a passo
├── scripts/
│   ├── api.sh            API real de um helper sem ler o arquivo (assinaturas, um método, busca)
│   ├── instalar.sh       copia helpers + dependências para o componente
│   ├── checar.sh         php -l, PHP 7.0, SQL concatenado, token, oportunidades de helper
│   └── _repo.sh          acha os helpers ($HELPERS_JOOMLA_DIR → /developer/helpersJoomla → clone em ~/.cache)
├── instalar-skill.sh     instala a skill nos agentes
└── AGENTS.snippet.md     para ferramentas sem suporte a skills
```

## Instalar

```bash
cd /developer/helpersJoomla/skill-local
./instalar-skill.sh                          # Claude Code (~/.claude/skills) e Codex (~/.codex/skills)
./instalar-skill.sh --projeto ~/meu-site     # só num projeto (.claude/skills, .codex/skills e .agents/skills)
./instalar-skill.sh --destino <pasta>        # outra ferramenta (ex.: a pasta de skills do Orca)
```
Por padrão cria **link simbólico**: um `git pull` aqui atualiza a skill em todos os agentes. Use `--copiar` para cópia independente (ex.: outra máquina).

| Ferramenta | Como ela usa |
|---|---|
| **Claude Code** | Detecta pela `description` e carrega sozinha. Para forçar: "use a skill joomla-helpers". |
| **Codex** | Mesmo formato (`SKILL.md` com `name`/`description`). Para forçar, peça "use a skill joomla-helpers". Se a sua versão do Codex não listar a skill, use o `AGENTS.snippet.md`. |
| **Orca ou outra** | Se ela aceitar skills no padrão `SKILL.md`, instale com `--destino` na pasta de skills dela. Se não, cole `AGENTS.snippet.md` no `AGENTS.md` (ou no arquivo de instruções equivalente) do projeto. |

Em outra máquina, sem este repositório: copie a pasta `skill-local/` e os scripts clonam os helpers do GitHub sozinhos (ou defina `HELPERS_JOOMLA_DIR`).

## Uso no dia a dia

- "Cria o controller de pedidos com listagem, salvar e excluir" → esqueleto + fichas; `checar.sh` antes de entregar.
- "Refatora `controllers/pedidos.php` para os helpers" → `refatoracao.md`, mantém tasks e respostas, entrega diff + o que testar.
- "Revisa esse componente" → `checar.sh` na pasta + achados por gravidade com a correção.
- "Esse CSV tem 300 mil linhas, importa" → receita de CSV em fila.

## Manutenção

Mudou um helper? Atualize a ficha da área (e o `SKILL.md` se mudar o uso comum). As fichas são as mesmas de `../skill/` (Perplexity): ao editar uma, copie para a outra. `api.sh` lê sempre o código atual, então assinaturas novas aparecem sem editar nada.
