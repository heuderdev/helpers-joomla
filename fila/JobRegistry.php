<?php

defined('_JEXEC') or die;

/*
 * Liga cada tipo de job à classe que o processa e à fila em que ele roda.
 *
 *     JobRegistry::register('importar_csv', JPATH_COMPONENT . '/jobs/ImportarCsvJob.php', 'ImportarCsvJob', 'imports');
 *     JobRegistry::register('enviar_email', null, 'EnviarEmailJob', 'emails');   // classe já carregada/autoload
 *
 * Registre os jobs num único arquivo (ex.: components/com_seu/jobs/jobs.php)
 * e carregue esse arquivo tanto no componente (para o push saber a fila)
 * quanto no worker (opção --bootstrap).
 */
class JobRegistry
{
    private static $jobs = [];

    /*
     * $file pode ser null quando a classe já estiver carregada ou tiver autoload.
     */
    public static function register($type, $file, $class, $queue = 'default')
    {
        $type = trim((string) $type);
        $file = $file === null ? '' : trim((string) $file);
        $class = trim((string) $class);
        $queue = trim((string) $queue);

        if (!preg_match('/^[a-zA-Z0-9_.-]{1,150}$/', $type)) {
            throw new InvalidArgumentException('Tipo de job inválido: "' . $type . '". Use letras, números, "_", "." e "-".');
        }

        if ($class === '' || !preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/', $class)) {
            throw new InvalidArgumentException('Classe inválida para o job "' . $type . '".');
        }

        if (!preg_match('/^[a-zA-Z0-9_.-]{1,100}$/', $queue)) {
            throw new InvalidArgumentException('Fila inválida para o job "' . $type . '".');
        }

        self::$jobs[$type] = [
            'file' => $file,
            'class' => $class,
            'queue' => $queue,
        ];
    }

    /*
     * Registra vários de uma vez:
     * ['tipo' => ['file' => ..., 'class' => ..., 'queue' => ...], ...]
     */
    public static function registerMany(array $jobs)
    {
        foreach ($jobs as $type => $config) {
            self::register(
                $type,
                isset($config['file']) ? $config['file'] : null,
                isset($config['class']) ? $config['class'] : '',
                isset($config['queue']) ? $config['queue'] : 'default'
            );
        }
    }

    public static function has($type)
    {
        return isset(self::$jobs[(string) $type]);
    }

    public static function forget($type)
    {
        unset(self::$jobs[(string) $type]);
    }

    public static function flush()
    {
        self::$jobs = [];
    }

    public static function getQueue($type)
    {
        return self::has($type) ? self::$jobs[$type]['queue'] : null;
    }

    /*
     * Cria a instância do job a partir da linha da fila.
     */
    public static function resolve($job)
    {
        if (empty($job) || empty($job->tipo)) {
            throw new InvalidArgumentException('Job inválido.');
        }

        $type = trim((string) $job->tipo);

        if (!self::has($type)) {
            throw new RuntimeException('Tipo de job não registrado: ' . $type);
        }

        $config = self::$jobs[$type];
        $class = $config['class'];

        if (!class_exists($class)) {
            if ($config['file'] === '' || !is_file($config['file'])) {
                throw new RuntimeException('Arquivo do job não encontrado para o tipo "' . $type . '": ' . $config['file']);
            }

            require_once $config['file'];
        }

        if (!class_exists($class)) {
            throw new RuntimeException('Classe do job não encontrada: ' . $class);
        }

        $instance = new $class($job);

        if (!$instance instanceof AbstractJob) {
            throw new RuntimeException('O job precisa herdar AbstractJob: ' . $class);
        }

        return $instance;
    }

    public static function all()
    {
        return self::$jobs;
    }

    /*
     * Tipos registrados numa fila. '' ou 'all' devolve todos.
     */
    public static function typesByQueue($queue)
    {
        $queue = trim((string) $queue);

        if ($queue === '' || $queue === 'all') {
            return array_keys(self::$jobs);
        }

        $types = [];

        foreach (self::$jobs as $type => $config) {
            if ($config['queue'] === $queue) {
                $types[] = $type;
            }
        }

        return $types;
    }
}
