<?php

define('_JEXEC', 1);

if (file_exists(dirname(__DIR__) . '/defines.php')) {
    require_once dirname(__DIR__) . '/defines.php';
}

if (!defined('_JDEFINES')) {
    define('JPATH_BASE', dirname(__DIR__));

    require_once JPATH_BASE . '/includes/defines.php';
}

require_once JPATH_LIBRARIES . '/import.legacy.php';
require_once JPATH_LIBRARIES . '/cms.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

class QueueWorkerCli extends JApplicationCli
{
    public function doExecute()
    {
        require_once JPATH_SITE . '/components/com_generico/helpers/QueueHelper.php';
        require_once JPATH_SITE . '/components/com_generico/helpers/OrmBase.php';
        require_once JPATH_SITE . '/components/com_generico/helpers/OrmTables.php';
        require_once JPATH_SITE . '/components/com_generico/helpers/ChunkHelper.php';
        require_once JPATH_SITE . '/components/com_generico/helpers/CsvHelper.php';
        require_once JPATH_SITE . '/components/com_generico/helpers/DbTransactionHelper.php';
        require_once JPATH_SITE . '/components/com_generico/helpers/LogHelper.php';
        require_once JPATH_SITE . '/components/com_generico/helpers/AuditHelper.php';
        require_once JPATH_SITE . '/components/com_generico/jobs/AbstractJob.php';
        require_once JPATH_SITE . '/components/com_generico/jobs/JobRegistry.php';

        $options = $this->getInput()->getArray(
            array(
                'queue' => 'CMD',
                'once' => 'BOOL',
                'max-jobs' => 'INT',
                'max-time' => 'INT',
                'sleep' => 'INT'
            )
        );

        $queue = isset($options['queue']) &&
            trim((string) $options['queue']) !== ''
                ? trim((string) $options['queue'])
                : 'all';

        $once = !empty($options['once']);

        $maxJobs = isset($options['max-jobs'])
            ? max(1, (int) $options['max-jobs'])
            : 1;

        $maxTime = isset($options['max-time'])
            ? max(10, (int) $options['max-time'])
            : 240;

        $sleep = isset($options['sleep'])
            ? max(1, (int) $options['sleep'])
            : 5;

        LogHelper::setBaseDirectory(
            JPATH_SITE . '/components/com_generico/logs'
        );

        LogHelper::setDefaultCategory(
            'com_generico.queue'
        );

        QueueHelper::releaseExpiredLocks(1800);

        $tipos = JobRegistry::typesByQueue($queue);

        if (empty($tipos)) {
            $this->out(
                'Nenhum job registrado para a fila: ' . $queue
            );

            return;
        }

        $iniciadoEm = time();
        $processados = 0;

        while (true) {
            if ((time() - $iniciadoEm) >= $maxTime) {
                $this->out(
                    'Worker finalizado por limite de tempo.'
                );

                break;
            }

            if ($processados >= $maxJobs) {
                $this->out(
                    'Worker finalizado por limite de jobs.'
                );

                break;
            }

            $job = QueueHelper::next(
                $tipos,
                $queue === 'all'
                    ? null
                    : $queue
            );

            if (!$job) {
                if ($once) {
                    $this->out(
                        'Nenhum job pendente encontrado.'
                    );

                    break;
                }

                sleep($sleep);

                continue;
            }

            $processados++;

            $this->out(
                'Processando job #' .
                $job->id .
                ' [' .
                $job->tipo .
                ']'
            );

            try {
                $handler = JobRegistry::resolve($job);

                $resultado = $handler->handle();

                LogHelper::info(
                    'Job processado pelo worker CLI.',
                    'com_generico.queue',
                    array(
                        'job_id' => $job->id,
                        'job_uuid' => $job->uuid,
                        'tipo' => $job->tipo,
                        'resultado' => $resultado
                    )
                );

                $this->out(
                    'Job #' .
                    $job->id .
                    ' processado com sucesso.'
                );
            } catch (Throwable $erro) {
                QueueHelper::fail(
                    $job->id,
                    $job->lock_token,
                    $erro->getMessage()
                );

                LogHelper::exception(
                    $erro,
                    'com_generico.queue',
                    array(
                        'job_id' => $job->id,
                        'job_uuid' => $job->uuid,
                        'tipo' => $job->tipo
                    )
                );

                $this->out(
                    'Falha no job #' .
                    $job->id .
                    ': ' .
                    $erro->getMessage()
                );
            }

            if ($once) {
                break;
            }
        }
    }
}

JApplicationCli::getInstance('QueueWorkerCli')->execute();