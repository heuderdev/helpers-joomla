<?php

// Bloqueia só o acesso direto pela URL; carrega no Joomla, Laravel ou PHP puro.
if (!defined('_JEXEC') && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    die('Acesso restrito.');
}

/*
 * Envio de e-mails sem dependências: SMTP nativo (Gmail, Outlook/Hotmail,
 * Office 365, Yahoo, Zoho, ElasticEmail SMTP, SendGrid, Brevo, Mailgun,
 * Mailtrap, Mailpit, Locaweb, Hostinger ou qualquer servidor), API HTTP
 * da ElasticEmail (v4), mail() do PHP, gravação em arquivo .eml e modo fake.
 *
 * 1) Registre as conexões (uma vez, no bootstrap):
 *
 *   $config = require __DIR__ . '/config.php';
 *
 *   EmailSendHelper::register('elasticemail', $config['ELASTICEMAIL']);   // ['api_key' => '...']
 *   EmailSendHelper::register('gmail', [
 *       'username' => 'conta@gmail.com',
 *       'password' => 'senha-de-app',       // myaccount.google.com/apppasswords
 *       'from_name' => 'Minha Empresa',
 *   ]);
 *   EmailSendHelper::register('hotmail', ['username' => 'conta@hotmail.com', 'password' => '...']);
 *   EmailSendHelper::register('empresa', [  // qualquer servidor SMTP
 *       'driver' => 'smtp', 'host' => 'smtp.empresa.com.br', 'port' => 587,
 *       'encryption' => 'tls', 'username' => '...', 'password' => '...',
 *   ]);
 *   EmailSendHelper::setDefault('elasticemail');
 *
 *   O nome da conexão (ou a chave 'provider') escolhe o provedor pronto:
 *   veja EmailSendHelper::providers(). A 1ª conexão registrada é a padrão.
 *
 * 2) Envie (estilo fluente):
 *
 *   $r = EmailSendHelper::make()                 // conexão padrão
 *       ->to('cliente@x.com', 'Ana')
 *       ->cc('financeiro@x.com')
 *       ->bcc(['auditoria@x.com', 'Diretoria <dir@x.com>'])
 *       ->replyTo('suporte@empresa.com')
 *       ->subject('Pedido {{ pedido.numero }} confirmado')
 *       ->view('emails/pedido.php', ['pedido' => $pedido, 'cliente' => $cliente])
 *       ->layout('emails/layout.php')
 *       ->attach('/caminho/boleto.pdf')
 *       ->attach('/caminho/nf.xml', 'Nota Fiscal 123.xml')
 *       ->attachData($csv, 'relatorio.csv')
 *       ->embed(__DIR__ . '/logo.png', 'logo')  // no HTML: <img src="cid:logo">
 *       ->send();
 *
 *   if (!$r['success']) { echo $r['mensagem']; print_r($r['errors']); }
 *
 *   Simples:   EmailSendHelper::make('gmail')->to('a@x.com')->subject('Oi')->text('Olá!')->send();
 *   Failover:  EmailSendHelper::make(['elasticemail', 'gmail'])->...->send();  // tenta na ordem
 *   Array:     EmailSendHelper::sendMail(['to' => ..., 'subject' => ..., 'html' => ..., 'attachments' => [...]], 'gmail');
 *   Exceção:   ->sendOrFail()  (lança RuntimeException em vez de devolver erro)
 *
 * 3) Views e layout (.php):
 *
 *   A view recebe os dados como variáveis ($pedido, $cliente...) e $e()
 *   para escapar: <p>Olá, <?= $e($cliente->nome) ?></p>
 *   O layout recebe as mesmas variáveis e mais $content (ou $conteudo) com
 *   o HTML da view e $subject (ou $assunto):
 *       <html><body><header>...</header><?= $content ?><footer>...</footer></body></html>
 *   Arquivos .html usam {{ variavel }} (escapada), {!! variavel !!} (crua),
 *   filtros {{ total | money }}, {{ data | date }}; no layout .html: {!! content !!}.
 *   Caminho: absoluto, relativo ao diretório atual ou a setViewDirectory().
 *
 * Assunto, html() e text() também aceitam {{ variavel }} com os dados de with()/view().
 *
 * Retorno: ['success', 'status', 'mensagem', 'data', 'errors']; data traz
 * connection, driver, message_id, to e (ElasticEmail) transaction_id.
 */

class EmailSendHelper
{
    // ------------------------------------------------------------------
    // Provedores prontos (o que for informado no register() vence)
    // ------------------------------------------------------------------

