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
        $data = $app->runAction($route[0], $route[1]);
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
    list($status, $ranking) = dispatch('GET', 'v1/stat/top', false, ['envelope' => '1', 'q' => 'Author']);
    routeCheck($status === 200 && $ranking['_meta']['totalCount'] === 1 && $ranking['items'][0]['username'] === 'Author', 'public ranking exact path dispatches searchable envelope');
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
    // Even an unexpected presence storage failure must not break unrelated protected endpoints.
    $runtime = Yii::getAlias('@runtime');
    Yii::setAlias('@runtime', null);
    try {
        routeCheck(dispatch('GET', 'v1/forum-theme/my', true, ['envelope' => '1'])[0] === 200,
            'presence write failure does not invalidate an authenticated forum request');
    } finally {
        Yii::setAlias('@runtime', $runtime);
    }
    echo "API routing integration passed on disposable SQLite.\n";
} finally {
    if (isset($db)) $db->close();
    if (is_file($file)) unlink($file);
}
