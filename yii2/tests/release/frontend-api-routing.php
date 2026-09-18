<?php

// Full Yii action dispatch with disposable data, never a running production API.
defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
error_reporting(E_ALL & ~E_DEPRECATED);

class RoutingIdentity implements \yii\web\IdentityInterface
{
    public $id = 1;
    public static function findIdentity($id) { return null; }
    public static function findIdentityByAccessToken($token, $type = null) { return $token === 'local-test-token' ? new self() : null; }
    public function getId() { return $this->id; }
    public function getAuthKey() { return ''; }
    public function validateAuthKey($key) { return false; }
}

function routeCheck(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}

function dispatch(string $method, string $path, bool $authenticated = false, array $query = [], array $body = []): array
{
    $app = Yii::$app;
    $app->user->setIdentity(null);
    $app->response->setStatusCode(200);
    $_SERVER['REQUEST_METHOD'] = $method;
    $app->request->getHeaders()->removeAll();
    $app->request->getHeaders()->set('Accept', 'application/json');
    if ($authenticated) $app->request->getHeaders()->set('Authorization', 'Bearer local-test-token');
    $app->request->setQueryParams($query);
    $app->request->setBodyParams($body);
    $app->request->setPathInfo($path);
    $route = $app->urlManager->parseRequest($app->request);
    if ($route === false) return [404, null];
    try {
        // Match Request::resolve(): route parameters take precedence over the query string.
        $data = $app->runAction($route[0], $route[1] + $query);
        return [$app->response->getStatusCode(), $data];
    } catch (\yii\web\HttpException $error) {
        return [$error->statusCode, null];
    }
}

