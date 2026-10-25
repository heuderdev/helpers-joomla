<?php

defined('_JEXEC') or die;

/*
 * Envia um e-mail colocado na fila por MailHelper::queue(). Registrado
 * por MailHelper::registerJob() (chame no arquivo de jobs do worker).
 * Falha do servidor de e-mail lança exceção: o worker tenta de novo
 * conforme as tentativas do job.
 */
class MailJob extends AbstractJob
{
    public function handle()
    {
        if (!class_exists('MailHelper')) {
            require_once dirname(dirname(__DIR__)) . '/MailHelper.php';
        }

        $resultado = MailHelper::send($this->getPayload());

        if (!$resultado['success']) {
            $detalhe = isset($resultado['errors']['_system'][0]) ? $resultado['errors']['_system'][0] : $resultado['mensagem'];

            throw new RuntimeException($detalhe);
        }

        return array('enviado_para' => $resultado['data']['to']);
    }
}
