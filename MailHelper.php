<?php

defined('_JEXEC') or die;

/*
 * E-mails transacionais pelo mailer do Joomla (configuração global:
 * PHP mail, Sendmail ou SMTP): templates com variáveis escapadas, HTML
 * com texto alternativo gerado, anexos, cópia, cópia oculta, resposta,
 * log de falhas e envio pela fila.
 *
 *   $r = MailHelper::send(array(
 *       'to' => $cliente->email,
 *       'subject' => 'Pedido {{ pedido.numero }} confirmado',
 *       'template' => 'pedido-confirmado',
 *       'data' => array('cliente' => $cliente, 'pedido' => $pedido),
 *   ));
 *   if (!$r['success']) { ... $r['mensagem'] }
 *
 * Não lança exceção: devolve array('success', 'status', 'mensagem', 'data', 'errors').
 */

class MailHelper
{
    private static $templateDirectory = null;

    private static $layout = null;

    private static $fake = false;

    private static $sent = array();

    private static $queueType = 'helpers_mail';

    private static $defaultFrom = null;

    private static $defaultReplyTo = null;

    // ------------------------------------------------------------------
    // Configuração
    // ------------------------------------------------------------------

    /*
     * Pasta dos templates: <pasta>/<nome>.html (e opcional <nome>.txt).
     */
    public static function setTemplateDirectory($directory)
    {
        self::$templateDirectory = rtrim((string) $directory, '/\\');
    }

    public static function getTemplateDirectory()
    {
        if (self::$templateDirectory !== null) {
            return self::$templateDirectory;
        }

        return defined('JPATH_COMPONENT') ? JPATH_COMPONENT . '/emails' : '';
    }

    /*
     * Layout em volta de todos os e-mails HTML (cabeçalho, rodapé, logo):
     * nome de um template que contém {!! conteudo !!}. null desliga.
     */
    public static function setLayout($template)
    {
        self::$layout = $template === null ? null : (string) $template;
    }

    /*
     * Remetente padrão (no lugar do mailfrom/fromname da configuração global).
     */
    public static function setDefaultFrom($email, $name = '')
    {
        self::$defaultFrom = array((string) $email, (string) $name);
    }

    public static function setDefaultReplyTo($email, $name = '')
    {
        self::$defaultReplyTo = array((string) $email, (string) $name);
    }

    /*
     * Modo de teste: nada é enviado; os e-mails ficam em MailHelper::sent().
     */
    public static function fake($fake = true)
    {
        self::$fake = (bool) $fake;
        self::$sent = array();
    }

    public static function sent()
    {
        return self::$sent;
    }

    public static function setQueueType($type)
    {
        self::$queueType = (string) $type;
    }

    // ------------------------------------------------------------------
    // Envio
    // ------------------------------------------------------------------

    /*
     * Chaves: to, cc, bcc (texto, lista ou array(email => nome)),
     * subject, html, text, template, data, layout (false desliga),
     * from (email ou array(email, nome)), reply_to, attachments (caminhos
     * ou array('path' => ..., 'name' => ...)), headers.
     */
    public static function send(array $mail)
    {
        try {
            $message = self::build($mail);

            if (self::$fake) {
                self::$sent[] = $message;

                return self::result(true, 'E-mail registrado (modo de teste).', array('to' => array_keys($message['to'])));
            }

            $mailer = self::mailer();

            $mailer->setSender(array($message['from'][0], $message['from'][1]));

            foreach ($message['to'] as $email => $name) {
                $mailer->addRecipient($email, $name);
            }

            foreach ($message['cc'] as $email => $name) {
                $mailer->addCc($email, $name);
            }

            foreach ($message['bcc'] as $email => $name) {
                $mailer->addBcc($email, $name);
            }

            foreach ($message['reply_to'] as $email => $name) {
                $mailer->addReplyTo($email, $name);
            }

            foreach ($message['attachments'] as $attachment) {
                $mailer->addAttachment($attachment['path'], $attachment['name']);
            }

            foreach ($message['headers'] as $name => $value) {
                $mailer->addCustomHeader($name, $value);
            }

            $mailer->setSubject($message['subject']);

            if ($message['html'] !== '') {
                $mailer->isHtml(true);
                $mailer->setBody($message['html']);
                $mailer->AltBody = $message['text'];
            } else {
                $mailer->isHtml(false);
                $mailer->setBody($message['text']);
            }

            $sent = $mailer->Send();

            // Joomla 3 devolve false ou um objeto de erro; Joomla 4/5 lança exceção.
            if ($sent !== true) {
                $error = is_object($sent) && method_exists($sent, 'getMessage') ? $sent->getMessage() : (isset($mailer->ErrorInfo) ? $mailer->ErrorInfo : '');

                throw new RuntimeException($error !== '' ? $error : 'O servidor de e-mail recusou o envio.');
            }

            self::log('info', 'E-mail enviado.', $message);

            return self::result(true, 'E-mail enviado com sucesso.', array('to' => array_keys($message['to'])));
        } catch (Throwable $error) {
            self::log('error', 'Falha ao enviar e-mail: ' . $error->getMessage(), isset($message) ? $message : $mail);

            return self::result(false, 'Não foi possível enviar o e-mail.', array(), array('_system' => array(self::publicError($error))));
        }
    }