    private const PROVIDERS = [
        'elasticemail' => ['driver' => 'elasticemail'],
        'elastic' => ['driver' => 'elasticemail'],
        'elasticemail_smtp' => ['driver' => 'smtp', 'host' => 'smtp.elasticemail.com', 'port' => 2525, 'encryption' => 'tls'],
        'gmail' => ['driver' => 'smtp', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls'],
        'google_workspace' => ['driver' => 'smtp', 'host' => 'smtp.gmail.com', 'port' => 587, 'encryption' => 'tls'],
        'outlook' => ['driver' => 'smtp', 'host' => 'smtp-mail.outlook.com', 'port' => 587, 'encryption' => 'tls'],
        'hotmail' => ['driver' => 'smtp', 'host' => 'smtp-mail.outlook.com', 'port' => 587, 'encryption' => 'tls'],
        'live' => ['driver' => 'smtp', 'host' => 'smtp-mail.outlook.com', 'port' => 587, 'encryption' => 'tls'],
        'office365' => ['driver' => 'smtp', 'host' => 'smtp.office365.com', 'port' => 587, 'encryption' => 'tls'],
        'yahoo' => ['driver' => 'smtp', 'host' => 'smtp.mail.yahoo.com', 'port' => 465, 'encryption' => 'ssl'],
        'icloud' => ['driver' => 'smtp', 'host' => 'smtp.mail.me.com', 'port' => 587, 'encryption' => 'tls'],
        'zoho' => ['driver' => 'smtp', 'host' => 'smtp.zoho.com', 'port' => 465, 'encryption' => 'ssl'],
        'sendgrid' => ['driver' => 'smtp', 'host' => 'smtp.sendgrid.net', 'port' => 587, 'encryption' => 'tls', 'username' => 'apikey'],
        'brevo' => ['driver' => 'smtp', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'encryption' => 'tls'],
        'mailgun' => ['driver' => 'smtp', 'host' => 'smtp.mailgun.org', 'port' => 587, 'encryption' => 'tls'],
        'mailtrap' => ['driver' => 'smtp', 'host' => 'sandbox.smtp.mailtrap.io', 'port' => 2525, 'encryption' => 'tls'],
        'locaweb' => ['driver' => 'smtp', 'host' => 'email-ssl.com.br', 'port' => 465, 'encryption' => 'ssl'],
        'hostinger' => ['driver' => 'smtp', 'host' => 'smtp.hostinger.com', 'port' => 465, 'encryption' => 'ssl'],
        'mailpit' => ['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'none'],
        'mailhog' => ['driver' => 'smtp', 'host' => '127.0.0.1', 'port' => 1025, 'encryption' => 'none'],
        'mail' => ['driver' => 'mail'],
        'log' => ['driver' => 'log'],
    ];

    private const DRIVERS = ['smtp', 'elasticemail', 'mail', 'log'];

    private const ELASTIC_URL = 'https://api.elasticemail.com/v4/emails/transactional';

    // Limite padrão da soma dos anexos (o Gmail recusa acima de 25 MB).
    private const DEFAULT_MAX_SIZE = 25 * 1024 * 1024;

    private const MIME_TYPES = [
        'pdf' => 'application/pdf', 'csv' => 'text/csv', 'txt' => 'text/plain', 'html' => 'text/html',
        'htm' => 'text/html', 'xml' => 'application/xml', 'json' => 'application/json', 'zip' => 'application/zip',
        'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed', 'gz' => 'application/gzip',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'mp3' => 'audio/mpeg', 'mp4' => 'video/mp4',
        'ics' => 'text/calendar', 'eml' => 'message/rfc822', 'rtf' => 'application/rtf', 'ofx' => 'application/x-ofx',
        'rem' => 'text/plain', 'ret' => 'text/plain',
    ];

    // ------------------------------------------------------------------
    // Estado global
    // ------------------------------------------------------------------

    private static array $connections = [];

    private static ?string $default = null;

    private static ?string $viewDirectory = null;

    private static $defaultLayout = null;

    private static bool $fake = false;

    private static array $sent = [];

    private static array $transcript = [];

    // ------------------------------------------------------------------
    // Mensagem (instância)
    // ------------------------------------------------------------------

    private $connection = null;

    private array $to = [];

    private array $cc = [];

    private array $bcc = [];

    private array $replyTo = [];

    private ?array $from = null;

    private string $subject = '';

    private ?string $html = null;

    private ?string $text = null;

    private ?string $view = null;

    private $layout = null;

    private bool $layoutSet = false;

    private array $data = [];

    private array $attachments = [];

    private array $embeds = [];

    private array $headers = [];

    private ?int $priority = null;

    private array $pending = [];

    // ------------------------------------------------------------------
    // Conexões
    // ------------------------------------------------------------------

    /*
     * Chaves aceitas: driver (smtp|elasticemail|mail|log), provider, host,
     * port, encryption (tls|ssl|none), username (user), password (pass),
     * oauth_token (XOAUTH2), api_key (apikey), api_url, from_email (from),
     * from_name, reply_to, envelope_from, timeout, verify_peer, helo,
     * directory (driver log), max_size (bytes dos anexos), debug.
     */
    public static function register(string $name, array $config = []): void
    {
        $key = strtolower(trim($name));

        if ($key === '') {
            throw new InvalidArgumentException('Informe o nome da conexão de e-mail.');
        }

        $config = self::normalizeConfig($config);
        $preset = [];

        foreach ([$config['provider'] ?? null, $key, $config['driver'] ?? null] as $candidate) {
            if ($candidate !== null && isset(self::PROVIDERS[strtolower((string) $candidate)])) {
                $preset = self::PROVIDERS[strtolower((string) $candidate)];
                break;
            }
        }

        $config = array_merge($preset, $config);

        if (empty($config['driver'])) {
            $config['driver'] = !empty($config['host']) ? 'smtp' : (!empty($config['api_key']) ? 'elasticemail' : '');
        }

        $config['driver'] = strtolower((string) $config['driver']);

        if (!in_array($config['driver'], self::DRIVERS, true)) {
            throw new InvalidArgumentException('Conexão "' . $name . '": informe driver (' . implode(', ', self::DRIVERS) . ') ou um provider conhecido.');
        }

        if ($config['driver'] === 'smtp' && empty($config['host'])) {
            throw new InvalidArgumentException('Conexão "' . $name . '": informe o host SMTP.');
        }

        if ($config['driver'] === 'elasticemail' && empty($config['api_key'])) {
            throw new InvalidArgumentException('Conexão "' . $name . '": informe a api_key da ElasticEmail.');
        }

        $config['name'] = $key;
        self::$connections[$key] = $config;

        if (self::$default === null) {
            self::$default = $key;
        }
    }

    /*
     * Registra várias de uma vez: ['elasticemail' => [...], 'gmail' => [...]].
     */
    public static function registerMany(array $connections): void
    {
        foreach ($connections as $name => $config) {
            self::register((string) $name, (array) $config);
        }
    }

    public static function setDefault(string $name): void
    {
        $key = strtolower($name);

        if (!isset(self::$connections[$key])) {
            throw new InvalidArgumentException('Conexão de e-mail não registrada: ' . $name);
        }

        self::$default = $key;
    }

    public static function getDefault(): ?string
    {
        return self::$default;
    }

    public static function has(string $name): bool
    {
        return isset(self::$connections[strtolower($name)]);
    }

    public static function forget(string $name): void
    {
        $key = strtolower($name);
        unset(self::$connections[$key]);

        if (self::$default === $key) {
            self::$default = array_key_first(self::$connections);
        }
    }

    /*
     * Nomes registrados (sem as credenciais).
     */
    public static function connections(): array
    {
        $list = [];

        foreach (self::$connections as $name => $config) {
            $list[$name] = ['driver' => $config['driver'], 'host' => $config['host'] ?? null, 'default' => $name === self::$default];
        }

        return $list;
    }

    /*
     * Provedores prontos: nome => host/porta/criptografia.
     */
    public static function providers(): array
    {
        return self::PROVIDERS;
    }

    /*
     * Pasta base das views/layouts (caminhos relativos procuram aqui).
     */
    public static function setViewDirectory(?string $directory): void
    {
        self::$viewDirectory = $directory === null ? null : rtrim($directory, '/\\');
    }

    /*
     * Layout aplicado a todo e-mail HTML (->layout(false) desliga em um envio).
     */
    public static function setDefaultLayout($file): void
    {
        self::$defaultLayout = $file ?: null;
    }

    /*
     * Modo de teste: nada sai; as mensagens montadas ficam em sent().
     */
    public static function fake(bool $fake = true): void
    {
        self::$fake = $fake;
        self::$sent = [];
    }

    public static function sent(): array
    {
        return self::$sent;
    }

    /*
     * Testa conexão e login SMTP sem enviar nada (útil para conferir a
     * senha de app do Gmail/Outlook). Outros drivers só validam a config.
     */
    public static function testConnection(?string $name = null): array
    {
        try {
            $config = self::config($name ?? self::$default);

            if ($config['driver'] === 'smtp') {
                $socket = self::smtpOpen($config);
                self::smtpQuit($socket);
            }

            return self::result(true, 'Conexão "' . $config['name'] . '" OK.', ['connection' => $config['name'], 'driver' => $config['driver'], 'transcript' => self::$transcript]);
        } catch (Throwable $error) {
            return self::result(false, 'Falha na conexão de e-mail.', ['transcript' => self::$transcript], ['_system' => [$error->getMessage()]]);
        }
    }

    // ------------------------------------------------------------------
    // Criação da mensagem
    // ------------------------------------------------------------------

    /*
     * $connection: nome, lista de nomes (failover) ou array de config avulsa.
     * $mail: mesmas chaves de fill().
     */
    public static function make($connection = null, array $mail = []): self
    {
        $message = new self();
        $message->via($connection);

        return $mail ? $message->fill($mail) : $message;
    }

    /*
     * Envio em uma linha a partir de um array (veja fill()).
     */
    public static function sendMail(array $mail, $connection = null): array
    {
        return self::make($connection ?? ($mail['connection'] ?? null), $mail)->send();
    }

    /*
     * Chaves: to, cc, bcc, reply_to, from, subject, html, text, view,
     * layout, data, attachments (caminhos, ['path','name','mime'] ou
     * ['content','name','mime']), embeds (['path','cid']), headers, priority.
     */
    public function fill(array $mail): self
    {
        foreach (['to', 'cc', 'bcc'] as $field) {
            if (isset($mail[$field])) {
                $this->$field($mail[$field]);
            }
        }

        if (isset($mail['reply_to'])) {
            $this->replyTo($mail['reply_to']);
        }

        if (!empty($mail['from'])) {
            is_array($mail['from']) ? $this->from(...array_values($mail['from'])) : $this->from((string) $mail['from']);
        }

        if (isset($mail['data'])) {
            $this->with((array) $mail['data']);
        }

        if (isset($mail['subject'])) {
            $this->subject((string) $mail['subject']);
        }

        if (isset($mail['html'])) {
            $this->html((string) $mail['html']);
        }

        if (isset($mail['text'])) {
            $this->text((string) $mail['text']);
        }

        if (!empty($mail['view'])) {
            $this->view((string) $mail['view']);
        }

        if (array_key_exists('layout', $mail)) {
            $this->layout($mail['layout']);
        }

        foreach ((array) ($mail['attachments'] ?? []) as $item) {
            if (is_array($item) && array_key_exists('content', $item)) {
                $this->attachData((string) $item['content'], (string) ($item['name'] ?? 'anexo'), $item['mime'] ?? null);
            } elseif (is_array($item)) {
                $this->attach((string) ($item['path'] ?? ''), $item['name'] ?? null, $item['mime'] ?? null);
            } else {
                $this->attach((string) $item);
            }
        }

        foreach ((array) ($mail['embeds'] ?? []) as $item) {
            is_array($item) ? $this->embed((string) ($item['path'] ?? ''), $item['cid'] ?? null) : $this->embed((string) $item);
        }

        foreach ((array) ($mail['headers'] ?? []) as $name => $value) {
            $this->header((string) $name, (string) $value);
        }

        if (isset($mail['priority'])) {
            $this->priority((int) $mail['priority']);
        }

        return $this;
    }

    public function via($connection): self
    {
        $this->connection = $connection;

        return $this;
    }

    /*
     * Endereços: 'a@x.com', 'a@x.com, b@x.com', 'Ana <a@x.com>',
     * ['a@x.com', 'b@x.com'] ou ['a@x.com' => 'Ana'].
     */
    public function to($address, ?string $name = null): self
    {
        return $this->addAddress('to', $address, $name);
    }

    public function cc($address, ?string $name = null): self
    {
        return $this->addAddress('cc', $address, $name);
    }

    public function bcc($address, ?string $name = null): self
    {
        return $this->addAddress('bcc', $address, $name);
    }

    public function replyTo($address, ?string $name = null): self
    {
        return $this->addAddress('replyTo', $address, $name);
    }

    public function from(string $email, ?string $name = null): self
    {
        $this->from = [$email, (string) $name];

        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function html(string $html): self
    {
        $this->html = $html;

        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    /*
     * Corpo HTML a partir de um arquivo .php (variáveis) ou .html ({{ }}).
     */
    public function view(string $file, array $data = []): self
    {
        $this->view = $file;

        return $this->with($data);
    }

    /*
     * Layout em volta do HTML; false desliga o layout padrão.
     */
    public function layout($file): self
    {
        $this->layout = $file ?: false;
        $this->layoutSet = true;

        return $this;
    }

    public function with($key, $value = null): self
    {
        if (is_array($key)) {
            $this->data = array_merge($this->data, $key);
        } else {
            $this->data[(string) $key] = $value;
        }

        return $this;
    }

    public function attach(string $path, ?string $name = null, ?string $mime = null): self
    {
        $this->attachments[] = ['path' => $path, 'name' => $name, 'mime' => $mime];

        return $this;
    }

    /*
     * Anexo gerado em memória (PDF, CSV, XML...), sem arquivo em disco.
     */
    public function attachData(string $content, string $name, ?string $mime = null): self
    {
        $this->attachments[] = ['content' => $content, 'name' => $name, 'mime' => $mime];

        return $this;
    }

    /*
     * Anexa direto de $_FILES['campo'] (simples ou múltiplo: name="arquivos[]").
     */
    public function attachUpload(array $file): self
    {
        if (is_array($file['name'] ?? null)) {
            foreach (array_keys($file['name']) as $i) {
                $this->attachUpload([
                    'name' => $file['name'][$i],
                    'type' => $file['type'][$i] ?? null,
                    'tmp_name' => $file['tmp_name'][$i] ?? '',
                    'error' => $file['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                ]);
            }

            return $this;
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_NO_FILE) {
            return $this;
        }

        if ($error !== UPLOAD_ERR_OK) {
            $this->pending[] = 'Falha no upload do anexo "' . ($file['name'] ?? '') . '" (código ' . $error . ').';

            return $this;
        }

        return $this->attach((string) $file['tmp_name'], (string) ($file['name'] ?? 'anexo'), null);
    }

    /*
     * Imagem embutida: no HTML use <img src="cid:SEU_CID">.
     * Sem $cid, usa o nome do arquivo sem extensão (logo.png => cid:logo).
     */
    public function embed(string $path, ?string $cid = null): self
    {
        $cid = $cid ?? pathinfo($path, PATHINFO_FILENAME);
        $cid = preg_replace('/[^A-Za-z0-9._-]/', '', $cid);
        $this->embeds[] = ['path' => $path, 'cid' => $cid];

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    /*
     * 1 = alta, 3 = normal, 5 = baixa.
     */
    public function priority(int $priority): self
    {
        $this->priority = max(1, min(5, $priority));

        return $this;
    }

    /*
     * Cabeçalho List-Unsubscribe (Gmail/Yahoo exigem em envios em massa).
     */
    public function unsubscribe(string $urlOrMailto): self
    {
        $value = strpos($urlOrMailto, '@') !== false && stripos($urlOrMailto, 'http') !== 0 && stripos($urlOrMailto, 'mailto:') !== 0
            ? 'mailto:' . $urlOrMailto
            : $urlOrMailto;

        $this->headers['List-Unsubscribe'] = '<' . $value . '>';

        if (stripos($value, 'https://') === 0) {
            $this->headers['List-Unsubscribe-Post'] = 'List-Unsubscribe=One-Click';
        }

        return $this;
    }

    // ------------------------------------------------------------------
    // Envio
    // ------------------------------------------------------------------

    public function send(): array
    {
        $errors = [];
        $names = [];

        try {
            $configs = $this->resolveConnections();
        } catch (Throwable $error) {
            return self::result(false, $error->getMessage(), [], ['_system' => [$error->getMessage()]]);
        }

        foreach ($configs as $config) {
            $names[] = $config['name'];
            self::$transcript = [];

            try {
                $message = $this->build($config);

                if (self::$fake) {
                    self::$sent[] = $message + ['connection' => $config['name'], 'driver' => $config['driver']];
                    $info = ['fake' => true];
                } else {
                    $info = self::deliver($config, $message);
                }

                self::log('info', 'E-mail enviado.', $config, $message);

                $data = [
                    'connection' => $config['name'],
                    'driver' => $config['driver'],
                    'message_id' => $message['message_id'],
                    'to' => array_keys($message['to']),
                ] + $info;

                if (!empty($config['debug'])) {
                    $data['transcript'] = self::$transcript;
                }

                return self::result(true, self::$fake ? 'E-mail registrado (modo de teste).' : 'E-mail enviado com sucesso.', $data);
            } catch (InvalidArgumentException $error) {
                // Erro na mensagem (destinatário, anexo, view): outro provedor não resolveria.
                self::log('error', 'E-mail inválido: ' . $error->getMessage(), $config, $message ?? []);

                return self::result(false, $error->getMessage(), ['connection' => $config['name']], ['_system' => [$error->getMessage()]]);
            } catch (Throwable $error) {
                $errors[$config['name']] = [$error->getMessage()];
                self::log('error', 'Falha ao enviar e-mail: ' . $error->getMessage(), $config, $message ?? []);
                unset($message);
            }
        }

        $data = ['tried' => $names];

        if (self::$transcript) {
            $data['transcript'] = self::$transcript;
        }

        return self::result(false, 'Não foi possível enviar o e-mail.', $data, $errors);
    }

    /*
     * Igual a send(), mas lança RuntimeException em caso de falha.
     */
    public function sendOrFail(): array
    {
        $result = $this->send();

        if (!$result['success']) {
            $details = [];

            foreach ($result['errors'] as $key => $messages) {
                $details[] = ($key === '_system' ? '' : $key . ': ') . implode('; ', (array) $messages);
            }

            throw new RuntimeException($result['mensagem'] . ($details ? ' ' . implode(' | ', $details) : ''));
        }

        return $result;
    }

    /*
     * HTML final (view + layout) para pré-visualizar no navegador.
     */
    public function preview(): string
    {
        $message = $this->build($this->previewConfig());

        return $message['html'] !== '' ? $message['html'] : '<pre>' . htmlspecialchars($message['text'], ENT_QUOTES, 'UTF-8') . '</pre>';
    }

    /*
     * Mensagem completa em formato .eml (abre no Outlook/Thunderbird).
     */
    public function toMime(): string
    {
        $message = $this->build($this->previewConfig());
        [$headers, $body] = self::mime($message, false);

        return self::headerLines($headers) . "\r\n" . $body;
    }

    /*
     * Monta a mensagem sem enviar: from, to, cc, bcc, reply_to, subject,
     * html, text, attachments, embeds, headers, message_id.
     */
    public function build(array $config = []): array
    {
        if ($this->pending) {
            throw new InvalidArgumentException(implode(' ', $this->pending));
        }

        if (empty($this->to) && empty($this->cc) && empty($this->bcc)) {
            throw new InvalidArgumentException('Informe ao menos um destinatário.');
        }

        $data = $this->data;
        $subject = self::oneLine(self::renderString($this->subject, $data, false));

        if ($subject === '') {
            throw new InvalidArgumentException('Informe o assunto do e-mail.');
        }

        $html = '';

        if ($this->view !== null) {
            $html = self::renderFile($this->view, $data);
        } elseif ($this->html !== null) {
            $html = self::renderString($this->html, $data, true);
        }

        $layout = $this->layoutSet ? $this->layout : self::$defaultLayout;

        if ($html !== '' && $layout) {
            $html = self::renderFile((string) $layout, array_merge($data, [
                'content' => $html,
                'conteudo' => $html,
                'subject' => $subject,
                'assunto' => $subject,
            ]));
        }

        $text = $this->text !== null ? self::renderString($this->text, $data, false) : '';

        if ($html === '' && trim($text) === '') {
            throw new InvalidArgumentException('Informe o conteúdo do e-mail (html, text ou view).');
        }

        if ($text === '') {
            $text = self::htmlToText($html);
        }

        $from = $this->from ?? (!empty($config['from_email']) ? [$config['from_email'], $config['from_name'] ?? ''] : null);

        if ($from === null && !empty($config['username']) && filter_var($config['username'], FILTER_VALIDATE_EMAIL)) {
            $from = [$config['username'], $config['from_name'] ?? ''];
        }

        if ($from === null || !filter_var($from[0], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Remetente inválido ou ausente: use ->from() ou from_email na conexão.');
        }

        $replyTo = $this->replyTo;

        if (!$replyTo && !empty($config['reply_to'])) {
            $replyTo = self::addresses($config['reply_to'], null, 'reply_to');
        }

        [$attachments, $embeds] = $this->loadFiles((int) ($config['max_size'] ?? self::DEFAULT_MAX_SIZE));

        $headers = [];

        foreach ($this->headers as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', $name)) {
                throw new InvalidArgumentException('Cabeçalho inválido: ' . $name);
            }

            $headers[$name] = self::oneLine($value);
        }

        if ($this->priority !== null && $this->priority !== 3) {
            $headers['X-Priority'] = $this->priority . ($this->priority < 3 ? ' (Highest)' : ' (Lowest)');
            $headers['Importance'] = $this->priority < 3 ? 'High' : 'Low';
        }

        $domain = substr(strrchr($from[0], '@'), 1);

        return [
            'from' => [$from[0], self::oneLine((string) $from[1])],
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'reply_to' => $replyTo,
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
            'attachments' => $attachments,
            'embeds' => $embeds,
            'headers' => $headers,
            'message_id' => '<' . bin2hex(random_bytes(16)) . '@' . $domain . '>',
        ];
    }

    // ------------------------------------------------------------------
    // Renderização
    // ------------------------------------------------------------------

    /*
     * {{ var }} escapada (em HTML), {!! var !!} crua, caminho com ponto
     * (pedido.cliente.nome) e filtros: money, number, date, datetime,
     * upper, lower, nl2br. Variável ausente vira ''.
     */
    public static function renderString(string $template, array $data = [], bool $html = true): string
    {
        if (strpos($template, '{{') === false && strpos($template, '{!!') === false) {
            return $template;
        }

        // Uma passada só: valor inserido nunca é reprocessado.
        return preg_replace_callback(
            '/\{!!\s*([a-zA-Z0-9_.]+)\s*(?:\|\s*([a-z0-9_]+)\s*)?!!\}|\{\{\s*([a-zA-Z0-9_.]+)\s*(?:\|\s*([a-z0-9_]+)\s*)?\}\}/',
            static function ($m) use ($data, $html) {
                if ($m[1] !== '') {
                    return self::filter(self::value($data, $m[1]), $m[2] ?? '');
                }

                $filter = $m[4] ?? '';
                $value = self::filter(self::value($data, $m[3]), $filter);

                if ($filter === 'nl2br') {
                    return $html ? $value : strip_tags($value);
                }

                return $html ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : $value;
            },
            $template
        );
    }

    /*
     * Renderiza um arquivo .php (dados viram variáveis, $e() escapa) ou
     * qualquer outro (.html/.txt) com {{ }}.
     */
    public static function renderFile(string $file, array $data = []): string
    {
        $path = self::resolveView($file);

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
            return self::renderString((string) file_get_contents($path), $data, true);
        }

        $render = static function (string $__path, array $__data): string {
            extract($__data, EXTR_SKIP);
            $e = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
            $level = ob_get_level();
            ob_start();

            try {
                include $__path;

                return (string) ob_get_clean();
            } catch (Throwable $error) {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }

                throw $error;
            }
        };

        return $render($path, $data);
    }

    /*
     * Versão texto de um HTML (parte text/plain gerada automaticamente).
     */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace('#<(style|script|head|title)[^>]*>.*?</\1>#is', '', $html);
        $text = preg_replace_callback('#<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', static function ($m) {
            $label = trim(strip_tags($m[2]));

            return ($label === '' || $label === $m[1]) ? $m[1] : $label . ' (' . $m[1] . ')';
        }, $text);
        $text = preg_replace('#<li[^>]*>#i', "\n- ", $text);
        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = preg_replace('#</(p|div|h[1-6]|tr|table|ul|ol|header|footer|section)>#i', "\n\n", $text);
        $text = preg_replace('#</t[dh]>#i', "\t", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text);
        $text = preg_replace("/ *\n */", "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    private static function resolveView(string $file): string
    {
        if ($file === '' || strpos($file, "\0") !== false) {
            throw new InvalidArgumentException('Arquivo de view inválido.');
        }

        $candidates = [];
        $bases = self::$viewDirectory !== null ? [self::$viewDirectory . '/' . ltrim($file, '/\\'), $file] : [$file];

        foreach ($bases as $base) {
            $candidates[] = $base;

            if (pathinfo($base, PATHINFO_EXTENSION) === '') {
                $candidates[] = $base . '.php';
                $candidates[] = $base . '.html';
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        throw new InvalidArgumentException('View de e-mail não encontrada: ' . $file);
    }

    private static function value(array $data, string $path)
    {
        if (array_key_exists($path, $data)) {
            return $data[$path];
        }

        $value = $data;

        foreach (explode('.', $path) as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } elseif ($value instanceof ArrayAccess && $value->offsetExists($part)) {
                $value = $value[$part];
            } elseif (is_object($value) && isset($value->$part)) {
                $value = $value->$part;
            } else {
                return '';
            }
        }

        return $value;
    }

    private static function filter($value, string $filter): string
    {
        switch ($filter) {
            case 'money':
                return 'R$ ' . number_format((float) $value, 2, ',', '.');
            case 'number':
                return number_format((float) $value, 2, ',', '.');
            case 'date':
            case 'datetime':
                if ($value === null || $value === '') {
                    return '';
                }

                $time = $value instanceof DateTimeInterface ? $value->getTimestamp() : strtotime((string) $value);

                return $time ? date($filter === 'date' ? 'd/m/Y' : 'd/m/Y H:i', $time) : self::scalar($value);
            case 'upper':
                return mb_strtoupper(self::scalar($value), 'UTF-8');
            case 'lower':
                return mb_strtolower(self::scalar($value), 'UTF-8');
            case 'nl2br':
                return nl2br(htmlspecialchars(self::scalar($value), ENT_QUOTES, 'UTF-8'));
        }

        return self::scalar($value);
    }

    private static function scalar($value): string
    {
        if ($value === null || is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'Sim' : 'Não';
        }

        return (string) $value;
    }

    // ------------------------------------------------------------------
    // Drivers
    // ------------------------------------------------------------------

    private static function deliver(array $config, array $message): array
    {
        switch ($config['driver']) {
            case 'smtp':
                return self::sendSmtp($config, $message);
            case 'elasticemail':
                return self::sendElastic($config, $message);
            case 'mail':
                return self::sendPhpMail($config, $message);
            case 'log':
                return self::sendLog($config, $message);
        }

        throw new RuntimeException('Driver de e-mail desconhecido: ' . $config['driver']);
    }

    private static function sendSmtp(array $config, array $message): array
    {
        $socket = self::smtpOpen($config);

        try {
            $envelope = $config['envelope_from'] ?? $message['from'][0];
            self::smtpCommand($socket, 'MAIL FROM:<' . $envelope . '>', [250]);

            foreach (array_keys($message['to'] + $message['cc'] + $message['bcc']) as $recipient) {
                self::smtpCommand($socket, 'RCPT TO:<' . $recipient . '>', [250, 251]);
            }

            self::smtpCommand($socket, 'DATA', [354]);

            [$headers, $body] = self::mime($message, false);
            $raw = self::headerLines($headers) . "\r\n" . $body;
            // Dot-stuffing: linha que começa com "." ganha outro ponto.
            $raw = preg_replace('/^\./m', '..', $raw);

            if (substr($raw, -2) !== "\r\n") {
                $raw .= "\r\n";
            }

            self::smtpWrite($socket, $raw, false);
            $response = self::smtpCommand($socket, '.', [250]);
            self::smtpQuit($socket);

            return ['server_response' => trim($response)];
        } catch (Throwable $error) {
            if (is_resource($socket)) {
                fclose($socket);
            }

            throw $error;
        }
    }

    private static function sendElastic(array $config, array $message): array
    {
        $body = [];

        if ($message['html'] !== '') {
            $body[] = ['ContentType' => 'HTML', 'Content' => $message['html'], 'Charset' => 'utf-8'];
        }

        $body[] = ['ContentType' => 'PlainText', 'Content' => $message['text'], 'Charset' => 'utf-8'];

        $attachments = [];

        // A API não tem imagens embutidas (cid:): vão como anexos. Para cid: use 'elasticemail_smtp'.
        foreach (array_merge($message['attachments'], $message['embeds']) as $file) {
            $attachments[] = [
                'BinaryContent' => base64_encode($file['content']),
                'Name' => $file['name'],
                'ContentType' => $file['mime'],
            ];
        }

        $content = [
            'Body' => $body,
            'From' => self::formatAddressPlain($message['from'][0], $message['from'][1]),
            'Subject' => $message['subject'],
        ];

        if ($message['reply_to']) {
            $email = array_key_first($message['reply_to']);
            $content['ReplyTo'] = self::formatAddressPlain($email, $message['reply_to'][$email]);
        }

        if ($attachments) {
            $content['Attachments'] = $attachments;
        }

        if ($message['headers']) {
            $content['Headers'] = $message['headers'];
        }

        if (!empty($config['envelope_from'])) {
            $content['EnvelopeFrom'] = $config['envelope_from'];
        }

        $recipients = ['To' => array_keys($message['to'])];

        if ($message['cc']) {
            $recipients['CC'] = array_keys($message['cc']);
        }

        if ($message['bcc']) {
            $recipients['BCC'] = array_keys($message['bcc']);
        }

        $payload = json_encode(['Recipients' => $recipients, 'Content' => $content], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        [$status, $response] = self::httpPost(
            (string) ($config['api_url'] ?? self::ELASTIC_URL),
            $payload,
            ['Content-Type: application/json', 'Accept: application/json', 'X-ElasticEmail-ApiKey: ' . $config['api_key']],
            (int) ($config['timeout'] ?? 30),
            (bool) ($config['verify_peer'] ?? true)
        );

        $json = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            $detail = is_array($json) ? ($json['Error'] ?? $json['error'] ?? $json['message'] ?? $json['title'] ?? '') : '';
            $detail = $detail !== '' ? $detail : trim(substr(strip_tags($response), 0, 300));

            if ($status === 401 || $status === 403) {
                $detail = 'API key inválida ou sem permissão de envio (' . $detail . ')';
            }

            throw new RuntimeException('ElasticEmail HTTP ' . $status . ': ' . $detail);
        }

        return [
            'transaction_id' => is_array($json) ? ($json['TransactionID'] ?? null) : null,
            'provider_message_id' => is_array($json) ? ($json['MessageID'] ?? null) : null,
        ];
    }

    private static function sendPhpMail(array $config, array $message): array
    {
        [$headers, $body] = self::mime($message, true);

        $to = $headers['To'] ?? '';
        $subject = $headers['Subject'] ?? '';
        unset($headers['To'], $headers['Subject']);

        $params = '';
        $envelope = $config['envelope_from'] ?? $message['from'][0];

        // -f define o Return-Path no sendmail (no Windows o mail() usa o SMTP do php.ini).
        if (DIRECTORY_SEPARATOR === '/' && filter_var($envelope, FILTER_VALIDATE_EMAIL)) {
            $params = '-f' . $envelope;
        }

        $error = null;
        set_error_handler(static function ($code, $text) use (&$error) {
            $error = $text;

            return true;
        });

        try {
            $ok = mail($to, $subject, $body, rtrim(self::headerLines($headers), "\r\n"), $params);
        } finally {
            restore_error_handler();
        }

        if (!$ok) {
            throw new RuntimeException('mail() recusou o envio' . ($error ? ': ' . $error : '.'));
        }

        return [];
    }

    private static function sendLog(array $config, array $message): array
    {
        $directory = rtrim((string) ($config['directory'] ?? (getcwd() . '/logs/emails')), '/\\');

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta de e-mails: ' . $directory);
        }

        [$headers, $body] = self::mime($message, true);
        $file = $directory . '/' . date('Y-m-d_His') . '_' . substr(bin2hex(random_bytes(4)), 0, 8) . '.eml';

        if (file_put_contents($file, self::headerLines($headers) . "\r\n" . $body) === false) {
            throw new RuntimeException('Não foi possível gravar o e-mail em ' . $file);
        }

        return ['file' => $file];
    }

    private static function httpPost(string $url, string $body, array $headers, int $timeout, bool $verify): array
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
                CURLOPT_SSL_VERIFYPEER => $verify,
                CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            ]);

            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            curl_close($curl);

            if ($response === false) {
                throw new RuntimeException('Falha HTTP: ' . $error);
            }

            return [$status, (string) $response];
        }

        $context = stream_context_create([
            'http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body, 'timeout' => $timeout, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => $verify, 'verify_peer_name' => $verify],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            throw new RuntimeException('Falha HTTP ao acessar ' . parse_url($url, PHP_URL_HOST));
        }

        $status = 0;

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        return [$status, $response];
    }

    // ------------------------------------------------------------------
    // Cliente SMTP
    // ------------------------------------------------------------------

    private static function smtpOpen(array $config)
    {
        self::$transcript = [];

        $host = (string) $config['host'];
        $port = (int) ($config['port'] ?? 587);
        $encryption = strtolower((string) ($config['encryption'] ?? 'tls'));
        $timeout = (int) ($config['timeout'] ?? 30);
        $verify = (bool) ($config['verify_peer'] ?? true);

        if (!in_array($encryption, ['tls', 'ssl', 'none', ''], true)) {
            throw new InvalidArgumentException('encryption deve ser tls, ssl ou none.');
        }

        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'allow_self_signed' => !$verify,
            'peer_name' => $host,
            'SNI_enabled' => true,
        ]]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if (!$socket) {
            throw new RuntimeException('Não foi possível conectar a ' . $host . ':' . $port . ' (' . ($errstr !== '' ? $errstr : 'erro ' . $errno) . ').');
        }

        stream_set_timeout($socket, $timeout);

        try {
            self::smtpExpect($socket, [220]);

            $helo = (string) ($config['helo'] ?? self::heloName());
            $capabilities = self::smtpEhlo($socket, $helo);

            if ($encryption === 'tls') {
                if (!isset($capabilities['STARTTLS'])) {
                    throw new RuntimeException('O servidor ' . $host . ':' . $port . ' não oferece STARTTLS (tente encryption "ssl" na porta 465).');
                }

                self::smtpCommand($socket, 'STARTTLS', [220]);

                $method = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;

                if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                    $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
                }

                if (!@stream_socket_enable_crypto($socket, true, $method)) {
                    throw new RuntimeException('Falha ao iniciar TLS com ' . $host . ' (certificado inválido? use verify_peer => false só em ambiente local).');
                }

                $capabilities = self::smtpEhlo($socket, $helo);
            }

            self::smtpAuth($socket, $config, $capabilities);
        } catch (Throwable $error) {
            fclose($socket);

            throw $error;
        }

        return $socket;
    }

    private static function smtpEhlo($socket, string $helo): array
    {
        [$code, $response] = self::smtpSend($socket, 'EHLO ' . $helo);

        if ($code !== 250) {
            self::smtpCommand($socket, 'HELO ' . $helo, [250]);

            return [];
        }

        $capabilities = [];

        foreach (preg_split('/\r?\n/', trim($response)) as $index => $line) {
            if ($index === 0) {
                continue;
            }

            $line = trim(substr($line, 4));
            $parts = preg_split('/[ =]/', $line, 2);
            $capabilities[strtoupper($parts[0])] = isset($parts[1]) ? strtoupper(trim($parts[1])) : '';
        }

        return $capabilities;
    }

    private static function smtpAuth($socket, array $config, array $capabilities): void
    {
        $user = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $token = (string) ($config['oauth_token'] ?? '');

        if ($user === '' && $token === '') {
            return;
        }

        if (!isset($capabilities['AUTH'])) {
            throw new RuntimeException('O servidor não oferece autenticação (AUTH). Confira a porta e a criptografia (tls/ssl).');
        }

        $mechanisms = preg_split('/\s+/', $capabilities['AUTH']);

        try {
            if ($token !== '') {
                $response = self::smtpCommand($socket, 'AUTH XOAUTH2 ' . base64_encode('user=' . $user . "\x01auth=Bearer " . $token . "\x01\x01"), [235, 334], true);

                // 334 = erro em JSON; responde vazio para receber o código final.
                if (strpos($response, '334') === 0) {
                    self::smtpCommand($socket, '', [235]);
                }

                return;
            }

            if (in_array('PLAIN', $mechanisms, true)) {
                self::smtpCommand($socket, 'AUTH PLAIN ' . base64_encode("\0" . $user . "\0" . $password), [235], true);

                return;
            }

            if (in_array('LOGIN', $mechanisms, true)) {
                self::smtpCommand($socket, 'AUTH LOGIN', [334]);
                self::smtpCommand($socket, base64_encode($user), [334], true);
                self::smtpCommand($socket, base64_encode($password), [235], true);

                return;
            }
        } catch (RuntimeException $error) {
            $hint = '';
            $host = strtolower((string) ($config['host'] ?? ''));

            if (strpos($host, 'gmail') !== false) {
                $hint = ' No Gmail use uma senha de app (myaccount.google.com/apppasswords), com verificação em 2 etapas ativa.';
            } elseif (strpos($host, 'outlook') !== false || strpos($host, 'office365') !== false) {
                $hint = ' No Outlook/Hotmail/Office 365 o SMTP AUTH precisa estar habilitado na conta (ou use oauth_token).';
            }

            throw new RuntimeException('Falha na autenticação SMTP: ' . $error->getMessage() . $hint);
        }

        throw new RuntimeException('Nenhum método de autenticação suportado (servidor oferece: ' . $capabilities['AUTH'] . ').');
    }

    private static function smtpCommand($socket, string $command, array $expected, bool $secret = false): string
    {
        [$code, $response] = self::smtpSend($socket, $command, $secret);

        if (!in_array($code, $expected, true)) {
            $label = $secret ? 'AUTH' : strtok($command, ' :');

            throw new RuntimeException($label . ' → ' . trim(preg_replace('/\s+/', ' ', $response)));
        }

        return $response;
    }

    private static function smtpSend($socket, string $command, bool $secret = false): array
    {
        self::smtpWrite($socket, $command . "\r\n", true, $secret);

        return self::smtpRead($socket);
    }

    private static function smtpExpect($socket, array $expected): string
    {
        [$code, $response] = self::smtpRead($socket);

        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('Resposta inesperada do servidor: ' . trim($response));
        }

        return $response;
    }

