<?php

defined('_JEXEC') or die;

/*
 * Base de todo job. Implemente handle() e, dentro dele, use:
 *
 *     $this->getPayload('chave')      dados enviados no push()
 *     $this->progress($feito, $total) atualiza a barra e renova o lock
 *     $this->shouldStop()             hora de parar? (tempo acabando, job cancelado, worker encerrando)
 *     $this->release()                pausa e volta à fila (não gasta tentativa)
 *     $this->complete([...])          conclui com um resultado
 *     $this->fail('motivo')           erro: tenta de novo mais tarde
 *
 * Se handle() terminar sem chamar complete/release/fail, o worker conclui
 * o job com o retorno de handle() como resultado. Se lançar exceção, o
 * worker chama fail() com a mensagem.
 */
abstract class AbstractJob
{
    protected $job;

    protected $payload = [];

    private $iniciadoEm;

    private $ultimoHeartbeat = 0.0;

    private $perdeuLock = false;

    private $finalizado = false;

    /*
     * Intervalo mínimo entre heartbeats automáticos de shouldStop(), em segundos.
     */
    protected $intervaloHeartbeat = 15;

    public function __construct($job)
    {
        $this->job = $job;
        $this->payload = isset($job->payload) && is_array($job->payload) ? $job->payload : [];
        $this->iniciadoEm = microtime(true);
        $this->ultimoHeartbeat = $this->iniciadoEm;
    }

    abstract public function handle();

    /* -------------------------- dados do job -------------------------- */

    public function getJob()
    {
        return $this->job;
    }

    public function getPayload($key = null, $default = null)
    {
        if ($key === null) {
            return $this->payload;
        }

        return array_key_exists($key, $this->payload) ? $this->payload[$key] : $default;
    }

    public function getId()
    {
        return (int) $this->job->id;
    }

    public function getUuid()
    {
        return (string) $this->job->uuid;
    }

    public function getType()
    {
        return (string) $this->job->tipo;
    }

    public function getQueue()
    {
        return (string) $this->job->queue;
    }

    public function getUserId()
    {
        return isset($this->job->usuario_id) ? $this->job->usuario_id : null;
    }

    public function getLockToken()
    {
        return (string) $this->job->lock_token;
    }

    /*
     * Número desta tentativa (1 na primeira). Pausas com release() não contam.
     */
    public function getAttempt()
    {
        return (int) $this->job->tentativas;
    }

    public function getMaxAttempts()
    {
        return (int) $this->job->max_tentativas;
    }

    public function isLastAttempt()
    {
        return $this->getAttempt() >= $this->getMaxAttempts();
    }

    /*
     * Progresso salvo até agora: use para retomar um job liberado com release().
     */
    public function getProgress()
    {
        return isset($this->job->progresso) ? (float) $this->job->progresso : 0.0;
    }

    public function timeoutSeconds()
    {
        return isset($this->job->timeout_seconds) && (int) $this->job->timeout_seconds > 0
            ? (int) $this->job->timeout_seconds
            : 240;
    }

    public function retryAfterSeconds()
    {
        return isset($this->job->retry_after_seconds) && (int) $this->job->retry_after_seconds > 0
            ? (int) $this->job->retry_after_seconds
            : 300;
    }

    /* ----------------------------- tempo ----------------------------- */

    public function elapsedSeconds()
    {
        return microtime(true) - $this->iniciadoEm;
    }

    /*
     * Segundos até o timeout desta execução (timeout_seconds do job).
     */
    public function remainingSeconds()
    {
        return max(0.0, $this->timeoutSeconds() - $this->elapsedSeconds());
    }

    /*
     * true quando o job deve parar e devolver o controle:
     *   - faltam menos de $margemSegundos para o timeout;
     *   - o job foi cancelado ou o lock foi perdido (checado com heartbeat
     *     no máximo a cada $intervaloHeartbeat segundos);
     *   - o worker recebeu pedido para encerrar (SIGTERM/SIGINT).
     *
     * Uso típico num laço longo:
     *
     *     if ($this->shouldStop()) {
     *         return $this->release();      // continua de onde parou na próxima vez
     *     }
     */
    public function shouldStop($margemSegundos = 10)
    {
        if ($this->perdeuLock || $this->remainingSeconds() <= $margemSegundos) {
            return true;
        }

        if (class_exists('QueueWorker', false) && QueueWorker::stopRequested()) {
            return true;
        }

        if (microtime(true) - $this->ultimoHeartbeat >= $this->intervaloHeartbeat) {
            $this->heartbeat();
        }

        return $this->perdeuLock;
    }

    /*
     * true se o job foi cancelado ou outro worker o assumiu.
     */
    public function lostLock()
    {
        return $this->perdeuLock;
    }

    /* ------------------------------ ações ------------------------------ */

    public function heartbeat()
    {
        $ok = QueueHelper::heartbeat($this->getId(), $this->getLockToken());

        return $this->registrarSinal($ok);
    }

    public function progress($progresso, $total = null)
    {
        $ok = QueueHelper::progress($this->getId(), $this->getLockToken(), $progresso, $total);

        if ($ok) {
            $this->job->progresso = (float) $progresso;

            if ($total !== null) {
                $this->job->total = (float) $total;
            }
        }

        return $this->registrarSinal($ok);
    }

    /*
     * Pausa o job e devolve à fila. Por padrão não gasta tentativa.
     * $contaTentativa = true para "tentar de novo mais tarde" contando
     * como tentativa (ex.: API externa fora do ar).
     */
    public function release($delaySeconds = 0, $contaTentativa = false)
    {
        return $this->finalizar(QueueHelper::release($this->getId(), $this->getLockToken(), [
            'delay_seconds' => (int) $delaySeconds,
            'count_attempt' => (bool) $contaTentativa,
        ]));
    }

    public function complete(array $resultado = [])
    {
        return $this->finalizar(QueueHelper::complete($this->getId(), $this->getLockToken(), $resultado));
    }

    /*
     * Registra erro. $definitivo = true falha de vez, sem novas tentativas.
     */
    public function fail($mensagem, $definitivo = false)
    {
        return $this->finalizar(QueueHelper::fail($this->getId(), $this->getLockToken(), $mensagem, [
            'permanent' => (bool) $definitivo,
        ]));
    }

    /*
     * true depois que complete(), release() ou fail() deu certo. O worker
     * usa para saber se precisa concluir o job automaticamente.
     */
    public function isFinished()
    {
        return $this->finalizado;
    }

    private function registrarSinal($ok)
    {
        $this->ultimoHeartbeat = microtime(true);

        if (!$ok) {
            $this->perdeuLock = true;
        }

        return $ok;
    }

    private function finalizar($ok)
    {
        if ($ok) {
            $this->finalizado = true;
        }

        return $ok;
    }
}
