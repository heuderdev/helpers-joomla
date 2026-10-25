<?php

defined('_JEXEC') or die;

/*
 * Formatação brasileira para telas, e-mails, PDFs e exportações:
 * dinheiro (inclusive em centavos), números, documentos, telefone, CEP,
 * slug, resumo de texto, bytes, plural e nomes.
 *
 *   FormatHelper::money(1234.5);            // 'R$ 1.234,50'
 *   FormatHelper::toCents('R$ 1.234,56');   // 123456
 *   FormatHelper::cpfCnpj('11222333000181'); // '11.222.333/0001-81'
 *   FormatHelper::slug('Pão de Açúcar!');   // 'pao-de-acucar'
 *
 * Nunca lança exceção: valor que não dá para formatar volta como veio
 * (texto) ou vazio (null).
 */

class FormatHelper
{
    // ------------------------------------------------------------------
    // Dinheiro e números
    // ------------------------------------------------------------------

    /*
     * 'R$ 1.234,50'. $value: número, texto ("1234.5", "1.234,50") ou null.
     */
    public static function money($value, $symbol = true, $decimals = 2)
    {
        $number = self::toNumber($value);

        if ($number === null) {
            return '';
        }

        $text = number_format(abs($number), (int) $decimals, ',', '.');

        return ($number < 0 && round(abs($number), (int) $decimals) > 0 ? '-' : '') . ($symbol ? 'R$ ' : '') . $text;
    }

    /*
     * Centavos inteiros (como gateways de pagamento guardam) para 'R$ 12,34'.
     */
    public static function moneyFromCents($cents, $symbol = true)
    {
        if ($cents === null || $cents === '') {
            return '';
        }

        $cents = (int) $cents;

        return ($cents < 0 ? '-' : '') . ($symbol ? 'R$ ' : '') . number_format(intdiv(abs($cents), 100), 0, ',', '.') . ',' . str_pad((string) (abs($cents) % 100), 2, '0', STR_PAD_LEFT);
    }

    /*
     * Valor digitado ou do banco para centavos inteiros, sem erro de
     * ponto flutuante: 'R$ 1.234,56' -> 123456; '19.9' -> 1990; 0.1 + 0.2 -> 30.
     */
    public static function toCents($value)
    {
        if (is_int($value)) {
            return $value * 100;
        }

        if (is_float($value)) {
            $value = number_format($value, 6, '.', '');
        }

        $number = self::normalizeNumberString($value);

        if ($number === null) {
            return null;
        }

        $negative = strpos($number, '-') === 0;
        $number = ltrim($number, '+-');
        $parts = explode('.', $number, 2);
        $decimals = isset($parts[1]) ? $parts[1] : '';
        $cents = (int) $parts[0] * 100 + (int) str_pad(substr($decimals, 0, 2), 2, '0');

        // Arredonda a partir da 3ª casa (meio para cima).
        if (strlen($decimals) > 2 && (int) $decimals[2] >= 5) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    /*
     * Texto com número em formato brasileiro ou internacional para float
     * (null se não for número): '1.234,56', '1,234.56', 'R$ 99,90', '12,5%'.
     */
    public static function toNumber($value)
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $number = self::normalizeNumberString($value);

        return $number === null ? null : (float) $number;
    }

    public static function number($value, $decimals = 2)
    {
        $number = self::toNumber($value);

        return $number === null ? '' : number_format($number, (int) $decimals, ',', '.');
    }

    public static function integer($value)
    {
        $number = self::toNumber($value);

        return $number === null ? '' : number_format(round($number), 0, ',', '.');
    }

    /*
     * 12.5 -> '12,5%'. Com $fraction = true, 0.125 -> '12,5%'.
     */
    public static function percent($value, $decimals = 1, $fraction = false)
    {
        $number = self::toNumber($value);

        if ($number === null) {
            return '';
        }

        return number_format($fraction ? $number * 100 : $number, (int) $decimals, ',', '.') . '%';
    }