    private static function smtpWrite($socket, string $data, bool $record = true, bool $secret = false): void
    {
        if ($record) {
            self::$transcript[] = 'C: ' . ($secret ? '[credenciais ocultas]' : rtrim($data));
        } else {
            self::$transcript[] = 'C: [mensagem: ' . strlen($data) . ' bytes]';
        }

        $length = strlen($data);
        $written = 0;

        while ($written < $length) {
            $bytes = @fwrite($socket, substr($data, $written, 65536));

            if ($bytes === false || $bytes === 0) {
                throw new RuntimeException('Conexão SMTP encerrada durante o envio.');
            }

            $written += $bytes;
        }
    }

    private static function smtpRead($socket): array
    {
        $response = '';

        while (($line = fgets($socket, 4096)) !== false) {
            $response .= $line;

            // "250-..." continua; "250 ..." é a última linha.
            if (strlen($line) < 4 || $line[3] === ' ' || $line[3] === "\r" || $line[3] === "\n") {
                break;
            }
        }

        if ($response === '') {
            $meta = stream_get_meta_data($socket);

            throw new RuntimeException(!empty($meta['timed_out']) ? 'Tempo esgotado aguardando o servidor SMTP.' : 'O servidor SMTP encerrou a conexão.');
        }

        self::$transcript[] = 'S: ' . rtrim($response);

        return [(int) substr($response, 0, 3), $response];
    }