    /*
     * Coloca o envio na fila (QueueHelper): a página responde na hora e o
     * worker envia, com novas tentativas se o servidor de e-mail falhar.
     * O conteúdo é montado já (erros de template aparecem aqui), e o
     * worker só envia. Devolve o resultado do QueueHelper::push().
     */
    public static function queue(array $mail, array $options = array())
    {
        try {
            if (!class_exists('QueueHelper')) {
                throw new RuntimeException('Carregue o QueueHelper para usar MailHelper::queue().');
            }

            $message = self::build($mail);

            foreach ($message['attachments'] as $attachment) {
                if (!is_readable($attachment['path'])) {
                    throw new RuntimeException('Anexo não encontrado: ' . basename($attachment['path']));
                }
            }

            self::registerJob();

            // Já montado: o worker não precisa da pasta de templates nem das variáveis.
            $payload = array(
                'to' => $message['to'],
                'cc' => $message['cc'],
                'bcc' => $message['bcc'],
                'reply_to' => $message['reply_to'],
                'from' => $message['from'],
                'subject' => $message['subject'],
                'html' => $message['html'],
                'text' => $message['text'],
                'attachments' => $message['attachments'],
                'headers' => $message['headers'],
                'prebuilt' => true
            );

            return QueueHelper::push(self::$queueType, $payload, $options);
        } catch (Throwable $error) {
            self::log('error', 'Falha ao enfileirar e-mail: ' . $error->getMessage(), $mail);

            return self::result(false, 'Não foi possível agendar o envio do e-mail.', array(), array('_system' => array($error->getMessage())));
        }
    }

    /*
     * Registra o job de envio no JobRegistry (o worker precisa disto no
     * bootstrap: chame MailHelper::registerJob() no arquivo de jobs).
     */
    public static function registerJob($queue = 'emails')
    {
        if (class_exists('JobRegistry') && !JobRegistry::has(self::$queueType)) {
            JobRegistry::register(self::$queueType, __DIR__ . '/fila/jobs/MailJob.php', 'MailJob', $queue);
        }
    }

