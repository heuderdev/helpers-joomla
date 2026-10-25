<?php

/*
 * Worker da fila pela linha de comando. Funciona no Joomla 3, 4 e 5.
 *
 *   php queue-worker.php --bootstrap=/caminho/jobs.php [opções]
 *
 * Opções:
 *   --root=DIR          raiz do Joomla (padrão: procurada a partir desta pasta)
 *   --bootstrap=ARQ     arquivo PHP que registra os jobs no JobRegistry (e carrega o que eles usam)
 *   --queue=NOME        fila a consumir (padrão: all)
 *   --once              processa no máximo um job e sai
 *   --stop-when-empty   sai quando a fila esvaziar
 *   --max-jobs=N        sai depois de N jobs (0 = sem limite)
 *   --max-time=N        sai depois de N segundos (padrão 240; 0 = sem limite, para supervisor/systemd)
 *   --memory=N          sai quando passar de N MB (padrão 256)
 *   --sleep=N           espera entre consultas com a fila vazia (padrão 3)
 *   --install           cria a tabela da fila, se não existir, e sai
 *   --stats             mostra a quantidade de jobs por status e sai
 *   --quiet             não escreve nada na saída
 *
 * Exemplos:
 *   Cron a cada minuto:      * * * * * php /site/cli/queue-worker.php --bootstrap=/site/components/com_x/jobs/jobs.php --max-time=55 --stop-when-empty
 *   Processo permanente:     php queue-worker.php --bootstrap=... --queue=imports --max-time=0    (sob supervisor/systemd)
 *
 * Saída: 0 em sucesso, 1 em erro de inicialização.
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 403 Forbidden');
    exit('Somente pela linha de comando.');
}

if (version_compare(PHP_VERSION, '7.0.0', '<')) {
    fwrite(STDERR, "O worker precisa de PHP 7.0 ou superior.\n");
    exit(1);
}

$opcoes = queueWorkerArgs($argv);

if (isset($opcoes['help']) || isset($opcoes['h'])) {
    $ajuda = file_get_contents(__FILE__);
    preg_match('#/\*(.*?)\*/#s', $ajuda, $m);
    echo preg_replace('/^[ \t]*\* ?/m', '', trim($m[1])), PHP_EOL;
    exit(0);
}

$quiet = isset($opcoes['quiet']);
$saida = function ($mensagem) use ($quiet) {
    if (!$quiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $mensagem . PHP_EOL;
    }
};

try {
    $raiz = queueWorkerRoot(isset($opcoes['root']) ? $opcoes['root'] : null);
    queueWorkerBootJoomla($raiz);

    // Helpers: QueueHelper fica uma pasta acima de fila/; os demais, em fila/.
    $pastaFila = dirname(__DIR__);

    foreach ([dirname($pastaFila) . '/QueueHelper.php', $pastaFila . '/AbstractJob.php', $pastaFila . '/JobRegistry.php', $pastaFila . '/QueueWorker.php'] as $arquivo) {
        if (!is_file($arquivo)) {
            throw new RuntimeException('Arquivo não encontrado: ' . $arquivo);
        }

        require_once $arquivo;
    }

    if (!empty($opcoes['bootstrap'])) {
        if (!is_file($opcoes['bootstrap'])) {
            throw new RuntimeException('Arquivo de --bootstrap não encontrado: ' . $opcoes['bootstrap']);
        }

        require_once $opcoes['bootstrap'];
    }

    if (isset($opcoes['install'])) {
        $saida(QueueHelper::createTable() ? 'Tabela da fila criada.' : 'A tabela da fila já existe.');
        exit(0);
    }

    if (isset($opcoes['stats'])) {
        foreach (QueueHelper::stats(isset($opcoes['queue']) ? ['queue' => $opcoes['queue']] : []) as $chave => $valor) {
            echo str_pad($chave, 22), $valor === null ? '-' : $valor, PHP_EOL;
        }

        exit(0);
    }

    QueueWorker::run([
        'queue' => isset($opcoes['queue']) ? $opcoes['queue'] : 'all',
        'once' => isset($opcoes['once']),
        'stop_when_empty' => isset($opcoes['stop-when-empty']),
        'max_jobs' => isset($opcoes['max-jobs']) ? (int) $opcoes['max-jobs'] : 0,
        // 240s por padrão: um cron que chama o script sem opções não acumula processos.
        'max_time' => isset($opcoes['max-time']) ? (int) $opcoes['max-time'] : 240,
        'memory_mb' => isset($opcoes['memory']) ? (int) $opcoes['memory'] : 256,
        'sleep' => isset($opcoes['sleep']) ? (int) $opcoes['sleep'] : 3,
        'output' => $saida,
    ]);

    exit(0);
} catch (Throwable $erro) {
    fwrite(STDERR, 'Erro: ' . $erro->getMessage() . PHP_EOL);
    exit(1);
}

