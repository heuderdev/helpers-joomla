# Skill helpers-joomla (Perplexity)

Ensina a IA a escrever código Joomla usando os helpers deste repositório, gastando o mínimo de tokens.

## Como ela economiza tokens

| Camada | Arquivo | Quando é lida |
|---|---|---|
| 1. Núcleo | `SKILL.md` (~2 mil tokens) | Sempre. Resolve a maioria dos pedidos: mapa pedido → helper, regras, esqueleto de controller. |
| 2. Fichas | `referencias/*.md` (~1 a 2 mil tokens cada) | Só a ficha da área do pedido. |
| 3. Receitas | `receitas.md` | Só quando o pedido junta vários helpers. |
| 4. Código-fonte | um `.php` cru do GitHub | Só em último caso, um arquivo por vez (os fontes têm de 6 a 120 KB). |

A IA não abre `docs/*.html` (pesados) nem varre o repositório.

## Instalação no Perplexity

**Como Skill** (se a sua conta tem "Skills"): envie a pasta `skill/` (ou um zip dela) com o `SKILL.md` na raiz.

**Como Space** (funciona em qualquer conta):
1. Crie um Space, por exemplo "Joomla Helpers".
2. Em **Instruções**, cole o conteúdo de `instrucoes-space.md`.
3. Em **Arquivos**, envie: `SKILL.md`, `receitas.md` e os 5 arquivos de `referencias/`.
4. Opcional: adicione o link `https://github.com/heuderdev/helpers-joomla` como fonte.

Para gerar o zip:
```bash
cd /developer/helpersJoomla && zip -r helpers-joomla-skill.zip skill -x 'skill/*.zip'
```

## Mantendo atualizada

Quando um helper mudar (método novo, opção nova, correção), atualize a ficha da área e, se mudar o uso comum, o `SKILL.md`. As assinaturas foram extraídas do código em 04/10/2026 (commit `f5fe591`). Os blocos de PHP das fichas passam no `php -l` e no PHPCompatibility para PHP 7.0.

## Teste rápido depois de instalar

- "Crie um controller de produtos com listagem paginada e salvar com validação."
- "Importe um CSV de clientes em segundo plano mostrando o progresso."
- "Exporte os pedidos pagos para CSV só para administradores."

A resposta deve vir com código usando `OrmTables`, `InputHelper`, `ValidationHelper`, `ApiResponseHelper`, `CsvHelper`, `QueueHelper`, `PermissionHelper` e `ExportHelper`, sem métodos inventados.