    /*
     * Monta o e-mail sem enviar (para pré-visualizar ou testar):
     * array(to, cc, bcc, reply_to, from, subject, html, text, attachments, headers).
     */
    public static function build(array $mail)
    {
        $data = isset($mail['data']) && is_array($mail['data']) ? $mail['data'] : array();
        // Já montado (vindo da fila): não processa {{ }} de novo, que poderia vir do texto do cliente.
        $prebuilt = !empty($mail['prebuilt']);

        $to = self::addresses(isset($mail['to']) ? $mail['to'] : array(), 'to');

        if (empty($to)) {
            throw new InvalidArgumentException('Informe ao menos um destinatário.');
        }

        $subject = isset($mail['subject']) ? (string) $mail['subject'] : '';
        $subject = $prebuilt ? $subject : self::render($subject, $data, false);
        // Assunto numa linha só (evita injeção de cabeçalhos).
        $subject = trim(preg_replace('/[\r\n]+/', ' ', $subject));

        if ($subject === '') {
            throw new InvalidArgumentException('Informe o assunto do e-mail.');
        }

        $html = '';
        $text = isset($mail['text']) ? (string) $mail['text'] : '';
        $text = $prebuilt ? $text : self::render($text, $data, false);

        if ($prebuilt) {
            $html = isset($mail['html']) ? (string) $mail['html'] : '';
        } elseif (!empty($mail['template'])) {
            $html = self::render(self::loadTemplate($mail['template'], 'html'), $data, true);
            $textTemplate = self::loadTemplate($mail['template'], 'txt', false);

            if ($text === '' && $textTemplate !== '') {
                $text = self::render($textTemplate, $data, false);
            }
        } elseif (isset($mail['html'])) {
            $html = self::render((string) $mail['html'], $data, true);
        }

        $layout = array_key_exists('layout', $mail) ? $mail['layout'] : self::$layout;

        if ($html !== '' && $layout && !$prebuilt) {
            $html = self::render(self::loadTemplate($layout, 'html'), array_merge($data, array('conteudo' => $html, 'assunto' => $subject)), true);
        }

        if ($html === '' && $text === '') {
            throw new InvalidArgumentException('Informe o conteúdo do e-mail (html, text ou template).');
        }

        if ($text === '') {
            $text = self::htmlToText($html);
        }

        $from = self::$defaultFrom;

        if (!empty($mail['from'])) {
            $from = is_array($mail['from']) ? array_values($mail['from']) + array('', '') : array((string) $mail['from'], '');
        }

        if ($from === null) {
            $from = self::siteSender();
        }

        if (!filter_var($from[0], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Remetente inválido: ' . $from[0]);
        }

        $replyTo = isset($mail['reply_to']) ? self::addresses($mail['reply_to'], 'reply_to') : array();

        if (empty($replyTo) && self::$defaultReplyTo !== null) {
            $replyTo = self::addresses(array(self::$defaultReplyTo[0] => self::$defaultReplyTo[1]), 'reply_to');
        }

        return array(
            'to' => $to,
            'cc' => self::addresses(isset($mail['cc']) ? $mail['cc'] : array(), 'cc'),
            'bcc' => self::addresses(isset($mail['bcc']) ? $mail['bcc'] : array(), 'bcc'),
            'reply_to' => $replyTo,
            'from' => array($from[0], self::oneLine($from[1])),
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'attachments' => self::attachments(isset($mail['attachments']) ? $mail['attachments'] : array()),
            'headers' => self::headers(isset($mail['headers']) ? $mail['headers'] : array())
        );
    }

    /*
     * Substitui {{ variavel }} (escapada para HTML), {!! variavel !!}
     * (sem escapar: só para HTML que você mesmo montou) e caminhos com
     * ponto ({{ pedido.cliente.nome }}). Filtros: {{ total | money }},
     * {{ data | date }}, {{ nome | upper }}. Variável ausente vira ''.
     */
    public static function render($template, array $data = array(), $html = true)
    {
        $self = __CLASS__;

        // Uma passada só: o que é inserido nunca é processado de novo (um
        // "{{ token }}" digitado pelo cliente não revela outra variável).
        return preg_replace_callback(
            '/\{!!\s*([a-zA-Z0-9_.]+)\s*(?:\|\s*([a-z0-9_]+)\s*)?!!\}|\{\{\s*([a-zA-Z0-9_.]+)\s*(?:\|\s*([a-z0-9_]+)\s*)?\}\}/',
            function ($m) use ($self, $data, $html) {
                if ($m[1] !== '') {
                    return $self::filter($self::value($data, $m[1]), isset($m[2]) ? $m[2] : '');
                }

                $filter = isset($m[4]) ? $m[4] : '';
                $value = $self::filter($self::value($data, $m[3]), $filter);

                // nl2br já escapa o texto antes de inserir os <br>.
                if ($filter === 'nl2br') {
                    return $html ? $value : strip_tags($value);
                }

                return $html ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
            },
            (string) $template
        );
    }

    /*
     * Versão texto de um HTML: parágrafos e quebras viram linhas, links
     * viram "texto (url)", listas viram "- item".
     */
    public static function htmlToText($html)
    {
        $text = preg_replace('#<(style|script|head)[^>]*>.*?</\1>#is', '', (string) $html);
        $text = preg_replace_callback('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(strip_tags($m[2]));

            return ($label === '' || $label === $m[1]) ? $m[1] : $label . ' (' . $m[1] . ')';
        }, $text);
        $text = preg_replace('#<li[^>]*>#i', "\n- ", $text);
        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = preg_replace('#</(p|div|h[1-6]|tr|table|ul|ol)>#i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    // ------------------------------------------------------------------
    // Internos (value e filter são públicos só para as closures do render)
    // ------------------------------------------------------------------

    public static function value(array $data, $path)
    {
        $value = $data;

        foreach (explode('.', $path) as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return '';
            }
        }

        return $value;
    }

    public static function filter($value, $filter)
    {
        switch ($filter) {
            case 'money':
                return 'R$ ' . number_format((float) $value, 2, ',', '.');

            case 'number':
                return number_format((float) $value, 2, ',', '.');

            case 'date':
                return self::formatDate($value, false);

            case 'datetime':
                return self::formatDate($value, true);

            case 'upper':
                return function_exists('mb_strtoupper') ? mb_strtoupper(self::scalar($value), 'UTF-8') : strtoupper(self::scalar($value));

            case 'lower':
                return function_exists('mb_strtolower') ? mb_strtolower(self::scalar($value), 'UTF-8') : strtolower(self::scalar($value));

            case 'nl2br':
                return nl2br(htmlspecialchars(self::scalar($value), ENT_QUOTES, 'UTF-8'));
        }

        return self::scalar($value);
    }

    private static function scalar($value)
    {
        if ($value === null || is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        return (string) $value;
    }

    private static function formatDate($value, $withTime)
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (class_exists('DateHelper')) {
            return DateHelper::toUser($value, $withTime ? 'd/m/Y H:i' : 'd/m/Y');
        }

        $time = $value instanceof DateTime ? $value->getTimestamp() : strtotime((string) $value);

        return $time ? date($withTime ? 'd/m/Y H:i' : 'd/m/Y', $time) : self::scalar($value);
    }

    private static function loadTemplate($name, $extension, $required = true)
    {
        $name = (string) $name;

        if (!preg_match('/^[a-zA-Z0-9_\-\/]+$/', $name) || strpos($name, '..') !== false) {
            throw new InvalidArgumentException('Nome de template inválido: ' . $name);
        }

        $path = self::getTemplateDirectory() . '/' . $name . '.' . $extension;

        if (!is_file($path)) {
            if ($required) {
                throw new RuntimeException('Template de e-mail não encontrado: ' . $name . '.' . $extension);
            }

            return '';
        }

        return (string) file_get_contents($path);
    }

    private static function addresses($value, $field)
    {
        if ($value === null || $value === '' || $value === array()) {
            return array();
        }

        if (is_string($value)) {
            $value = preg_split('/[,;]/', $value);
        }

        $list = array();

        foreach ((array) $value as $key => $item) {
            // array('ana@x.com' => 'Ana') ou array('ana@x.com', 'bia@x.com')
            $email = is_string($key) ? $key : $item;
            $name = is_string($key) ? $item : '';
            $email = trim((string) $email);

            if ($email === '') {
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('E-mail inválido em "' . $field . '": ' . $email);
            }

            $list[$email] = self::oneLine((string) $name);
        }

        return $list;
    }

    private static function attachments($value)
    {
        $list = array();

        foreach ((array) $value as $item) {
            $path = is_array($item) ? (isset($item['path']) ? $item['path'] : '') : $item;
            $name = is_array($item) && !empty($item['name']) ? $item['name'] : basename((string) $path);

            if ($path === '' || !is_file($path) || !is_readable($path)) {
                throw new InvalidArgumentException('Anexo não encontrado: ' . basename((string) $path));
            }

            $list[] = array('path' => (string) $path, 'name' => self::oneLine($name));
        }

        return $list;
    }

    private static function headers($value)
    {
        $list = array();

        foreach ((array) $value as $name => $content) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', (string) $name)) {
                throw new InvalidArgumentException('Cabeçalho inválido: ' . $name);
            }

            $list[(string) $name] = self::oneLine((string) $content);
        }

