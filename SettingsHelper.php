<?php

defined('_JEXEC') or die;

/*
 * Configurações do componente (Opções do componente / config.xml) com
 * tipo e padrão, em vez de getParams() espalhado pelo código:
 *
 *   $limite = SettingsHelper::int('limite_upload_mb', 10);
 *   $ativo  = SettingsHelper::bool('pix_ativo');
 *   $token  = SettingsHelper::secret('erp_token');   // decifra se gravado com CryptoHelper
 *   SettingsHelper::requireKeys(array('erp_url', 'erp_token'));   // erro claro se faltar
 *
 * Componente padrão: o da requisição (option) ou o definido em setComponent().
 */

class SettingsHelper
{
    private static $component = null;

    // Valores trocados em tempo de execução (testes, ambiente): componente => chave => valor
    private static $overrides = array();

    public static function setComponent($component)
    {
        self::$component = self::normalizeComponent($component);
    }

    public static function getComponent()
    {
        if (self::$component !== null) {
            return self::$component;
        }

        try {
            $option = JFactory::getApplication()->input->getCmd('option', '');

            if (preg_match('/^com_[a-z0-9_]+$/i', $option)) {
                return strtolower($option);
            }
        } catch (Throwable $error) {
            // CLI sem aplicação: exige setComponent().
        }

        throw new RuntimeException('Informe o componente: SettingsHelper::setComponent(\'com_x\').');
    }

    // ------------------------------------------------------------------
    // Leitura com tipo
    // ------------------------------------------------------------------

    /*
     * Valor cru (o que estiver gravado), ou $default se ausente ou vazio.
     * Chaves com ponto leem campos aninhados (subform): 'smtp.host'.
     */
    public static function get($key, $default = null, $component = null)
    {
        $component = $component === null ? self::getComponent() : self::normalizeComponent($component);

        if (isset(self::$overrides[$component]) && array_key_exists($key, self::$overrides[$component])) {
            return self::$overrides[$component][$key];
        }

        $value = self::params($component)->get($key, null);

        if ($value === null || $value === '') {
            return $default;
        }

        return $value;
    }

    public static function string($key, $default = '', $component = null)
    {
        $value = self::get($key, null, $component);

        return is_scalar($value) ? trim((string) $value) : (string) $default;
    }

    public static function int($key, $default = 0, $component = null)
    {
        $value = self::get($key, null, $component);

        return is_numeric($value) ? (int) $value : (int) $default;
    }

    public static function float($key, $default = 0.0, $component = null)
    {
        $value = self::get($key, null, $component);

        if (is_string($value)) {
            $value = str_replace(',', '.', trim($value));
        }

        return is_numeric($value) ? (float) $value : (float) $default;
    }

    /*
     * Rádio Sim/Não do Joomla grava '1'/'0'; aceita também true/false, on/off, sim/não.
     */
    public static function bool($key, $default = false, $component = null)
    {
        $value = self::get($key, null, $component);

        if ($value === null) {
            return (bool) $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        $text = strtolower(trim((string) $value));

        if (in_array($text, array('1', 'true', 'on', 'yes', 'sim', 's'), true)) {
            return true;
        }

        if (in_array($text, array('0', 'false', 'off', 'no', 'nao', 'não', 'n', ''), true)) {
            return false;
        }

        return (bool) $default;
    }

    /*
     * Lista: campo múltiplo do Joomla (array), JSON ('["a","b"]') ou texto
     * separado por vírgula/linha ('a, b' / uma por linha). Itens vazios saem.
     */
    public static function array($key, array $default = array(), $component = null)
    {
        $value = self::get($key, null, $component);

        if ($value === null) {
            return $default;
        }

        if (is_object($value)) {
            $value = json_decode(json_encode($value), true);
        }

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : preg_split('/[\r\n,;]+/', $value);
        }

        if (!is_array($value)) {
            return $default;
        }

        return array_values(array_filter(array_map(function ($item) {
            return is_string($item) ? trim($item) : $item;
        }, $value), function ($item) {
            return $item !== '' && $item !== null;
        }));
    }

