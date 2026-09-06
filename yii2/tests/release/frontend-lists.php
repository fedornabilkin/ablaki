<?php

// Disposable SQLite only: no application .env or real API credentials are loaded.
defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
error_reporting(E_ALL & ~E_DEPRECATED);

use api\components\ApiList;
use api\components\ListSerializer;
use api\modules\v1\controllers\HistoryController;
use api\modules\v1\controllers\StatController;
use api\modules\v1\controllers\TipsController;
use api\modules\v1\controllers\UserController;
use common\modules\exchange\api\controllers\ExchangeController;
use common\modules\exchange\api\controllers\TransferController;
use common\services\user\PresenceService;
use yii\db\Query;
use yii\web\Application;
use yii\web\IdentityInterface;

class ListTestIdentity implements IdentityInterface
{
    public $id = 1;
    public function getId() { return $this->id; }
    public static function findIdentity($id) { return null; }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getAuthKey() { return ''; }
    public function validateAuthKey($authKey) { return false; }
}

function checkList(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo 'PASS ' . $label . PHP_EOL;
}

function params(array $params): void
{
    Yii::$app->request->setQueryParams($params);
}

function serialized($provider): array
{
    return (new ListSerializer())->serialize($provider);
}

$file = tempnam(sys_get_temp_dir(), 'ablakin-lists-');
try {
    $app = new Application([
        'id' => 'list-test', 'basePath' => dirname(__DIR__, 2), 'vendorPath' => dirname(__DIR__, 2) . '/vendor',
        'runtimePath' => sys_get_temp_dir() . '/ablakin-list-presence-' . uniqid(),
        'modules' => ['user' => ['class' => dektrium\user\Module::class]],
        'components' => [
            'db' => ['class' => yii\db\Connection::class, 'dsn' => 'sqlite:' . $file],
            'request' => ['cookieValidationKey' => 'temporary-test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php', 'hostInfo' => 'http://test.invalid'],
            'user' => ['identityClass' => ListTestIdentity::class, 'enableSession' => false],
            'urlManager' => ['enablePrettyUrl' => true, 'showScriptName' => false],
            'cache' => ['class' => yii\caching\ArrayCache::class],
            'i18n' => ['translations' => ['*' => ['class' => yii\i18n\PhpMessageSource::class, 'basePath' => '@api/messages']]],
        ],
    ]);
    $app->controller = new yii\rest\Controller('test', $app);
    $config = require dirname(__DIR__, 2) . '/api/config/main.php';
    $db = $app->db;
    $db->open();
    $db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT, email TEXT, created_at INTEGER, last_login_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE persone (id INTEGER PRIMARY KEY, user_id INTEGER, refovod INTEGER, rating NUMERIC, bonus_count INTEGER, description TEXT, balance NUMERIC, credit NUMERIC)')->execute();
    $db->createCommand('CREATE TABLE history_balance (id INTEGER PRIMARY KEY, user_id INTEGER, balance NUMERIC, credit NUMERIC, balance_up NUMERIC, credit_up NUMERIC, type TEXT, comment TEXT, created_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE history_rating (id INTEGER PRIMARY KEY, user_id INTEGER, rating NUMERIC, rating_up NUMERIC, type TEXT, comment TEXT, created_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE fact (id INTEGER PRIMARY KEY, title TEXT, type TEXT, hide INTEGER)')->execute();
    $db->createCommand('CREATE TABLE credit_transfer (id INTEGER PRIMARY KEY, user_id INTEGER, user_buyer INTEGER, amount NUMERIC, password TEXT, created_at INTEGER, updated_at INTEGER)')->execute();
    $db->createCommand('CREATE TABLE credit_exchange (id INTEGER PRIMARY KEY, user_id INTEGER, user_buyer INTEGER, amount NUMERIC, credit NUMERIC, type TEXT, created_at INTEGER, updated_at INTEGER)')->execute();
    $now = time();
    for ($id = 1; $id <= 45; $id++) {
        $db->createCommand()->insert('user', ['id' => $id, 'username' => 'Member' . $id, 'email' => 'private' . $id . '@test.invalid', 'created_at' => $now, 'last_login_at' => 123])->execute();
        $db->createCommand()->insert('persone', ['id' => $id, 'user_id' => $id, 'refovod' => $id % 2 ? 1 : 2, 'rating' => $id, 'bonus_count' => 0, 'description' => '', 'balance' => 100, 'credit' => 100])->execute();
    }
    $app->user->setIdentity(new ListTestIdentity());
    params(['envelope' => '1', 'page' => '2']);
    $users = new UserController('users', $app);
    $page = serialized($users->actionIndex());
    checkList(count($page['items']) === 20 && (int)$page['items'][0]['id'] === 25, 'users are newest-first across actual second page');
    checkList($page['_meta'] === ['totalCount' => 45, 'pageCount' => 3, 'currentPage' => 2, 'perPage' => 20], 'envelope contains real count and page count');
    checkList(!isset($page['items'][0]['email']) && !isset($page['items'][0]['person']['credit']), 'public users do not expose other accounts private fields');
    params(['q' => 'mEmBeR4', 'envelope' => '1', 'per-page' => '2']);
    $page = serialized($users->actionIndex());
    checkList($page['_meta']['totalCount'] === 7 && $page['_meta']['pageCount'] === 4, 'case-insensitive search is applied before pagination/count');
    params(['q' => '%', 'envelope' => '1']);
    checkList(serialized($users->actionIndex())['_meta']['totalCount'] === 0, 'wildcards are escaped, not interpreted as a full-table query');
    params(['sort' => 'id']);
    $legacy = serialized($users->actionLast());
    checkList(isset($legacy[0]) && !isset($legacy['items']) && (int)$legacy[0]['id'] === 1, 'legacy arrays and explicit URL sorting remain supported');
    params(['q' => ['bad']]);
    try { $users->actionIndex(); throw new RuntimeException('Search array must fail'); }
    catch (yii\web\BadRequestHttpException $expected) { echo "PASS malformed query rejected\n"; }
    params(['envelope' => '1', 'per-page' => '1000']);
    checkList(serialized($users->actionIndex())['_meta']['perPage'] === 100, 'page size is capped');
    params(['envelope' => '1', 'q' => 'Member4']);
    $referrals = serialized($users->actionReferrals());
    checkList(array_column($referrals['items'], 'id') === [45, 43, 41], 'referral query and search are scoped to current inviter');

    $presence = new PresenceService();
    $presence->touch(1, $now);
    $presence->touch(1, $now - 100);
    $presence->touch(2, $now - 400);
    $presence->touch(3, $now - 10);
    checkList($users->actionOnlineCount()['count'] === 2, 'online count excludes stale activity');
    checkList((int)$db->createCommand('SELECT last_login_at FROM user WHERE id=1')->queryScalar() === 123, 'presence does not alter last login');
    checkList(in_array(1, PresenceService::onlineIds($now + 300), true), 'older requests cannot regress presence');
    params(['q' => 'Member3', 'envelope' => '1']);
    checkList(serialized($users->actionOnline())['_meta']['totalCount'] === 1, 'online list is searchable and paginated');
    $onlineUser = serialized($users->actionOnline())['items'][0];
    checkList($onlineUser['is_online'] === true, 'user DTO exposes actual online presence');
    params(['envelope' => '1', 'q' => 'Member2']);
    checkList(serialized($users->actionIndex())['items'][0]['is_online'] === false, 'inactive users are not marked online');

    $db->createCommand("INSERT INTO history_balance VALUES (1,1,5,2,1,0,'game','win',1),(2,2,9,3,1,0,'game','win',2),(3,1,7,4,1,0,'everyday','bonus',3)")->execute();
    $db->createCommand("INSERT INTO history_rating VALUES (1,1,5,1,'rating','earned',1),(2,2,6,1,'private','other',2)")->execute();
    $history = new HistoryController('history', $app);
    params(['envelope' => '1', 'filter' => ['type' => 'game'], 'q' => 'WIN']);
    $balance = serialized($history->actionBalance());
    checkList(count($balance['items']) === 1 && isset($balance['items'][0]['balance']), 'balance selects balance model and enforces owner/type/search together');
    params(['envelope' => '1']);
    $rating = serialized($history->actionRating());
    checkList(count($rating['items']) === 1 && isset($rating['items'][0]['rating']), 'rating selects rating model and enforces owner');
    checkList($history->actionRatingType() === [['type' => 'rating', 'count' => 1]], 'history type options cannot reveal other users types');

    $db->createCommand("INSERT INTO fact VALUES (1,'Visible tip','info',0),(2,'Hidden tip','info',1)")->execute();
    $tips = new TipsController('tips', $app);
    checkList($tips->actionRandom()['title'] === 'Visible tip', 'random tip uses only visible existing facts');
    $db->createCommand('UPDATE fact SET hide=1')->execute();
    checkList($tips->actionRandom() === null, 'empty tips are represented as null without a made-up fallback');

    params(['envelope' => '1', 'q' => 'Member4', 'per-page' => '2']);
    $stat = new StatController('stat', $app);
    $ranking = serialized($stat->actionTop('all'));
    checkList($ranking['_meta']['totalCount'] === 7 && $ranking['items'][0]['username'] === 'Member45', 'statistics ranking searches all users and sorts scores descending');

    $db->createCommand("INSERT INTO credit_transfer VALUES (1,1,0,2,'private-code',1,1),(2,2,0,3,'not-yours',2,2),(3,2,1,4,'used-code',3,3)")->execute();
    params(['envelope' => '1', 'q' => 'Member1']);
    $transfers = new TransferController('transfer', $app);
    $action = $transfers->actions()['index'];
    $transferPage = serialized($action['prepareDataProvider'](null, null));
    checkList(count($transferPage['items']) === 1 && $transferPage['items'][0]['amount'] == 2, 'transfer search preserves owner scope and serializes amount');
    params(['envelope' => '1']);
    $action = $transfers->actions()['history'];
    $transferPage = serialized($action['prepareDataProvider'](null, null));
    checkList($transferPage['items'][0]['password'] === null, 'received transfer history does not expose creator codes');

    $db->createCommand("INSERT INTO credit_exchange VALUES (1,1,0,2,2,'buy',1,1),(2,2,0,3,3,'buy',2,2),(3,3,0,4,4,'sell',3,3)")->execute();
    params(['envelope' => '1', 'q' => 'Member2']);
    $exchange = new ExchangeController('exchange', $app);
    $action = $exchange->actions()['index'];
    $exchangePage = serialized($action['prepareDataProvider'](null, ['type' => 'buy']));
    checkList(count($exchangePage['items']) === 1 && (int)$exchangePage['items'][0]['id'] === 2
        && $exchangePage['items'][0]['username'] === 'Member2' && $exchangePage['items'][0]['username_client'] === null,
        'exchange applies owner exclusion, type and server user search with safe participant names');

    params(['envelope' => '1', 'sort' => '-created_at', 'per-page' => '2']);
    checkList(array_column(serialized($users->actionIndex())['items'], 'id') === [45, 44], 'equal dates use a stable newest-first tie break');
    params(['envelope' => '1', 'q' => 'Member1']);
    $db->createCommand()->insert('history_rating', ['id' => 3, 'user_id' => 1, 'rating' => 7, 'rating_up' => 2, 'type' => 'rating', 'comment' => '', 'created_at' => time()])->execute();
    checkList((float)serialized($stat->actionTop('day'))['items'][0]['rating'] === 2.0, 'period statistics search the aggregate server-side');

    Yii::configure(Yii::$container, $config['container']);
    checkList(Yii::createObject(yii\rest\Serializer::class) instanceof ListSerializer, 'API config actually wires envelope serializer into REST controllers');
    $routes = new yii\web\UrlManager([
        'enablePrettyUrl' => true, 'enableStrictParsing' => true, 'showScriptName' => false,
        'rules' => require dirname(__DIR__, 2) . '/api/modules/v1/config/urlRules.php',
    ]);
    foreach (['v1/users' => 'v1/user/index', 'v1/users/referrals' => 'v1/user/referrals',
        'v1/users/online-count' => 'v1/user/online-count', 'v1/tips/random' => 'v1/tips/random',
        'v1/stat/top' => 'v1/stat/top', 'v1/users/wall/test.member-1' => 'v1/user/wall'] as $path => $route) {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $app->request->setPathInfo($path);
        checkList($routes->parseRequest($app->request)[0] === $route, 'GET route resolves: ' . $path);
    }
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $app->request->setPathInfo('v1/users/heartbeat');
    checkList($routes->parseRequest($app->request)[0] === 'v1/user/heartbeat', 'heartbeat is a POST route');
    for ($id = 1; $id <= 45; $id++) $presence->touch($id, time());
    params(['all' => '1']);
    $allOnline = serialized($users->actionOnline());
    checkList(count($allOnline) === 45 && $allOnline[0]['id'] === 45,
        'online modal receives every active user beyond the default page size');
    checkList(!isset($allOnline[0]['email']) && !isset($allOnline[0]['person']['balance']),
        'full online list preserves public-field privacy');
    echo "All frontend list checks passed.\n";
} finally {
    if (isset($db)) $db->close();
    if (file_exists($file)) unlink($file);
}