        return $list;
    }

    private static function oneLine($text)
    {
        return trim(preg_replace('/[\r\n]+/', ' ', (string) $text));
    }

    private static function siteSender()
    {
        if (!class_exists('JFactory')) {
            throw new RuntimeException('Informe o remetente (from).');
        }

        $config = JFactory::getConfig();

        return array((string) $config->get('mailfrom'), (string) $config->get('fromname'));
    }

    private static function mailer()
    {
        // getMailer() devolve uma cópia nova a cada chamada (destinatários não se acumulam).
        $mailer = JFactory::getMailer();

        if (!is_object($mailer)) {
            throw new RuntimeException('O envio de e-mails está indisponível neste site.');
        }

        if (method_exists($mailer, 'clearAllRecipients')) {
            $mailer->clearAllRecipients();
            $mailer->clearAttachments();
            $mailer->clearReplyTos();
            $mailer->clearCustomHeaders();
        }

        $mailer->CharSet = 'UTF-8';

        return $mailer;
    }

    // Mensagem segura para a tela (sem senha SMTP ou caminho do servidor).
    private static function publicError(Throwable $error)
    {
        if ($error instanceof InvalidArgumentException) {
            return $error->getMessage();
        }

        $class = get_class($error);

        if (stripos($class, 'MailDisabled') !== false) {
            return 'O envio de e-mails está desligado na configuração do site.';
        }

        return 'O servidor de e-mail não aceitou a mensagem. Tente novamente mais tarde.';
    }

    private static function log($level, $text, array $message)
    {
        if (!class_exists('LogHelper') || !method_exists('LogHelper', $level)) {
            return;
        }

        $to = isset($message['to']) ? $message['to'] : array();

        LogHelper::$level($text, 'mail', array(
            'to' => is_array($to) ? implode(', ', array_keys($to) === range(0, count($to) - 1) ? $to : array_keys($to)) : (string) $to,
            'subject' => isset($message['subject']) ? (string) $message['subject'] : '',
            'anexos' => isset($message['attachments']) ? count((array) $message['attachments']) : 0
        ));
    }

    private static function result($success, $message, array $data = array(), array $errors = array())
    {
        return array(
            'success' => (bool) $success,
            'status' => $success ? 'sucesso' : 'erro',
            'mensagem' => (string) $message,
            'data' => $data,
            'errors' => $errors
        );
    }
}
