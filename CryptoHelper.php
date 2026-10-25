<?php

defined('_JEXEC') or die;

/*
 * Criptografia para segredos guardados no banco (tokens de API,
 * credenciais de integração, dados sensíveis), assinatura HMAC de
 * webhooks e tokens aleatórios seguros.
 *
 *   $cifrado = CryptoHelper::encrypt($tokenDoErp, 'erp_token');   // grava no banco
 *   $token   = CryptoHelper::decrypt($cifrado, 'erp_token');      // null se adulterado ou chave errada
 *
 * Algoritmo: XChaCha20-Poly1305 (sodium, PHP 7.2+) ou, sem sodium,
 * AES-256-CBC + HMAC-SHA256 (encrypt-then-MAC). Os dois autenticam: um
 * byte alterado faz o decrypt falhar em vez de devolver lixo.
 *
 * Chave: a definida em setKey() ou, por padrão, derivada do "secret"
 * do configuration.php (trocar o secret impede ler o que foi cifrado;
 * guarde a chave antiga em setPreviousKeys() ao trocar).
 */

class CryptoHelper
{
    const PREFIX_SODIUM = 'h1:';

    const PREFIX_OPENSSL = 'h2:';

    private static $key = null;

    private static $previousKeys = array();

    // null = detecta; false força o caminho openssl (testes de compatibilidade).
    private static $useSodium = null;

    // ------------------------------------------------------------------
    // Chaves
    // ------------------------------------------------------------------

    /*
     * Chave mestra própria (recomendado): 32 bytes em base64, gerada com
     * CryptoHelper::generateKey() e guardada fora da pasta pública ou em
     * variável de ambiente.
     */
    public static function setKey($base64Key)
    {
        self::$key = self::decodeKey($base64Key);
    }

    /*
     * Chaves anteriores, tentadas no decrypt depois da atual (rotação).
     */
    public static function setPreviousKeys(array $base64Keys)
    {
        self::$previousKeys = array();

        foreach ($base64Keys as $key) {
            self::$previousKeys[] = self::decodeKey($key);
        }
    }

    public static function generateKey()
    {
        return base64_encode(random_bytes(32));
    }

    // ------------------------------------------------------------------
    // Criptografia
    // ------------------------------------------------------------------

