<?php

// Bloqueia só o acesso direto pela URL; carrega no Joomla, Laravel ou PHP puro.
if (!defined('_JEXEC') && isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    die('Acesso restrito.');
}

if (!class_exists('OrmTables')) {
    require_once __DIR__ . '/OrmTables.php';
}

/*
 * Usuário, grupos e perfil num lugar só, sobre as tabelas do Joomla:
 * #__users, #__usergroups e #__user_usergroup_map.
 *
 * ---- Grupos -------------------------------------------------------------
 *
 *   UserHelper::inGroup(15);                       // pelo id
 *   UserHelper::inGroup('Gerentes');               // pelo nome (title)
 *   UserHelper::inGroup('gerentes');               // pelo apelido (setGroupAliases)
 *   UserHelper::inAnyGroup(['Gerentes', 'TI']);
 *   UserHelper::inAllGroups([15, 25]);
 *   UserHelper::inGroupDirect('Gerentes');         // sem herança
 *
 *   UserHelper::groups();        // [15 => 'Gerentes', 25 => 'TI', ...]
 *   UserHelper::groupNames();    // ['Gerentes', 'TI', ...]
 *   UserHelper::groupTitle(15);  // 'Gerentes'
 *   UserHelper::groupPath(15);   // 'Public > Registered > Gerentes'
 *
 *   UserHelper::whenInGroup('TI', function () { ... }, function () { ... });
 *   UserHelper::requireGroup('Gerentes');          // nega com 403 padrão
 *
 *   UserHelper::usersInGroup('Gerentes');          // membros (sem dados sensíveis)
 *   UserHelper::emailsInGroup('Gerentes');         // para o EmailSendHelper
 *
 * Herança: como no ACL do Joomla, quem está num grupo filho também é
 * considerado membro dos grupos pai. inGroup() segue essa regra;
 * inGroupDirect() olha só os grupos em que o usuário foi colocado.
 *
 * Nome inexistente nunca libera acesso: o grupo não é resolvido, a
 * checagem dá false e um aviso vai para o log.
 *
 * ---- Apelidos (evitam id fixo espalhado pelo código) ------------------
 *
 *   UserHelper::setGroupAliases([
 *       'gerentes' => 15,
 *       'ti'       => [25, 26],
 *       'diretoria'=> 'Diretoria Executiva',
 *   ]);
 *
 * ---- Perfil -------------------------------------------------------------
 *
 *   UserHelper::toApi();          // perfil seguro para devolver em JSON
 *   UserHelper::firstName();      // 'Maria'
 *   UserHelper::cpfMasked();      // '***.456.789-**'
 *   UserHelper::cooperativa();    // ['central' => '300', 'singular' => '300', 'pa' => '0', 'uf' => 'DF']
 *
 * Nada aqui devolve password, activation, otpKey, otep ou password_clear.
 */
class UserHelper
{
    const TABLE_USERS = '#__users';

    const TABLE_GROUPS = '#__usergroups';

    const TABLE_MAP = '#__user_usergroup_map';

    /*
     * Campos de #__users que podem sair do helper. Lista de permitidos,
     * nunca de proibidos: coluna nova na tabela não vaza sozinha.
     */
    const USER_FIELDS = ['id', 'name', 'username', 'email', 'block', 'sendEmail', 'registerDate', 'lastvisitDate', 'requireReset'];

    const SENSITIVE_FIELDS = ['password', 'password_clear', 'activation', 'otpKey', 'otep', 'lastResetTime', 'resetCount', 'params'];

    const NULL_DATE = '0000-00-00 00:00:00';

    // Tipos de usuário pela posição na cooperativa (userType()).
    const TYPE_CCS = 'CCS';

    const TYPE_CENTRAL = 'CENTRAL';

    const TYPE_SINGULAR = 'SINGULAR';

    const TYPE_PA = 'PA';

    const TYPE_OUTRO = 'OUTRO';

    // Códigos de "singular" que identificam o CCS.
    private static $ccsCodes = [6004, 300, 1, 9805, 310, 382, 9986, 309];

    private static $groupAliases = [];

    // null = conexão do Joomla; ou nome registrado no DbConnectionHelper.
    private static $connection = null;

    private static $dadosPessoaisResolver = null;

    private static $cacheSeconds = 0;

    private static $logCategory = 'user_helper';

    // Cache da requisição.
    private static $catalog = null;

    private static $memo = [];

    /* =================================================================
     * Configuração
     * ================================================================= */

    /*
     * Apelido => id, nome ou lista de ids/nomes.
     */
    public static function setGroupAliases(array $aliases, $merge = true)
    {
        $normalized = [];

        foreach ($aliases as $alias => $groups) {
            $normalized[self::normalizeText($alias)] = $groups;
        }

        self::$groupAliases = $merge ? array_merge(self::$groupAliases, $normalized) : $normalized;
    }

    public static function groupAliases()
    {
        return self::$groupAliases;
    }

    /*
     * Função que devolve os dados pessoais de um usuário (array ou objeto)
     * a partir do id. Sem ela, só o usuário logado tem dados pessoais
     * (via JFactory::getDadosPessoais()).
     *
     *   UserHelper::setDadosPessoaisResolver(function ($userId) {
     *       return OrmTables::table('#__dados_pessoais')->findBy('user_id', $userId);
     *   });
     */
    public static function setDadosPessoaisResolver(?callable $resolver = null)
    {
        self::$dadosPessoaisResolver = $resolver;
        self::$memo = [];
    }

    /*
     * Guarda o catálogo de grupos no CacheHelper por N segundos (0 = só
     * na requisição). Depois de criar/renomear grupo, chame clearCache().
     */
    public static function setCacheSeconds($seconds)
    {
        self::$cacheSeconds = max(0, (int) $seconds);
    }

    /*
     * Conexão onde estão #__users/#__usergroups (null = a do Joomla).
     *   DbConnectionHelper::register('portal', $config);
     *   UserHelper::setConnection('portal');
     */
    public static function setConnection($connection)
    {
        self::$connection = $connection;
        self::clearCache();
    }

    public static function setLogCategory($category)
    {
        self::$logCategory = (string) $category;
    }

    public static function clearCache()
    {
        self::$catalog = null;
        self::$memo = [];

        if (self::hasHelper('CacheHelper')) {
            try {
                CacheHelper::forget('user_helper:groups');
            } catch (Throwable $error) {
            }
        }
    }

    /* =================================================================
     * Usuário atual e busca de usuários
     * ================================================================= */

    /*
     * Objeto JUser do usuário logado (ou o informado, se já for JUser).
     * Fora do Joomla, devolve a linha segura de #__users ou null.
     */
    public static function current($user = null)
    {
        if (is_object($user) && !($user instanceof stdClass)) {
            return $user;
        }

        if ($user !== null) {
            return self::find($user);
        }

        if (class_exists('JFactory')) {
            try {
                return JFactory::getUser();
            } catch (Throwable $error) {
            }
        }

        if (class_exists('PlatformHelper')) {
            $id = PlatformHelper::userId();

            return $id ? self::find($id) : null;
        }

        return null;
    }

    /*
     * Id do usuário: aceita null (logado), id, username, e-mail, JUser,
     * objeto ou array com 'id'.
     */
    public static function id($user = null)
    {
        if (is_int($user) || (is_string($user) && ctype_digit($user))) {
            return (int) $user;
        }

        if (is_array($user)) {
            return isset($user['id']) ? (int) $user['id'] : 0;
        }

        if (is_object($user)) {
            return isset($user->id) ? (int) $user->id : 0;
        }

        if (is_string($user) && trim($user) !== '') {
            $row = self::find($user);

            return $row ? (int) $row->id : 0;
        }

        $current = self::current();

        return $current && isset($current->id) ? (int) $current->id : 0;
    }

