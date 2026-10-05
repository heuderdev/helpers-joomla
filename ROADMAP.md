# Roadmap de helpers

Helpers que ainda faltam para cobrir os problemas do dia a dia em componentes Joomla. Cada novo helper segue o padrão do repositório: Joomla 3.4.5+/4/5, PHP 7.0+, registrado no `IncludeHelper` e no `instalar.sh` da skill, página em `docs/`, ficha nas duas skills (`skills/joomla-helpers` e `skill/`).

## Feitos

| Helper | O que resolve |
|---|---|
| `DateHelper` | Datas e fuso horário: grava em UTC, mostra no fuso do usuário, lê formulários e monta períodos de relatório. `OrmBase` e `QueueHelper` já o usam. |
| `Vigia.js` (`js/`) | Formulário no navegador: mesmas regras e mensagens do `ValidationHelper`, erros 422 nos campos, máscaras brasileiras, axios com token CSRF, Alpine opcional. `ValidationHelper::clientConfig()` exporta as regras do PHP. |

## Execução noturna (iniciada em 2026-10-05 01:15, entrega 08:00)

Fila de trabalho em ordem. Quem retomar (sessão nova ou depois do limite de uso): pegue o **primeiro item não marcado**, leia "Notas de retomada" e continue. Ao terminar um item: marque `[x]`, faça um commit local só daquele item (`[main]: feat/docs/fix: ...`) e siga para o próximo. Não fazer `git push` (o Eduardo/Heuder revisa de manhã).

Checklist de cada helper novo (memória `helper-checklist`): `IncludeHelper::$dependencies` (e `$autoloadMap`); `skills/joomla-helpers/scripts/instalar.sh` (`DEP` e `TODOS`); `docs/<helper>.html`; link no nav e no rodapé de **todos** os `docs/*.html`; anterior/próxima dos vizinhos; card em `docs/index.html`; seção em `docs/referencia-rapida.html`; `index.html` da raiz; ficha nas duas skills (`skills/joomla-helpers/` e `skill/`). Código PHP 7.0+ e passar em `skills/joomla-helpers/scripts/checar.sh`; `php -l` em tudo.

- [x] 1. Vigia.js: corrigir erro sem campo que trava o envio em silêncio (vai para a mensagem geral) e `min`/`max` em listas indexadas (objetos contam chaves); atualizar `docs/vigia-js.html` (remover os avisos de limitação) e testar no jsdom.
- [x] 2. Documentar helpers sem página: `LogHelper`, `PermissionHelper`, `ExportHelper` (página, nav, index, referência rápida, skills se faltar).
- [ ] 3. `HttpHelper`
- [ ] 4. `LockHelper`
- [ ] 5. `CacheHelper`
- [ ] 6. `MailHelper`
- [ ] 7. `RateLimitHelper`
- [ ] 8. `CryptoHelper`
- [ ] 9. `PermissionHelper::requireToken()` (token do formulário ou header `X-CSRF-Token`)
- [ ] 10. `js/lista.js` — par do Vigia para listagens (paginação/ordenação/filtros na URL, debounce, cancelamento, ações em lote) sobre `ApiResponseHelper::paginated()` e `InputHelper::pagination()/sorting()/filters()`.
- [ ] 11. `FormatHelper`
- [ ] 12. `WebhookHelper`
- [ ] 13. `SettingsHelper`
- [ ] 14. Outras pendências (âncoras quebradas da referência rápida, `ChunkUploadHelper` com `DateHelper`, `OrmBase` com `Throwable`).
- [ ] 15. Revisão final: `php -l` + `checar.sh` em tudo, links dos docs, `git log` com um commit por item; atualizar a tabela "Feitos".

### Notas de retomada