/*
 * --chave=valor, --chave valor e --flag.
 */
function queueWorkerArgs(array $argv)
{
    $opcoes = [];
    $total = count($argv);

    for ($i = 1; $i < $total; $i++) {
        if (strpos($argv[$i], '--') !== 0) {
            continue;
        }

        $arg = substr($argv[$i], 2);

        if (strpos($arg, '=') !== false) {
            list($chave, $valor) = explode('=', $arg, 2);
            $opcoes[$chave] = $valor;
        } elseif ($i + 1 < $total && strpos($argv[$i + 1], '--') !== 0 && in_array($arg, ['root', 'bootstrap', 'queue', 'max-jobs', 'max-time', 'memory', 'sleep'], true)) {
            $opcoes[$arg] = $argv[++$i];
        } else {
            $opcoes[$arg] = true;
        }
    }

    return $opcoes;
}

/*
 * Sobe a partir desta pasta até achar configuration.php + includes/defines.php.
 */
function queueWorkerRoot($informada)
{
    if ($informada !== null) {
        $raiz = realpath($informada);

        if ($raiz === false || !is_file($raiz . '/configuration.php')) {
            throw new RuntimeException('--root não aponta para uma instalação do Joomla: ' . $informada);
        }

        return $raiz;
    }

    $pasta = __DIR__;

    while ($pasta !== dirname($pasta)) {
        if (is_file($pasta . '/configuration.php') && is_file($pasta . '/includes/defines.php')) {
            return $pasta;
        }

        $pasta = dirname($pasta);
    }

    throw new RuntimeException('Raiz do Joomla não encontrada a partir de ' . __DIR__ . '. Informe --root=/caminho/do/site.');
}

/*
 * Carrega o Joomla sem página web: o banco, a configuração, o log e uma
 * aplicação de linha de comando ficam disponíveis para os jobs.
 */
function queueWorkerBootJoomla($raiz)
{
    define('_JEXEC', 1);
    define('JPATH_BASE', $raiz);

    $_SERVER['HTTP_HOST'] = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $_SERVER['REQUEST_METHOD'] = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

    if (is_file($raiz . '/defines.php')) {
        require_once $raiz . '/defines.php';
    }

    if (!defined('_JDEFINES')) {
        require_once $raiz . '/includes/defines.php';
    }

    // libraries/bootstrap.php só existe a partir do Joomla 4 (o 4 e o 5
    // ainda têm um import.legacy.php vazio, que não serve como teste).
    if (!is_file(JPATH_LIBRARIES . '/bootstrap.php')) {
        // Joomla 3
        require_once JPATH_LIBRARIES . '/import.legacy.php';
        require_once JPATH_LIBRARIES . '/cms.php';

        if (!class_exists('QueueWorkerCliApplication', false)) {
            // Aplicação mínima: só para JFactory::getApplication() funcionar dentro dos jobs.
            class QueueWorkerCliApplication extends JApplicationCli
            {
                protected function doExecute()
                {
                }
            }
        }

        JFactory::getConfig(JPATH_CONFIGURATION . '/configuration.php');
        JFactory::$application = JApplicationCli::getInstance('QueueWorkerCliApplication');

        return;
    }

    // Joomla 4 e 5
    require_once JPATH_BASE . '/includes/framework.php';

    $container = \Joomla\CMS\Factory::getContainer();
    $container->alias('session', 'session.cli')
        ->alias('JSession', 'session.cli')
        ->alias(\Joomla\CMS\Session\Session::class, 'session.cli')
        ->alias(\Joomla\Session\Session::class, 'session.cli')
        ->alias(\Joomla\Session\SessionInterface::class, 'session.cli');

    \Joomla\CMS\Factory::$application = $container->get(\Joomla\Console\Application::class);

    // Joomla 5: os nomes antigos (JFactory, JLog…), usados por helpers e
    // jobs, vêm do plugin de compatibilidade, que não roda na linha de
    // comando. Carrega o mapa de nomes dele.
    $classmap = JPATH_PLUGINS . '/behaviour/compat/src/classmap/classmap.php';

    if (!class_exists('JFactory') && is_file($classmap)) {
        require_once $classmap;
    }

    $aliases = [
        'JFactory' => 'Joomla\\CMS\\Factory',
        'JLog' => 'Joomla\\CMS\\Log\\Log',
        'JDate' => 'Joomla\\CMS\\Date\\Date',
        'JText' => 'Joomla\\CMS\\Language\\Text',
        'JUri' => 'Joomla\\CMS\\Uri\\Uri',
    ];

    foreach ($aliases as $antigo => $novo) {
        if (!class_exists($antigo) && class_exists($novo)) {
            class_alias($novo, $antigo);
        }
    }
}