    public static function isLoggedIn($user = null)
    {
        return self::id($user) > 0;
    }

    public static function isGuest($user = null)
    {
        return !self::isLoggedIn($user);
    }

    public static function isCurrent($user)
    {
        $id = self::id($user);

        return $id > 0 && $id === self::id();
    }

    /*
     * Linha segura de #__users por id, username ou e-mail. null se não existir.
     */
    public static function find($login)
    {
        if ($login === null || $login === '' || $login === 0) {
            return null;
        }

        if (is_object($login) || is_array($login)) {
            $login = self::id($login);
        }

        $key = 'find:' . strtolower((string) $login);

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $query = self::usersTable()->select(self::USER_FIELDS);

        if (is_int($login) || ctype_digit((string) $login)) {
            $query->where('id', (int) $login);
        } elseif (strpos((string) $login, '@') !== false) {
            $query->where('email', trim((string) $login));
        } else {
            $query->where('username', trim((string) $login));
        }

        $row = self::safeRow($query->first());

        self::$memo[$key] = $row;

        if ($row) {
            self::$memo['find:' . (int) $row->id] = $row;
        }

        return $row;
    }

    public static function findOrFail($login)
    {
        $row = self::find($login);

        if ($row === null) {
            throw new RuntimeException('Usuário não encontrado.', 404);
        }

        return $row;
    }

    public static function findMany(array $ids)
    {
        $ids = self::intList($ids);

        if (!$ids) {
            return [];
        }

        $rows = self::usersTable()->select(self::USER_FIELDS)->whereIn('id', $ids)->orderBy('name')->get();

        return array_map([__CLASS__, 'safeRow'], $rows);
    }

    public static function exists($login)
    {
        return self::find($login) !== null;
    }

    public static function emailExists($email, $exceptUserId = null)
    {
        $query = self::usersTable()->where('email', trim((string) $email));

        if ($exceptUserId) {
            $query->where('id', '!=', (int) $exceptUserId);
        }

        return $query->exists();
    }

    public static function usernameExists($username, $exceptUserId = null)
    {
        $query = self::usersTable()->where('username', trim((string) $username));

        if ($exceptUserId) {
            $query->where('id', '!=', (int) $exceptUserId);
        }

        return $query->exists();
    }