    private static function smtpQuit($socket): void
    {
        try {
            self::smtpSend($socket, 'QUIT');
        } catch (Throwable $ignored) {
            // Já enviado; falha no QUIT não importa.
        }

        if (is_resource($socket)) {
            fclose($socket);
        }
    }

    private static function heloName(): string
    {
        $name = $_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost';

        return preg_match('/^[A-Za-z0-9.-]+$/', $name) && strpos($name, '.') !== false ? $name : 'localhost.localdomain';
    }

    // ------------------------------------------------------------------
    // MIME
    // ------------------------------------------------------------------

    /*
     * Devolve [headers (nome => valor), corpo]. $withBcc só para mail()/log.
     */
    private static function mime(array $message, bool $withBcc): array
    {
        $headers = [
            'Date' => date('r'),
            'From' => self::formatAddress($message['from'][0], $message['from'][1]),
            'To' => self::formatList($message['to']),
        ];

        if (!$message['to']) {
            $headers['To'] = 'undisclosed-recipients:;';
        }

        if ($message['cc']) {
            $headers['Cc'] = self::formatList($message['cc']);
        }

        if ($withBcc && $message['bcc']) {
            $headers['Bcc'] = self::formatList($message['bcc']);
        }

        if ($message['reply_to']) {
            $headers['Reply-To'] = self::formatList($message['reply_to']);
        }

        $headers['Subject'] = self::encodeHeader($message['subject']);
        $headers['Message-ID'] = $message['message_id'];
        $headers['MIME-Version'] = '1.0';
        $headers['X-Mailer'] = 'EmailSendHelper';

        foreach ($message['headers'] as $name => $value) {
            $headers[$name] = self::encodeHeader($value);
        }

        $textPart = self::part('text/plain; charset=UTF-8', self::qp($message['text']));
        $root = $textPart;

        if ($message['html'] !== '') {
            $root = self::multipart('alternative', [$textPart, self::part('text/html; charset=UTF-8', self::qp($message['html']))]);

            if ($message['embeds']) {
                $parts = [$root];

                foreach ($message['embeds'] as $file) {
                    $parts[] = self::filePart($file, 'inline', $file['cid']);
                }

                $root = self::multipart('related', $parts, 'multipart/alternative');
            }
        }

        $attachments = $message['attachments'];

        // Sem HTML, imagens embutidas viram anexos comuns.
        if ($message['html'] === '') {
            $attachments = array_merge($attachments, $message['embeds']);
        }

        if ($attachments) {
            $parts = [$root];

            foreach ($attachments as $file) {
                $parts[] = self::filePart($file, 'attachment');
            }

            $root = self::multipart('mixed', $parts);
        }

        return [$headers + $root['headers'], $root['body']];
    }

