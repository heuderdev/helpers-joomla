<?php

defined('_JEXEC') or die;

/*
 * Chamadas HTTP a APIs externas (ERP, pagamento, frete, WhatsApp) com
 * cURL: timeout sempre ligado, nova tentativa com espera crescente só
 * quando é seguro, JSON de ida e volta, autenticação, log com segredos
 * mascarados e proteção opcional contra SSRF.
 *
 * Nunca lança exceção por padrão: devolve a resposta normalizada
 * (ok, status, headers, body, json, error, time_ms, attempts, url).
 * Com 'throw' => true, lança HttpHelperException quando ok = false.
 */

class HttpHelperException extends RuntimeException
{
    private $response = array();

    public function __construct($message, array $response = array(), $previous = null)
    {
        parent::__construct($message, isset($response['status']) ? (int) $response['status'] : 0, $previous);

        $this->response = $response;
    }

    public function getResponse()
    {
        return $this->response;
    }

    public function getStatus()
    {
        return isset($this->response['status']) ? (int) $this->response['status'] : 0;
    }
}

class HttpHelper
{
    private static $defaults = array(
        'timeout' => 15,
        'connect_timeout' => 5,
        'retries' => 2,
        'retry_delay' => 300,
        'retry_max_delay' => 5000,
        'retry_on' => array(429, 500, 502, 503, 504),
        'retry_post' => false,
        'retry_after_max' => 30,
        'follow_redirects' => 0,
        'verify' => true,
        'user_agent' => 'helpers-joomla HttpHelper',
        'block_private' => false,
        'log' => true,
        'log_body' => false,
        'log_category' => 'api',
        'throw' => false,
        'max_body_log' => 2000
    );

    // Métodos que podem ser repetidos sem efeito colateral (RFC 9110).
    private static $idempotent = array('GET', 'HEAD', 'OPTIONS', 'PUT', 'DELETE');

    private static $sensitiveParams = array(
        'token', 'key', 'apikey', 'api_key', 'secret', 'password', 'senha', 'signature', 'sig', 'access_token', 'auth'
    );

    /*
     * Padrões de todas as chamadas: HttpHelper::setDefaults(array('timeout' => 10)).
     */
    public static function setDefaults(array $defaults)
    {
        self::$defaults = array_merge(self::$defaults, $defaults);
    }

    public static function getDefaults()
    {
        return self::$defaults;
    }

    /*
     * Cliente com padrões próprios (URL base, autenticação, cabeçalhos):
     *   $erp = HttpHelper::client(array('base_url' => 'https://erp/api/v2', 'bearer' => $token));
     *   $erp->get('/pedidos', array('pagina' => 2));
     */
    public static function client(array $defaults = array())
    {
        return new HttpClient($defaults);
    }

    public static function get($url, array $query = array(), array $options = array())
    {
        $options['query'] = array_merge(isset($options['query']) ? (array) $options['query'] : array(), $query);

        return self::request('GET', $url, $options);
    }

    public static function head($url, array $options = array())
    {
        return self::request('HEAD', $url, $options);
    }

    /*
     * $body: array/objeto vira JSON; string vai como está. Para formulário
     * use $options['form'], para upload $options['multipart'].
     */
    public static function post($url, $body = null, array $options = array())
    {
        return self::request('POST', $url, self::withBody($body, $options));
    }

    public static function put($url, $body = null, array $options = array())
    {
        return self::request('PUT', $url, self::withBody($body, $options));
    }

    public static function patch($url, $body = null, array $options = array())
    {
        return self::request('PATCH', $url, self::withBody($body, $options));
    }

    public static function delete($url, $body = null, array $options = array())
    {
        return self::request('DELETE', $url, self::withBody($body, $options));
    }

    /*
     * Baixa direto para um arquivo (memória constante). O arquivo só
     * aparece no destino quando o download termina com sucesso.
     */
    public static function download($url, $destination, array $options = array())
    {
        $options['sink'] = (string) $destination;

        return self::request(isset($options['method']) ? $options['method'] : 'GET', $url, $options);
    }

    private static function withBody($body, array $options)
    {
        if ($body === null) {
            return $options;
        }

        if (is_string($body)) {
            $options['body'] = $body;
        } else {
            $options['json'] = $body;
        }

        return $options;
    }

