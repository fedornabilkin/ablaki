<?php

defined('YII_DEBUG') or define('YII_DEBUG', true);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');

use common\modules\games\apiControllers\OrelController;
use common\modules\games\apiControllers\SaperController;
use common\modules\games\models\GameOrel;
use common\modules\games\models\GameSaper;
use common\modules\games\service\GameOverview;

function check($condition, $message)
{
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS ' . $message . "\n";
}

$app = new \yii\console\Application([
    'id' => 'game-overview-test',
    'basePath' => dirname(__DIR__, 2),
    'timeZone' => 'Europe/Moscow',
    'modules' => ['user' => ['class' => \dektrium\user\Module::class]],
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
        'request' => ['class' => \yii\web\Request::class, 'cookieValidationKey' => 'test-only', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php'],
        'user' => ['class' => \yii\web\User::class, 'identityClass' => \common\models\user\User::class, 'enableSession' => false],
        'i18n' => ['translations' => ['*' => ['class' => \yii\i18n\PhpMessageSource::class, 'basePath' => '@common/messages']]],
    ],
]);
$identity = new class extends \yii\base\BaseObject implements \yii\web\IdentityInterface {
    public static function findIdentity($id) { return null; }
    public static function findIdentityByAccessToken($token, $type = null) { return null; }
    public function getId() { return 1; }
    public function getAuthKey() { return ''; }
    public function validateAuthKey($authKey) { return false; }
};
$app->user->setIdentity($identity);
$db = $app->db;
$db->createCommand('CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT)')->execute();
$db->createCommand("INSERT INTO user VALUES (1, 'First'), (2, 'Second'), (3, 'Third')")->execute();
$db->createCommand('CREATE TABLE game_orel (id INTEGER PRIMARY KEY, user_id INTEGER, user_gamer INTEGER,
    kon NUMERIC, type INTEGER, hod INTEGER, created_at INTEGER, updated_at INTEGER)')->execute();
$db->createCommand('CREATE TABLE game_saper (id INTEGER PRIMARY KEY, user_id INTEGER, user_gamer INTEGER,
    kon NUMERIC, etap INTEGER, created_at INTEGER, time_over_at INTEGER)')->execute();
$db->createCommand('CREATE TABLE history_balance (id INTEGER PRIMARY KEY, user_id INTEGER, type TEXT,
    balance_up NUMERIC, credit_up NUMERIC, created_at INTEGER)')->execute();
$from = (new DateTimeImmutable('2026-09-05 00:00:00', new DateTimeZone('Europe/Moscow')))->getTimestamp();
$until = $from + 86400;
$orel = [
    [1, 1, 2, 10, 1, 1, $from, $from + 1], [2, 1, 2, 10, 1, 2, $from, $from + 2],
    [3, 2, 1, 10, 1, 1, $from, $from + 3], [4, 2, 1, 10, 1, 2, $from, $from + 4],
    [5, 1, 0, 3, 1, 0, $from, $from], [6, 1, 0, 7, 1, 0, $from, $from],
    [7, 3, 2, 10, 1, 1, $from, $from + 2], [8, 1, 2, 10, 1, 2, $from - 2, $from - 1],
    [9, 1, 2, 10, 1, 2, $until, $until], [10, 1, 2, 10, 1, 0, $from, $from],
    [11, 2, 0, 4, 1, 0, $from, $from], [12, 3, 0, 4, 1, 0, $from, $from],
];
$db->createCommand()->batchInsert('game_orel', ['id', 'user_id', 'user_gamer', 'kon', 'type', 'hod', 'created_at', 'updated_at'], $orel)->execute();
$db->createCommand()->batchInsert('game_saper', ['id', 'user_id', 'user_gamer', 'kon', 'etap', 'created_at', 'time_over_at'], [
    [1, 1, 2, 1, 0, $from, $from + 1], [2, 1, 2, 1, 10, $from, $from + 2],
    [3, 2, 1, 1, 0, $from, $from + 3], [4, 2, 1, 1, 3, $from, $from + 4],
    [5, 1, 0, 10, 5, $from, $from], [6, 1, 2, 1, 0, $from - 2, $from - 1],
    [7, 3, 2, 1, 0, $from, $from + 1],
])->execute();
$db->createCommand()->batchInsert('history_balance', ['user_id', 'type', 'balance_up', 'credit_up', 'created_at'], [
    [1, 'game_orel', 0, -10, $from], [1, 'game_orel', 0, 18, $from + 1], [1, 'game_orel', 0, -5, $until - 1],
    [1, 'game_orel', 0, 100, $from - 1], [1, 'game_orel', 0, 100, $until],
    [2, 'game_orel', 0, 100, $from], [1, 'bonus', 0, 100, $from],
    [1, 'game_saper', -3, 0, $from], [1, 'game_saper', 1.9, 0, $from + 1],
])->execute();

