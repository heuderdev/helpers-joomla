# Fila: QueueHelper, AbstractJob, JobRegistry, QueueWorker, worker CLI

Para tarefas demoradas (importar CSV, PDFs, e-mails em massa, APIs lentas): o controller enfileira e responde na hora; um worker executa em segundo plano.

## Estrutura

```
components/com_x/helpers/QueueHelper.php
components/com_x/helpers/fila/{AbstractJob,JobRegistry,QueueWorker}.php + fila/cli/queue-worker.php
components/com_x/jobs/jobs.php             ← registro (carregado no componente e no worker)
components/com_x/jobs/MeuJob.php
```
Tabela: `fila/table.sql` (MySQL), `fila/table.postgresql.sql`, ou `QueueHelper::createTable()`, ou `queue-worker.php --install`.

## 1. Job

```php
class ImportarClientesJob extends AbstractJob
{
    public function handle()
    {
        $id = (int) $this->getPayload('importacao_id');
        if ($id <= 0) return $this->fail('Importação inválida.', true);   // true = falha de vez

        // trabalho em partes, retomando do ponto salvo:
        $feito = (int) $this->getProgress();
        while ($feito < $total) {
            // ... processa o próximo bloco ...
            $feito += 500;
            $this->progress($feito, $total);                  // salva o ponto e renova o lock
            if ($this->shouldStop()) return $this->release(); // tempo acabando/cancelado/SIGTERM → continua depois (não gasta tentativa)
        }
        return ['linhas' => $feito];                          // array devolvido = resultado; o worker conclui
    }
}
```
- Fim do `handle()`: array/nada → conclui automaticamente; exceção → `fail` (nova tentativa com espera 30s, 60s, 120s… até 1h); erro fatal/timeout/`exit` → `fail` pelo shutdown handler.
- Dados: `getPayload($k, $padrao)`, `getId`, `getUuid`, `getType`, `getQueue`, `getUserId`, `getAttempt` (1 na primeira), `getMaxAttempts`, `isLastAttempt`, `getProgress`.
- Tempo: `remainingSeconds`, `elapsedSeconds`, `shouldStop($margem = 10)`, `lostLock`.
- Ações (bool): `progress($feito, $total)`, `heartbeat()`, `complete($resultado)`, `release($atraso = 0, $contaTentativa = false)`, `fail($msg, $definitivo = false)`.
- **Jobs devem poder rodar de novo** sem duplicar efeito (o worker pode morrer depois do trabalho e antes de concluir).

## 2. Registro (`jobs.php`)

```php
if (!class_exists('QueueHelper')) {
    require_once JPATH_SITE . '/components/com_x/helpers/QueueHelper.php';
    require_once JPATH_SITE . '/components/com_x/helpers/fila/AbstractJob.php';
    require_once JPATH_SITE . '/components/com_x/helpers/fila/JobRegistry.php';
}
JobRegistry::register('importar_clientes', JPATH_SITE . '/components/com_x/jobs/ImportarClientesJob.php', 'ImportarClientesJob', 'imports');
// registerMany([...]), has, getQueue, typesByQueue, forget, flush
QueueHelper::setLogDirectory('components/com_x/logs');   // opcional
```

## 3. Enfileirar (controller)

```php
$r = QueueHelper::push('importar_clientes', ['importacao_id' => $id], [
    'usuario_id' => (int) JFactory::getUser()->id,
    'unico' => ['importacao_id'],      // ou true: não duplica job ativo igual (clique duplo)
    // 'prioridade' => 10, 'delay_seconds' => 60, 'disponivel_em' => 'Y-m-d H:i:s (UTC)',
    // 'max_tentativas' => 3, 'timeout_seconds' => 240, 'retry_after_seconds' => 300, 'queue' => 'imports', 'total' => 0,
]);
if (!$r['success']) return ApiResponseHelper::error($r['mensagem']);          // motivo em $r['erro']
return ApiResponseHelper::created('Na fila.', ['uuid' => $r['data']['uuid']]);  // data: id, uuid, queue, tipo, status, duplicado
```
A fila sai do `JobRegistry` (carregue `jobs.php` antes do `push`).

## 4. Acompanhar / gerenciar

```php
$s = QueueHelper::status($uuid, $userId);   // ['success', 'data' => [status, percentual, progresso, total, finalizado, resultado, erro, tentativas, ...]] sem payload
QueueHelper::cancel($uuid, $userId);         // pendente/retry; em processamento só com 3º parâmetro true
QueueHelper::retry($uuid, $userId);          // failed/cancelled → pending (['reset_progress' => true])
QueueHelper::list(['queue' => 'imports', 'status' => ['failed', 'retry'], 'usuario_id' => 1, 'date_start' => '-7 days'], 50, 0);
QueueHelper::count($filtros); QueueHelper::stats(['queue' => 'imports']);   // por status + mais_antigo_pendente
QueueHelper::purge('-30 days');              // apaga finalizados antigos (1000 por chamada)
QueueHelper::lastError();                    // motivo da última falha (os métodos de ação devolvem bool)
```
Status: `pending` → `processing` → `completed` | `retry` | `failed` | `cancelled`. Mostre ao usuário uma mensagem própria, não o `erro` técnico.

## 5. Rodar o worker

```bash
# teste
php components/com_x/helpers/fila/cli/queue-worker.php --bootstrap=components/com_x/jobs/jobs.php --once
# cron a cada minuto
* * * * * php /site/components/com_x/helpers/fila/cli/queue-worker.php --bootstrap=/site/components/com_x/jobs/jobs.php --max-time=55 --stop-when-empty --quiet
# permanente (supervisor/systemd, stopsignal=TERM)
php .../queue-worker.php --bootstrap=... --queue=imports --max-time=3600
```
Opções: `--queue`, `--once`, `--stop-when-empty`, `--max-jobs`, `--max-time` (padrão 240; 0 = sem limite), `--memory` (256), `--sleep` (3), `--root`, `--install`, `--stats`, `--quiet`. Funciona em Joomla 3, 4 e 5. No 4/5 a pasta `installation/` não pode existir. Reinicie workers permanentes após deploy.

Sem linha de comando: controller protegido por segredo (`hash_equals`) chamando `QueueWorker::run(['max_time' => 25, 'stop_when_empty' => true])`, acionado por cron externo. Retorno: `processados`, `concluidos`, `liberados`, `falhas`, `cancelados`, `motivo`.

## Tempos

`timeout_seconds` (240) = tempo máximo de uma execução. `retry_after_seconds` (300) = tempo sem `progress()`/`heartbeat()` para considerar o worker morto e outro assumir. Mantenha `retry_after > timeout` ou chame `progress()` com frequência. No Linux o limite do PHP conta só CPU; use `shouldStop()` em laços longos.
