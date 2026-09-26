<?php
// Isolated SQLite or allowlisted CI databases only; never loads application configuration.
define('YII_ENABLE_ERROR_HANDLER', false);
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
require __DIR__ . '/worker-barrier.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
error_reporting(E_ALL & ~E_DEPRECATED);
use common\services\user\UserActivity;
use common\services\user\PresenceService;
use common\services\user\InactiveRatingService;
use yii\db\Query;

class ActivityIdentity implements \yii\web\IdentityInterface {
    public static function findIdentity($id) { return (int)$id === 1 ? new self : null; }
    public static function findIdentityByAccessToken($token, $type = null) { return $token === 'activity-token' ? new self : null; }
    public function getId() { return 1; }
    public function getAuthKey() { return 'activity-token'; }
    public function validateAuthKey($key) { return $key === 'activity-token'; }
}
class ActivityTestController extends \yii\rest\Controller {
    use \api\modules\v1\traites\AuthTrait;
    public function authExceptAction(): array { return ['read']; }
    public function actionRead() { return ['public' => true]; }
    public function actionPrivate() { return ['private' => true]; }
}
$dsn = getenv('ABL_TEST_DSN');
if ($dsn && !preg_match('/^(mysql|pgsql):host=127\.0\.0\.1;port=(3306|5432);dbname=ablakin_ci$/D', $dsn)) throw new RuntimeException('Dedicated CI DB required.');
$app = new \yii\web\Application([
    'id' => 'activity-tests', 'basePath' => dirname(__DIR__, 2),
    'bootstrap' => [\common\services\user\ActivityBootstrap::class],
    'controllerMap' => ['activity-test' => ActivityTestController::class],
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => $dsn ?: 'sqlite::memory:', 'username' => $dsn ? 'ablakin_ci' : null, 'password' => $dsn ? 'ci-only-password' : null, 'tablePrefix' => 'activity71_', 'charset' => 'utf8'],
        'request' => ['cookieValidationKey' => 'local-test', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php', 'hostInfo' => 'http://test.invalid'],
        'user' => ['identityClass' => ActivityIdentity::class, 'enableSession' => false],
    ],
]);
$db = $app->db;
$service = new InactiveRatingService($db);
if (($argv[1] ?? '') === 'worker') {
    $db->open(); workerReady();
    if ($argv[2] === 'touch') { UserActivity::touch($db, (int)$argv[3], (int)$argv[4]); echo 'touch'; }
    else echo $service->apply((int)$argv[3], (int)$argv[4]) ? 'deducted' : 'skipped';
    exit;
}
function checkActivity($ok, $message) { if (!$ok) throw new RuntimeException($message); echo 'PASS ' . $message . PHP_EOL; }
function rowActivity($id) { return (new Query())->from('{{%user}}')->where(['id' => $id])->one(); }
function ratingActivity($id) { return (float)(new Query())->select('rating')->from('{{%persone}}')->where(['user_id' => $id])->scalar(); }
$now = time(); $day = 86400;
foreach (['history_rating', 'persone', 'user'] as $table) if ($db->getTableSchema('{{%' . $table . '}}', true)) $db->createCommand()->dropTable('{{%' . $table . '}}')->execute();
$db->createCommand()->createTable('{{%user}}', ['id' => 'pk', 'created_at' => 'integer', 'last_login_at' => 'integer', 'password_hash' => 'string', 'salt' => 'string', 'auth_key' => 'string'])->execute();
$db->createCommand()->createTable('{{%persone}}', ['id' => 'pk', 'user_id' => 'integer UNIQUE', 'rating' => 'double', 'credit' => 'double'])->execute();
$db->createCommand()->createTable('{{%history_rating}}', ['id' => 'pk', 'user_id' => 'integer', 'rating' => 'double', 'rating_up' => 'double', 'type' => 'string', 'comment' => 'string', 'created_at' => 'integer'])->execute();
for ($id = 1; $id <= 10; $id++) {
    $db->createCommand()->insert('{{%user}}', ['id' => $id, 'created_at' => $now - 400 * $day, 'last_login_at' => $now - 200 * $day, 'password_hash' => 'unchanged-hash', 'salt' => 'abc', 'auth_key' => 'unchanged-key'])->execute();
    $db->createCommand()->insert('{{%persone}}', ['id' => $id, 'user_id' => $id, 'rating' => 100, 'credit' => 50])->execute();
}
$db->createCommand()->update('{{%user}}', ['created_at' => 0, 'last_login_at' => 0], ['id' => 10])->execute();
require dirname(__DIR__, 2) . '/console/migrations/m260924_120000_user_activity.php';
$migration = new \m260924_120000_user_activity(['db' => $db]);
ob_start(); $migration->up(); ob_end_clean();
checkActivity(rowActivity(1)['latest_activity'] === UserActivity::date($now - 200 * $day), 'test schema migration initializes activity from last known visit');
checkActivity(UserActivity::timestamp(rowActivity(10)['latest_activity']) >= $now, 'unknown activity starts a safe new observation period');
UserActivity::touch($db, 1, $now);
UserActivity::touch($db, 1, $now + 1);
UserActivity::touch($db, 1, $now - 20);
checkActivity(rowActivity(1)['latest_activity'] === UserActivity::date($now + 1), 'every action updates within a minute and older requests cannot rewind activity');
$snapshot = rowActivity(1);
ob_start(); $migration->up(); ob_end_clean();
checkActivity(rowActivity(1) === $snapshot, 'repeated migration preserves existing production-shaped activity and credentials');
checkActivity(!$service->apply(1, $now), 'active user is protected');
$db->createCommand()->update('{{%user}}', ['latest_activity' => UserActivity::date($now - 180 * $day)], ['id' => 2])->execute();
checkActivity(!$service->apply(2, $now - 1) && $service->apply(2, $now) && ratingActivity(2) === 90.0, 'first 10 percent deduction occurs exactly at 180 days');
checkActivity(!$service->apply(2, $now) && !$service->apply(2, $now + 30 * $day - 1), 'retries and early runs do not deduct again');
checkActivity($service->apply(2, $now + 30 * $day) && ratingActivity(2) === 81.0, 'next pass at 30 days takes 10 percent of current rating');
UserActivity::touch($db, 2, $now + 31 * $day);
checkActivity(!$service->apply(2, $now + 211 * $day - 1) && $service->apply(2, $now + 211 * $day), 'returning activity restarts the full 180-day period');
$db->createCommand()->update('{{%persone}}', ['rating' => 5.2], ['user_id' => 3])->execute();
checkActivity($service->apply(3, $now) && ratingActivity(3) === 5.0 && !$service->apply(3, $now + 30 * $day), 'minimum rating is 5 and later passes do not lower it');
$db->createCommand()->update('{{%persone}}', ['rating' => 4], ['user_id' => 4])->execute();
checkActivity(!$service->apply(4, $now) && ratingActivity(4) === 4.0, 'ratings below 5 remain unchanged');
$db->createCommand()->update('{{%user}}', ['latest_activity' => null], ['id' => 5])->execute();
checkActivity(!$service->apply(5, $now), 'unknown legacy activity never incurs a penalty');
$history = (new Query())->from('{{%history_rating}}')->where(['user_id' => 3])->one();
checkActivity($history['type'] === 'inactivity' && abs($history['rating_up'] + .2) < .00001 && strpos($history['comment'], '10%') !== false && strpos($history['comment'], rowActivity(3)['latest_activity']) !== false, 'history contains actual deduction, final rating and reason');
checkActivity((float)(new Query())->from('{{%persone}}')->sum('credit') === 500.0 && rowActivity(1)['auth_key'] === 'unchanged-key' && rowActivity(1)['password_hash'] === 'unchanged-hash' && rowActivity(1)['salt'] === 'abc', 'credit and authentication data are unchanged');
// A journal failure must roll back both the rating and the next-pass timestamp.
if (!$dsn) {
    $db->pdo->exec("CREATE TRIGGER activity_fail BEFORE INSERT ON activity71_history_rating WHEN NEW.user_id = 6 BEGIN SELECT RAISE(ABORT, 'journal failure'); END");
    try { $service->apply(6, $now); throw new RuntimeException('Expected journal failure'); } catch (\yii\db\Exception $expected) {}
    checkActivity(ratingActivity(6) === 100.0 && rowActivity(6)['inactive_rating_at'] === null, 'failed audit insertion rolls back the entire pass');
    $db->pdo->exec('DROP TRIGGER activity_fail');
}
// Full application event + public route: authentication is not required for the page.
$db->createCommand()->update('{{%user}}', ['latest_activity' => UserActivity::date($now - 200 * $day)], ['id' => 1])->execute();
$app->request->headers->set('Authorization', 'Bearer activity-token');
$app->runAction('activity-test/read');
checkActivity(UserActivity::timestamp(rowActivity(1)['latest_activity']) >= $now && $app->user->isGuest, 'public token-authenticated action records activity without changing login state');
$app->request->headers->set('Authorization', 'Bearer invalid');
$app->runAction('activity-test/read');
$app->request->headers->set('Authorization', 'Bearer activity-token');
$app->runAction('activity-test/private');
checkActivity(!$app->user->isGuest && UserActivity::timestamp(rowActivity(1)['latest_activity']) >= $now, 'protected API action records activity through the existing authentication filter');
$app->user->setIdentity(null);
$db->createCommand()->update('{{%user}}', ['latest_activity' => UserActivity::date($now - $day)], ['id' => 1])->execute();
$app->user->login(new ActivityIdentity());
checkActivity(UserActivity::timestamp(rowActivity(1)['latest_activity']) >= $now, 'session login records activity');
checkActivity(in_array(1, PresenceService::onlineIds(), true) && in_array(1, PresenceService::todayIds(), true), 'online and daily visitors use durable database activity');
if ($dsn) {
    $children = [];
    for ($i = 0; $i < 5; $i++) {
        $pipes = []; $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker penalty 7 ' . $now;
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Cannot start worker.');
        $children[] = [$proc, $pipes];
    }
    releaseWorkers($children); $deductions = 0;
    foreach ($children as list($proc, $pipes)) {
        $result = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($proc) !== 0 || !in_array($result, ['deducted', 'skipped'], true)) throw new RuntimeException($result . $error);
        if ($result === 'deducted') $deductions++;
    }
    checkActivity($deductions === 1 && ratingActivity(7) === 90.0, 'concurrent MySQL/PostgreSQL passes deduct only once');
    // Hold a row while the worker starts; it must re-read after the lock is released.
    foreach ([8 => 'activity', 9 => 'rating'] as $id => $change) {
        $tx = $db->beginTransaction();
        $lock = new \common\services\user\CreditLedger($db);
        $lock->lock($change === 'activity' ? '{{%user}}' : '{{%persone}}', $change === 'activity' ? ['id' => $id] : ['user_id' => $id]);
        $pipes = []; $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' worker penalty ' . $id . ' ' . $now;
        $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) throw new RuntimeException('Cannot start worker.');
        releaseWorkers([[$proc, $pipes]]);
        if ($change === 'activity') UserActivity::touch($db, $id, $now);
        else $db->createCommand()->update('{{%persone}}', ['rating' => 120], ['user_id' => $id])->execute();
        $tx->commit();
        $result = trim(stream_get_contents($pipes[1])); $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        checkActivity(proc_close($proc) === 0 && $result === ($change === 'activity' ? 'skipped' : 'deducted') && ratingActivity($id) === ($change === 'activity' ? 100.0 : 108.0), 'concurrent ' . $change . ' update is preserved: ' . $error);
    }
}
$service->run($now);
checkActivity($service->run($now) === 0, 'batch cursor processes all due users without repeat deductions');
foreach (['history_rating', 'persone', 'user'] as $table) $db->createCommand()->dropTable('{{%' . $table . '}}')->execute();
// Production already has NOT NULL DATETIME: preserve its definition, data and existing index.
$db->createCommand()->createTable('{{%user}}', ['id' => 'pk', 'created_at' => 'integer', 'last_login_at' => 'integer', 'latest_activity' => 'datetime NOT NULL'])->execute();
$db->createCommand()->createIndex('latest_activity', '{{%user}}', 'latest_activity')->execute();
$legacyDate = '2020-06-15 18:25:33';
$db->createCommand()->insert('{{%user}}', ['id' => 1, 'created_at' => 1, 'last_login_at' => $now, 'latest_activity' => $legacyDate])->execute();
ob_start(); $migration->up(); $migration->up(); ob_end_clean();
checkActivity(rowActivity(1)['latest_activity'] === $legacyDate && !$db->getTableSchema('{{%user}}', true)->columns['latest_activity']->allowNull, 'production NOT NULL DATETIME and its existing value survive both migration runs');
$db->createCommand()->dropTable('{{%user}}')->execute();
