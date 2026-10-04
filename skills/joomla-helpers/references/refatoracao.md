# Refatoração: Joomla legado → helpers

Troque **comportamento igual por código menor e seguro**. Nada de mudar regra de negócio, nomes de tasks, URLs, formato de resposta consumido pelo front ou estrutura de tabela sem pedir.

## Tabela de troca

| Legado | Troque por | Atenção |
|---|---|---|
| `$_GET['x']`, `$_POST['x']`, `$input->get('x', 0, 'int')`, `JRequest::getVar` | `InputHelper::int('x', 0, 'get')` (string, uint, decimal, bool, email, cpf, date, dateBr, arrayOfInt…) | Mantenha a fonte (`get`/`post`) e o padrão originais |
| Vários campos lidos um a um | `InputHelper::post(['nome' => 'string', 'idade' => 'int'])` | Filtros do Joomla no mapa |
| `if (empty($x)) { $erros[] = ... }` em série | `ValidationHelper::validate($dados, $regras)` + `ApiResponseHelper::fromValidation($v)` | Copie as mensagens existentes para o 3º parâmetro |
| `$db->getQuery(true)->select()->from()->where(quote...)` + `loadObjectList` | `OrmTables::table('#__x')->where('col', $v)->get()` | `get()` devolve `stdClass[]`, como `loadObjectList` |
| `loadObject` / `loadResult` / `loadColumn` | `first()` / `value('col')` / `pluck('col')` | |
| Contagem + `JPagination` manual | `->paginate($porPagina, $pagina)` (já traz `paginacao` = `JPagination`) | |
| `insertObject` / `updateObject` | `create($dados)` / `update($id, $dados)` (timestamps e fillable automáticos) | Se a tabela não tem `created_at`/`updated_at`: `'timestamps' => false` |
| `DELETE` | `delete($id)` ou `where(...)->deleteWhere()` | Sem `where`, só com `allowMassOperation()` |
| `transactionStart/Commit/Rollback` + try/catch | `DbTransactionHelper::run(function () {...})` | Aninhamento vira savepoint; não use `transactionStart()` sem savepoint |
| SQL com variável concatenada | `where()`/`whereIn()`/`whereLike()`; em `whereRaw`, só valores com `$db->quote()` | **Correção de segurança: sempre faça** |
| `echo json_encode($r); JFactory::getApplication()->close();` | `return ApiResponseHelper::success($msg, $dados)` (error, notFound, forbidden…) | Se o front lê chaves próprias (`ok`, `msg`), confirme antes de mudar o formato |
| `$app->enqueueMessage($m); $app->redirect($url);` | `ApiResponseHelper::success($m, null, ['redirect' => $url])` | Em HTML, ele faz exatamente isso |
| `JFactory::getUser()->authorise('core.edit', 'com_x')` + `JError`/`throw` | `PermissionHelper::require('core.edit', 'com_x', $msg, $redirect)` ou `can()` | Em HTML sem `$redirect` devolve `false`: faça `return` |
| `move_uploaded_file` + checagem de extensão | `UploadMaster::upload($arquivo, $pasta, null, ['allowed_extensions' => [...], 'max_size' => ...])` | Confere o tipo real; grave `relative_path` |
| `readfile` + `header('Content-Disposition…')` | `UploadMaster::stream()` / `FileHelper::stream()` | Confira a permissão antes |
| `fopen` + `fputcsv` para download | `ExportHelper::download()` / `downloadFromQuery()` | Proteção contra CSV injection inclusa |
| `fgetcsv` em laço + `insert` | `CsvHelper::import($arq, '#__tabela', $base, [...])` | Codificação, delimitador, tipos e erros por linha |
| `file_put_contents` / `file_get_contents` / `unlink` / `scandir` | `FileHelper::write/read/delete/listFiles` | Restritos ao diretório base |
| `set_time_limit(0)` + laço longo no request | Job da fila: `QueueHelper::push()` + `AbstractJob` | Usuário acompanha por `status($uuid)` |
| `JLog::add(...)` espalhado | `LogHelper::info/error/exception($msg, $categoria, $contexto)` | |
| Gravação de "quem alterou" manual | `AuditHelper::updated($tipo, $id, $antes, $depois, ['only_changes' => true])` | Dentro da mesma transação |
| `catch (Exception $e)` | `catch (Throwable $e)` | Pega também `Error` do PHP 7 |

## Procedimento

1. Leia o arquivo inteiro uma vez e liste **o que ele faz** (tasks, entradas, saídas, efeitos no banco), em no máximo 5 linhas, para si mesmo.
2. Rode `scripts/checar.sh <arquivo>`: os AVISOS são o mapa do que trocar.
3. Garanta os helpers no componente: `scripts/instalar.sh <componente>/helpers <Helpers usados>`.
4. Reescreva por task/método, mantendo assinaturas públicas, nomes de task, rotas e o formato de resposta. Corrija na passagem: SQL concatenado, falta de `checkToken` em escrita, `Exception` → `Throwable`, upload sem checagem do tipo real.
5. Rode `scripts/checar.sh` de novo até zerar os ERROS. Os AVISOS restantes devem ter motivo.
6. Entregue: o diff (ou os arquivos), e uma lista curta "mudou / ficou igual / precisa testar".

## Não faça

- Não refatore o que não foi pedido só porque há helper para isso; mencione numa linha.
- Não troque `JControllerLegacy`/MVC do Joomla por outra arquitetura.
- Não mova SQL para `whereRaw` para "ganhar tempo".
- Não remova checagens existentes de permissão ou de token, mesmo que pareçam redundantes.