    /*
     * Cifra um texto. $context liga o valor ao lugar onde ele fica (ex.:
     * 'erp_token', 'clientes.cpf'): um valor copiado para outra coluna
     * não decifra lá. Devolve texto ASCII seguro para VARCHAR/TEXT
     * (cerca de 1,4x o tamanho original + 60 caracteres).
     */
    public static function encrypt($plaintext, $context = '')
    {
        $plaintext = (string) $plaintext;
        $master = self::masterKey();

        if (self::hasSodium()) {
            $key = self::subKey($master, 'enc-sodium');
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, self::aad($context), $nonce, $key);

            return self::PREFIX_SODIUM . self::base64UrlEncode($nonce . $cipher);
        }

        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('O PHP não tem sodium nem openssl: não é possível criptografar.');
        }

        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'aes-256-cbc', self::subKey($master, 'enc-aes'), OPENSSL_RAW_DATA, $iv);

        if ($cipher === false) {
            throw new RuntimeException('Falha ao criptografar.');
        }

        $mac = hash_hmac('sha256', self::PREFIX_OPENSSL . self::aad($context) . $iv . $cipher, self::subKey($master, 'mac-aes'), true);

        return self::PREFIX_OPENSSL . self::base64UrlEncode($iv . $cipher . $mac);
    }

    /*
     * Decifra. Devolve null se o valor foi adulterado, é de outro
     * contexto, foi cifrado com outra chave ou não é um valor cifrado.
     */
    public static function decrypt($encrypted, $context = '')
    {
        $encrypted = (string) $encrypted;

        foreach (self::allKeys() as $master) {
            $plain = self::decryptWith($encrypted, $context, $master);

            if ($plain !== null) {
                return $plain;
            }
        }

        if ($encrypted !== '' && class_exists('LogHelper')) {
            LogHelper::warning('Valor cifrado não pôde ser lido (adulterado, outro contexto ou outra chave).', 'crypto', array('contexto' => (string) $context));
        }

        return null;
    }

    /*
     * true se o texto tem o formato de um valor cifrado por este helper
     * (útil para migrar colunas que ainda têm valores em claro).
     */
    public static function isEncrypted($value)
    {
        $value = (string) $value;

        return strpos($value, self::PREFIX_SODIUM) === 0 || strpos($value, self::PREFIX_OPENSSL) === 0;
    }

    /*
     * Cifra de novo com a chave atual (rotação de chave). null se não decifrar.
     */
    public static function reencrypt($encrypted, $context = '')
    {
        $plain = self::decrypt($encrypted, $context);

        return $plain === null ? null : self::encrypt($plain, $context);
    }

    // ------------------------------------------------------------------
    // HMAC e webhooks
    // ------------------------------------------------------------------

    /*
     * Assinatura HMAC (hex). Sem $secret, usa uma subchave da chave mestra
     * (para assinar links e dados do próprio sistema).
     */
    public static function hmac($data, $secret = null, $algo = 'sha256')
    {
        $secret = $secret === null ? self::subKey(self::masterKey(), 'hmac') : (string) $secret;

        return hash_hmac($algo, (string) $data, $secret);
    }

    /*
     * Confere a assinatura de um webhook em tempo constante.
     * Opções: 'algo' (sha256), 'encoding' ('hex' ou 'base64'), 'prefix'
     * (ex.: 'sha256=' do GitHub). Use o corpo CRU da requisição
     * (file_get_contents('php://input')), nunca o JSON decodificado.
     */
    public static function verifySignature($payload, $signature, $secret, array $options = array())
    {
        $algo = isset($options['algo']) ? $options['algo'] : 'sha256';
        $encoding = isset($options['encoding']) ? $options['encoding'] : 'hex';
        $prefix = isset($options['prefix']) ? (string) $options['prefix'] : '';
        $signature = trim((string) $signature);

        if ($prefix !== '') {
            if (strpos($signature, $prefix) !== 0) {
                return false;
            }

            $signature = substr($signature, strlen($prefix));
        }

        if ($signature === '' || (string) $secret === '') {
            return false;
        }

        $raw = hash_hmac($algo, (string) $payload, (string) $secret, true);
        $expected = $encoding === 'base64' ? base64_encode($raw) : bin2hex($raw);

        if ($encoding !== 'base64') {
            $signature = strtolower($signature);
        }

        return hash_equals($expected, $signature);
    }

    /*
     * Assina dados com prazo (links de "confirmar e-mail", "baixar
     * arquivo"): devolve um texto que verifySigned() confere.
     */
    public static function sign(array $data, $seconds = 3600)
    {
        $payload = self::base64UrlEncode(json_encode(array('d' => $data, 'e' => time() + (int) $seconds)));

        return $payload . '.' . self::base64UrlEncode(hash_hmac('sha256', $payload, self::subKey(self::masterKey(), 'sign'), true));
    }

    /*
     * Dados assinados por sign(), ou null se adulterado ou vencido.
     */
    public static function verifySigned($token)
    {
        $parts = explode('.', (string) $token);

        if (count($parts) !== 2) {
            return null;
        }

        foreach (self::allKeys() as $master) {
            $expected = self::base64UrlEncode(hash_hmac('sha256', $parts[0], self::subKey($master, 'sign'), true));

            if (hash_equals($expected, $parts[1])) {
                $decoded = json_decode(self::base64UrlDecode($parts[0]), true);

                if (!is_array($decoded) || !isset($decoded['e']) || (int) $decoded['e'] < time()) {
                    return null;
                }

                return isset($decoded['d']) ? $decoded['d'] : null;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Tokens e comparação
    // ------------------------------------------------------------------

    /*
     * Token aleatório seguro para URL (base64url, sem = + /).
     * 32 bytes = 43 caracteres.
     */
    public static function token($bytes = 32)
    {
        return self::base64UrlEncode(random_bytes(max(16, (int) $bytes)));
    }

    /*
     * Código numérico (verificação por SMS/e-mail), sem viés: '048213'.
     */
    public static function numericCode($digits = 6)
    {
        $digits = max(4, min(12, (int) $digits));
        $code = '';

        for ($i = 0; $i < $digits; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    /*
     * Hash para guardar um token no banco (link de redefinição de senha,
     * chave de API): quem lê o banco não consegue usar o token.
     */
    public static function hashToken($token)
    {
        return hash_hmac('sha256', (string) $token, self::subKey(self::masterKey(), 'token'));
    }

    /*
     * Comparação em tempo constante.
     */
    public static function equals($known, $given)
    {
        return hash_equals((string) $known, (string) $given);
    }

    /*
     * 'joao@exemplo.com' -> 'j***@exemplo.com'; '12345678909' -> '*******8909'.
     */
    public static function mask($value, $visible = 4)
    {
        $value = (string) $value;

        if (strpos($value, '@') !== false) {
            list($user, $domain) = explode('@', $value, 2);

            return substr($user, 0, 1) . str_repeat('*', max(3, strlen($user) - 1)) . '@' . $domain;
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        $visible = max(0, min((int) $visible, $length - 1));
        $end = function_exists('mb_substr') ? mb_substr($value, $length - $visible, null, 'UTF-8') : substr($value, -$visible);

        return str_repeat('*', $length - $visible) . ($visible > 0 ? $end : '');
    }

    // ------------------------------------------------------------------

    private static function decryptWith($encrypted, $context, $master)
    {
        try {
            if (strpos($encrypted, self::PREFIX_SODIUM) === 0) {
                if (!self::hasSodium()) {
                    throw new RuntimeException('Valor cifrado com sodium, mas este PHP não tem a extensão sodium.');
                }

                $raw = self::base64UrlDecode(substr($encrypted, strlen(self::PREFIX_SODIUM)));
                $nonceSize = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;

                if ($raw === false || strlen($raw) < $nonceSize + 16) {
                    return null;
                }

                $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    substr($raw, $nonceSize),
                    self::aad($context),
                    substr($raw, 0, $nonceSize),
                    self::subKey($master, 'enc-sodium')
                );

                return $plain === false ? null : $plain;
            }

            if (strpos($encrypted, self::PREFIX_OPENSSL) === 0) {
                $raw = self::base64UrlDecode(substr($encrypted, strlen(self::PREFIX_OPENSSL)));

                if ($raw === false || strlen($raw) < 16 + 16 + 32) {
                    return null;
                }

                $iv = substr($raw, 0, 16);
                $mac = substr($raw, -32);
                $cipher = substr($raw, 16, -32);
                $expected = hash_hmac('sha256', self::PREFIX_OPENSSL . self::aad($context) . $iv . $cipher, self::subKey($master, 'mac-aes'), true);

                // MAC antes de decifrar: nada adulterado chega ao openssl.
                if (!hash_equals($expected, $mac)) {
                    return null;
                }

                $plain = openssl_decrypt($cipher, 'aes-256-cbc', self::subKey($master, 'enc-aes'), OPENSSL_RAW_DATA, $iv);

                return $plain === false ? null : $plain;
            }
        } catch (RuntimeException $error) {
            throw $error;
        } catch (Throwable $error) {
            return null;
        }

        return null;
    }

    private static function hasSodium()
    {
        if (self::$useSodium !== null) {
            return self::$useSodium;
        }

        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    private static function aad($context)
    {
        return 'helpers-joomla|' . (string) $context;
    }

    private static function masterKey()
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $secret = '';

        if (class_exists('JFactory')) {
            $secret = (string) JFactory::getConfig()->get('secret');
        }

        if (strlen($secret) < 16) {
            throw new RuntimeException('Defina a chave com CryptoHelper::setKey() (o "secret" do Joomla está vazio ou curto).');
        }

        return hash('sha256', 'helpers-joomla-crypto|' . $secret, true);
    }

    private static function allKeys()
    {
        return array_merge(array(self::masterKey()), self::$previousKeys);
    }

    // Subchave por finalidade: a mesma chave mestra nunca é usada em dois algoritmos.
    private static function subKey($master, $purpose)
    {
        return hash_hmac('sha256', 'helpers-joomla|' . $purpose, $master, true);
    }

    private static function decodeKey($base64Key)
    {
        $key = base64_decode(strtr((string) $base64Key, '-_', '+/'), true);

        if ($key === false || strlen($key) < 32) {
            throw new InvalidArgumentException('Chave inválida: use CryptoHelper::generateKey() (32 bytes em base64).');
        }

        return $key;
    }

    private static function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode($data)
    {
        $data = strtr((string) $data, '-_', '+/');
        $padding = strlen($data) % 4;

        if ($padding) {
            $data .= str_repeat('=', 4 - $padding);
        }

        return base64_decode($data, true);
    }
}