$summary = GameOverview::summary(GameOrel::class, 1, 'Europe/Moscow', $from + 500);
check($summary['today']['played'] === 4 && $summary['today']['wins'] === 2, 'coin totals distinguish creator and gamer and exclude other users / unfinished games');
check($summary['today']['balance'] === 3.0, 'coin ledger total respects currency, owner, type and both midnight boundaries');
check($summary['own'] === ['count' => 2, 'amount' => 10.0], 'reserved stakes only include own available games');
check($summary['today']['date'] === '2026-09-05' && $summary['today']['timezone'] === 'Europe/Moscow', 'server timezone defines today');
$summary = GameOverview::summary(GameSaper::class, 1, 'Europe/Moscow', $from + 500);
check($summary['today']['played'] === 3 && $summary['today']['wins'] === 2, 'mine totals exclude started but incomplete games');
check(abs($summary['today']['balance'] + 1.1) < 0.00001, 'mine net balance uses the balance ledger');

// Run the same data provider callbacks used by the authenticated REST actions.
$orelController = new OrelController('orel', $app);
$actions = $orelController->actions();
$app->request->setQueryParams(['q' => 'secOND', 'page' => 1, 'per-page' => 1]);
$provider = $actions['index']['prepareDataProvider'](null, ['kon' => 4]);
check($provider->getTotalCount() === 1 && (int)$provider->getModels()[0]->id === 11, 'server search is case-insensitive and preserves free-game and stake filters');
$app->request->setQueryParams(['page' => 1, 'per-page' => 1]);
$provider = $actions['index']['prepareDataProvider'](null, []);
$models = $provider->getModels();
check($provider->getTotalCount() === 2 && $provider->getPagination()->getPageCount() === 2 && (int)$models[0]->id === 12, 'available games are paginated with an exact page count and newest first');
$app->request->setQueryParams(['q' => '11']);
$provider = $actions['index']['prepareDataProvider'](null, []);
check($provider->getTotalCount() === 1 && (int)$provider->getModels()[0]->id === 11, 'game number search executes on the server');
$app->request->setQueryParams(['q' => 'Third']);
$provider = $actions['my']['prepareDataProvider'](null, []);
check($provider->getTotalCount() === 0, 'search cannot include another user\'s games in my list');
$app->request->setQueryParams([]);
$saperController = new SaperController('saper', $app);
$saperActions = $saperController->actions();
$history = $saperActions['history']['prepareDataProvider'](null, [])->getModels();
check(array_map(static function ($game) { return (int)$game->id; }, $history) === [6, 3, 2, 1], 'mine history contains only completed games involving the current account');
$recent = $saperActions['recent']['prepareDataProvider'](null, [])->getModels();
check(array_map(static function ($game) { return (int)$game->id; }, $recent) === [7, 6, 3, 2, 1], 'recent games include all players but never an unfinished field');
$fields = $recent[0]->toArray();
check($fields['win'] === true && $fields['completed_at'] !== null && !isset($fields['pole1']), 'completed mine DTO contains the outcome and time without exposing mines');
$own = $saperActions['my']['prepareDataProvider'](null, [])->getModels()[0]->toArray();
check($own['win'] === null && $own['username_gamer'] === null, 'available game serialization tolerates a missing opponent and does not invent a winner');
$db->createCommand('CREATE TABLE game_duel (id INTEGER PRIMARY KEY, user_id INTEGER, user_gamer INTEGER,
    kon NUMERIC, u1 INTEGER, u2 INTEGER, b1 INTEGER, b2 INTEGER, created_at INTEGER, updated_at INTEGER)')->execute();