    private static function part(string $type, string $body, string $encoding = 'quoted-printable', array $extra = []): array
    {
        return [
            'headers' => ['Content-Type' => $type, 'Content-Transfer-Encoding' => $encoding] + $extra,
            'body' => $body,
        ];
    }

    private static function multipart(string $subtype, array $parts, ?string $type = null): array
    {
        $boundary = '=_' . bin2hex(random_bytes(12));
        $body = "This is a multi-part message in MIME format.\r\n";

        foreach ($parts as $part) {
            $body .= "\r\n--" . $boundary . "\r\n" . self::headerLines($part['headers']) . "\r\n" . $part['body'] . "\r\n";
        }

        $body .= "\r\n--" . $boundary . "--\r\n";
        $contentType = 'multipart/' . $subtype . '; boundary="' . $boundary . '"' . ($type ? '; type="' . $type . '"' : '');

        return ['headers' => ['Content-Type' => $contentType], 'body' => $body];
    }

    private static function filePart(array $file, string $disposition, ?string $cid = null): array
    {
        $extra = ['Content-Disposition' => $disposition . '; ' . self::fileParam('filename', $file['name'])];

        if ($cid !== null) {
            $extra['Content-ID'] = '<' . $cid . '>';
        }

        return self::part(
            $file['mime'] . '; ' . self::fileParam('name', $file['name']),
            rtrim(chunk_split(base64_encode($file['content']), 76, "\r\n")),
            'base64',
            $extra
        );
    }

