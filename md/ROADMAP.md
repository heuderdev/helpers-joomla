# Roadmap de helpers

Helpers que ainda faltam para cobrir os problemas do dia a dia em componentes Joomla. Cada novo helper segue o padrão do repositório: Joomla 4.4/5/6, PHP 8.2+ (até 2026-11-10 era PHP 7.0 e Joomla 3), registrado no `IncludeHelper` e no `instalar.sh` da skill, página em `docs/`, ficha nas duas skills (`skills/joomla-helpers` e `skill/`).

## Feitos

| Helper | O que resolve |
|---|---|
| `DateHelper` | Datas e fuso horário: grava em UTC, mostra no fuso do usuário, lê formulários e monta períodos de relatório. `OrmBase` e `QueueHelper` já o usam. |
| `Vigia.js` (`js/`) | Formulário no navegador: mesmas regras e mensagens do `ValidationHelper`, erros 422 nos campos, máscaras brasileiras, axios com token CSRF, Alpine opcional. `ValidationHelper::clientConfig()` exporta as regras do PHP. |
| `PermissionHelper::requireToken()` | Token CSRF do campo, do cabeçalho `X-CSRF-Token` ou do JSON, no Joomla 3, 4 e 5. |
| `Vitrine.js` (`js/`) | Listagens com paginação, ordenação e filtros na URL, busca com espera e cancelamento, ações por linha e em lote. |
| `FormatHelper` | Dinheiro (e centavos inteiros), números, CPF/CNPJ, CEP, telefone, slug, resumo, plural, nomes. |
| `ArrayHelper` | Arrays e objetos com notação de ponto (`get`/`set`, curinga `*`) e coleções na memória: filtrar, agrupar, indexar, ordenar, somar, árvore. Trazido do pacote `helpers-joomla-vendor` em 2026-11-10, com 3 correções (`toTree` perdia registro pai de si mesmo com id em texto; `except` alterava objetos aninhados do original; `where` não aceitava Closure). |
| `BackupDatabaseHelper` | Backup do MySQL/MariaDB ou PostgreSQL 10+ em `.sql`/`.sql.gz`, só estrutura ou completo, salvo no servidor ou baixado, sem nunca apagar nada (nem na origem nem ao restaurar). Trazido do pacote `helpers-joomla-vendor` em 2026-11-12, com 1 correção (no MySQL, view que lê outra view saía antes dela e a restauração falhava). Testado com restauração real no MySQL 8.4 e no PostgreSQL 18. |
| `EmailSendHelper` | E-mail sem dependências e sem o mailer do Joomla: cliente SMTP próprio (Gmail, Outlook/Hotmail, Office 365, Yahoo, Zoho, SendGrid, Brevo, Mailgun, Locaweb, Hostinger...), API da ElasticEmail, `mail()` e `.eml`; várias contas por nome com failover, views `.php`/`.html` com layout, anexos (disco, memória, upload), imagens embutidas (`cid:`), modo de teste e `testConnection()`. Trazido do pacote `heuderdev/helpers` em 2026-11-13, sem alterações. Testado com servidor SMTP local sem TLS (AUTH, Bcc só no envelope, dot-stuffing, nomes acentuados), endpoint falso da API v4 da ElasticEmail, driver `log`, failover e modo fake. |
| Docs | Páginas do `LogHelper`, `PermissionHelper` e `ExportHelper`. |
| `PlatformHelper` | `LogHelper`, `FileHelper`, `ExportHelper`, `CsvHelper`, `ChunkHelper` e `UploadMaster` rodam no Joomla 3 a 6, no Laravel e em PHP puro (Joomla continua o padrão). Testado em 2026-10-25 com Joomla 3.10.12, 4.4.14, 5.4.10, 6.2.0 e Laravel 13 no PHP 8.4. |
| Docs do `PlatformHelper` | Sistemas completos (importação com prévia e rejeitados, exportação, foto/documento, CLI com retomada, backup) em Joomla, Laravel e PHP puro, executados de ponta a ponta. Corrigidos no caminho: `ChunkHelper::readLines()` gravava no checkpoint o fim do buffer (a retomada pulava linhas); `CsvHelper::import()` ignorava `createdAtColumn`/`updatedAtColumn`; `DbConnectionHelper` e `DbTransactionHelper` usavam `JFactory`/`JLog` (quebravam o `import()` e o ORM no Joomla 6). |

## Execução noturna (iniciada em 2026-10-05 01:15, entrega 08:00)