    /*
     * Busca por nome, username ou e-mail (autocomplete, select de usuários).
     */
    public static function search($term, $limit = 20, $onlyActive = true)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return [];
        }

        $query = self::usersTable()
            ->select(self::USER_FIELDS)
            ->whereGroup(function ($q) use ($term) {
                $q->whereLike('name', $term)
                    ->orWhereLike('username', $term)
                    ->orWhereLike('email', $term);
            })
            ->orderBy('name')
            ->limit(max(1, (int) $limit));

        if ($onlyActive) {
            $query->where('block', 0);
        }

        return array_map([__CLASS__, 'safeRow'], $query->get());
    }

    /*
     * Lista de usuários com filtros. Com 'page' devolve o array do
     * OrmBase::paginate(); sem, devolve a lista.
     *
     *   UserHelper::users([
     *       'group'       => 'Gerentes',   // id, nome, apelido ou lista
     *       'descendants' => true,         // inclui subgrupos
     *       'term'        => 'maria',
     *       'blocked'     => false,        // true, false ou null (todos)
     *       'order'       => 'name',
     *       'page'        => 1, 'per_page' => 20,
     *   ]);
     */
    public static function users(array $filters = [])
    {
        $query = self::usersTable()->select(self::USER_FIELDS);

        if (isset($filters['group']) && $filters['group'] !== '' && $filters['group'] !== null) {
            $groupIds = self::resolveGroupIds($filters['group']);

            if (!empty($filters['descendants'])) {
                $groupIds = self::expandDescendants($groupIds);
            }

            $userIds = self::userIdsForGroupIds($groupIds);
            $query->whereIn('id', $userIds);
        }

        if (!empty($filters['term'])) {
            $term = trim((string) $filters['term']);
            $query->whereGroup(function ($q) use ($term) {
                $q->whereLike('name', $term)->orWhereLike('username', $term)->orWhereLike('email', $term);
            });
        }

        if (array_key_exists('blocked', $filters) && $filters['blocked'] !== null) {
            $query->where('block', $filters['blocked'] ? 1 : 0);
        }

        if (!empty($filters['ids'])) {
            $query->whereIn('id', self::intList((array) $filters['ids']));
        }

        $order = isset($filters['order']) && in_array($filters['order'], self::USER_FIELDS, true) ? $filters['order'] : 'name';
        $direction = isset($filters['direction']) && strtoupper($filters['direction']) === 'DESC' ? 'DESC' : 'ASC';
        $query->orderBy($order, $direction);

        if (!empty($filters['page'])) {
            $result = $query->paginate(isset($filters['per_page']) ? (int) $filters['per_page'] : 20, (int) $filters['page']);
            $result['itens'] = array_map([__CLASS__, 'safeRow'], $result['itens']);

            return $result;
        }

        if (!empty($filters['limit'])) {
            $query->limit((int) $filters['limit']);
        }

        return array_map([__CLASS__, 'safeRow'], $query->get());
    }

    /*
     * [id => nome] para montar <select>.
     */
    public static function userOptions(array $filters = [])
    {
        $filters['blocked'] = array_key_exists('blocked', $filters) ? $filters['blocked'] : false;
        unset($filters['page']);

        $options = [];

        foreach (self::users($filters) as $user) {
            $options[(int) $user->id] = $user->name;
        }

        return $options;
    }

    /* =================================================================
     * Status da conta
     * ================================================================= */

    public static function isBlocked($user = null)
    {
        $row = self::row($user);

        return $row ? (int) $row->block === 1 : true;
    }

    public static function isActive($user = null)
    {
        return !self::isBlocked($user);
    }

    public static function mustResetPassword($user = null)
    {
        $row = self::row($user);

        return $row ? (int) $row->requireReset === 1 : false;
    }

    public static function receivesSystemEmails($user = null)
    {
        $row = self::row($user);

        return $row ? (int) $row->sendEmail === 1 : false;
    }

    public static function registeredAt($user = null)
    {
        $row = self::row($user);

        return $row ? self::dateOrNull($row->registerDate) : null;
    }

    public static function lastVisitAt($user = null)
    {
        $row = self::row($user);

        return $row ? self::dateOrNull($row->lastvisitDate) : null;
    }

    // 'há 3 dias', ou 'nunca acessou'.
    public static function lastVisitRelative($user = null)
    {
        $date = self::lastVisitAt($user);

        if ($date === null) {
            return 'nunca acessou';
        }

        return class_exists('DateHelper') ? DateHelper::relative($date) : $date;
    }

    public static function daysSinceLastVisit($user = null)
    {
        $date = self::lastVisitAt($user);

        if ($date === null) {
            return null;
        }

        return (int) floor((time() - strtotime($date . ' UTC')) / 86400);
    }

    public static function isInactiveFor($days, $user = null)
    {
        $since = self::daysSinceLastVisit($user);

        return $since === null || $since >= (int) $days;
    }

    /* =================================================================
     * Catálogo de grupos (#__usergroups)
     * ================================================================= */

    /*
     * Todos os grupos: [id => {id, parent_id, lft, rgt, title, level, path}],
     * em ordem de árvore.
     */
    public static function allGroups()
    {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $loader = function () {
            $rows = OrmTables::table(self::TABLE_GROUPS, self::ormOptions())
                ->select(['id', 'parent_id', 'lft', 'rgt', 'title'])
                ->orderBy('lft')
                ->get();

            return self::buildCatalog($rows);
        };

        if (self::$cacheSeconds > 0 && self::hasHelper('CacheHelper')) {
            self::$catalog = CacheHelper::remember('user_helper:groups', self::$cacheSeconds, $loader);
        } else {
            self::$catalog = $loader();
        }

        return self::$catalog;
    }

    /*
     * Um grupo por id, nome ou apelido. null se não existir; se o nome
     * se repetir em pais diferentes, devolve o primeiro da árvore.
     */
    public static function group($group)
    {
        $ids = self::resolveGroupIds($group);

        if (!$ids) {
            return null;
        }

        $catalog = self::allGroups();

        return isset($catalog[$ids[0]]) ? $catalog[$ids[0]] : null;
    }

    public static function groupExists($group)
    {
        return self::resolveGroupIds($group, false) !== [];
    }

    // Id de um grupo pelo nome/apelido (0 se não existir).
    public static function groupId($group)
    {
        $ids = self::resolveGroupIds($group);

        return $ids ? $ids[0] : 0;
    }

    /*
     * Ids a partir de id, nome, apelido ou lista misturando os três.
     * Ids inexistentes e nomes desconhecidos são descartados.
     */
    public static function groupIds($groups)
    {
        return self::resolveGroupIds($groups);
    }

    public static function groupTitle($groupId, $default = '')
    {
        $catalog = self::allGroups();
        $groupId = (int) $groupId;

        return isset($catalog[$groupId]) ? $catalog[$groupId]->title : $default;
    }

    // [id => nome] dos ids informados (ids inexistentes ficam de fora).
    public static function groupTitles(array $groupIds)
    {
        $catalog = self::allGroups();
        $titles = [];

        foreach (self::intList($groupIds) as $id) {
            if (isset($catalog[$id])) {
                $titles[$id] = $catalog[$id]->title;
            }
        }

        return $titles;
    }

    // 'Public > Registered > Gerentes'.
    public static function groupPath($group, $separator = ' > ')
    {
        $node = self::group($group);

        if (!$node) {
            return '';
        }

        return implode($separator, array_map(function ($ancestor) {
            return $ancestor->title;
        }, array_merge(self::groupAncestors($node->id), [$node])));
    }

    public static function groupParent($group)
    {
        $node = self::group($group);
        $catalog = self::allGroups();

        return $node && isset($catalog[(int) $node->parent_id]) ? $catalog[(int) $node->parent_id] : null;
    }

    // Grupos acima, do topo até o pai imediato.
    public static function groupAncestors($group)
    {
        $node = self::group($group);

        if (!$node) {
            return [];
        }

        $ancestors = [];

        foreach (self::allGroups() as $candidate) {
            if ($candidate->lft < $node->lft && $candidate->rgt > $node->rgt) {
                $ancestors[] = $candidate;
            }
        }

        return $ancestors;
    }

    // Filhos diretos.
    public static function groupChildren($group)
    {
        $node = self::group($group);

        if (!$node) {
            return [];
        }

        return array_values(array_filter(self::allGroups(), function ($candidate) use ($node) {
            return (int) $candidate->parent_id === (int) $node->id;
        }));
    }

    // Todos os grupos abaixo (filhos, netos...).
    public static function groupDescendants($group, $includeSelf = false)
    {
        $node = self::group($group);

        if (!$node) {
            return [];
        }

        return array_values(array_filter(self::allGroups(), function ($candidate) use ($node, $includeSelf) {
            if ($includeSelf && (int) $candidate->id === (int) $node->id) {
                return true;
            }

            return $candidate->lft > $node->lft && $candidate->rgt < $node->rgt;
        }));
    }

    public static function isGroupChildOf($group, $parent)
    {
        $node = self::group($group);
        $parentNode = self::group($parent);

        return $node && $parentNode && $node->lft > $parentNode->lft && $node->rgt < $parentNode->rgt;
    }

    /*
     * Árvore aninhada: [{id, title, level, children: [...]}, ...].
     */
    public static function groupTree($rootGroup = null)
    {
        $catalog = self::allGroups();
        $nodes = [];

        foreach ($catalog as $id => $group) {
            $nodes[$id] = (object) [
                'id' => $group->id,
                'parent_id' => $group->parent_id,
                'title' => $group->title,
                'level' => $group->level,
                'path' => $group->path,
                'children' => [],
            ];
        }

        $roots = [];

        foreach ($nodes as $id => $node) {
            if (isset($nodes[$node->parent_id])) {
                $nodes[$node->parent_id]->children[] = $node;
            } else {
                $roots[] = $node;
            }
        }

        if ($rootGroup !== null) {
            $rootId = self::groupId($rootGroup);

            return isset($nodes[$rootId]) ? [$nodes[$rootId]] : [];
        }

        return $roots;
    }

    /*
     * [id => '— — Gerentes'] para <select>, já em ordem de árvore.
     */
    public static function groupOptions($indent = '— ', $rootGroup = null)
    {
        $groups = $rootGroup !== null ? self::groupDescendants($rootGroup, true) : array_values(self::allGroups());
        $baseLevel = $groups ? $groups[0]->level : 0;
        $options = [];

        foreach ($groups as $group) {
            $options[$group->id] = str_repeat($indent, max(0, $group->level - $baseLevel)) . $group->title;
        }

        return $options;
    }

    /* =================================================================
     * Grupos do usuário
     * ================================================================= */

    /*
     * Ids dos grupos do usuário. $inherited = true inclui os grupos pai
     * (regra do ACL do Joomla).
     */
    public static function userGroupIds($user = null, $inherited = true)
    {
        $userId = self::id($user);

        if ($userId <= 0) {
            return [];
        }

        $key = 'groups:' . $userId . ':' . ($inherited ? 1 : 0);

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $direct = self::directGroupIds($userId, $user);
        $ids = $inherited ? self::expandAncestors($direct) : $direct;

        sort($ids);

        return self::$memo[$key] = $ids;
    }

    // [id => nome] dos grupos do usuário.
    public static function groups($user = null, $inherited = true)
    {
        return self::groupTitles(self::userGroupIds($user, $inherited));
    }

    // ['Gerentes', 'TI', ...]
    public static function groupNames($user = null, $inherited = true)
    {
        return array_values(self::groups($user, $inherited));
    }

    /*
     * Lista detalhada: [{id, title, path, level, direct}] — direct indica
     * se o usuário foi colocado no grupo ou só herda dele.
     */
    public static function groupsDetailed($user = null)
    {
        $catalog = self::allGroups();
        $direct = self::userGroupIds($user, false);
        $list = [];

        foreach (self::userGroupIds($user, true) as $id) {
            if (!isset($catalog[$id])) {
                continue;
            }

            $list[] = (object) [
                'id' => $id,
                'title' => $catalog[$id]->title,
                'path' => $catalog[$id]->path,
                'level' => $catalog[$id]->level,
                'direct' => in_array($id, $direct, true),
            ];
        }

        usort($list, function ($a, $b) use ($catalog) {
            return $catalog[$a->id]->lft <=> $catalog[$b->id]->lft;
        });

        return $list;
    }

    /*
     * O usuário pertence ao grupo? $group: id, nome, apelido ou lista
     * (lista = qualquer um deles). Com herança.
     */
    public static function inGroup($group, $user = null)
    {
        return self::matchesAny($group, self::userGroupIds($user, true));
    }

    // Só os grupos em que o usuário foi colocado, sem herança.
    public static function inGroupDirect($group, $user = null)
    {
        return self::matchesAny($group, self::userGroupIds($user, false));
    }

    public static function notInGroup($group, $user = null)
    {
        return !self::inGroup($group, $user);
    }

    public static function inAnyGroup(array $groups, $user = null)
    {
        return self::matchesAny($groups, self::userGroupIds($user, true));
    }

    /*
     * Precisa estar em todos. Cada item da lista é avaliado sozinho:
     * apelido com vários grupos conta como "qualquer um deles".
     */
    public static function inAllGroups(array $groups, $user = null)
    {
        if (!$groups) {
            return false;
        }

        $userGroups = self::userGroupIds($user, true);

        foreach ($groups as $group) {
            if (!self::matchesAny($group, $userGroups)) {
                return false;
            }
        }

        return true;
    }

    // Membro do grupo ou de qualquer subgrupo dele.
    public static function inGroupOrDescendants($group, $user = null)
    {
        $ids = self::expandDescendants(self::resolveGroupIds($group));

        return (bool) array_intersect($ids, self::userGroupIds($user, false));
    }

    // Quais dos grupos informados o usuário tem: ['Gerentes' => true, 'TI' => false].
    public static function groupMatrix(array $groups, $user = null)
    {
        $userGroups = self::userGroupIds($user, true);
        $matrix = [];

        foreach ($groups as $label => $group) {
            $key = is_int($label) ? (is_scalar($group) ? (string) $group : (string) $label) : $label;
            $matrix[$key] = self::matchesAny($group, $userGroups);
        }

        return $matrix;
    }

    /*
     * Executa $then se o usuário estiver no grupo; senão $else (opcional).
     * Devolve o retorno do callback executado (ou null).
     */
    public static function whenInGroup($group, callable $then, ?callable $else = null, $user = null)
    {
        if (self::inGroup($group, $user)) {
            return $then(self::current($user));
        }

        return $else ? $else(self::current($user)) : null;
    }

    /*
     * Valor por grupo, o primeiro que bater:
     *
     *   $limite = UserHelper::valueByGroup([
     *       'Diretoria' => 100000,
     *       'Gerentes'  => 20000,
     *   ], 1000);
     */
    public static function valueByGroup(array $map, $default = null, $user = null)
    {
        $userGroups = self::userGroupIds($user, true);

        foreach ($map as $group => $value) {
            if (self::matchesAny($group, $userGroups)) {
                return $value instanceof Closure ? $value() : $value;
            }
        }

        return $default instanceof Closure ? $default() : $default;
    }

    /*
     * Barra quem não está em nenhum dos grupos. Usa o fluxo do
     * PermissionHelper (log, auditoria, JSON ou redirect); sem ele,
     * lança RuntimeException 403.
     */
    public static function requireGroup($groups, $message = 'Você não possui permissão para esta operação.', $redirect = null, array $context = [])
    {
        $context = array_merge(['required_groups' => (array) $groups], $context);

        if (self::hasHelper('PermissionHelper')) {
            return PermissionHelper::requireCallback(function () use ($groups) {
                return UserHelper::inGroup($groups);
            }, $message, $redirect, $context);
        }

        if (!self::inGroup($groups)) {
            self::log('Acesso negado por grupo.', $context);

            throw new RuntimeException($message, 403);
        }

        return true;
    }

    public static function requireAllGroups(array $groups, $message = 'Você não possui todas as permissões necessárias para esta operação.', $redirect = null, array $context = [])
    {
        $context = array_merge(['required_groups' => $groups, 'mode' => 'all'], $context);

        if (self::hasHelper('PermissionHelper')) {
            return PermissionHelper::requireCallback(function () use ($groups) {
                return UserHelper::inAllGroups($groups);
            }, $message, $redirect, $context);
        }

        if (!self::inAllGroups($groups)) {
            self::log('Acesso negado por grupo.', $context);

            throw new RuntimeException($message, 403);
        }

        return true;
    }

    /* =================================================================
     * Membros de um grupo
     * ================================================================= */

    /*
     * Usuários do grupo (sem dados sensíveis). Opções:
     *   'descendants' => true   inclui membros dos subgrupos
     *   'blocked'     => false  (padrão) só ativos; true só bloqueados; null todos
     *   'limit'       => 0
     */
    public static function usersInGroup($group, array $options = [])
    {
        $filters = [
            'group' => $group,
            'descendants' => !empty($options['descendants']),
            'blocked' => array_key_exists('blocked', $options) ? $options['blocked'] : false,
        ];

        if (!empty($options['limit'])) {
            $filters['limit'] = (int) $options['limit'];
        }

        if (!empty($options['page'])) {
            $filters['page'] = (int) $options['page'];
            $filters['per_page'] = isset($options['per_page']) ? (int) $options['per_page'] : 20;
        }

        return self::users($filters);
    }

    public static function userIdsInGroup($group, $descendants = false, $onlyActive = true)
    {
        return array_map(function ($user) {
            return (int) $user->id;
        }, self::usersInGroup($group, ['descendants' => $descendants, 'blocked' => $onlyActive ? false : null]));
    }

    public static function countUsersInGroup($group, $descendants = false, $onlyActive = true)
    {
        $groupIds = self::resolveGroupIds($group);

        if ($descendants) {
            $groupIds = self::expandDescendants($groupIds);
        }

        $userIds = self::userIdsForGroupIds($groupIds);

        if (!$userIds) {
            return 0;
        }

        $query = self::usersTable()->whereIn('id', $userIds);

        if ($onlyActive) {
            $query->where('block', 0);
        }

        return $query->count();
    }

    // [user_id => email] dos membros ativos (para disparos de e-mail).
    public static function emailsInGroup($group, $descendants = false)
    {
        $emails = [];

        foreach (self::usersInGroup($group, ['descendants' => $descendants]) as $user) {
            if (filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                $emails[(int) $user->id] = $user->email;
            }
        }

        return $emails;
    }

    // [group_id => total de membros ativos] de todos os grupos (dashboard).
    public static function groupMemberCounts($onlyActive = true)
    {
        $sql = 'SELECT m.group_id, COUNT(DISTINCT m.user_id) AS total'
            . ' FROM ' . self::TABLE_MAP . ' m'
            . ' INNER JOIN ' . self::TABLE_USERS . ' u ON u.id = m.user_id'
            . ($onlyActive ? ' WHERE u.block = 0' : '')
            . ' GROUP BY m.group_id';

        $counts = array_fill_keys(array_keys(self::allGroups()), 0);

        foreach (OrmTables::raw($sql, [], self::ormOptions()) as $row) {
            $counts[(int) $row->group_id] = (int) $row->total;
        }

        return $counts;
    }

    /* =================================================================
     * Alterar grupos (escreve em #__user_usergroup_map)
     * ================================================================= */

    /*
     * Coloca o usuário nos grupos. Devolve os ids efetivamente adicionados.
     * Usa o JUserHelper quando existe (mantém a sessão do Joomla em dia).
     */
    public static function addToGroup($user, $groups)
    {
        $userId = self::requireUserId($user);
        $groupIds = self::resolveGroupIds($groups, true, true);
        $current = self::directGroupIds($userId, null, true);
        $added = [];

        foreach (array_diff($groupIds, $current) as $groupId) {
            if (class_exists('JUserHelper')) {
                JUserHelper::addUserToGroup($userId, $groupId);
            } else {
                self::mapTable()->create(['user_id' => $userId, 'group_id' => $groupId]);
            }

            $added[] = (int) $groupId;
        }

        self::forgetUser($userId);
        self::audit('user.group.add', $userId, ['groups' => $added]);

        return array_values($added);
    }

    // Tira o usuário dos grupos. Devolve os ids removidos.
    public static function removeFromGroup($user, $groups)
    {
        $userId = self::requireUserId($user);
        $groupIds = self::resolveGroupIds($groups, true, true);
        $current = self::directGroupIds($userId, null, true);
        $removed = array_values(array_intersect($groupIds, $current));

        if (count($removed) === count($current) && $removed) {
            throw new LogicException('O usuário não pode ficar sem nenhum grupo.');
        }

        foreach ($removed as $groupId) {
            if (class_exists('JUserHelper')) {
                JUserHelper::removeUserFromGroup($userId, $groupId);
            } else {
                self::mapTable()->where('user_id', $userId)->where('group_id', (int) $groupId)->deleteWhere();
            }
        }

        self::forgetUser($userId);
        self::audit('user.group.remove', $userId, ['groups' => $removed]);

        return $removed;
    }

    /*
     * Deixa o usuário exatamente nos grupos informados.
     * Devolve ['added' => [...], 'removed' => [...]].
     */
    public static function syncGroups($user, $groups)
    {
        $userId = self::requireUserId($user);
        $target = self::resolveGroupIds($groups, true, true);

        if (!$target) {
            throw new LogicException('Informe ao menos um grupo válido.');
        }

        $current = self::directGroupIds($userId, null, true);
        $added = self::addToGroup($userId, array_values(array_diff($target, $current)));
        $toRemove = array_values(array_diff($current, $target));
        $removed = $toRemove ? self::removeFromGroup($userId, $toRemove) : [];

        return ['added' => $added, 'removed' => $removed];
    }

    /* =================================================================
     * Perfil e dados pessoais
     * ================================================================= */

    /*
     * Dados pessoais (sexo, data_nascimento, cpf, central, singular,
     * estado, pa...) como array. [] quando não houver.
     */
    public static function dadosPessoais($user = null)
    {
        $userId = self::id($user);

        if ($userId <= 0) {
            return [];
        }

        $key = 'dados:' . $userId;

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $data = null;

        try {
            if (self::$dadosPessoaisResolver !== null) {
                $data = call_user_func(self::$dadosPessoaisResolver, $userId);
            } elseif (self::isCurrent($userId) && class_exists('JFactory') && method_exists('JFactory', 'getDadosPessoais')) {
                $data = JFactory::getDadosPessoais();
            }
        } catch (Throwable $error) {
            self::log('Falha ao carregar dados pessoais.', ['user_id' => $userId, 'erro' => $error->getMessage()], 'error');
        }

        $data = is_object($data) ? get_object_vars($data) : (is_array($data) ? $data : []);

        foreach (self::SENSITIVE_FIELDS as $field) {
            unset($data[$field]);
        }

        return self::$memo[$key] = $data;
    }

    // Um campo do perfil (#__users + dados pessoais).
    public static function get($field, $default = null, $user = null)
    {
        if (in_array($field, self::SENSITIVE_FIELDS, true)) {
            return $default;
        }

        $dados = self::dadosPessoais($user);

        if (array_key_exists($field, $dados) && $dados[$field] !== null && $dados[$field] !== '') {
            return $dados[$field];
        }

        $row = self::row($user);

        return $row && isset($row->$field) ? $row->$field : $default;
    }

    public static function name($user = null)
    {
        return (string) self::get('name', '', $user);
    }

    // 'MARIA DA SILVA SOUZA' -> 'Maria da Silva Souza'.
    public static function displayName($user = null)
    {
        return self::formatName(self::name($user));
    }

    public static function firstName($user = null)
    {
        $parts = preg_split('/\s+/u', trim(self::displayName($user)));

        return $parts && $parts[0] !== '' ? $parts[0] : '';
    }

    public static function lastName($user = null)
    {
        $parts = preg_split('/\s+/u', trim(self::displayName($user)));

        return count($parts) > 1 ? end($parts) : '';
    }

    // 'Maria Souza' — primeiro e último nome.
    public static function shortName($user = null)
    {
        $last = self::lastName($user);

        return trim(self::firstName($user) . ($last !== '' ? ' ' . $last : ''));
    }

    public static function initials($user = null, $max = 2)
    {
        $words = array_values(array_filter(preg_split('/\s+/u', trim(self::name($user))), function ($word) {
            return $word !== '' && !in_array(mb_strtolower($word), ['da', 'de', 'do', 'das', 'dos', 'e'], true);
        }));

        if (!$words) {
            return '';
        }

        $picked = count($words) > $max && $max > 1
            ? array_merge(array_slice($words, 0, $max - 1), [end($words)])
            : array_slice($words, 0, max(1, (int) $max));

        return mb_strtoupper(implode('', array_map(function ($word) {
            return mb_substr($word, 0, 1);
        }, $picked)));
    }

    public static function username($user = null)
    {
        return (string) self::get('username', '', $user);
    }

    public static function email($user = null)
    {
        return (string) self::get('email', '', $user);
    }

    public static function emailDomain($user = null)
    {
        $email = self::email($user);
        $at = strrpos($email, '@');

        return $at === false ? '' : strtolower(substr($email, $at + 1));
    }

    public static function cpf($user = null, $formatted = true)
    {
        $cpf = preg_replace('/\D+/', '', (string) self::get('cpf', '', $user));

        if ($cpf === '') {
            return '';
        }

        $cpf = str_pad($cpf, 11, '0', STR_PAD_LEFT);

        return $formatted ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $cpf) : $cpf;
    }

    public static function cpfMasked($user = null)
    {
        return self::maskCpf(self::cpf($user, false));
    }

    public static function emailMasked($user = null)
    {
        return self::maskEmail(self::email($user));
    }

    // 'M', 'F' ou ''.
    public static function gender($user = null)
    {
        $value = strtoupper(substr(trim((string) self::get('sexo', '', $user)), 0, 1));

        return in_array($value, ['M', 'F'], true) ? $value : '';
    }

    public static function genderLabel($user = null)
    {
        $labels = ['M' => 'Masculino', 'F' => 'Feminino'];
        $gender = self::gender($user);

        return isset($labels[$gender]) ? $labels[$gender] : 'Não informado';
    }

    // Saudação concordando com o sexo: 'Bem-vindo' / 'Bem-vinda'.
    public static function greeting($user = null, $male = 'Bem-vindo', $female = 'Bem-vinda', $neutral = 'Boas-vindas')
    {
        $gender = self::gender($user);
        $word = $gender === 'M' ? $male : ($gender === 'F' ? $female : $neutral);

        return $word . ', ' . self::firstName($user);
    }

    // 'Bom dia', 'Boa tarde', 'Boa noite' no fuso do usuário.
    public static function timeGreeting($user = null)
    {
        try {
            $now = new DateTime('now', new DateTimeZone(self::timezone($user)));
        } catch (Throwable $error) {
            $now = new DateTime('now');
        }

        $hour = (int) $now->format('G');
        $word = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
        $name = self::firstName($user);

        return $name !== '' ? $word . ', ' . $name : $word;
    }

    public static function birthDate($user = null)
    {
        $date = trim((string) self::get('data_nascimento', '', $user));

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $date) && strpos($date, '0000') !== 0 ? substr($date, 0, 10) : null;
    }

    public static function age($user = null)
    {
        $birth = self::birthDate($user);

        if ($birth === null) {
            return null;
        }

        return (int) (new DateTime($birth))->diff(new DateTime('today'))->y;
    }

    public static function isBirthdayToday($user = null)
    {
        $birth = self::birthDate($user);

        return $birth !== null && substr($birth, 5) === date('m-d');
    }

    public static function isBirthdayMonth($user = null, $month = null)
    {
        $birth = self::birthDate($user);
        $month = $month === null ? (int) date('n') : (int) $month;

        return $birth !== null && (int) substr($birth, 5, 2) === $month;
    }

    // Dias até o próximo aniversário (0 = hoje).
    public static function daysUntilBirthday($user = null)
    {
        $birth = self::birthDate($user);

        if ($birth === null) {
            return null;
        }

        $today = new DateTime('today');
        $next = DateTime::createFromFormat('!Y-m-d', $today->format('Y') . substr($birth, 4));

        // 29/02 em ano não bissexto: comemora em 01/03.
        if ($next === false || $next->format('m-d') !== substr($birth, 5)) {
            $next = new DateTime($today->format('Y') . '-03-01');
        }

        if ($next < $today) {
            $next->modify('+1 year');
        }

        return (int) $today->diff($next)->days;
    }

    /* ---- Cooperativa (central / singular / PA / UF) ----------------- */

    public static function cooperativa($user = null)
    {
        return [
            'central' => self::nullableString(self::get('central', null, $user)),
            'singular' => self::nullableString(self::get('singular', null, $user)),
            'pa' => self::nullableString(self::get('pa', null, $user)),
            'uf' => self::nullableString(self::get('estado', null, $user)),
        ];
    }

    public static function central($user = null)
    {
        return self::cooperativa($user)['central'];
    }

    public static function singular($user = null)
    {
        return self::cooperativa($user)['singular'];
    }

    public static function pa($user = null)
    {
        return self::cooperativa($user)['pa'];
    }

    public static function uf($user = null)
    {
        $uf = self::cooperativa($user)['uf'];

        return $uf === null ? null : strtoupper($uf);
    }

    // Aceita código ou lista: inSingular([300, 3001]).
    public static function inCentral($codes, $user = null)
    {
        return self::codeIn(self::central($user), $codes);
    }

    public static function inSingular($codes, $user = null)
    {
        return self::codeIn(self::singular($user), $codes);
    }

    public static function inPa($codes, $user = null)
    {
        return self::codeIn(self::pa($user), $codes);
    }

    public static function inUf($ufs, $user = null)
    {
        $uf = self::uf($user);

        return $uf !== null && in_array($uf, array_map('strtoupper', (array) $ufs), true);
    }

    /* ---- Tipo do usuário (CCS / CENTRAL / SINGULAR / PA) ------------ */

    /*
     * Troca a lista de códigos que identificam o CCS.
     *   UserHelper::setCcsCodes([6004, 300, 1, 9805, 310, 382, 9986, 309]);
     */
    public static function setCcsCodes(array $codes)
    {
        self::$ccsCodes = array_values(array_unique(array_map('intval', $codes)));
    }

    public static function ccsCodes()
    {
        return self::$ccsCodes;
    }

    /*
     * Tipo do usuário pelo código da singular e do PA:
     *
     *   singular na lista do CCS        -> 'CCS'
     *   singular entre 1001 e 2999      -> 'CENTRAL'
     *   singular acima de 3000, PA > 0  -> 'PA'
     *   singular acima de 3000, PA = 0  -> 'SINGULAR'
     *   qualquer outro caso             -> 'OUTRO'
     *
     * Sem dados pessoais (visitante, cadastro incompleto) dá 'OUTRO'.
     * Use as constantes: UserHelper::userType() === UserHelper::TYPE_PA.
     */
    public static function userType($user = null)
    {
        $coop = self::cooperativa($user);
        $singular = (int) $coop['singular'];
        $pa = (int) $coop['pa'];

        if (in_array($singular, self::$ccsCodes, true)) {
            return self::TYPE_CCS;
        }

        if ($singular > 1000 && $singular < 3000) {
            return self::TYPE_CENTRAL;
        }

        if ($singular > 3000) {
            return $pa > 0 ? self::TYPE_PA : self::TYPE_SINGULAR;
        }

        return self::TYPE_OUTRO;
    }

    // 'CCS' -> 'Centro Cooperativo Sicoob', 'PA' -> 'Posto de Atendimento'...
    public static function userTypeLabel($user = null)
    {
        $labels = [
            self::TYPE_CCS => 'Centro Cooperativo Sicoob',
            self::TYPE_CENTRAL => 'Cooperativa Central',
            self::TYPE_SINGULAR => 'Cooperativa Singular',
            self::TYPE_PA => 'Posto de Atendimento',
            self::TYPE_OUTRO => 'Outro',
        ];

        return $labels[self::userType($user)];
    }

    // O usuário é de algum desses tipos? isType(['CCS', 'CENTRAL']).
    public static function isType($types, $user = null)
    {
        return in_array(self::userType($user), array_map('strtoupper', (array) $types), true);
    }

    public static function isCcs($user = null)
    {
        return self::userType($user) === self::TYPE_CCS;
    }

    public static function isCentral($user = null)
    {
        return self::userType($user) === self::TYPE_CENTRAL;
    }

    // Só quem está na sede da singular (PA = 0). Para incluir os PAs: isType(['SINGULAR', 'PA']).
    public static function isSingular($user = null)
    {
        return self::userType($user) === self::TYPE_SINGULAR;
    }

    public static function isPa($user = null)
    {
        return self::userType($user) === self::TYPE_PA;
    }

    /*
     * Barra quem não é de um dos tipos, no mesmo fluxo do requireGroup().
     *   UserHelper::requireType(['CCS', 'CENTRAL']);
     */
    public static function requireType($types, $message = 'Você não possui permissão para esta operação.', $redirect = null, array $context = [])
    {
        $context = array_merge(['required_types' => (array) $types], $context);

        if (self::hasHelper('PermissionHelper')) {
            return PermissionHelper::requireCallback(function () use ($types) {
                return UserHelper::isType($types);
            }, $message, $redirect, $context);
        }

        if (!self::isType($types)) {
            self::log('Acesso negado por tipo de usuário.', $context);

            throw new RuntimeException($message, 403);
        }

        return true;
    }

    /*
     * Tipo e códigos juntos, no formato do verificarTipoUsuario():
     * ['ROLE' => 'PA', 'ROLE_LABEL' => 'Posto de Atendimento',
     *  'CENTRAL' => 300, 'SINGULAR' => 3001, 'PA' => 2, 'NOME' => '...'].
     */
    public static function userTypeInfo($user = null)
    {
        $coop = self::cooperativa($user);

        return [
            'ROLE' => self::userType($user),
            'ROLE_LABEL' => self::userTypeLabel($user),
            'CENTRAL' => (int) $coop['central'],
            'SINGULAR' => (int) $coop['singular'],
            'PA' => (int) $coop['pa'],
            'NOME' => self::name($user),
        ];
    }

    /*
     * Limita a consulta aos dados da cooperativa do usuário:
     *
     *   UserHelper::scopeQuery($orm, ['singular' => 'p.cod_singular', 'pa' => 'p.cod_pa']);
     *
     * Só aplica as chaves informadas. Se o usuário não tiver o código,
     * a consulta não devolve nada (fail closed). Use $bypassGroups para
     * liberar quem pode ver tudo: ['Diretoria', 'TI'].
     */
    public static function scopeQuery($query, array $columns, array $bypassGroups = [], $user = null)
    {
        if ($bypassGroups && self::inAnyGroup($bypassGroups, $user)) {
            return $query;
        }

        $coop = self::cooperativa($user);

        foreach ($columns as $key => $column) {
            if (!array_key_exists($key, $coop)) {
                throw new InvalidArgumentException('Chave de escopo inválida: ' . $key . ' (use central, singular, pa ou uf).');
            }

            if ($coop[$key] === null) {
                $query->whereRaw('1 = 0');

                continue;
            }

            $query->where($column, $coop[$key]);
        }

        return $query;
    }

    /* ---- Preferências (#__users.params) ----------------------------- */

    public static function params($user = null)
    {
        $userId = self::id($user);

        if ($userId <= 0) {
            return [];
        }

        $key = 'params:' . $userId;

        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        $raw = self::usersTable()->where('id', $userId)->value('params');
        $params = json_decode((string) $raw, true);

        return self::$memo[$key] = is_array($params) ? $params : [];
    }

    public static function param($name, $default = null, $user = null)
    {
        $params = self::params($user);

        return isset($params[$name]) && $params[$name] !== '' ? $params[$name] : $default;
    }

    public static function timezone($user = null)
    {
        $timezone = self::param('timezone', null, $user);

        if (!$timezone && class_exists('JFactory')) {
            try {
                $timezone = JFactory::getConfig()->get('offset');
            } catch (Throwable $error) {
            }
        }

        return $timezone && in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'America/Sao_Paulo';
    }

    public static function language($user = null)
    {
        return self::param('language', 'pt-BR', $user) ?: 'pt-BR';
    }

    /* =================================================================
     * Saídas prontas
     * ================================================================= */

    /*
     * Perfil seguro, mesclando #__users com os dados pessoais.
     * Opções:
     *   'groups'    => true   inclui grupos com nome
     *   'sensitive' => false  true devolve CPF e e-mail sem máscara
     *   'fields'    => []     restringe às chaves informadas
     */
    public static function profile($user = null, array $options = [])
    {
        $row = self::row($user);

        if (!$row) {
            return null;
        }

        $sensitive = !empty($options['sensitive']);
        $birth = self::birthDate($row);

        $profile = [
            'id' => (int) $row->id,
            'nome' => self::displayName($row),
            'nome_curto' => self::shortName($row),
            'primeiro_nome' => self::firstName($row),
            'iniciais' => self::initials($row),
            'username' => $row->username,
            'email' => $sensitive ? $row->email : self::maskEmail($row->email),
            'cpf' => $sensitive ? self::cpf($row) : self::cpfMasked($row),
            'sexo' => self::gender($row) ?: null,
            'data_nascimento' => $sensitive ? $birth : null,
            'idade' => self::age($row),
            'aniversario_hoje' => self::isBirthdayToday($row),
            'cooperativa' => self::cooperativa($row),
            'tipo' => self::userType($row),
            'bloqueado' => (int) $row->block === 1,
            'cadastrado_em' => self::dateOrNull($row->registerDate),
            'ultimo_acesso' => self::dateOrNull($row->lastvisitDate),
            'timezone' => self::timezone($row),
            'idioma' => self::language($row),
        ];

        if (!array_key_exists('groups', $options) || $options['groups']) {
            $profile['grupos'] = array_map(function ($group) {
                return ['id' => $group->id, 'nome' => $group->title, 'direto' => $group->direct];
            }, self::groupsDetailed($row));
        }

        if (!empty($options['fields'])) {
            $profile = array_intersect_key($profile, array_flip((array) $options['fields']));
        }

        return $profile;
    }

    // Igual ao profile(), pensado para ApiResponseHelper::success().
    public static function toApi($user = null, array $options = [])
    {
        return self::profile($user, $options);
    }

    /*
     * Contexto mínimo para LogHelper/AuditHelper.
     */
    public static function snapshot($user = null)
    {
        $userId = self::id($user);

        return [
            'user_id' => $userId ?: null,
            'username' => $userId ? self::username($userId) : null,
            'singular' => $userId ? self::singular($userId) : null,
            'pa' => $userId ? self::pa($userId) : null,
            'tipo' => $userId ? self::userType($userId) : null,
            'ip' => self::ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 255) : null,
        ];
    }

    // IP do cliente (REMOTE_ADDR; proxies não são considerados confiáveis).
    public static function ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : null;
    }

    /*
     * Remove campos sensíveis de qualquer usuário (JUser, objeto, array).
     * Devolve stdClass só com os campos permitidos.
     */
    public static function sanitize($user)
    {
        if ($user === null) {
            return null;
        }

        $data = is_object($user) ? get_object_vars($user) : (array) $user;
        $safe = new stdClass();

        foreach (self::USER_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $safe->$field = $data[$field];
            }
        }

        return $safe;
    }

    /* =================================================================
     * Máscaras (LGPD)
     * ================================================================= */

    // '12345678909' -> '***.456.789-**'.
    public static function maskCpf($cpf)
    {
        $digits = preg_replace('/\D+/', '', (string) $cpf);

        if ($digits === '') {
            return '';
        }

        $digits = str_pad($digits, 11, '0', STR_PAD_LEFT);

        return '***.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-**';
    }

    // 'maria.silva@dominio.com' -> 'ma*********@dominio.com'.
    public static function maskEmail($email)
    {
        $email = trim((string) $email);
        $at = strrpos($email, '@');

        if ($at === false) {
            return $email === '' ? '' : str_repeat('*', mb_strlen($email));
        }

        $local = substr($email, 0, $at);
        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $visible . str_repeat('*', max(1, mb_strlen($local) - mb_strlen($visible))) . substr($email, $at);
    }

    // 'Maria da Silva Souza' -> 'Maria da S. S.'.
    public static function maskName($name)
    {
        $parts = preg_split('/\s+/u', trim((string) $name));
        $small = ['da', 'de', 'do', 'das', 'dos', 'e'];

        foreach ($parts as $i => $part) {
            if ($i === 0 || in_array(mb_strtolower($part), $small, true)) {
                continue;
            }

            $parts[$i] = mb_substr($part, 0, 1) . '.';
        }

        return implode(' ', $parts);
    }

    /* =================================================================
     * Internos
     * ================================================================= */

    /*
     * Alguns helpers encerram o script fora do Joomla (defined('_JEXEC')
     * or die). Só deixa o autoload carregá-los dentro do Joomla.
     */
    private static function hasHelper($class)
    {
        if (class_exists($class, false)) {
            return true;
        }

        return defined('_JEXEC') && class_exists($class);
    }

    private static function ormOptions(array $extra = [])
    {
        $options = array_merge(['timestamps' => false], $extra);

        if (self::$connection !== null) {
            $options['connection'] = self::$connection;
        }

        return $options;
    }

    private static function usersTable()
    {
        return OrmTables::table(self::TABLE_USERS, self::ormOptions());
    }

    private static function mapTable()
    {
        return OrmTables::table(self::TABLE_MAP, self::ormOptions(['primaryKey' => 'user_id']));
    }

    // Linha segura do usuário (aceita tudo que id() aceita).
    private static function row($user = null)
    {
        $userId = self::id($user);

        return $userId > 0 ? self::find($userId) : null;
    }

    private static function safeRow($row)
    {
        if (!$row) {
            return null;
        }

        $safe = self::sanitize($row);

        foreach (['id', 'block', 'sendEmail', 'requireReset'] as $intField) {
            if (isset($safe->$intField)) {
                $safe->$intField = (int) $safe->$intField;
            }
        }

        return $safe;
    }

    private static function buildCatalog(array $rows)
    {
        $catalog = [];

        foreach ($rows as $row) {
            $catalog[(int) $row->id] = (object) [
                'id' => (int) $row->id,
                'parent_id' => (int) $row->parent_id,
                'lft' => (int) $row->lft,
                'rgt' => (int) $row->rgt,
                'title' => (string) $row->title,
                'level' => 0,
                'path' => '',
            ];
        }

        foreach ($catalog as $group) {
            $titles = [];
            $level = 0;

            foreach ($catalog as $candidate) {
                if ($candidate->lft < $group->lft && $candidate->rgt > $group->rgt) {
                    $titles[] = $candidate->title;
                    $level++;
                }
            }

            // Os ancestrais já vêm em ordem de lft, do topo para baixo.
            $titles[] = $group->title;
            $group->level = $level;
            $group->path = implode(' > ', $titles);
        }

        return $catalog;
    }

    /*
     * Resolve id, nome, apelido ou lista para ids existentes.
     * $strict = true lança exceção para grupo desconhecido (usado nas
     * escritas, onde ignorar seria perigoso).
     */
    private static function resolveGroupIds($groups, $log = true, $strict = false, $depth = 0)
    {
        if ($depth > 5) {
            throw new LogicException('Apelidos de grupo em ciclo.');
        }

        $catalog = self::allGroups();
        $ids = [];

        foreach ((array) $groups as $group) {
            if (is_array($group)) {
                $ids = array_merge($ids, self::resolveGroupIds($group, $log, $strict, $depth + 1));

                continue;
            }

            if (is_object($group) && isset($group->id)) {
                $group = (int) $group->id;
            }

            if (is_int($group) || (is_string($group) && ctype_digit(trim($group)))) {
                $id = (int) $group;

                if (isset($catalog[$id])) {
                    $ids[] = $id;
                } else {
                    self::unknownGroup($group, $log, $strict);
                }

                continue;
            }

            $text = self::normalizeText($group);

            if ($text === '') {
                continue;
            }

            if (isset(self::$groupAliases[$text])) {
                $ids = array_merge($ids, self::resolveGroupIds(self::$groupAliases[$text], $log, $strict, $depth + 1));

                continue;
            }

            $found = false;

            foreach ($catalog as $id => $node) {
                if (self::normalizeText($node->title) === $text) {
                    $ids[] = $id;
                    $found = true;
                }
            }

            if (!$found) {
                self::unknownGroup($group, $log, $strict);
            }
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private static function unknownGroup($group, $log, $strict)
    {
        if ($strict) {
            throw new InvalidArgumentException('Grupo não encontrado: ' . $group);
        }

        if ($log) {
            self::log('Grupo não encontrado ao verificar permissão.', ['grupo' => (string) $group]);
        }
    }

    private static function matchesAny($groups, array $userGroupIds)
    {
        if (!$userGroupIds) {
            return false;
        }

        return (bool) array_intersect(self::resolveGroupIds($groups), $userGroupIds);
    }

    /*
     * Grupos diretos. Para o usuário logado usa o JUser (já em sessão);
     * para os demais, ou com $fresh, lê a tabela de mapeamento.
     */
    private static function directGroupIds($userId, $user = null, $fresh = false)
    {
        if (!$fresh && $user === null && self::isCurrent($userId)) {
            $current = self::current();

            if ($current && !empty($current->groups) && is_array($current->groups)) {
                return self::intList($current->groups);
            }
        }

        if (!$fresh && is_object($user) && !empty($user->groups) && is_array($user->groups) && (int) $user->id === (int) $userId) {
            return self::intList($user->groups);
        }

        $ids = self::mapTable()->where('user_id', (int) $userId)->pluck('group_id');

        return self::intList($ids ?: []);
    }

    private static function userIdsForGroupIds(array $groupIds)
    {
        if (!$groupIds) {
            return [];
        }

        $ids = self::mapTable()->whereIn('group_id', $groupIds)->pluck('user_id');

        return self::intList($ids ?: []);
    }

    private static function expandAncestors(array $groupIds)
    {
        $catalog = self::allGroups();
        $result = [];

        foreach ($groupIds as $id) {
            if (!isset($catalog[$id])) {
                continue;
            }

            $node = $catalog[$id];

            foreach ($catalog as $candidate) {
                if ($candidate->lft <= $node->lft && $candidate->rgt >= $node->rgt) {
                    $result[] = $candidate->id;
                }
            }
        }

        return array_values(array_unique($result));
    }

    private static function expandDescendants(array $groupIds)
    {
        $catalog = self::allGroups();
        $result = [];

        foreach ($groupIds as $id) {
            if (!isset($catalog[$id])) {
                continue;
            }

            $node = $catalog[$id];

            foreach ($catalog as $candidate) {
                if ($candidate->lft >= $node->lft && $candidate->rgt <= $node->rgt) {
                    $result[] = $candidate->id;
                }
            }
        }

        return array_values(array_unique($result));
    }

    private static function requireUserId($user)
    {
        $userId = self::id($user);

        if ($userId <= 0 || !self::exists($userId)) {
            throw new InvalidArgumentException('Usuário inválido.');
        }

        return $userId;
    }

    private static function forgetUser($userId)
    {
        foreach (array_keys(self::$memo) as $key) {
            if (strpos($key, 'groups:' . (int) $userId . ':') === 0) {
                unset(self::$memo[$key]);
            }
        }
    }

    private static function codeIn($value, $codes)
    {
        if ($value === null) {
            return false;
        }

        foreach ((array) $codes as $code) {
            if (ltrim((string) $code, '0') === ltrim((string) $value, '0')) {
                return true;
            }
        }

        return false;
    }

    private static function intList(array $values)
    {
        return array_values(array_unique(array_filter(array_map('intval', $values), function ($value) {
            return $value > 0;
        })));
    }

    private static function normalizeText($text)
    {
        $text = trim((string) $text);

        $text = strtr($text, [
            'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
            'ç' => 'c', 'Ç' => 'c', 'ñ' => 'n', 'Ñ' => 'n',
        ]);

        return mb_strtolower(preg_replace('/\s+/u', ' ', $text));
    }

    // 'MARIA DA SILVA' -> 'Maria da Silva'.
    private static function formatName($name)
    {
        $small = ['da', 'de', 'do', 'das', 'dos', 'e', 'di', 'du', 'del', 'van', 'von'];
        $words = preg_split('/\s+/u', mb_strtolower(trim((string) $name)));

        foreach ($words as $i => $word) {
            if ($word === '' || ($i > 0 && in_array($word, $small, true))) {
                continue;
            }

            $words[$i] = mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
        }

        return implode(' ', $words);
    }

    private static function nullableString($value)
    {
        return $value === null || trim((string) $value) === '' ? null : trim((string) $value);
    }

    private static function dateOrNull($value)
    {
        $value = trim((string) $value);

        return $value === '' || $value === self::NULL_DATE || strpos($value, '0000-00-00') === 0 ? null : $value;
    }

    private static function log($message, array $context = [], $level = 'warning')
    {
        try {
            if (class_exists('LogHelper') && method_exists('LogHelper', $level)) {
                LogHelper::$level($message, self::$logCategory, $context);
            }
        } catch (Throwable $error) {
        }
    }

    private static function audit($event, $userId, array $metadata)
    {
        try {
            if (self::hasHelper('AuditHelper') && method_exists('AuditHelper', 'log')) {
                AuditHelper::log($event, [
                    'category' => 'usuario',
                    'entity_type' => 'user',
                    'entity_id' => (int) $userId,
                    'metadata' => $metadata,
                ]);
            } else {
                self::log('Grupos alterados.', array_merge(['evento' => $event, 'user_id' => $userId], $metadata), 'info');
            }
        } catch (Throwable $error) {
        }
    }
}