    private static function fileParam(string $param, string $name): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $name)) {
            return $param . '="' . addcslashes($name, '"\\') . '"';
        }

        // Codificado de dois jeitos: RFC 2231 (padrão) e encoded-word (Outlook antigo).
        return $param . '="=?UTF-8?B?' . base64_encode($name) . '?="; ' . $param . "*=UTF-8''" . rawurlencode($name);
    }

    private static function headerLines(array $headers): string
    {
        $lines = '';

        foreach ($headers as $name => $value) {
            $lines .= $name . ': ' . $value . "\r\n";
        }

        return $lines;
    }

    private static function qp(string $text): string
    {
        $text = preg_replace('/\r\n|\r|\n/', "\r\n", $text);

        return quoted_printable_encode($text);
    }

    private static function encodeHeader(string $value): string
    {
        if (!preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }

        return mb_encode_mimeheader($value, 'UTF-8', 'B', "\r\n");
    }

    private static function formatAddress(string $email, string $name): string
    {
        if ($name === '') {
            return $email;
        }

        $name = preg_match('/[^\x20-\x7E]/', $name)
            ? mb_encode_mimeheader($name, 'UTF-8', 'B', "\r\n")
            : '"' . addcslashes($name, '"\\') . '"';

        return $name . ' <' . $email . '>';
    }

    private static function formatAddressPlain(string $email, string $name): string
    {
        return $name === '' ? $email : str_replace(['<', '>', '"'], '', $name) . ' <' . $email . '>';
    }

    private static function formatList(array $list): string
    {
        $items = [];

        foreach ($list as $email => $name) {
            $items[] = self::formatAddress((string) $email, (string) $name);
        }

        return implode(', ', $items);
    }

    // ------------------------------------------------------------------
    // Internos
    // ------------------------------------------------------------------

    private function addAddress(string $field, $address, ?string $name): self
    {
        try {
            $this->$field = array_merge($this->$field, self::addresses($address, $name, $field));
        } catch (InvalidArgumentException $error) {
            // Guardado para o send() devolver o erro sem quebrar a cadeia.
            $this->pending[] = $error->getMessage();
        }

        return $this;
    }

    private static function addresses($value, ?string $name, string $field): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }

        if (is_string($value) && $name !== null && $name !== '') {
            $value = [$value => $name];
        } elseif (is_string($value)) {
            // Divide por , ou ; fora de aspas ("Silva, Ana" <a@x.com>).
            $value = preg_split('/[,;](?=(?:[^"]*"[^"]*")*[^"]*$)/', $value);
        }

        $list = [];

        foreach ((array) $value as $key => $item) {
            if (is_string($key)) {
                $email = $key;
                $label = (string) $item;
            } elseif (is_array($item)) {
                $email = (string) ($item['email'] ?? $item[0] ?? '');
                $label = (string) ($item['name'] ?? $item[1] ?? '');
            } else {
                $email = (string) $item;
                $label = '';

                if (preg_match('/^\s*"?(.*?)"?\s*<([^<>]+)>\s*$/', $email, $m)) {
                    $email = $m[2];
                    $label = $m[1];
                }
            }

            $email = trim($email);

            if ($email === '') {
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('E-mail inválido em "' . $field . '": ' . $email);
            }

            $list[$email] = self::oneLine(trim($label));
        }

        return $list;
    }

    private function loadFiles(int $maxSize): array
    {
        $attachments = [];
        $embeds = [];
        $total = 0;

        foreach ($this->attachments as $item) {
            if (array_key_exists('content', $item)) {
                $content = $item['content'];
                $name = (string) $item['name'];
            } else {
                $path = (string) $item['path'];

                if ($path === '' || !is_file($path) || !is_readable($path)) {
                    throw new InvalidArgumentException('Anexo não encontrado: ' . ($path === '' ? '(vazio)' : basename($path)));
                }

                $content = (string) file_get_contents($path);
                $name = (string) ($item['name'] ?? basename($path));
            }

            $name = self::fileName($name);
            $total += strlen($content);
            $attachments[] = ['name' => $name, 'mime' => $item['mime'] ?: self::mimeType($name, $content), 'content' => $content];
        }

        foreach ($this->embeds as $item) {
            if (!is_file($item['path']) || !is_readable($item['path'])) {
                throw new InvalidArgumentException('Imagem embutida não encontrada: ' . basename($item['path']));
            }

            $content = (string) file_get_contents($item['path']);
            $name = self::fileName(basename($item['path']));
            $total += strlen($content);
            $embeds[] = ['name' => $name, 'mime' => self::mimeType($name, $content), 'content' => $content, 'cid' => $item['cid']];
        }

        if ($maxSize > 0 && $total > $maxSize) {
            throw new InvalidArgumentException(sprintf('Anexos somam %.1f MB; o limite é %.1f MB.', $total / 1048576, $maxSize / 1048576));
        }

        return [$attachments, $embeds];
    }

    private static function fileName(string $name): string
    {
        $name = basename(str_replace('\\', '/', self::oneLine($name)));
        $name = trim(str_replace(['"', "\0"], '', $name));

        return $name === '' ? 'anexo' : $name;
    }

    private static function mimeType(string $name, string $content): string
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (isset(self::MIME_TYPES[$extension])) {
            return self::MIME_TYPES[$extension];
        }

        if (class_exists('finfo')) {
            $type = (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

            if (is_string($type) && $type !== '') {
                return $type;
            }
        }

        return 'application/octet-stream';
    }

    private function resolveConnections(): array
    {
        $connection = $this->connection;

        // Config avulsa: ->via(['driver' => 'smtp', 'host' => ...])
        if (is_array($connection) && $connection && !array_is_list($connection)) {
            $name = '_inline_' . bin2hex(random_bytes(4));
            self::register($name, $connection);
            $config = self::$connections[$name];
            self::forget($name);
            $config['name'] = (string) ($connection['name'] ?? 'inline');

            return [$config];
        }

        $names = $connection === null || $connection === '' || $connection === [] ? [self::$default] : (array) $connection;
        $configs = [];

        foreach ($names as $name) {
            $configs[] = self::config($name);
        }

        return $configs;
    }

    private function previewConfig(): array
    {
        try {
            return $this->resolveConnections()[0];
        } catch (Throwable $ignored) {
            return [];
        }
    }

    private static function config(?string $name): array
    {
        if ($name === null) {
            throw new RuntimeException('Nenhuma conexão de e-mail registrada (use EmailSendHelper::register()).');
        }

        $key = strtolower($name);

        if (!isset(self::$connections[$key])) {
            throw new RuntimeException('Conexão de e-mail não registrada: ' . $name);
        }

        return self::$connections[$key];
    }

    private static function normalizeConfig(array $config): array
    {
        $aliases = [
            'user' => 'username', 'login' => 'username', 'pass' => 'password', 'senha' => 'password',
            'apikey' => 'api_key', 'key' => 'api_key', 'from' => 'from_email', 'secure' => 'encryption',
            'smtp_host' => 'host', 'smtp_port' => 'port',
        ];
        $normalized = [];

        foreach ($config as $key => $value) {
            $key = strtolower((string) $key);
            $normalized[$aliases[$key] ?? $key] = $value;
        }

        if (isset($normalized['encryption'])) {
            $map = ['starttls' => 'tls', 'smtps' => 'ssl', 'false' => 'none', '0' => 'none'];
            $value = strtolower((string) $normalized['encryption']);
            $normalized['encryption'] = $map[$value] ?? $value;
        }

        return $normalized;
    }

    private static function oneLine(string $text): string
    {
        return trim(preg_replace('/[\r\n\0]+/', ' ', $text));
    }

    private static function log(string $level, string $text, array $config, array $message): void
    {
        if (!class_exists('LogHelper', false) || !method_exists('LogHelper', $level)) {
            return;
        }

        LogHelper::$level($text, 'mail', [
            'connection' => $config['name'] ?? '',
            'driver' => $config['driver'] ?? '',
            'to' => isset($message['to']) && is_array($message['to']) ? implode(', ', array_keys($message['to'])) : '',
            'subject' => $message['subject'] ?? '',
            'anexos' => isset($message['attachments']) ? count($message['attachments']) : 0,
        ]);
    }

    private static function result(bool $success, string $message, array $data = [], array $errors = []): array
    {
        return [
            'success' => $success,
            'status' => $success ? 'sucesso' : 'erro',
            'mensagem' => $message,
            'data' => $data,
            'errors' => $errors,
        ];
    }
}