Fila de trabalho em ordem. Quem retomar (sessão nova ou depois do limite de uso): pegue o **primeiro item não marcado**, leia "Notas de retomada" e continue. Ao terminar um item: marque `[x]`, faça um commit local só daquele item (`[main]: feat/docs/fix: ...`) e siga para o próximo. Não fazer `git push` (o Eduardo/Heuder revisa de manhã).

Checklist de cada helper novo (memória `helper-checklist`): `IncludeHelper::$dependencies` (e `$autoloadMap`); `skills/joomla-helpers/scripts/instalar.sh` (`DEP` e `TODOS`); `docs/<helper>.html`; link no nav e no rodapé de **todos** os `docs/*.html`; anterior/próxima dos vizinhos; card em `docs/index.html`; seção em `docs/referencia-rapida.html`; `index.html` da raiz; ficha nas duas skills (`skills/joomla-helpers/` e `skill/`). Código PHP 8.2+ e passar em `skills/joomla-helpers/scripts/checar.sh`; `php -l` em tudo.

- [x] 1. Vigia.js: corrigir erro sem campo que trava o envio em silêncio (vai para a mensagem geral) e `min`/`max` em listas indexadas (objetos contam chaves); atualizar `docs/vigia-js.html` (remover os avisos de limitação) e testar no jsdom.
- [x] 2. Documentar helpers sem página: `LogHelper`, `PermissionHelper`, `ExportHelper` (página, nav, index, referência rápida, skills se faltar).
- [x] 3. `HttpHelper`
- [x] 4. `LockHelper`
- [x] 5. `CacheHelper`
- [x] 6. `MailHelper`
- [x] 7. `RateLimitHelper`
- [x] 8. `CryptoHelper`
- [x] 9. `PermissionHelper::requireToken()` (token do formulário ou header `X-CSRF-Token`)
- [x] 10. `js/vitrine.js` — par do Vigia para listagens (paginação/ordenação/filtros na URL, debounce, cancelamento, ações em lote) sobre `ApiResponseHelper::paginated()` e `InputHelper::pagination()/sorting()/filters()`.
- [x] 11. `FormatHelper`
- [x] 12. `WebhookHelper`
- [x] 13. `SettingsHelper`
- [x] 14. Outras pendências (âncoras quebradas da referência rápida, `ChunkUploadHelper` com `DateHelper`, `OrmBase` com `Throwable`).
- [x] 15. Revisão final: `php -l` + `checar.sh` em tudo, links dos docs, `git log` com um commit por item; atualizar a tabela "Feitos".

### Notas de retomada

- **Concluído em 2026-10-05 ~04:10.** 15/15 itens, um commit por item, sem push. Revisão final: `php -l` e `checar.sh` em todos os PHP (0 erros), `instalar.sh --todos` testado, autoload e ordem do IncludeHelper conferidos, testes do Vigia.js e da Vitrine.js no jsdom, paridade Vigia × ValidationHelper (8/8), 0 links quebrados nos docs.

- Ferramenta de docs: `/tmp/claude-1000/-developer-helpersJoomla/c10b9e3f-ad8d-495d-a7f4-258375c63021/scratchpad/docs_tool.py` (gera página a partir de `.src`, regrava nav/anterior-próxima/rodapé de todos os docs, `card()`, `ref()`, `root()`). Lista do menu em `nav.json` ao lado. Se o arquivo sumiu, a navegação é HTML estático: copie de uma página existente.
- Item 2 também corrigiu: `PermissionHelper` chamava `LogHelper::write` (privado; log de negativas nunca gravava) e expunha o contexto no JSON; `ExportHelper` usava escape vazio no `fputcsv` (só existe no PHP 7.4+).
- Scripts da noite (mesma pasta do docs_tool.py): `register.py <Helper> "<deps>" "<classes extras>"` (IncludeHelper + instalar.sh), `skill_add.py <Helper> <ficha> <secao.md> "<pedido>" "<uso>"` (duas skills). Fichas novas: `integracao.md` (Http, Mail, Webhook).

## Prioridade alta

Nada pendente (tudo entregue na execução de 2026-10-05).

## Prioridade média

Nada pendente (tudo entregue na execução de 2026-10-05).

## Prioridade baixa

Nada pendente (tudo entregue na execução de 2026-10-05).

## Outras pendências encontradas

- ~~Âncoras quebradas da referência rápida~~ (resolvido em 2026-10-05: `headers()`, `info()` e `formatBytes()` documentados).
- ~~`ChunkUploadHelper` com `date()`~~ (resolvido: usa `DateHelper::nowSql()`, senão UTC).
- ~~`OrmBase` capturando `Exception`~~ (resolvido: `Throwable` em `getTableColumns()` e `executar()`).