$file = tempnam(sys_get_temp_dir(), 'ablakin-routing-');
try {
    $api = require dirname(__DIR__, 2) . '/api/config/main.php';
    $app = new \yii\web\Application([
        'id' => 'routing-test', 'basePath' => dirname(__DIR__, 2), 'vendorPath' => dirname(__DIR__, 2) . '/vendor',
        'runtimePath' => sys_get_temp_dir() . '/ablakin-routing-presence-' . uniqid(),
        'container' => $api['container'],
        'modules' => ['v1' => ['class' => \api\modules\v1\Module::class], 'user' => ['class' => \dektrium\user\Module::class]],
        'components' => [
            'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite:' . $file],
            'request' => ['cookieValidationKey' => 'local-test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php', 'hostInfo' => 'http://test.invalid'],
            'user' => ['identityClass' => RoutingIdentity::class, 'enableSession' => false],
            'response' => ['format' => \yii\web\Response::FORMAT_JSON],
            'urlManager' => ['enablePrettyUrl' => true, 'enableStrictParsing' => true, 'showScriptName' => false, 'rules' => array_merge(
                require dirname(__DIR__, 2) . '/api/modules/v1/config/urlRules.php',
                require dirname(__DIR__, 2) . '/common/modules/games/config/urlRules.php',
                require dirname(__DIR__, 2) . '/common/modules/forum/config/urlRules.php',
                require dirname(__DIR__, 2) . '/common/modules/exchange/config/urlRules.php'
            )],
            'i18n' => ['translations' => ['*' => ['class' => \yii\i18n\PhpMessageSource::class, 'basePath' => '@api/messages']]],
            'cache' => ['class' => \yii\caching\ArrayCache::class],
        ],
    ]);
    $db = $app->db;
    $db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT, email TEXT, created_at INTEGER, last_login_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE persone (id INTEGER PRIMARY KEY, user_id INTEGER UNIQUE, balance NUMERIC, credit NUMERIC, rating NUMERIC, description TEXT, refovod INTEGER, bonus_count INTEGER)')->execute();
    $db->createCommand('CREATE TABLE forum_theme (id INTEGER PRIMARY KEY, user_id INTEGER, title TEXT, created_at INTEGER, last_post INTEGER, view INTEGER)')->execute();
    $db->createCommand('CREATE TABLE forum_comment (id INTEGER PRIMARY KEY, user_id INTEGER, theme_id INTEGER, comment TEXT, active INTEGER, created_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE forum_comment_gift (id INTEGER PRIMARY KEY, comment_id INTEGER, user_id INTEGER, recipient_id INTEGER, created_at INTEGER, UNIQUE(comment_id,user_id))')->execute();
    $db->createCommand('CREATE TABLE history_balance (id INTEGER PRIMARY KEY, user_id INTEGER, balance NUMERIC, credit NUMERIC, balance_up NUMERIC, credit_up NUMERIC, type TEXT, comment TEXT, created_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE history_rating (id INTEGER PRIMARY KEY, user_id INTEGER, rating NUMERIC, rating_up NUMERIC, type TEXT, comment TEXT, created_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE game_orel (id INTEGER PRIMARY KEY, user_id INTEGER, user_gamer INTEGER, kon NUMERIC, type INTEGER, hod INTEGER, created_at INTEGER, updated_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE game_saper (id INTEGER PRIMARY KEY, user_id INTEGER, user_gamer INTEGER, kon NUMERIC, etap INTEGER, created_at INTEGER, time_over_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE credit_transfer (id INTEGER PRIMARY KEY, user_id INTEGER, user_buyer INTEGER, amount NUMERIC, password TEXT, created_at INTEGER, updated_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE credit_exchange (id INTEGER PRIMARY KEY, user_id INTEGER, user_buyer INTEGER, amount NUMERIC, credit NUMERIC, type TEXT, created_at INTEGER, updated_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE fact (id INTEGER PRIMARY KEY, title TEXT, type TEXT, hide INTEGER)')->execute();
    $db->createCommand("INSERT INTO user VALUES (1,'Donor','private1',1,1),(2,'Author','private2',2,2)")->execute();
    $db->createCommand("INSERT INTO persone VALUES (1,1,10,2,1,'',0,0),(2,2,10,1,2,'',1,0)")->execute();
    $db->createCommand("INSERT INTO forum_theme VALUES (1,2,'Topic',1,1,0)")->execute();
    $db->createCommand("INSERT INTO forum_comment VALUES (1,2,1,'Message',1,1)")->execute();
    $db->createCommand("INSERT INTO forum_comment VALUES (2,2,1,'Hidden message',0,2)")->execute();
    list($themeStatus, $themeList) = dispatch('GET', 'v1/forum-theme', false, ['envelope' => '1']);
    routeCheck($themeStatus === 200 && $themeList['items'][0]['comment_count'] === 1
        && $themeList['items'][0]['view'] === 0, 'topic list returns visible comment and view counts');
    foreach (['v1/users','v1/users/online','v1/forum-theme','v1/forum-comment'] as $path) {
        list($status, $data) = dispatch('GET', $path, false, ['envelope' => '1']);
        routeCheck($status === 200 && isset($data['items'], $data['_meta']['pageCount']), 'public list dispatch and serializer: ' . $path);
    }
    list($status, $stat) = dispatch('GET', 'v1/stat');
    routeCheck($status === 200 && $stat['users'] === 2 && $stat['games']['saper'] === 0, 'public statistics exact path dispatches current database totals');
    $statTransaction = $db->beginTransaction();
    try {
        $app->formatter->timeZone = 'Europe/Moscow';
        $today = (new DateTimeImmutable('today', new DateTimeZone('Europe/Moscow')))->getTimestamp();
        $yesterday = $today - 86400;
        $dates = [$yesterday - 1, $yesterday, $today - 1, $today, time(), time() + 86400];
        $tables = [
            'user' => ['username' => 'StatUser'],
            'game_orel' => ['user_id' => 1, 'user_gamer' => 2],
            'game_saper' => ['user_id' => 1, 'user_gamer' => 2, 'etap' => 0],
            'forum_theme' => ['user_id' => 1],
            'forum_comment' => ['user_id' => 1, 'active' => 1],
            'credit_transfer' => ['user_id' => 1, 'user_buyer' => 2],
            'credit_exchange' => ['user_id' => 1, 'user_buyer' => 2],
        ];
        foreach ($tables as $table => $fields) {
            $db->createCommand()->delete($table)->execute();
            foreach ($dates as $index => $date) {
                $db->createCommand()->insert($table, array_merge($fields, ['id' => $index + 1, 'created_at' => $date]))->execute();
            }
        }
        $db->createCommand()->insert('game_orel', ['id' => 7, 'created_at' => $today, 'user_gamer' => 0])->execute();
        $db->createCommand()->insert('game_saper', ['id' => 7, 'created_at' => $today, 'etap' => 5])->execute();
        $db->createCommand()->insert('credit_exchange', ['id' => 7, 'created_at' => $today, 'user_buyer' => 0])->execute();
        $app->cache->flush();
        $app->cache->set(\api\modules\v1\controllers\StatController::CACHE_KEY, ['users' => 999]);
        list($status, $periodStat) = dispatch('GET', 'v1/stat');
        routeCheck($status === 200 && isset($periodStat['periods']), 'statistics ignores the cached legacy payload after deployment');
        $periods = $periodStat['periods'];
        foreach ([$periods['users'], $periods['games']['orel'], $periods['games']['saper'],
            $periods['forum']['themes'], $periods['forum']['comments'], $periods['transfers'], $periods['exchange']] as $counts) {
            routeCheck($counts === ['total' => 6, 'today' => 2, 'yesterday' => 2],
                'statistics counts midnight boundaries once and excludes future records from today');
        }
        routeCheck($periodStat['transfers'] === 6 && $periodStat['games']['orel'] === 6
            && $periodStat['games']['saper'] === 6 && $periodStat['exchange'] === 6,
            'legacy scalar totals are preserved and unfinished games/orders are excluded');
        routeCheck(dispatch('GET', 'v1/stat')[1] === $periodStat, 'cached statistics preserves the complete period contract');
        foreach (array_keys($tables) as $table) $db->createCommand()->delete($table)->execute();
        $app->cache->flush();
        $emptyStats = dispatch('GET', 'v1/stat')[1];
        routeCheck($emptyStats['periods']['users'] === ['total' => 0, 'today' => 0, 'yesterday' => 0]
            && $emptyStats['periods']['transfers']['total'] === 0 && $emptyStats['topRating'] === [],
            'empty database returns real zeroes and an empty rating');
    } finally {
        $statTransaction->rollBack();
        $app->cache->flush();
    }
    list($status, $ranking) = dispatch('GET', 'v1/stat/top', false, ['envelope' => '1', 'q' => 'Author']);
    routeCheck($status === 200 && $ranking['_meta']['totalCount'] === 1 && $ranking['items'][0]['username'] === 'Author', 'public ranking exact path dispatches searchable envelope');
    $rankingTransaction = $db->beginTransaction();
    try {
        $now = time();
        foreach ([[1, 1.25, $now], [1, 2.5, $now], [1, -100, $now], [2, 10, $now - 172800]] as $earned) {
            $db->createCommand()->insert('history_rating', ['user_id' => $earned[0], 'rating_up' => $earned[1], 'created_at' => $earned[2]])->execute();
        }
        foreach (['day', 'week', 'month', 'half-year'] as $period) {
            list($status, $top) = dispatch('GET', 'v1/stat/top', false, ['envelope' => '1', 'period' => $period, 'per-page' => '1']);
            routeCheck($status === 200 && $top['_meta']['totalCount'] === ($period === 'day' ? 1 : 2)
                && $top['_meta']['perPage'] === 1 && (float)$top['items'][0]['rating'] === ($period === 'day' ? 3.75 : 10.0),
                'ranking aggregates positive gains before sorting/pagination for ' . $period);
        }
        $second = dispatch('GET', 'v1/stat/top', false, ['envelope' => '1', 'period' => 'week', 'per-page' => '1', 'page' => '2'])[1];
        routeCheck($second['items'][0]['username'] === 'Donor' && $second['_meta']['pageCount'] === 2, 'ranking serves the actual second page');
        $empty = dispatch('GET', 'v1/stat/top', false, ['envelope' => '1', 'period' => 'week', 'q' => 'missing-user'])[1];
        routeCheck($empty['items'] === [] && $empty['_meta']['totalCount'] === 0, 'ranking search returns an empty successful envelope');
        $legacyTop = dispatch('GET', 'v1/stat/top', false, ['period' => 'day'])[1];
        routeCheck($legacyTop['period'] === 'day' && count($legacyTop['list']) === 1, 'legacy ranking list remains compatible');
        routeCheck(dispatch('GET', 'v1/stat/top', false, ['period' => 'invalid'])[0] === 400, 'unknown ranking period is rejected');
    } finally {
        $rankingTransaction->rollBack();
    }
    routeCheck(dispatch('GET', 'v1/tips/random') === [200, null], 'random tip exact path returns null when existing facts are empty');
    foreach (['v1/history/balance','v1/history/rating','v1/users/referrals','v1/transfer','v1/transfer/history',
        'v1/exchange','v1/exchange/my','v1/exchange/history','v1/orel','v1/orel/my','v1/orel/history','v1/orel/recent',
        'v1/saper','v1/saper/my','v1/saper/history','v1/saper/recent','v1/forum-theme/my','v1/forum-comment/my'] as $path) {
        routeCheck(dispatch('GET', $path, false)[0] === 401, 'private list rejects guest: ' . $path);
        list($status, $data) = dispatch('GET', $path, true, ['envelope' => '1']);
        routeCheck($status === 200 && isset($data['items'], $data['_meta']['pageCount']), 'authenticated list dispatch and serializer: ' . $path);
    }
    foreach (['v1/orel/summary','v1/saper/summary'] as $path) {
        routeCheck(dispatch('GET', $path)[0] === 401, 'summary rejects guest: ' . $path);
        list($status, $data) = dispatch('GET', $path, true);
        routeCheck($status === 200 && isset($data['today']['played'], $data['own']['count']), 'summary dispatch: ' . $path);
    }
    $db->createCommand('CREATE TABLE game_duel (id INTEGER PRIMARY KEY, user_id INTEGER, user_gamer INTEGER, kon NUMERIC, u1 INTEGER, u2 INTEGER, b1 INTEGER, b2 INTEGER, created_at INTEGER, updated_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE game_five (id INTEGER PRIMARY KEY, user_id INTEGER, user_gamer INTEGER, kon NUMERIC, status TEXT, user_amount INTEGER, gamer_amount INTEGER, created_at INTEGER, updated_at INTEGER)')->execute();
    foreach (['orel', 'saper', 'duel', 'five'] as $kind) {
        $values = ['id' => 1, 'user_id' => 1, 'user_gamer' => 2, 'kon' => 5, 'created_at' => 1];
        $values[$kind === 'saper' ? 'time_over_at' : 'updated_at'] = time();
        if ($kind === 'orel') $values += ['type' => 1, 'hod' => 2];
        elseif ($kind === 'saper') $values += ['etap' => 0];
        elseif ($kind === 'duel') $values += ['u1' => 1, 'u2' => 2, 'b1' => 2, 'b2' => 3];
        else $values += ['status' => 'user', 'user_amount' => 21, 'gamer_amount' => 5];
        $db->createCommand()->insert('game_' . $kind, $values)->execute();
        $path = 'v1/' . $kind . '/history-kons';
        routeCheck(dispatch('GET', $path)[0] === 401, 'grouped stakes reject guest: ' . $kind);
        list($status, $groups) = dispatch('GET', $path, true, ['period' => 'today', 'filter' => ['kon' => 999]]);
        routeCheck($status === 200 && count($groups) === 1 && (int)$groups[0]['kon'] === 5 && (int)$groups[0]['count'] === 1, 'grouped stakes dispatch: ' . $kind);
        list($status, $games) = dispatch('GET', 'v1/' . $kind . '/history', true, ['period' => 'today', 'filter' => ['kon' => 5], 'envelope' => 1]);
        routeCheck($status === 200 && $games['_meta']['totalCount'] === 1
            && $games['items'][0]['creator']['username'] === 'Donor' && $games['items'][0]['player']['person']['rating'] === 2.0, 'history filters and public objects survive REST dispatch: ' . $kind);
        routeCheck(dispatch('GET', $path, true, ['period' => 'invalid'])[0] === 400, 'grouped period validation: ' . $kind);
    }
    routeCheck(dispatch('POST', 'v1/forum-comment/1/gift')[0] === 401, 'gift route requires authentication');
    routeCheck(dispatch('GET', 'v1/forum-comment/1/gift', true)[0] === 404, 'GET cannot trigger a gift');
    list($status, $gift) = dispatch('POST', 'v1/forum-comment/1/gift', true);
    routeCheck($status === 200 && $gift['giftedByMe'] && $gift['credit'] === 1.0, 'authenticated POST invokes gift service');
    routeCheck(dispatch('POST', 'v1/forum-comment/1/gift', true)[1]['alreadyGiven'], 'HTTP retry is idempotent');
    list($status, $comments) = dispatch('GET', 'v1/forum-comment', true, ['envelope' => '1', 'expand' => 'user']);
    routeCheck($status === 200 && $comments['items'][0]['gifted_by_me'], 'optional auth resolves viewer fields on public comments');
    routeCheck(!isset($comments['items'][0]['user']['email']) && !isset($comments['items'][0]['user']['person']['credit']), 'expanded forum author does not expose private data');
    list($status, $gifts) = dispatch('GET', 'v1/forum-comment/1/gifts', false, ['envelope' => '1']);
    routeCheck($status === 200 && $gifts['items'][0]['username'] === 'Donor', 'public donor list resolves exact nested path');
    routeCheck(dispatch('OPTIONS', 'v1/forum-comment/1/gift')[0] === 200, 'gift preflight does not require credentials');
    routeCheck(dispatch('POST', 'v1/users/heartbeat')[0] === 401, 'heartbeat requires authentication');
    routeCheck(dispatch('POST', 'v1/users/heartbeat', true)[1]['count'] === 1, 'heartbeat activity is visible in online count');
    routeCheck((int)$db->createCommand('SELECT COUNT(*) FROM history_balance')->queryScalar() === 2, 'full dispatch retry produced one exact gift history pair');

    $db->createCommand("INSERT INTO forum_theme VALUES (2,1,'Own topic',2,2,0)")->execute();
    routeCheck(dispatch('POST', 'v1/users/heartbeat')[0] === 401, 'legacy heartbeat still requires authentication');
    list($status, $heartbeat) = dispatch('POST', 'v1/users/heartbeat', true);
    routeCheck($status === 200 && $heartbeat === ['count' => 1, 'windowSeconds' => 300],
        'authenticated heartbeat succeeds without the presence migration');
    list($status, $themes) = dispatch('GET', 'v1/forum-theme/my', true, ['page' => '1', 'sort' => '-id', 'envelope' => '1']);
    routeCheck($status === 200 && array_column($themes['items'], 'id') === [2] && $themes['_meta']['totalCount'] === 1,
        'exact my topics request succeeds and excludes another author on legacy schema');
    list($status, $online) = dispatch('GET', 'v1/users/online', false, ['envelope' => '1']);
    routeCheck($status === 200 && array_column($online['items'], 'id') === [1]
        && $online['_meta']['totalCount'] === $heartbeat['count'], 'public online list and count agree on legacy schema');
    $now = time();
    $presence = new \common\services\user\PresenceService();
    $presence->touch(2, $now - 300);
    routeCheck(in_array(2, \common\services\user\PresenceService::onlineIds($now), true), 'activity at five-minute boundary is included');
    routeCheck(!in_array(2, \common\services\user\PresenceService::onlineIds($now + 1), true), 'activity older than five minutes is excluded');
    $presence->touch(1, $now - 400);
    routeCheck(in_array(1, \common\services\user\PresenceService::onlineIds($now), true), 'older requests cannot regress cached presence');
    routeCheck(array_column(dispatch('GET', 'v1/users/online', false, ['q' => 'Donor', 'envelope' => '1'])[1]['items'], 'id') === [1],
        'legacy online list applies server search');
    list($dayStart, $dayEnd) = \common\services\user\PresenceService::dayBounds($now);
    $noon = $dayStart + 43200;
    $presence->touch(999, $noon - 600);
    routeCheck(in_array(999, \common\services\user\PresenceService::todayIds($noon), true)
        && !in_array(999, \common\services\user\PresenceService::onlineIds($noon), true), 'daily visitors outlive the online window');
    routeCheck(!in_array(999, \common\services\user\PresenceService::todayIds($dayEnd), true), 'daily visitors reset at Moscow midnight');
    $homeTransaction = $db->beginTransaction();
    try {
        $db->createCommand()->update('user', ['last_login_at' => $now], ['id' => 2])->execute();
        list($status, $visitors) = dispatch('GET', 'v1/users/visited', false, ['envelope' => '1', 'per-page' => '1']);
        routeCheck($status === 200 && $visitors['_meta']['totalCount'] === 2 && count($visitors['items']) === 1,
            'daily visitors combine login and activity with bounded pagination');
        routeCheck(array_column(dispatch('GET', 'v1/users/visited', false, ['envelope' => '1', 'q' => 'Author'])[1]['items'], 'id') === [2],
            'daily visitors support user search');
        foreach ([[$dayStart, 1, 'everyday'], [$dayStart - 1, 4, 'everyday'], [$dayStart, 9, 'gift'], [$dayEnd, 5, 'everyday']] as $record) {
            $db->createCommand()->insert('history_balance', ['user_id' => 1, 'created_at' => $record[0], 'credit_up' => $record[1], 'type' => $record[2]])->execute();
        }
        list($status, $recipients) = dispatch('GET', 'v1/bonus/recipients', false, ['envelope' => '1', 'q' => 'Donor']);
        $recipient = $recipients['items'][0];
        routeCheck($status === 200 && $recipients['_meta']['totalCount'] === 1 && (float)$recipient['amount'] === 1.0
            && $recipient['user']['username'] === 'Donor', 'recipients show only actual positive daily credits for today');
        routeCheck(!isset($recipient['user']['email']) && !isset($recipient['user']['auth_key'])
            && !isset($recipient['user']['person']['credit']) && !isset($recipient['user']['person']['balance']), 'bonus recipients never expose private account data');
        $db->createCommand('CREATE TABLE comission (id INTEGER PRIMARY KEY, type TEXT, amount NUMERIC, created_at INTEGER)')->execute();
        foreach ([[$dayStart - 86400, 10, 'credit'], [$dayStart, 2, 'game_five'], [$dayStart, 999, 'game_saper'], [$dayStart, 999, 'exchange'], [$dayEnd, 999, 'credit'], [$dayStart - 86401, 999, 'credit']] as $row) {
            $db->createCommand()->insert('comission', ['created_at' => $row[0], 'amount' => $row[1], 'type' => $row[2]])->execute();
        }
        list($status, $fund) = dispatch('GET', 'v1/bonus/fund');
        routeCheck($status === 200 && (float)$fund['today'] === 10.0 && (float)$fund['tomorrow'] === 2.0 && $fund['user_today'] === null,
            'public fund respects day bounds, credit currency and guest privacy');
        routeCheck(dispatch('GET', 'v1/bonus/my-fund')[0] === 401, 'personal prize estimate requires authentication');
        list($status, $fund) = dispatch('GET', 'v1/bonus/my-fund', true);
        routeCheck($status === 200 && (float)$fund['user_today'] === 4.0 && (float)$fund['user_tomorrow'] === 1.0,
            'personal prize estimate uses the current authenticated rating share');
        $db->createCommand()->update('persone', ['rating' => 0])->execute();
        routeCheck((float)dispatch('GET', 'v1/bonus/my-fund', true)[1]['user_today'] === 0.0, 'zero total rating never divides by zero');
    } finally { $homeTransaction->rollBack(); }
    // Even an unexpected presence storage failure must not break unrelated protected endpoints.
    $runtime = Yii::getAlias('@runtime');
    Yii::setAlias('@runtime', null);
    try {
        routeCheck(dispatch('GET', 'v1/forum-theme/my', true, ['envelope' => '1'])[0] === 200,
            'presence write failure does not invalidate an authenticated forum request');
    } finally {
        Yii::setAlias('@runtime', $runtime);
    }
    require __DIR__ . '/forum-batch-cases.php';
    echo "API routing integration passed on disposable SQLite.\n";
} finally {
    if (isset($db)) $db->close();
    if (is_file($file)) unlink($file);
}
