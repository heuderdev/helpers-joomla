<?php

defined('_JEXEC') or die;

/*
 * Cache de resultados caros (dashboards, totais, respostas de API,
 * listas de selects) sobre o cache do Joomla 3, 4 e 5: usa o handler
 * configurado no site (arquivo, Redis, Memcached, APCu) e funciona mesmo
 * com o cache do site desligado.
 *
 *   $totais = CacheHelper::remember('dashboard:totais', 300, function () {
 *       return DashboardService::totais();
 *   });
 *   CacheHelper::flushPrefix('dashboard');   // invalida dashboard:*
 *
 * Cada item guarda o próprio prazo; null e false também são guardados.
 */

class CacheHelper
{
    const GROUP = 'helpers_cache';

    private static $enabled = true;

    private static $group = self::GROUP;

    private static $controller = null;

    // Itens já lidos nesta requisição (evita ler e desserializar de novo).
    private static $memory = array();

    // Armazenamento quando o Joomla não está disponível (CLI isolado, testes).
    private static $fallback = array();

    private static $stats = array('hits' => 0, 'misses' => 0, 'writes' => 0);

    /*
     * Devolve o valor guardado ou executa $callback, guarda e devolve.
     * Com o LockHelper carregado, só um processo recalcula uma chave
     * expirada; os outros esperam (até 10 s) e leem o resultado.
     * Opções: 'lock' => false desliga a espera; 'lock_wait' => segundos.
     */
    public static function remember($key, $seconds, $callback, array $options = array())
    {
        $found = false;
        $value = self::read($key, $found);

        if ($found) {
            return $value;
        }

        if (!is_callable($callback)) {
            throw new InvalidArgumentException('CacheHelper::remember() precisa de um callable.');
        }

        $useLock = self::$enabled
            && (!isset($options['lock']) || $options['lock'])
            && class_exists('LockHelper');

        if (!$useLock) {
            $value = call_user_func($callback);
            self::set($key, $value, $seconds);

            return $value;
        }

        $wait = isset($options['lock_wait']) ? (float) $options['lock_wait'] : 10;
        $self = __CLASS__;

        $result = LockHelper::run('cache:' . $key, function () use ($self, $key, $seconds, $callback) {
            // Outro processo pode ter calculado enquanto esperávamos a trava.
            $found = false;
            $value = $self::readFresh($key, $found);

            if ($found) {
                return $value;
            }

            $value = call_user_func($callback);
            $self::set($key, $value, $seconds);

            return $value;
        }, $wait);

        if ($result['acquired']) {
            return $result['result'];
        }

        // Não conseguiu a trava a tempo: calcula sem guardar disputa.
        $value = call_user_func($callback);
        self::set($key, $value, $seconds);

        return $value;
    }

    /*
     * remember() sem prazo (até ser apagado ou o cache ser limpo).
     */
    public static function rememberForever($key, $callback, array $options = array())
    {
        return self::remember($key, 0, $callback, $options);
    }

    public static function get($key, $default = null)
    {
        $found = false;
        $value = self::read($key, $found);

        return $found ? $value : $default;
    }

    public static function has($key)
    {
        $found = false;
        self::read($key, $found);

        return $found;
    }

    /*
     * Guarda $value por $seconds segundos (0 = sem prazo). Devolve false
     * se o armazenamento falhar (o sistema segue sem cache).
     */
    public static function set($key, $value, $seconds = 0)
    {
        if (!self::$enabled) {
            return false;
        }

        $seconds = max(0, (int) $seconds);
        $id = self::id($key);
        $entry = array(
            'expires' => $seconds > 0 ? time() + $seconds : 0,
            'value' => $value
        );

        self::$memory[$id] = $entry;
        self::$stats['writes']++;

        try {
            $controller = self::controller();

            if ($controller === false) {
                self::$fallback[$id] = serialize($entry);

                return true;
            }

            // Prazo do Joomla em minutos, com folga: quem decide é o 'expires' do item.
            $controller->setLifeTime($seconds > 0 ? (int) ceil($seconds / 60) + 1 : 525600);

            return (bool) $controller->store(serialize($entry), $id, self::$group);
        } catch (Throwable $error) {
            self::log('Falha ao gravar no cache.', $error, $key);

            return false;
        }
    }

    public static function forget($key)
    {
        $id = self::id($key);
        unset(self::$memory[$id], self::$fallback[$id]);

        try {
            $controller = self::controller();

            return $controller === false ? true : (bool) $controller->remove($id, self::$group);
        } catch (Throwable $error) {
            self::log('Falha ao apagar do cache.', $error, $key);

            return false;
        }
    }

    /*
     * Lê e apaga (ex.: aviso mostrado uma vez).
     */
    public static function pull($key, $default = null)
    {
        $value = self::get($key, $default);
        self::forget($key);

        return $value;
    }

    /*
     * Soma $by a um contador guardado (cria com 0). Não é atômico entre
     * processos: para limites de tentativas use o RateLimitHelper.
     */
    public static function increment($key, $by = 1, $seconds = 0)
    {
        $value = (int) self::get($key, 0) + (int) $by;
        self::set($key, $value, $seconds);

        return $value;
    }

