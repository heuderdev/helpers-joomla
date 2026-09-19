<?php

defined('_JEXEC') or die;

abstract class AbstractJob
{
    protected $job;

    protected $payload = array();

    public function __construct($job)
    {
        $this->job = $job;

        $this->payload = isset($job->payload) && is_array($job->payload)
            ? $job->payload
            : array();
    }

    public function getJob()
    {
        return $this->job;
    }

    public function getPayload($key = null, $default = null)
    {
        if ($key === null) {
            return $this->payload;
        }

        return array_key_exists($key, $this->payload)
            ? $this->payload[$key]
            : $default;
    }

    public function getId()
    {
        return (int) $this->job->id;
    }

    public function getUuid()
    {
        return (string) $this->job->uuid;
    }

    public function getLockToken()
    {
        return (string) $this->job->lock_token;
    }

    public function progress($progresso, $total = null)
    {
        return QueueHelper::progress(
            $this->getId(),
            $this->getLockToken(),
            $progresso,
            $total
        );
    }

    public function heartbeat()
    {
        return QueueHelper::heartbeat(
            $this->getId(),
            $this->getLockToken()
        );
    }

    public function release($delaySeconds = 0)
    {
        return QueueHelper::release(
            $this->getId(),
            $this->getLockToken(),
            array(
                'delay_seconds' => (int) $delaySeconds
            )
        );
    }

    public function complete(array $resultado = array())
    {
        return QueueHelper::complete(
            $this->getId(),
            $this->getLockToken(),
            $resultado
        );
    }

    public function fail($mensagem)
    {
        return QueueHelper::fail(
            $this->getId(),
            $this->getLockToken(),
            $mensagem
        );
    }

    public function timeoutSeconds()
    {
        return isset($this->job->timeout_seconds)
            ? (int) $this->job->timeout_seconds
            : 240;
    }

    public function retryAfterSeconds()
    {
        return isset($this->job->retry_after_seconds)
            ? (int) $this->job->retry_after_seconds
            : 300;
    }

    abstract public function handle();
}