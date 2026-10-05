<?php

defined('_JEXEC') or die;

/*
 * Datas e fuso horário.
 *
 * A regra do Joomla: o banco guarda UTC e a tela mostra no fuso do
 * usuário (campo "Fuso horário" do perfil ou, se vazio, o da
 * configuração global). Este helper concentra as conversões:
 *
 *     DateHelper::nowSql();                              // gravar: '2026-10-05 02:30:00' (UTC)
 *     DateHelper::toUser($pedido->created_at);           // mostrar: '04/10/2026 23:30'
 *     DateHelper::fromUser('25/12/2026 14:30');          // o que o usuário digitou -> UTC
 *     DateHelper::range('month');                        // ['start' => ..., 'end' => ...] em UTC
 *
 * Entradas aceitas por todos os métodos que recebem uma data:
 * DateTimeInterface, timestamp (int ou texto com 9+ dígitos),
 * 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d', 'Y-m-d\TH:i[:s]' (input
 * datetime-local), ISO 8601 com fuso ('2026-10-05T02:30:00Z',
 * '...-03:00'), 'd/m/Y', 'd/m/Y H:i[:s]' e, onde indicado, textos
 * relativos como 'now', 'today', '+30 minutes', '-7 days'.
 * Datas impossíveis (31/02) são recusadas, nunca "ajustadas".
 *
 * Fusos: 'UTC', 'user' (fuso do usuário logado), um nome como
 * 'America/Sao_Paulo' ou um DateTimeZone.
 *
 * Funciona no Joomla 3.4.5 ou superior, 4 e 5, em PHP 7.0 ou superior.
 */
class DateHelper
{
    const SQL = 'Y-m-d H:i:s';

    const SQL_DATE = 'Y-m-d';

    const BR = 'd/m/Y';

    const BR_DATETIME = 'd/m/Y H:i';

    const BR_FULL = 'd/m/Y H:i:s';

    const ISO = 'Y-m-d\TH:i:sP';

    /*
     * Formatos fixos aceitos na leitura, do mais para o menos específico.
     */
    private static $formatos = [
        'Y-m-d H:i:s',
        'Y-m-d H:i',
        'Y-m-d\TH:i:s',
        'Y-m-d\TH:i',
        'Y-m-d',
        'd/m/Y H:i:s',
        'd/m/Y H:i',
        'd/m/Y',
    ];

    private static $formatosComFuso = [
        'Y-m-d\TH:i:sP',
        'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:iP',
    ];

    /*
     * Datas "vazias" do Joomla 3 (nullDate do MySQL) contam como sem data.
     */
    private static $datasVazias = [
        '',
        '0000-00-00',
        '0000-00-00 00:00:00',
    ];

    private static $meses = [
        1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
        'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
    ];

    private static $diasDaSemana = [
        'domingo', 'segunda-feira', 'terça-feira', 'quarta-feira',
        'quinta-feira', 'sexta-feira', 'sábado',
    ];

    private static $utc = null;

    private static $fusoDoUsuario = null;

    private static $agoraDeTeste = null;

    // ---- Agora ----

    /*
     * Momento atual em UTC (DateTimeImmutable). Respeita setTestNow().
     */
    public static function now()
    {
        if (self::$agoraDeTeste !== null) {
            return self::$agoraDeTeste;
        }

        return new DateTimeImmutable('now', self::utc());
    }

    /*
     * Momento atual em UTC no formato do banco. Use para gravar.
     */
    public static function nowSql()
    {
        return self::now()->format(self::SQL);
    }

    /*
     * Data de hoje ('Y-m-d') no fuso informado; por padrão, no do usuário.
     */
    public static function today($timezone = 'user')
    {
        return self::now()
            ->setTimezone(self::timezone($timezone))
            ->format(self::SQL_DATE);
    }

    /*
     * Congela o relógio do helper (testes, simulações). null volta ao
     * relógio real.
     */
    public static function setTestNow($value = null)
    {
        self::$agoraDeTeste = null;

        if ($value !== null) {
            self::$agoraDeTeste = self::parse($value);
        }
    }

    // ---- Leitura e conversão ----