    /*
     * Invalida todas as chaves de um prefixo: 'dashboard' apaga
     * 'dashboard:totais', 'dashboard:grafico:2026'... O prefixo é a parte
     * antes do primeiro ':' da chave. Custo constante (não percorre itens).
     */
    public static function flushPrefix($prefix)
    {
        $prefix = trim((string) $prefix, ": \t\n\r");

        if ($prefix === '') {
            throw new InvalidArgumentException('Informe o prefixo.');
        }

        self::$memory = array();

        return self::setRaw('__v:' . $prefix, self::newVersion());
    }

    /*
     * Apaga tudo do CacheHelper (só o grupo dele, não o cache do site).
     */
    public static function flush()
    {
        self::$memory = array();
        self::$fallback = array();

        try {
            $controller = self::controller();

            return $controller === false ? true : (bool) $controller->clean(self::$group);
        } catch (Throwable $error) {
            self::log('Falha ao limpar o cache.', $error, '*');

            return false;
        }
    }

    public static function setEnabled($enabled)
    {
        self::$enabled = (bool) $enabled;
        self::$memory = array();
    }

    /*
     * Grupo do cache do Joomla (padrão 'helpers_cache'). Útil para
     * separar componentes: CacheHelper::setGroup('com_loja').
     */
    public static function setGroup($group)
    {
        $group = preg_replace('/[^a-zA-Z0-9_.-]/', '_', trim((string) $group));

        if ($group === '') {
            throw new InvalidArgumentException('Grupo de cache inválido.');
        }

        self::$group = $group;
        self::$memory = array();
    }

    /*
     * Usa outro controlador de cache (ex.: para testes). null volta ao do Joomla.
     */
    public static function setController($controller)
    {
        self::$controller = $controller;
        self::$memory = array();
    }

    public static function stats()
    {
        return self::$stats;
    }

    // ------------------------------------------------------------------

    /*
     * Leitura ignorando a memória da requisição (usada dentro da trava).
     * Pública só para o remember() a chamar de dentro da closure.
     */
    public static function readFresh($key, &$found)
    {
        unset(self::$memory[self::id($key)]);

        return self::read($key, $found);
    }

    private static function read($key, &$found)
    {
        $found = false;

        if (!self::$enabled) {
            return null;
        }

        $id = self::id($key);
        $count = strpos((string) $key, '__v:') !== 0;   // leituras internas de versão não entram nas estatísticas

        if (isset(self::$memory[$id])) {
            $entry = self::$memory[$id];
        } else {
            $raw = self::getRaw($id);
            $entry = is_string($raw) ? @unserialize($raw) : false;

            if (!is_array($entry) || !array_key_exists('expires', $entry)) {
                self::$stats['misses'] += $count ? 1 : 0;

                return null;
            }

            self::$memory[$id] = $entry;
        }

        if ($entry['expires'] > 0 && $entry['expires'] <= time()) {
            unset(self::$memory[$id]);
            self::$stats['misses'] += $count ? 1 : 0;

            return null;
        }

        $found = true;
        self::$stats['hits'] += $count ? 1 : 0;

        return $entry['value'];
    }

    private static function getRaw($id)
    {
        try {
            $controller = self::controller();

            if ($controller === false) {
                return isset(self::$fallback[$id]) ? self::$fallback[$id] : false;
            }

            // Prazo longo na leitura: o 'expires' do item é que vale.
            $controller->setLifeTime(525600);

            return $controller->get($id, self::$group);
        } catch (Throwable $error) {
            self::log('Falha ao ler o cache.', $error, $id);

            return false;
        }
    }

    private static function setRaw($key, $value)
    {
        return self::set($key, $value, 0);
    }

    // Chave interna: versão do prefixo + hash (qualquer texto vira uma chave segura).
    private static function id($key)
    {
        $key = (string) $key;

        if (strpos($key, '__v:') === 0) {
            return 'v_' . sha1($key);
        }

        $colon = strpos($key, ':');
        $version = '';

        if ($colon !== false && $colon > 0) {
            $found = false;
            $version = self::read('__v:' . substr($key, 0, $colon), $found);
            $version = $found ? (string) $version : '';
        }

        return sha1($version . '|' . $key);
    }

    private static function newVersion()
    {
        return function_exists('random_bytes') ? bin2hex(random_bytes(8)) : uniqid('', true);
    }

    private static function controller()
    {
        if (self::$controller !== null) {
            return self::$controller;
        }

        if (!class_exists('JFactory')) {
            return false;
        }

        // 'output' guarda texto; caching forçado vale mesmo com o cache do site desligado.
        $controller = JFactory::getCache(self::$group, 'output');
        $controller->setCaching(true);

        self::$controller = $controller;

        return $controller;
    }

    private static function log($message, Throwable $error, $key)
    {
        if (class_exists('LogHelper')) {
            LogHelper::warning($message, 'cache', array('chave' => $key, 'erro' => $error->getMessage()));
        }
    }
}