    /*
     * Opções: headers, query, json, form, multipart, body, bearer,
     * basic (array(usuario, senha)), timeout, connect_timeout (segundos),
     * retries, retry_delay, retry_max_delay (ms), retry_on, retry_post,
     * retry_after_max, follow_redirects (0 = não segue), verify,
     * ca_bundle, user_agent, block_private, sink, log, log_body,
     * log_category, throw, base_url.
     */
    public static function request($method, $url, array $options = array())
    {
        $options = array_merge(self::$defaults, $options);
        $method = strtoupper(trim((string) $method));
        $started = microtime(true);
        $response = null;

        try {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('A extensão cURL do PHP não está instalada.');
            }

            $url = self::buildUrl($url, $options);

            if (!empty($options['block_private'])) {
                self::assertPublicUrl($url);
            }

            $maxAttempts = 1 + max(0, (int) $options['retries']);
            $canRetry = in_array($method, self::$idempotent, true) || !empty($options['retry_post']);

            for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
                $response = self::send($method, $url, $options);
                $response['attempts'] = $attempt;

                if ($attempt === $maxAttempts || !$canRetry || !self::shouldRetry($response, $options)) {
                    break;
                }

                usleep(self::retryDelay($attempt, $response, $options) * 1000);
            }
        } catch (Throwable $error) {
            $response = self::emptyResponse($url, $error->getMessage());
        }

        $response['time_ms'] = (int) round((microtime(true) - $started) * 1000);

        self::log($method, $url, $options, $response);

        if (!empty($options['throw']) && !$response['ok']) {
            throw new HttpHelperException(self::errorMessage($response), $response);
        }

        return $response;
    }

    /*
     * true se o host resolve só para IPs públicos. Use quando a URL vem
     * de quem usa o sistema (webhook configurável, importar de URL).
     */
    public static function isPublicUrl($url)
    {
        try {
            self::assertPublicUrl($url);

            return true;
        } catch (Throwable $error) {
            return false;
        }
    }

    /*
     * Texto curto para mostrar a quem usa o sistema.
     */
    public static function errorMessage(array $response)
    {
        if (!empty($response['ok'])) {
            return '';
        }

        if (!empty($response['error'])) {
            return $response['error'];
        }

        $status = isset($response['status']) ? (int) $response['status'] : 0;

        if ($status === 429) {
            return 'O serviço externo recebeu muitas requisições. Tente novamente em instantes.';
        }

        if ($status >= 500) {
            return 'O serviço externo está indisponível no momento (HTTP ' . $status . ').';
        }

        if ($status === 401 || $status === 403) {
            return 'O serviço externo recusou as credenciais (HTTP ' . $status . ').';
        }

        return 'O serviço externo recusou a requisição (HTTP ' . $status . ').';
    }

    // ------------------------------------------------------------------

    private static function buildUrl($url, array $options)
    {
        $url = trim((string) $url);

        if (!empty($options['base_url']) && !preg_match('#^https?://#i', $url)) {
            $url = rtrim($options['base_url'], '/') . '/' . ltrim($url, '/');
        }

        if (!preg_match('#^https?://[^/\s]+#i', $url)) {
            throw new InvalidArgumentException('URL inválida: use http:// ou https://.');
        }

        if (!empty($options['query'])) {
            $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($options['query'], '', '&', PHP_QUERY_RFC3986);
        }

        return $url;
    }

    private static function assertPublicUrl($url)
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            throw new InvalidArgumentException('URL sem host.');
        }

        $host = trim($host, '[]');
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? array($host) : self::resolve($host);

        if (empty($ips)) {
            throw new RuntimeException('Não foi possível resolver o endereço ' . $host . '.');
        }

        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new RuntimeException('Endereço interno bloqueado: ' . $host . '.');
            }
        }
    }

    private static function resolve($host)
    {
        $ips = array();
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }

                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        if (empty($ips)) {
            $v4 = @gethostbynamel($host);
            $ips = is_array($v4) ? $v4 : array();
        }

        return $ips;
    }

    private static function send($method, $url, array $options)
    {
        $handle = curl_init();
        $headers = array();
        $sinkHandle = null;
        $sinkTemp = null;

        $requestHeaders = self::requestHeaders($options);
        $body = self::requestBody($options, $requestHeaders);

        curl_setopt($handle, CURLOPT_URL, $url);
        curl_setopt($handle, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($handle, CURLOPT_TIMEOUT_MS, (int) round((float) $options['timeout'] * 1000));
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT_MS, (int) round((float) $options['connect_timeout'] * 1000));
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, (bool) $options['verify']);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, $options['verify'] ? 2 : 0);
        curl_setopt($handle, CURLOPT_USERAGENT, (string) $options['user_agent']);
        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, (int) $options['follow_redirects'] > 0);
        curl_setopt($handle, CURLOPT_MAXREDIRS, max(0, (int) $options['follow_redirects']));
        curl_setopt($handle, CURLOPT_ENCODING, '');
        curl_setopt($handle, CURLOPT_NOSIGNAL, true);

        // Só http e https, inclusive em redirecionamentos (sem file://, gopher://...).
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($handle, CURLOPT_PROTOCOLS_STR, 'http,https');
            curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS_STR, 'http,https');
        } else {
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($handle, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        if (!empty($options['ca_bundle'])) {
            curl_setopt($handle, CURLOPT_CAINFO, (string) $options['ca_bundle']);
        }

        if (!empty($options['basic']) && is_array($options['basic'])) {
            curl_setopt($handle, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($handle, CURLOPT_USERPWD, $options['basic'][0] . ':' . (isset($options['basic'][1]) ? $options['basic'][1] : ''));
        }

        self::applyProxy($handle);

        if ($method === 'HEAD') {
            curl_setopt($handle, CURLOPT_NOBODY, true);
        } elseif ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $lines = array();

        foreach ($requestHeaders as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        // Sem "Expect: 100-continue": evita 1 s de espera em corpos grandes.
        $lines[] = 'Expect:';
        curl_setopt($handle, CURLOPT_HTTPHEADER, $lines);

        curl_setopt($handle, CURLOPT_HEADERFUNCTION, function ($curl, $line) use (&$headers) {
            $length = strlen($line);
            $line = trim($line);

            // Nova resposta (redirecionamento ou 100 Continue): recomeça.
            if (stripos($line, 'HTTP/') === 0) {
                $headers = array();
            } elseif (strpos($line, ':') !== false) {
                list($name, $value) = explode(':', $line, 2);
                $name = strtolower(trim($name));
                $value = trim($value);
                $headers[$name] = isset($headers[$name]) ? $headers[$name] . ', ' . $value : $value;
            }

            return $length;
        });

        if (!empty($options['sink'])) {
            $sinkTemp = $options['sink'] . '.part';
            $sinkHandle = @fopen($sinkTemp, 'wb');

            if ($sinkHandle === false) {
                self::close($handle);
                throw new RuntimeException('Não foi possível gravar o arquivo de destino.');
            }

            curl_setopt($handle, CURLOPT_FILE, $sinkHandle);
        } else {
            curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        }

        $raw = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $finalUrl = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);

        self::close($handle);

        if ($sinkHandle) {
            fclose($sinkHandle);
            $raw = '';

            if ($errno === 0 && $status >= 200 && $status < 300) {
                if (!@rename($sinkTemp, $options['sink'])) {
                    @unlink($sinkTemp);
                    throw new RuntimeException('Não foi possível mover o arquivo baixado para o destino.');
                }
            } else {
                @unlink($sinkTemp);
            }
        }

        if ($errno !== 0) {
            $response = self::emptyResponse($url, self::curlMessage($errno, $error, $options));
            $response['network_error'] = true;
            $response['timeout'] = $errno === 28;

            return $response;
        }

        $body = is_string($raw) ? $raw : '';
        $json = null;

        if ($body !== '' && (strpos(isset($headers['content-type']) ? $headers['content-type'] : '', 'json') !== false || preg_match('/^\s*[\[{]/', $body))) {
            $decoded = json_decode($body, true);
            $json = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
        }

        return array(
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'json' => $json,
            'error' => null,
            'network_error' => false,
            'timeout' => false,
            'time_ms' => 0,
            'attempts' => 1,
            'url' => $finalUrl !== '' ? $finalUrl : $url
        );
    }

    // curl_close() não faz nada desde o PHP 8.0 e é obsoleta no 8.5.
    private static function close($handle)
    {
        if (PHP_VERSION_ID < 80000) {
            curl_close($handle);
        }
    }

    private static function requestHeaders(array $options)
    {
        $headers = array('Accept' => 'application/json, */*;q=0.8');

        if (!empty($options['bearer'])) {
            $headers['Authorization'] = 'Bearer ' . $options['bearer'];
        }

        if (!empty($options['headers']) && is_array($options['headers'])) {
            foreach ($options['headers'] as $name => $value) {
                $headers[(string) $name] = (string) $value;
            }
        }

        return $headers;
    }

    private static function requestBody(array $options, array &$headers)
    {
        if (array_key_exists('json', $options) && $options['json'] !== null) {
            $json = json_encode($options['json'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($json === false) {
                throw new InvalidArgumentException('Não foi possível converter o corpo para JSON: ' . json_last_error_msg());
            }

            self::setDefaultHeader($headers, 'Content-Type', 'application/json; charset=utf-8');

            return $json;
        }

        if (!empty($options['form']) && is_array($options['form'])) {
            self::setDefaultHeader($headers, 'Content-Type', 'application/x-www-form-urlencoded');

            return http_build_query($options['form'], '', '&');
        }

        if (!empty($options['multipart']) && is_array($options['multipart'])) {
            // O cURL monta o multipart (com boundary) a partir do array.
            return self::flattenMultipart($options['multipart']);
        }

        if (isset($options['body']) && $options['body'] !== '') {
            return (string) $options['body'];
        }

        return null;
    }

    private static function setDefaultHeader(array &$headers, $name, $value)
    {
        foreach (array_keys($headers) as $existing) {
            if (strcasecmp($existing, $name) === 0) {
                return;
            }
        }

        $headers[$name] = $value;
    }

    // array('itens' => array(array('sku' => 'A'))) -> 'itens[0][sku]' => 'A'
    private static function flattenMultipart(array $data, $prefix = '')
    {
        $flat = array();

        foreach ($data as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if ($value instanceof CURLFile) {
                $flat[$name] = $value;
            } elseif (is_array($value)) {
                $flat = array_merge($flat, self::flattenMultipart($value, $name));
            } elseif (is_bool($value)) {
                $flat[$name] = $value ? '1' : '0';
            } elseif ($value !== null) {
                $flat[$name] = (string) $value;
            }
        }

        return $flat;
    }

    private static function applyProxy($handle)
    {
        try {
            if (!class_exists('JFactory')) {
                return;
            }

            $config = JFactory::getConfig();

            if (!$config->get('proxy_enable')) {
                return;
            }

            curl_setopt($handle, CURLOPT_PROXY, $config->get('proxy_host') . ':' . $config->get('proxy_port'));

            if ($config->get('proxy_user')) {
                curl_setopt($handle, CURLOPT_PROXYUSERPWD, $config->get('proxy_user') . ':' . $config->get('proxy_pass'));
            }
        } catch (Throwable $error) {
            // Sem configuração do Joomla (CLI isolado): segue sem proxy.
        }
    }

    private static function shouldRetry(array $response, array $options)
    {
        if (!empty($response['network_error'])) {
            return true;
        }

        return in_array((int) $response['status'], array_map('intval', (array) $options['retry_on']), true);
    }

    // Espera crescente com variação aleatória; respeita Retry-After (até retry_after_max).
    private static function retryDelay($attempt, array $response, array $options)
    {
        if (!empty($response['headers']['retry-after'])) {
            $retryAfter = $response['headers']['retry-after'];
            $seconds = is_numeric($retryAfter) ? (int) $retryAfter : max(0, strtotime($retryAfter) - time());

            return min($seconds, (int) $options['retry_after_max']) * 1000;
        }

        $delay = (int) $options['retry_delay'] * pow(2, $attempt - 1);

        return min($delay, (int) $options['retry_max_delay']) + mt_rand(0, 100);
    }

    private static function curlMessage($errno, $error, array $options)
    {
        switch ($errno) {
            case 6:
                return 'Não foi possível encontrar o servidor do serviço externo.';
            case 7:
                return 'Não foi possível conectar ao serviço externo.';
            case 28:
                return 'O serviço externo não respondeu a tempo (limite de ' . $options['timeout'] . ' s).';
            case 35:
            case 51:
            case 58:
            case 60:
                return 'Falha na conexão segura (SSL) com o serviço externo.';
            case 47:
                return 'O serviço externo redirecionou vezes demais.';
        }

        return 'Falha na comunicação com o serviço externo (' . $errno . ': ' . $error . ').';
    }

    private static function emptyResponse($url, $error)
    {
        return array(
            'ok' => false,
            'status' => 0,
            'headers' => array(),
            'body' => '',
            'json' => null,
            'error' => (string) $error,
            'network_error' => false,
            'timeout' => false,
            'time_ms' => 0,
            'attempts' => 1,
            'url' => (string) $url
        );
    }

    private static function maskUrl($url)
    {
        $parts = explode('?', (string) $url, 2);

        if (count($parts) < 2) {
            return $url;
        }

        parse_str($parts[1], $query);

        foreach ($query as $key => $value) {
            foreach (self::$sensitiveParams as $sensitive) {
                if (stripos((string) $key, $sensitive) !== false) {
                    $query[$key] = '****';
                    break;
                }
            }
        }

        return $parts[0] . '?' . http_build_query($query, '', '&');
    }

    private static function log($method, $url, array $options, array $response)
    {
        if (empty($options['log']) || !class_exists('LogHelper')) {
            return;
        }

        try {
            $context = array(
                'method' => $method,
                'url' => self::maskUrl($url),
                'status' => $response['status'],
                'time_ms' => $response['time_ms'],
                'attempts' => $response['attempts']
            );

            if (!empty($response['error'])) {
                $context['error'] = $response['error'];
            }

            if (!empty($options['log_body'])) {
                $limit = (int) $options['max_body_log'];
                $context['request'] = isset($options['json']) ? $options['json'] : null;
                $context['response'] = $response['json'] !== null ? $response['json'] : substr($response['body'], 0, $limit);
            }

            $level = $response['ok'] ? 'info' : ($response['status'] >= 400 && $response['status'] < 500 ? 'warning' : 'error');
            $message = $method . ' ' . parse_url($url, PHP_URL_HOST) . ' -> ' . ($response['status'] ?: 'falha');

            if (method_exists('LogHelper', $level)) {
                LogHelper::$level($message, $options['log_category'], $context);
            }
        } catch (Throwable $error) {
            // Log nunca derruba a chamada.
        }
    }
}

/*
 * Cliente com padrões fixos (URL base, token, timeout) para uma API.
 * Criado por HttpHelper::client(); os métodos aceitam as mesmas opções.
 */
class HttpClient
{
    private $defaults;

    public function __construct(array $defaults = array())
    {
        $this->defaults = $defaults;
    }

    public function withOptions(array $options)
    {
        return new HttpClient($this->merge($options));
    }

    public function request($method, $path, array $options = array())
    {
        return HttpHelper::request($method, $path, $this->merge($options));
    }

    public function get($path, array $query = array(), array $options = array())
    {
        return HttpHelper::get($path, $query, $this->merge($options));
    }

    public function post($path, $body = null, array $options = array())
    {
        return HttpHelper::post($path, $body, $this->merge($options));
    }

    public function put($path, $body = null, array $options = array())
    {
        return HttpHelper::put($path, $body, $this->merge($options));
    }

    public function patch($path, $body = null, array $options = array())
    {
        return HttpHelper::patch($path, $body, $this->merge($options));
    }

    public function delete($path, $body = null, array $options = array())
    {
        return HttpHelper::delete($path, $body, $this->merge($options));
    }

    public function download($path, $destination, array $options = array())
    {
        return HttpHelper::download($path, $destination, $this->merge($options));
    }

    private function merge(array $options)
    {
        $merged = array_merge($this->defaults, $options);

        // Cabeçalhos e query se somam (os da chamada têm prioridade).
        foreach (array('headers', 'query') as $key) {
            if (isset($this->defaults[$key]) || isset($options[$key])) {
                $merged[$key] = array_merge(
                    isset($this->defaults[$key]) ? (array) $this->defaults[$key] : array(),
                    isset($options[$key]) ? (array) $options[$key] : array()
                );
            }
        }

        return $merged;
    }
}