    /*
     * 1536 -> '1,5 KB'.
     */
    public static function bytes($bytes, $decimals = 1)
    {
        $bytes = max(0, (float) $bytes);
        $units = array('B', 'KB', 'MB', 'GB', 'TB');
        $power = $bytes > 0 ? min(count($units) - 1, (int) floor(log($bytes, 1024))) : 0;
        $value = $bytes / pow(1024, $power);

        return number_format($value, $power === 0 ? 0 : (int) $decimals, ',', '.') . ' ' . $units[$power];
    }

    // ------------------------------------------------------------------
    // Documentos e contatos
    // ------------------------------------------------------------------

    public static function onlyDigits($value)
    {
        return preg_replace('/\D+/', '', (string) $value);
    }

    public static function cpf($value)
    {
        $digits = self::onlyDigits($value);

        return strlen($digits) === 11 ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digits) : (string) $value;
    }

    public static function cnpj($value)
    {
        $digits = self::onlyDigits($value);

        return strlen($digits) === 14 ? preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digits) : (string) $value;
    }

    public static function cpfCnpj($value)
    {
        $length = strlen(self::onlyDigits($value));

        return $length === 14 ? self::cnpj($value) : ($length === 11 ? self::cpf($value) : (string) $value);
    }

    public static function cep($value)
    {
        $digits = self::onlyDigits($value);

        return strlen($digits) === 8 ? substr($digits, 0, 5) . '-' . substr($digits, 5) : (string) $value;
    }

    /*
     * '11987654321' -> '(11) 98765-4321'; '1133334444' -> '(11) 3333-4444';
     * '+55 11 98765-4321' -> '(11) 98765-4321'. 0800 -> '0800 123 4567'.
     */
    public static function phone($value)
    {
        $digits = self::onlyDigits($value);

        if (strlen($digits) === 11 && strpos($digits, '0800') === 0) {
            return substr($digits, 0, 4) . ' ' . substr($digits, 4, 3) . ' ' . substr($digits, 7);
        }

        if ((strlen($digits) === 12 || strlen($digits) === 13) && strpos($digits, '55') === 0) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11) {
            return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 5) . '-' . substr($digits, 7);
        }

        if (strlen($digits) === 10) {
            return '(' . substr($digits, 0, 2) . ') ' . substr($digits, 2, 4) . '-' . substr($digits, 6);
        }

        return (string) $value;
    }

    /*
     * Número para link do WhatsApp (wa.me): só dígitos com o 55.
     */
    public static function whatsapp($value)
    {
        $digits = self::onlyDigits($value);

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            $digits = '55' . $digits;
        }

        return $digits;
    }

    // ------------------------------------------------------------------
    // Texto
    // ------------------------------------------------------------------

    /*
     * 'Pão de Açúcar — Promoção!' -> 'pao-de-acucar-promocao'.
     */
    public static function slug($text, $separator = '-', $maxLength = 0)
    {
        $text = self::removeAccents((string) $text);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', $separator, $text);
        $text = trim($text, $separator);

        if ($maxLength > 0 && strlen($text) > $maxLength) {
            $text = rtrim(substr($text, 0, (int) $maxLength), $separator);
        }

        return $text;
    }

    public static function removeAccents($text)
    {
        $map = array(
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'ç' => 'c', 'Ç' => 'C', 'ñ' => 'n', 'Ñ' => 'N', 'ý' => 'y', 'ÿ' => 'y', 'Ý' => 'Y',
            'ß' => 'ss', 'æ' => 'ae', 'Æ' => 'AE', 'ø' => 'o', 'Ø' => 'O', 'œ' => 'oe', 'Œ' => 'OE',
            'ª' => 'a', 'º' => 'o', '°' => 'o', '–' => '-', '—' => '-', '’' => '', '‘' => '', '“' => '', '”' => '', '€' => 'eur'
        );

        return strtr((string) $text, $map);
    }

    /*
     * Resume o texto sem cortar palavras: limit('Uma frase bem longa', 12) -> 'Uma frase…'.
     * Remove HTML antes; conta caracteres (acentos e emojis contam 1).
     */
    public static function limit($text, $max = 100, $end = '…')
    {
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8')));

        if (self::length($text) <= $max) {
            return $text;
        }

        $size = max(1, (int) $max - self::length($end));
        $cut = self::substr($text, 0, $size);
        $space = self::lastSpace($cut);
        $endsWord = self::substr($text, $size, 1) === ' ';

        // Corta no último espaço, a menos que o corte já caia no fim de uma palavra.
        if (!$endsWord && $space > (int) ($max * 0.6)) {
            $cut = self::substr($cut, 0, $space);
        }

        return rtrim($cut, " \t,.;:-") . $end;
    }

    /*
     * plural(1, 'item', 'itens') -> '1 item'; plural(3, ...) -> '3 itens'; 0 -> '0 itens'.
     * Com $zero: plural(0, 'item', 'itens', 'Nenhum item') -> 'Nenhum item'.
     */
    public static function plural($count, $singular, $plural, $zero = null)
    {
        $count = (int) $count;

        if ($count === 0 && $zero !== null) {
            return $zero;
        }

        return number_format($count, 0, ',', '.') . ' ' . (abs($count) === 1 ? $singular : $plural);
    }

    /*
     * 'MARIA DA SILVA' / 'maria da silva' -> 'Maria da Silva'.
     */
    public static function name($text)
    {
        $lower = array('da', 'de', 'do', 'das', 'dos', 'e', 'di', 'du', 'del', 'van', 'von');
        $words = preg_split('/\s+/u', trim(self::lower((string) $text)));

        foreach ($words as $i => $word) {
            if ($i > 0 && in_array($word, $lower, true)) {
                continue;
            }

            $words[$i] = self::upper(self::substr($word, 0, 1)) . self::substr($word, 1);
        }

        return implode(' ', $words);
    }

    /*
     * 'Maria da Silva' -> 'MS' (avatar).
     */
    public static function initials($text, $max = 2)
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim((string) $text)), function ($word) {
            return $word !== '' && !in_array(strtolower($word), array('da', 'de', 'do', 'das', 'dos', 'e'), true);
        }));

        if (!$words) {
            return '';
        }

        $picked = count($words) > $max ? array_merge(array_slice($words, 0, $max - 1), array(end($words))) : $words;
        $out = '';

        foreach ($picked as $word) {
            $out .= self::upper(self::substr($word, 0, 1));
        }

        return $out;
    }

    // ------------------------------------------------------------------

    // Texto -> número com ponto decimal ('-1234.56'), ou null.
    private static function normalizeNumberString($value)
    {
        if ($value === null || is_array($value) || is_object($value) || is_bool($value)) {
            return null;
        }

        $text = preg_replace('/R\$|US\$|\$|€|%|\s|\x{00A0}/u', '', trim((string) $value));

        if ($text === '' || $text === null) {
            return null;
        }

        if (preg_match('/^[+-]?\d+([.,]\d+)?$/', $text)) {
            return str_replace(',', '.', $text);
        }

        if (preg_match('/^[+-]?\d{1,3}(\.\d{3})+(,\d+)?$/', $text)) {
            return str_replace(array('.', ','), array('', '.'), $text);
        }

        if (preg_match('/^[+-]?\d{1,3}(,\d{3})+(\.\d+)?$/', $text)) {
            return str_replace(',', '', $text);
        }

        return null;
    }

    private static function length($text)
    {
        return function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : (int) preg_match_all('/./us', $text);
    }

    private static function substr($text, $start, $length = null)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, $start, $length, 'UTF-8');
        }

        preg_match_all('/./us', $text, $chars);

        return implode('', array_slice($chars[0], $start, $length));
    }

    private static function lastSpace($text)
    {
        $position = function_exists('mb_strrpos') ? mb_strrpos($text, ' ', 0, 'UTF-8') : strrpos($text, ' ');

        return $position === false ? 0 : $position;
    }

    private static function upper($text)
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($text, 'UTF-8') : strtoupper($text);
    }

    private static function lower($text)
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}