$db->createCommand('CREATE TABLE game_five (id INTEGER PRIMARY KEY, user_id INTEGER, user_gamer INTEGER,
    kon NUMERIC, status TEXT, user_amount INTEGER, gamer_amount INTEGER, created_at INTEGER, updated_at INTEGER)')->execute();
foreach (['duel' => \common\modules\games\apiControllers\DuelController::class, 'five' => \common\modules\games\apiControllers\FiveController::class] as $kind => $controllerClass) {
    $defaults = $kind === 'duel' ? ['u1' => 1, 'u2' => 2, 'b1' => 2, 'b2' => 3] : ['status' => 'user', 'user_amount' => 21, 'gamer_amount' => 5];
    foreach ([[1,1,2,5,10], [2,2,1,10,20], [3,2,3,5,30], [4,1,2,5,40], [5,1,0,5,50]] as $row) {
        $values = array_merge($defaults, ['id' => $row[0], 'user_id' => $row[1], 'user_gamer' => $row[2], 'kon' => $row[3], 'created_at' => 1, 'updated_at' => $row[4]]);
        if ($kind === 'five' && $row[0] === 5) $values['status'] = 'free';
        $db->createCommand()->insert('game_' . $kind, $values)->execute();
    }
    $controller = new $controllerClass($kind, $app);
    $prepare = $controller->actions()['history']['prepareDataProvider'];
    $app->request->setQueryParams(['envelope' => '1', 'per-page' => '1', 'page' => '2']);
    $provider = $prepare(null, null);
    $models = $provider->getModels();
    check($provider->getTotalCount() === 3 && $provider->getPagination()->getPageCount() === 3
        && (int)$provider->getModels()[0]->id === 2, $kind . ' history is ordered by completion, paginated and scoped to the current participant');
    $dto = $provider->getModels()[0]->toArray();
    check($dto['username'] === 'Second' && $dto['username_gamer'] === 'First' && (int)$dto['user_gamer'] === 1
        && (int)$dto['kon'] === 10 && (int)$dto['completed_at'] === 20, $kind . ' history exposes both participants, stake and actual completion time');
    check(!isset($dto['auth_key'], $dto['email'], $dto['balance']), $kind . ' history does not expose account credentials or balances');
    $app->request->setQueryParams(['q' => 'seCOND', 'filter' => ['kon' => '5']]);
    $provider = $prepare(null, null);
    check(array_map(static function ($game) { return (int)$game->id; }, $provider->getModels()) === [4, 1], $kind . ' combines player search and stake filter before pagination');
    $app->request->setQueryParams(['q' => '3']);
    check($prepare(null, null)->getTotalCount() === 0, $kind . ' game number search cannot reveal games of other users');
    $app->request->setQueryParams(['q' => 'missing-player']);
    check($prepare(null, null)->getTotalCount() === 0, $kind . ' empty search is successful');
    $app->request->setQueryParams(['filter' => ['kon' => ['bad']]]);
    try { $prepare(null, null); throw new RuntimeException('Invalid stake accepted'); }
    catch (\yii\web\BadRequestHttpException $expected) { echo "PASS malformed history stake rejected\n"; }
}
echo "Read-only checks use SQLite in memory; no live game or account was changed.\n";
