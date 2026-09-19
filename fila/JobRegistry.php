<?php

defined('_JEXEC') or die;

class JobRegistry
{
    private static $jobs = array(
        'importar_csv_cooperados' => array(
            'file' => JPATH_SITE . '/components/com_generico/jobs/ImportarCsvCooperadosJob.php',
            'class' => 'ImportarCsvCooperadosJob',
            'queue' => 'imports'
        ),
        'exportar_relatorio' => array(
            'file' => JPATH_SITE . '/components/com_generico/jobs/ExportarRelatorioJob.php',
            'class' => 'ExportarRelatorioJob',
            'queue' => 'exports'
        ),
        'enviar_email' => array(
            'file' => JPATH_SITE . '/components/com_generico/jobs/EnviarEmailJob.php',
            'class' => 'EnviarEmailJob',
            'queue' => 'emails'
        ),
        'gerar_pdf' => array(
            'file' => JPATH_SITE . '/components/com_generico/jobs/GerarPdfJob.php',
            'class' => 'GerarPdfJob',
            'queue' => 'reports'
        ),
        'processar_webhook' => array(
            'file' => JPATH_SITE . '/components/com_generico/jobs/ProcessarWebhookJob.php',
            'class' => 'ProcessarWebhookJob',
            'queue' => 'webhooks'
        ),
        'limpar_arquivos_temporarios' => array(
            'file' => JPATH_SITE . '/components/com_generico/jobs/LimparArquivosJob.php',
            'class' => 'LimparArquivosJob',
            'queue' => 'maintenance'
        )
    );

    public static function register($type, $file, $class, $queue = 'default')
    {
        $type = trim((string) $type);
        $file = trim((string) $file);
        $class = trim((string) $class);
        $queue = trim((string) $queue);

        if (
            $type === '' ||
            $file === '' ||
            $class === '' ||
            $queue === ''
        ) {
            throw new InvalidArgumentException(
                'Configuração de job inválida.'
            );
        }

        self::$jobs[$type] = array(
            'file' => $file,
            'class' => $class,
            'queue' => $queue
        );
    }

    public static function has($type)
    {
        return isset(self::$jobs[$type]);
    }

    public static function getQueue($type)
    {
        if (!self::has($type)) {
            return null;
        }

        return self::$jobs[$type]['queue'];
    }

    public static function resolve($job)
    {
        if (
            empty($job) ||
            empty($job->tipo)
        ) {
            throw new InvalidArgumentException(
                'Job inválido.'
            );
        }

        $type = trim((string) $job->tipo);

        if (!self::has($type)) {
            throw new RuntimeException(
                'Tipo de job não registrado: ' . $type
            );
        }

        $config = self::$jobs[$type];

        if (!is_file($config['file'])) {
            throw new RuntimeException(
                'Arquivo do job não encontrado: ' . $type
            );
        }

        require_once $config['file'];

        if (!class_exists($config['class'])) {
            throw new RuntimeException(
                'Classe do job não encontrada: ' . $config['class']
            );
        }

        $class = $config['class'];

        $instance = new $class($job);

        if (!$instance instanceof AbstractJob) {
            throw new RuntimeException(
                'O job precisa herdar AbstractJob: ' . $type
            );
        }

        return $instance;
    }

    public static function all()
    {
        return self::$jobs;
    }

    public static function typesByQueue($queue)
    {
        $queue = trim((string) $queue);

        if ($queue === '' || $queue === 'all') {
            return array_keys(self::$jobs);
        }

        $types = array();

        foreach (self::$jobs as $type => $config) {
            if ($config['queue'] === $queue) {
                $types[] = $type;
            }
        }

        return $types;
    }
}