    /*
     * Interpreta $value como uma data no fuso $timezone e devolve o
     * mesmo instante em UTC (DateTimeImmutable). Datas com fuso explícito
     * (ISO 8601, DateTimeInterface, timestamp) ignoram $timezone.
     *
     * Lança InvalidArgumentException se a data for vazia ou inválida.
     */
    public static function parse($value, $timezone = 'UTC', $allowRelative = true)
    {
        if (self::isEmpty($value)) {
            throw new InvalidArgumentException('Data vazia.');
        }

        if ($value instanceof DateTimeInterface) {
            return self::doTimestamp($value->getTimestamp());
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value) && strlen($value) >= 9)) {
            return self::doTimestamp((int) $value);
        }

        if (!is_string($value)) {
            throw new InvalidArgumentException('Data inválida: tipo ' . gettype($value) . ' não suportado.');
        }

        $texto = trim($value);
        $fuso = self::timezone($timezone);

        $data = self::lerFormatoComFuso($texto);

        if ($data === null) {
            $data = self::lerFormatoFixo($texto, $fuso);
        }

        if ($data === null && $allowRelative) {
            $data = self::lerRelativo($texto, $fuso);
        }

        if ($data === null) {
            throw new InvalidArgumentException(
                'Data inválida: "' . $texto . '". Use Y-m-d H:i:s, Y-m-d, d/m/Y ou d/m/Y H:i.'
            );
        }

        return $data->setTimezone(self::utc());
    }

    /*
     * Converte para o formato do banco, em UTC. $timezone é o fuso em que
     * $value foi escrito. Vazio (null, '', 0000-00-00) devolve null.
     *
     *     DateHelper::toSql();                         // agora
     *     DateHelper::toSql('+30 minutes');            // daqui a 30 minutos
     *     DateHelper::toSql('2026-12-25 08:00', 'user');
     */
    public static function toSql($value = 'now', $timezone = 'UTC')
    {
        if (self::isEmpty($value)) {
            return null;
        }

        return self::parse($value, $timezone)->format(self::SQL);
    }

    /*
     * Converte o que o usuário digitou (no fuso dele) para UTC, pronto
     * para gravar. Com $format, exige exatamente esse formato; sem ele,
     * aceita os formatos fixos (não aceita textos relativos).
     * Vazio devolve null; inválido lança InvalidArgumentException.
     */
    public static function fromUser($value, $format = null)
    {
        if (self::isEmpty($value)) {
            return null;
        }

        if ($format === null) {
            return self::parse($value, 'user', false)->format(self::SQL);
        }

        $data = self::lerComFormato(trim((string) $value), $format, self::userTimezone());

        if ($data === null) {
            throw new InvalidArgumentException(
                'Data inválida: "' . $value . '". Formato esperado: ' . $format . '.'
            );
        }

        return $data->setTimezone(self::utc())->format(self::SQL);
    }

    /*
     * Formata uma data gravada em UTC no fuso do usuário. Vazio devolve ''.
     *
     *     DateHelper::toUser($pedido->created_at);               // '04/10/2026 23:30'
     *     DateHelper::toUser($pedido->created_at, DateHelper::BR);
     */
    public static function toUser($value, $format = self::BR_DATETIME)
    {
        return self::format($value, $format, 'user');
    }

    /*
     * Formata uma data gravada em UTC no fuso $timezone. Vazio devolve ''.
     */
    public static function format($value, $format = self::BR_DATETIME, $timezone = 'user')
    {
        if (self::isEmpty($value)) {
            return '';
        }

        return self::parse($value)
            ->setTimezone(self::timezone($timezone))
            ->format($format);
    }

    /*
     * Data por extenso em português, no fuso informado:
     * '5 de outubro de 2026', com $withTime '5 de outubro de 2026 às 14:30'
     * e com $withWeekday 'segunda-feira, 5 de outubro de 2026'.
     */
    public static function longDate($value, $withTime = false, $withWeekday = false, $timezone = 'user')
    {
        if (self::isEmpty($value)) {
            return '';
        }

        $data = self::parse($value)->setTimezone(self::timezone($timezone));

        $texto = (int) $data->format('j') . ' de ' .
            self::$meses[(int) $data->format('n')] . ' de ' .
            $data->format('Y');

        if ($withWeekday) {
            $texto = self::$diasDaSemana[(int) $data->format('w')] . ', ' . $texto;
        }

        if ($withTime) {
            $texto .= ' às ' . $data->format('H:i');
        }

        return $texto;
    }

    /*
     * Timestamp Unix do instante.
     */
    public static function timestamp($value = 'now')
    {
        return self::parse($value)->getTimestamp();
    }

    /*
     * Indica se $value é uma data válida (nos formatos aceitos ou, com
     * $format, exatamente nesse formato). Vazio é inválido.
     */
    public static function isValid($value, $format = null)
    {
        if (self::isEmpty($value)) {
            return false;
        }

        if ($format !== null) {
            return self::lerComFormato(trim((string) $value), $format, self::utc()) !== null;
        }

        try {
            self::parse($value, 'UTC', false);

            return true;
        } catch (InvalidArgumentException $e) {
            return false;
        }
    }

    /*
     * null, '', '0000-00-00' e '0000-00-00 00:00:00' contam como sem data.
     */
    public static function isEmpty($value)
    {
        if ($value === null || $value === false) {
            return true;
        }

        if (is_string($value)) {
            return in_array(trim($value), self::$datasVazias, true);
        }

        return false;
    }

    // ---- Comparações ----

    public static function isPast($value)
    {
        return self::timestamp($value) < self::now()->getTimestamp();
    }

    public static function isFuture($value)
    {
        return self::timestamp($value) > self::now()->getTimestamp();
    }

    /*
     * Segundos de $from até $to (positivo se $to for depois).
     */
    public static function diffInSeconds($from, $to = 'now')
    {
        return self::timestamp($to) - self::timestamp($from);
    }

    /*
     * Dias de calendário de $from até $to, contados no fuso informado
     * (positivo se $to for depois). Ignora as horas: de 23:00 de um dia
     * a 01:00 do dia seguinte é 1 dia.
     */
    public static function diffInDays($from, $to = 'now', $timezone = 'user')
    {
        $fuso = self::timezone($timezone);

        $inicio = new DateTimeImmutable(
            self::parse($from)->setTimezone($fuso)->format(self::SQL_DATE),
            self::utc()
        );
        $fim = new DateTimeImmutable(
            self::parse($to)->setTimezone($fuso)->format(self::SQL_DATE),
            self::utc()
        );

        $dias = (int) $inicio->diff($fim)->days;

        return $fim < $inicio ? -$dias : $dias;
    }

    /*
     * Texto relativo em português: 'agora', 'há 5 minutos', 'em 3 dias',
     * 'há 2 meses'.
     */
    public static function relative($value, $reference = 'now')
    {
        $segundos = self::diffInSeconds($reference, $value);
        $distancia = abs($segundos);

        if ($distancia < 45) {
            return 'agora';
        }

        $unidades = [
            [31536000, 'ano', 'anos'],
            [2592000, 'mês', 'meses'],
            [604800, 'semana', 'semanas'],
            [86400, 'dia', 'dias'],
            [3600, 'hora', 'horas'],
            [60, 'minuto', 'minutos'],
        ];

        foreach ($unidades as $unidade) {
            if ($distancia >= $unidade[0]) {
                $quantidade = (int) round($distancia / $unidade[0]);
                break;
            }
        }

        if (!isset($quantidade)) {
            $unidade = [60, 'minuto', 'minutos'];
            $quantidade = 1;
        }

        $texto = $quantidade . ' ' . ($quantidade === 1 ? $unidade[1] : $unidade[2]);

        return $segundos < 0 ? 'há ' . $texto : 'em ' . $texto;
    }

    // ---- Períodos para filtros e relatórios ----

    /*
     * Início e fim (inclusivos, em UTC) do dia, semana (segunda a
     * domingo), mês ou ano que contém $reference, no fuso informado.
     * $reference: só data ('2026-02-10', '10/02/2026') é o dia do
     * calendário; data com hora é um instante em UTC (como vem do
     * banco); relativa ('today', '+30 days') é calculada no fuso.
     * Pronto para whereBetween():
     *
     *     $mes = DateHelper::range('month');
     *     PedidoModel::query()->whereBetween('created_at', $mes['start'], $mes['end'])->sum('total');
     */
    public static function range($period = 'day', $reference = 'now', $timezone = 'user')
    {
        $fuso = self::timezone($timezone);
        $data = self::referencia($reference, $fuso);

        switch ($period) {
            case 'day':
                $inicio = $data->setTime(0, 0, 0);
                $fim = $data->setTime(23, 59, 59);
                break;

            case 'week':
                $inicio = $data->modify('monday this week')->setTime(0, 0, 0);
                $fim = $data->modify('sunday this week')->setTime(23, 59, 59);
                break;

            case 'month':
                $inicio = $data->modify('first day of this month')->setTime(0, 0, 0);
                $fim = $data->modify('last day of this month')->setTime(23, 59, 59);
                break;

            case 'year':
                $inicio = $data->setDate((int) $data->format('Y'), 1, 1)->setTime(0, 0, 0);
                $fim = $data->setDate((int) $data->format('Y'), 12, 31)->setTime(23, 59, 59);
                break;

            default:
                throw new InvalidArgumentException(
                    'Período inválido: "' . $period . '". Use day, week, month ou year.'
                );
        }

        return self::intervalo($inicio, $fim);
    }

    /*
     * Últimos $days dias contando hoje (lastDays(7) = hoje e os 6 dias
     * anteriores), em UTC e inclusivos.
     */
    public static function lastDays($days, $reference = 'now', $timezone = 'user')
    {
        $days = max(1, (int) $days);
        $fuso = self::timezone($timezone);
        $data = self::referencia($reference, $fuso);

        return self::intervalo(
            $data->modify('-' . ($days - 1) . ' days')->setTime(0, 0, 0),
            $data->setTime(23, 59, 59)
        );
    }

    /*
     * Intervalo entre duas datas digitadas pelo usuário (ex.: filtro
     * "de/até" de um relatório): início às 00:00:00 de $from e fim às
     * 23:59:59 de $to, no fuso informado, devolvidos em UTC.
     */
    public static function between($from, $to, $timezone = 'user')
    {
        $fuso = self::timezone($timezone);

        $inicio = self::parse($from, $fuso, false)->setTimezone($fuso)->setTime(0, 0, 0);
        $fim = self::parse($to, $fuso, false)->setTimezone($fuso)->setTime(23, 59, 59);

        if ($fim < $inicio) {
            throw new InvalidArgumentException('A data final é anterior à data inicial.');
        }

        return self::intervalo($inicio, $fim);
    }

    // ---- Fuso horário ----

    /*
     * Fuso do usuário logado: o do perfil, senão o da configuração
     * global, senão UTC. setUserTimezone() sobrescreve (CLI, jobs).
     */
    public static function userTimezone()
    {
        if (self::$fusoDoUsuario !== null) {
            return self::$fusoDoUsuario;
        }

        try {
            $usuario = self::usuarioAtual();
        } catch (Throwable $e) {
            $usuario = null;
        }

        return self::fusoDoPerfil($usuario);
    }

    /*
     * Fuso de um usuário específico (ex.: e-mail enviado pelo worker).
     */
    public static function timezoneForUser($userId)
    {
        try {
            $usuario = call_user_func([self::factoryClass(), 'getUser'], (int) $userId);
        } catch (Throwable $e) {
            $usuario = null;
        }

        return self::fusoDoPerfil($usuario);
    }

    /*
     * Fixa o fuso usado como 'user' (string, DateTimeZone ou null para
     * voltar ao automático). Útil no worker CLI, onde não há usuário.
     */
    public static function setUserTimezone($timezone = null)
    {
        self::$fusoDoUsuario = null;

        if ($timezone !== null) {
            self::$fusoDoUsuario = self::timezone($timezone);
        }
    }

    /*
     * Converte 'UTC', 'user', um nome de fuso ou um DateTimeZone em
     * DateTimeZone.
     */
    public static function timezone($timezone = 'user')
    {
        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }

        $nome = trim((string) $timezone);

        if ($nome === '' || strtoupper($nome) === 'UTC') {
            return self::utc();
        }

        if ($nome === 'user') {
            return self::userTimezone();
        }

        $fuso = self::criarFuso($nome, null);

        if ($fuso === null) {
            throw new InvalidArgumentException('Fuso horário inválido: "' . $nome . '".');
        }

        return $fuso;
    }

    // ---- Internos ----

    private static function utc()
    {
        if (self::$utc === null) {
            self::$utc = new DateTimeZone('UTC');
        }

        return self::$utc;
    }

    private static function doTimestamp($timestamp)
    {
        $data = new DateTimeImmutable('@' . (int) $timestamp);

        return $data->setTimezone(self::utc());
    }

    private static function lerFormatoComFuso($texto)
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T.+(Z|[+-]\d{2}:?\d{2})$/i', $texto)) {
            return null;
        }

        $texto = preg_replace('/Z$/i', '+00:00', $texto);

        foreach (self::$formatosComFuso as $formato) {
            $data = self::lerComFormato($texto, $formato, self::utc());

            if ($data !== null) {
                return $data;
            }
        }

        return null;
    }

    private static function lerFormatoFixo($texto, DateTimeZone $fuso)
    {
        foreach (self::$formatos as $formato) {
            $data = self::lerComFormato($texto, $formato, $fuso);

            if ($data !== null) {
                return $data;
            }
        }

        return null;
    }

    /*
     * createFromFormat estrito: recusa avisos (31/02 viraria 03/03) e
     * campos sobrando. O '!' zera o que o formato não informa (hora em
     * datas sem hora).
     */
    private static function lerComFormato($texto, $formato, DateTimeZone $fuso)
    {
        $data = DateTimeImmutable::createFromFormat('!' . $formato, $texto, $fuso);
        $erros = DateTimeImmutable::getLastErrors();

        if ($data === false) {
            return null;
        }

        if (is_array($erros) && ($erros['warning_count'] > 0 || $erros['error_count'] > 0)) {
            return null;
        }

        return $data;
    }

    /*
     * Textos relativos ('now', 'today', '+30 minutes', '-7 days',
     * 'first day of next month'), calculados a partir de now() no fuso
     * informado: 'today' com fuso do usuário é a meia-noite dele.
     */
    private static function lerRelativo($texto, DateTimeZone $fuso)
    {
        if (!preg_match('/^[a-z0-9 +\-:]+$/i', $texto) || !preg_match('/[a-z]/i', $texto)) {
            return null;
        }

        try {
            $data = @self::now()->setTimezone($fuso)->modify($texto);
        } catch (Throwable $e) {
            return null;
        }

        return $data instanceof DateTimeImmutable ? $data : null;
    }

    /*
     * Referência de range()/lastDays(), já no fuso do período: só data
     * é dia do calendário nesse fuso; data com hora é instante UTC;
     * relativa é calculada nesse fuso.
     */
    private static function referencia($reference, DateTimeZone $fuso)
    {
        if (is_string($reference) && preg_match('#^(\d{4}-\d{2}-\d{2}|\d{2}/\d{2}/\d{4})$#', trim($reference))) {
            return self::parse($reference, $fuso, false)->setTimezone($fuso);
        }

        try {
            $data = self::parse($reference, 'UTC', false);
        } catch (InvalidArgumentException $e) {
            $data = self::parse($reference, $fuso, true);
        }

        return $data->setTimezone($fuso);
    }

    private static function intervalo(DateTimeImmutable $inicio, DateTimeImmutable $fim)
    {
        return [
            'start' => $inicio->setTimezone(self::utc())->format(self::SQL),
            'end' => $fim->setTimezone(self::utc())->format(self::SQL),
        ];
    }

    private static function criarFuso($nome, $padrao)
    {
        $nome = trim((string) $nome);

        if ($nome === '') {
            return $padrao;
        }

        try {
            return new DateTimeZone($nome);
        } catch (Throwable $e) {
            return $padrao;
        }
    }

    /*
     * Fuso do perfil; se vazio ou inválido, o da configuração global;
     * se também faltar, UTC.
     */
    private static function fusoDoPerfil($usuario)
    {
        $fuso = null;

        if (is_object($usuario) && method_exists($usuario, 'getParam')) {
            $fuso = self::criarFuso($usuario->getParam('timezone', ''), null);
        }

        if ($fuso === null) {
            $fuso = self::criarFuso(self::fusoDaConfiguracao(), null);
        }

        return $fuso !== null ? $fuso : self::utc();
    }

    private static function factoryClass()
    {
        return class_exists('Joomla\\CMS\\Factory') ? 'Joomla\\CMS\\Factory' : 'JFactory';
    }

    /*
     * Joomla 4/5: getIdentity() da aplicação. Joomla 3: getUser().
     */
    private static function usuarioAtual()
    {
        $fabrica = self::factoryClass();

        if (!class_exists($fabrica)) {
            return null;
        }

        $aplicacao = call_user_func([$fabrica, 'getApplication']);

        if (is_object($aplicacao) && method_exists($aplicacao, 'getIdentity')) {
            $usuario = $aplicacao->getIdentity();

            if ($usuario !== null) {
                return $usuario;
            }
        }

        return call_user_func([$fabrica, 'getUser']);
    }

    private static function fusoDaConfiguracao()
    {
        $fabrica = self::factoryClass();

        if (!class_exists($fabrica)) {
            return '';
        }

        try {
            $aplicacao = call_user_func([$fabrica, 'getApplication']);

            if (is_object($aplicacao) && method_exists($aplicacao, 'get')) {
                $fuso = (string) $aplicacao->get('offset', '');

                if ($fuso !== '') {
                    return $fuso;
                }
            }
        } catch (Throwable $e) {
        }

        try {
            return (string) call_user_func([$fabrica, 'getConfig'])->get('offset', '');
        } catch (Throwable $e) {
            return '';
        }
    }
}
