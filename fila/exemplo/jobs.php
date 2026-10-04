<?php

defined('_JEXEC') or die;

/*
 * Registro dos jobs do componente. Carregue este arquivo:
 *   - no componente (ex.: no controller principal), para o push() saber a fila de cada tipo;
 *   - no worker, com --bootstrap=/caminho/para/jobs.php.
 *
 * Também é o lugar para carregar os helpers que os jobs usam.
 */

$base = JPATH_SITE . '/components/com_generico';

// No worker estas classes já vêm carregadas.
if (!class_exists('QueueHelper')) {
    require_once $base . '/helpers/QueueHelper.php';
    require_once $base . '/helpers/fila/AbstractJob.php';
    require_once $base . '/helpers/fila/JobRegistry.php';
}

JobRegistry::registerMany([
    'importar_csv_cooperados' => ['file' => $base . '/jobs/ImportarCsvCooperadosJob.php', 'class' => 'ImportarCsvCooperadosJob', 'queue' => 'imports'],
    'exportar_relatorio' => ['file' => $base . '/jobs/ExportarRelatorioJob.php', 'class' => 'ExportarRelatorioJob', 'queue' => 'exports'],
    'enviar_email' => ['file' => $base . '/jobs/EnviarEmailJob.php', 'class' => 'EnviarEmailJob', 'queue' => 'emails'],
    'gerar_pdf' => ['file' => $base . '/jobs/GerarPdfJob.php', 'class' => 'GerarPdfJob', 'queue' => 'reports'],
    'processar_webhook' => ['file' => $base . '/jobs/ProcessarWebhookJob.php', 'class' => 'ProcessarWebhookJob', 'queue' => 'webhooks'],
    'limpar_arquivos_temporarios' => ['file' => $base . '/jobs/LimparArquivosJob.php', 'class' => 'LimparArquivosJob', 'queue' => 'maintenance'],
]);

QueueHelper::setLogDirectory('components/com_generico/logs');