    /*
     * Valor permitido de uma lista (select do config.xml), senão o padrão.
     */
    public static function oneOf($key, array $allowed, $default = null, $component = null)
    {
        $value = self::string($key, '', $component);

        return in_array($value, array_map('strval', $allowed), true) ? $value : $default;
    }

    /*
     * Segredo: se foi gravado com CryptoHelper::encrypt($v, 'settings.<chave>')
     * (ou com SettingsHelper::set(..., true)), devolve decifrado.
     */
    public static function secret($key, $default = '', $component = null)
    {
        $value = self::string($key, '', $component);

        if ($value === '') {
            return $default;
        }

        if (class_exists('CryptoHelper') && CryptoHelper::isEncrypted($value)) {
            $plain = CryptoHelper::decrypt($value, 'settings.' . $key);

            return $plain === null ? $default : $plain;
        }

        return $value;
    }

    /*
     * Todas as configurações do componente (array).
     */
    public static function all($component = null)
    {
        $component = $component === null ? self::getComponent() : self::normalizeComponent($component);
        $values = self::params($component)->toArray();

        return isset(self::$overrides[$component]) ? array_merge($values, self::$overrides[$component]) : $values;
    }

    /*
     * Lança RuntimeException com a lista do que falta (vazio conta como
     * faltando). Use no início de integrações: "Configure erp_url, erp_token
     * em Opções do componente".
     */
    public static function requireKeys(array $keys, $component = null)
    {
        $missing = array();

        foreach ($keys as $key) {
            $value = self::get($key, null, $component);

            if ($value === null || $value === '' || $value === array()) {
                $missing[] = $key;
            }
        }

        if ($missing) {
            throw new RuntimeException('Configure em Opções do componente: ' . implode(', ', $missing) . '.');
        }

        return true;
    }

    // ------------------------------------------------------------------
    // Escrita
    // ------------------------------------------------------------------

    /*
     * Grava uma configuração no componente (#__extensions.params). Com
     * $encrypt = true, cifra com o CryptoHelper (leia com secret()).
     * Use em tarefas do administrador (ex.: token renovado por OAuth);
     * confira a permissão core.admin antes.
     */
    public static function set($key, $value, $encrypt = false, $component = null)
    {
        $component = $component === null ? self::getComponent() : self::normalizeComponent($component);

        if (!preg_match('/^[a-zA-Z0-9_.-]+$/', (string) $key)) {
            throw new InvalidArgumentException('Nome de configuração inválido: ' . $key);
        }

        if ($encrypt) {
            if (!class_exists('CryptoHelper')) {
                throw new RuntimeException('Carregue o CryptoHelper para gravar configurações cifradas.');
            }

            $value = CryptoHelper::encrypt((string) $value, 'settings.' . $key);
        }

        $params = self::params($component);
        $params->set($key, $value);

        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('params') . ' = ' . $db->quote($params->toString()))
            ->where($db->quoteName('element') . ' = ' . $db->quote($component))
            ->where($db->quoteName('type') . ' = ' . $db->quote('component'));

        $db->setQuery($query);
        $db->execute();

        if (isset(self::$overrides[$component])) {
            unset(self::$overrides[$component][$key]);
        }

        return true;
    }

    /*
     * Troca um valor só nesta execução (testes, ambiente de homologação).
     * null remove a troca.
     */
    public static function override($key, $value, $component = null)
    {
        $component = $component === null ? self::getComponent() : self::normalizeComponent($component);

        if ($value === null) {
            unset(self::$overrides[$component][$key]);

            return;
        }

        self::$overrides[$component][$key] = $value;
    }

    // ------------------------------------------------------------------

    private static function params($component)
    {
        // Mesmo objeto que o Joomla guarda em cache: set() já reflete na requisição.
        return JComponentHelper::getParams($component);
    }

    private static function normalizeComponent($component)
    {
        $component = strtolower(trim((string) $component));

        if (!preg_match('/^com_[a-z0-9_]+$/', $component)) {
            throw new InvalidArgumentException('Componente inválido: ' . $component);
        }

        return $component;
    }
}