- Ferramenta de docs: `/tmp/claude-1000/-developer-helpersJoomla/c10b9e3f-ad8d-495d-a7f4-258375c63021/scratchpad/docs_tool.py` (gera página a partir de `.src`, regrava nav/anterior-próxima/rodapé de todos os docs, `card()`, `ref()`, `root()`). Lista do menu em `nav.json` ao lado. Se o arquivo sumiu, a navegação é HTML estático: copie de uma página existente.
- Item 2 também corrigiu: `PermissionHelper` chamava `LogHelper::write` (privado; log de negativas nunca gravava) e expunha o contexto no JSON; `ExportHelper` usava escape vazio no `fputcsv` (só existe no PHP 7.4+).

## Prioridade alta

| Helper | O que resolve | Pontos principais |
|---|---|---|
| `HttpHelper` | Chamadas a APIs externas (ERP, pagamento, frete, WhatsApp). Hoje nenhum helper usa curl ou `JHttp`. | Timeout padrão (sem ele, uma API lenta trava o site); retry com espera crescente só em 5xx, 429 e erro de rede; JSON de ida e volta; Bearer/Basic; log da chamada com token mascarado pelo `LogHelper`; resposta `{status, ok, body, json, headers, tempo_ms}`. |
| `LockHelper` | A mesma tarefa rodando duas vezes ao mesmo tempo (cron que se sobrepõe, clique duplo em "gerar boletos", importação concorrente). | `GET_LOCK` no MySQL, `pg_advisory_lock` no PostgreSQL e `flock` sem banco; `LockHelper::run('nome', $callback, $timeout)`. O `lockForUpdate()` trava linhas, não uma tarefa inteira. |
| `CacheHelper` | Resultados caros recalculados a cada requisição (dashboards, totais, respostas de API externa, listas de selects). | `remember($chave, $segundos, $callback)`, `forget()`, invalidação por prefixo, sobre o cache do Joomla 3/4/5. |
| `MailHelper` | E-mails transacionais. | Template com variáveis, HTML com texto alternativo, anexos, `replyTo`, cópia oculta, log de falha e `MailHelper::queue()` para enviar pela fila. |

## Prioridade média

| Helper | O que resolve | Pontos principais |
|---|---|---|
| `RateLimitHelper` | Abuso de login, formulário de contato, envio de SMS/código e endpoints públicos. | `hit('login:' . $ip, 5, 900)`; resposta 429 pelo `ApiResponseHelper`. |
| `CryptoHelper` | Segredos guardados no banco (tokens de API, credenciais de integração) e webhooks assinados. | Criptografia com `sodium` e a chave do `configuration.php`; `hmac()` e `verificarAssinatura()` para webhooks; tokens aleatórios seguros. |
| CSRF em `fetch` (método no `PermissionHelper`) | Nenhum helper confere o token do Joomla em requisições JSON. | `PermissionHelper::requireToken()`: aceita o token do formulário ou do header `X-CSRF-Token`, responde 403. |

## Prioridade baixa

| Helper | O que resolve |
|---|---|
| `FormatHelper` / `TextHelper` | Dinheiro em centavos ↔ `R$ 1.234,56`, máscaras de CPF/CNPJ/CEP/telefone, slug e transliteração, `limitar($texto, 100)`. |
| `WebhookHelper` | Receber webhooks com verificação de assinatura, sem processar o mesmo evento duas vezes, repassando o processamento para a fila (Crypto + Queue + ApiResponse). |
| `SettingsHelper` | Configurações do componente com padrão e tipo (`SettingsHelper::int('limite_upload', 10)`) em vez de `getParams()` espalhado. |

## Outras pendências encontradas

- `docs/referencia-rapida.html` aponta para âncoras que não existem: `csv-helper.html#headers`, `chunk-upload-helper.html#info` e `chunk-upload-helper.html#formatBytes`.
- `ChunkUploadHelper.php` (linha ~1943) usa `date('Y-m-d H:i:s')` como alternativa quando o `JFactory` não existe; pode passar a usar o `DateHelper`.
- `OrmBase.php` captura `Exception` em `getTableColumns()` e `executar()`; o `checar.sh` recomenda `Throwable` (pega também `Error` do PHP 7).
