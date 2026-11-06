<?php
// Isolated auth lifecycle tests. No application .env or production database is loaded.
defined('YII_ENABLE_ERROR_HANDLER') || define('YII_ENABLE_ERROR_HANDLER', false);
defined('YII_ENV') || define('YII_ENV', 'test');
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
Yii::setAlias('@common', dirname(__DIR__, 2) . '/common');
Yii::setAlias('@api', dirname(__DIR__, 2) . '/api');
Yii::setAlias('@console', dirname(__DIR__, 2) . '/console');
error_reporting(E_ALL & ~E_DEPRECATED);

use api\modules\v1\models\User;
use api\services\TelegramAccounts;
use api\services\TelegramAuthService;
use common\services\auth\TelegramConfig;
use yii\db\Query;
require __DIR__ . '/worker-barrier.php';

function tgCheck(bool $ok, string $name): void {
    if (!$ok) throw new RuntimeException($name);
    echo "PASS $name\n";
}
function tgReject(callable $call, int $status, string $name): void {
    try { $call(); } catch (\yii\web\HttpException $error) { tgCheck($error->statusCode === $status, $name); return; }
    throw new RuntimeException('Expected rejection: ' . $name);
}

$dsn = getenv('TELEGRAM_TEST_DSN') ?: 'sqlite::memory:';
if (!in_array($dsn, ['sqlite::memory:', 'mysql:host=127.0.0.1;port=33316;dbname=ablakin_telegram_ci'], true)) {
    throw new RuntimeException('Only the isolated Telegram test database is allowed.');
}
$app = new \yii\web\Application([
    'id' => 'telegram-auth-test', 'basePath' => dirname(__DIR__, 2),
    'runtimePath' => sys_get_temp_dir() . '/ablakin-telegram-' . getmypid(),
    'extensions' => [], 'bootstrap' => [\dektrium\user\Bootstrap::class],
    'controllerNamespace' => 'api\controllers',
    'modules' => ['user' => ['class' => \dektrium\user\Module::class,
        'modelMap' => ['User' => User::class], 'enableConfirmation' => false, 'enableGeneratingPassword' => false,
        'mailer' => ['class' => \api\components\SilentMailer::class]]],
    'components' => [
        'db' => ['class' => \yii\db\Connection::class, 'dsn' => $dsn, 'username' => 'root', 'password' => getenv('TELEGRAM_TEST_PASSWORD') ?: 'telegram-fixture-only', 'charset' => 'utf8mb4'],
        'request' => ['cookieValidationKey' => 'fixture', 'scriptFile' => __FILE__, 'scriptUrl' => '/index.php', 'hostInfo' => 'https://test.invalid'],
        'user' => ['identityClass' => User::class, 'enableSession' => false, 'enableAutoLogin' => false],
        'response' => ['format' => \yii\web\Response::FORMAT_JSON],
        'cache' => ['class' => \yii\caching\ArrayCache::class],
        'i18n' => ['translations' => ['user*' => ['class' => \yii\i18n\PhpMessageSource::class, 'basePath' => '@vendor/dektrium/yii2-user/messages']]],
    ],
]);
$db = $app->db;
$config = new TelegramConfig(true, 'AblakinTestBot', str_repeat('fixture-', 8));
$service = new TelegramAuthService($db, $config, new TelegramAccounts());
if (in_array($argv[1] ?? '', ['unlink-worker', 'revoked-login-worker'], true)) {
    $db->open(); workerReady();
    try {
        if ($argv[1] === 'unlink-worker') $service->unlink((int)$argv[2], $argv[3], 'local-fixture-password');
        else $service->complete($argv[2], $argv[3], null, null);
        echo json_encode(['status' => 200]);
    } catch (\yii\web\HttpException $error) { echo json_encode(['status' => $error->statusCode]); }
    exit(0);
}
if (($argv[1] ?? '') === 'worker') {
    $db->open(); workerReady();
    try {
        $result = $service->complete($argv[2], $argv[3], null, null);
        echo json_encode(['user_id' => (int)$result['user']->id]);
    } catch (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage()); exit(1); }
    exit(0);
}
$db->createCommand()->createTable('user', ['id' => $db->driverName === 'mysql' ? 'int unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'pk', 'username' => 'varchar(255) UNIQUE',
    'email' => $db->driverName === 'mysql' ? 'varchar(255) NOT NULL UNIQUE' : 'varchar(255) UNIQUE',
    'password_hash' => 'varchar(255)', 'auth_key' => 'varchar(255)', 'registration_ip' => 'varchar(255)',
    'created_at' => 'int', 'updated_at' => 'int', 'last_login_at' => 'int', 'latest_activity' => 'datetime',
    'confirmed_at' => 'int', 'blocked_at' => 'int'])->execute();
$db->createCommand()->createTable('profile', ['user_id' => 'integer PRIMARY KEY', 'gravatar_email' => 'varchar(255)'])->execute();
$db->createCommand()->createTable('persone', ['id' => 'pk', 'user_id' => 'integer UNIQUE', 'bonus_count' => 'integer DEFAULT 0',
    'refovod' => 'integer DEFAULT 0', 'rating' => 'decimal DEFAULT 0', 'balance' => 'decimal DEFAULT 0', 'credit' => 'decimal DEFAULT 0'])->execute();
if ($db->driverName === 'mysql') {
    // The original installer could commit this signed table, then fail at the legacy unsigned FK.
    $db->createCommand()->createTable('auth_identity', ['id' => 'pk', 'provider' => 'varchar(20) NOT NULL',
        'subject' => 'varchar(32) NOT NULL', 'user_id' => 'int NULL', 'username' => 'varchar(64)', 'created_at' => 'int NOT NULL'])->execute();
}
$installer = new \console\controllers\TelegramAuthController('telegram-install-test', $app);
putenv('TELEGRAM_AUTH_INSTALL=confirmed-test-checkout');
try { tgCheck($installer->actionInstall() === 0, 'deployment installs Telegram into a legacy database'); }
finally { putenv('TELEGRAM_AUTH_INSTALL'); }
if ($db->driverName === 'mysql') {
    tgCheck($db->schema->getTableSchema('auth_identity', true)->columns['user_id']->unsigned, 'partial migration resumes with the legacy unsigned user id');
    tgCheck(count($db->schema->getTableForeignKeys('auth_identity', true)) === 1, 'legacy owner foreign key is installed');
}
$migration = new \m261104_120000_telegram_auth(['db' => $db]);
$migration->up(); $migration->up();
$unlinkMigration = new \m261106_140000_telegram_unlink(['db' => $db]);
$unlinkMigration->up(); $unlinkMigration->up();
tgCheck(true, 'migration is repeatable');
$start = fn(bool $signup = true) => $service->start('login', $signup, null, null)['request'];
$approve = function (array $request, string $id = '1234567890123') use ($service): void {
    $service->prepare($request['id'], $id, 'test_person');
    $service->decide($request['id'], $id, true);
};
$complete = fn(array $request) => $service->complete($request['id'], $request['browser_secret'], null, null);
$r = $start();
tgCheck(strlen('login_' . $r['id']) <= 64 && $r['id'] !== $r['browser_secret'], 'deep link and browser proof are distinct');
$stored = (new Query())->from('telegram_auth_request')->where(['id' => $r['id']])->one();
tgCheck(!str_contains(json_encode($stored), $r['browser_secret']), 'no plaintext browser proof in database');
tgReject(fn() => $complete($r), 409, 'cannot complete without Telegram approval');
tgReject(fn() => $service->status($r['id'], str_repeat('z',43), null, null), 404, 'deep link alone cannot poll');
$service->prepare($r['id'], '1234567890123', 'test_person');
tgReject(fn() => $service->prepare($r['id'], '2', null), 409, 'request cannot be claimed by another Telegram user');
tgReject(fn() => $service->decide($r['id'], '2', true), 403, 'another Telegram user cannot confirm');
$approve($r);
$first = $complete($r);
tgCheck((int)$first['user']->id > 0 && strlen($first['token']) >= 32, 'approved request creates account and normal site session');
tgCheck($first['user']->email === null && $first['user']->person !== null, 'registration creates ordinary profile without inventing email');
tgCheck($complete($r)['token'] === $first['token'] && (new Query())->from('user')->count() == 1, 'lost-response retry does not duplicate account');
tgCheck((new Query())->from('auth_audit')->count() == 1, 'completion audit recorded once');
$r2 = $start(); $approve($r2);
tgCheck($complete($r2)['user']->id === $first['user']->id, 'same Telegram logs into same account');
$new = $start(false); $approve($new, '222');
tgReject(fn() => $complete($new), 409, 'new account requires explicit permission');
tgCheck((new Query())->from('user')->count() == 1, 'failed completion rolls back identity reservation');
$expired = $start(); $approve($expired);
$db->createCommand()->update('telegram_auth_request', ['expires_at' => time() - 1], ['id' => $expired['id']])->execute();
tgReject(fn() => $complete($expired), 410, 'expired approval cannot log in');
tgCheck($service->status($expired['id'], $expired['browser_secret'], null, null)['request']['status'] === 'expired', 'expiry is visible to browser');
$cancelled = $start(); $approve($cancelled);
$service->cancel($cancelled['id'], $cancelled['browser_secret'], null, null);
tgReject(fn() => $complete($cancelled), 409, 'closing dialog revokes approved request');
$denied = $start(); $service->prepare($denied['id'], '333', null); $service->decide($denied['id'], '333', false);
tgReject(fn() => $complete($denied), 409, 'denied request cannot log in');

$other = $start(); $approve($other, '444'); $second = $complete($other);
$link = $service->start('link', false, (int)$second['user']->id, $second['token'])['request'];
$approve($link);
tgReject(fn() => $service->complete($link['id'], $link['browser_secret'], (int)$second['user']->id, $second['token']), 409, 'Telegram cannot be stolen from another account');
$thirdId = (new TelegramAccounts())->create();
$third = User::findOne($thirdId);
$link = $service->start('link', false, $thirdId, $third->auth_key)['request']; $approve($link, '555');
tgReject(fn() => $complete($link), 403, 'binding requires original account session');
tgCheck($service->complete($link['id'], $link['browser_secret'], $thirdId, $third->auth_key)['linked'], 'binding succeeds with both proofs');
tgCheck($service->identity($thirdId)['connected'], 'profile exposes binding without secret or Telegram ID');
$boundLogin = $start(false); $approve($boundLogin, '555');
tgCheck((int)$complete($boundLogin)['user']->id === $thirdId, 'linked Telegram preserves existing account');
// A password is required so Telegram-only users cannot lose their sole login method.
tgReject(fn() => $service->unlink($thirdId, $third->auth_key, 'unknown-password'), 403, 'cannot remove sole login with an unknown password');
$password = 'local-fixture-password';
$db->createCommand()->update('user', ['password_hash' => \dektrium\user\helpers\Password::hash($password)], ['id' => $thirdId])->execute();
$pendingLogin = $start(false); $approve($pendingLogin, '555');
$pendingLink = $service->start('link', false, $thirdId, $third->auth_key)['request']; $approve($pendingLink, '555');
$userBefore = (new Query())->from('user')->where(['id' => $thirdId])->one();
$personBefore = (new Query())->from('persone')->where(['user_id' => $thirdId])->one();
tgReject(fn() => $service->unlink($thirdId, 'stale-key', $password), 403, 'unlink requires current account session');
tgReject(fn() => $service->unlink($thirdId, $third->auth_key, 'wrong-password'), 403, 'unlink rejects wrong password');
tgCheck($service->identity($thirdId) !== null, 'failed unlink preserves binding');
tgCheck($service->unlink($thirdId, $third->auth_key, $password)['telegram'] === null, 'unlink removes Telegram from profile');
$service->unlink($thirdId, $third->auth_key, $password);
tgCheck((new Query())->from('auth_audit')->where(['event' => 'telegram.unlink', 'user_id' => $thirdId])->count() == 1, 'repeated unlink records one audit');
tgCheck((new Query())->from('user')->where(['id' => $thirdId])->one() === $userBefore
    && (new Query())->from('persone')->where(['user_id' => $thirdId])->one() === $personBefore, 'unlink preserves password, session, progress and money');
tgReject(fn() => $complete($pendingLogin), 409, 'old approved login cannot restore removed identity');
tgReject(fn() => $complete($boundLogin), 409, 'old completed login cannot replay after unlink');
tgReject(fn() => $service->complete($link['id'], $link['browser_secret'], $thirdId, $third->auth_key), 409, 'old completed link cannot replay after unlink');
$approve($pendingLink, '555');
tgReject(fn() => $service->complete($pendingLink['id'], $pendingLink['browser_secret'], $thirdId, $third->auth_key), 409, 'reopening old chat does not refresh revoked approval');
$freshLink = $service->start('link', false, $thirdId, $third->auth_key)['request']; $approve($freshLink, '555');
tgCheck($service->complete($freshLink['id'], $freshLink['browser_secret'], $thirdId, $third->auth_key)['linked'], 'new explicit binding works after unlink');
$boundLogin = $start(false); $approve($boundLogin, '555'); $complete($boundLogin);
$db->createCommand()->update('user', ['auth_key' => 'rotated-on-logout'], ['id' => $thirdId])->execute();
tgReject(fn() => $complete($boundLogin), 409, 'logout invalidates completed-request recovery');
$db->createCommand()->update('user', ['blocked_at' => time()], ['id' => $thirdId])->execute();
$blocked = $start(); $approve($blocked, '555');
tgReject(fn() => $complete($blocked), 403, 'blocked account cannot authenticate');
$limiter = new \common\services\auth\AuthRateLimit($db);
$limiter->hit('fixture', 'ip', 2); $limiter->hit('fixture', 'ip', 2);
tgReject(fn() => $limiter->hit('fixture', 'ip', 2), 429, 'shared rate limit rejects excess attempts');

$settingsMigration = new \m261106_120000_telegram_settings(['db' => $db]);
$settingsMigration->up(); $settingsMigration->up();
$settings = new \common\services\auth\TelegramSettings($db);
tgCheck($settings->read()['enabled'], 'provider enabled by default');
$settings->registerBot('12345', 'AblakinTestBot');
putenv('TELEGRAM_AUTH_API_KEY=' . $config->apiKey);
$_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['HTTPS'] = 'on';
$app->request->headers->set('Accept', 'application/json');
$app->request->setBodyParams(['request_id' => $r['id'], 'telegram_user_id' => '1234567890123', 'approve' => true]);
$app->request->headers->set('Authorization', 'Bearer browser-not-bot');
tgReject(fn() => $app->runAction('telegram-auth/bot-confirm'), 401, 'browser cannot impersonate bot');
$app->request->headers->set('Authorization', 'Bearer ' . $config->apiKey);
tgCheck($app->runAction('telegram-auth/bot-confirm')['request']['status'] === 'completed', 'signed-in bot retry is idempotent');
$app->request->setBodyParams(['bot_id' => '12345', 'bot_username' => 'AblakinTestBot']);
tgCheck($app->runAction('telegram-auth/bot-register')['bot']['registered'], 'bot registers its Telegram identity through authenticated API');
$app->request->setBodyParams(['bot_id' => '12345', 'bot_username' => 'invalid name']);
tgReject(fn() => $app->runAction('telegram-auth/bot-register'), 400, 'invalid bot metadata is rejected');
$settings->setEnabled(false);
$settingsMigration->up();
tgCheck(!$settings->read()['enabled'], 'repeated migration preserves disabled setting');
$settings->registerBot('12345', 'AblakinTestBot');
tgCheck(!$settings->read()['enabled'], 'bot reconnect does not re-enable disabled provider');
$app->request->setBodyParams(['bot_id' => '12345', 'bot_username' => 'AblakinTestBot']);
tgCheck($app->runAction('telegram-auth/bot-register')['bot']['registered'], 'disabled provider still accepts authenticated bot registration');
$_SERVER['REQUEST_METHOD'] = 'GET';
tgCheck($app->runAction('telegram-auth/config')['telegram']['enabled'] === false, 'kill switch hides availability');
$_SERVER['REQUEST_METHOD'] = 'POST';
tgReject(fn() => $app->runAction('telegram-auth/start'), 503, 'kill switch rejects new login');
$app->request->setBodyParams(['password' => $password]);
$app->request->headers->remove('Authorization');
tgReject(fn() => $app->runAction('telegram-auth/unlink'), 401, 'unlink requires bearer authentication');
$apiUser = User::findOne($second['user']->id);
$db->createCommand()->update('user', ['password_hash' => \dektrium\user\helpers\Password::hash($password)], ['id' => $apiUser->id])->execute();
$app->request->headers->set('Authorization', 'Bearer ' . $second['token']);
$app->request->setBodyParams(['password' => ['invalid']]);
tgReject(fn() => $app->runAction('telegram-auth/unlink'), 400, 'unlink validates password type');
$app->request->setBodyParams(['password' => $password]);
tgCheck($app->runAction('telegram-auth/unlink')['telegram'] === null, 'disabled provider still allows unlink');
$_SERVER['REQUEST_METHOD'] = 'GET';
tgCheck($app->runAction('telegram-auth/identity')['telegram'] === null, 'disabled provider still exposes current binding');
if ($db->driverName === 'mysql') {
    $raceId = (int)$first['user']->id;
    $db->createCommand()->update('user', ['password_hash' => \dektrium\user\helpers\Password::hash($password)], ['id' => $raceId])->execute();
    $raceLogin = $start(false); $approve($raceLogin);
    $children = [];
    foreach ([['unlink-worker', (string)$raceId, $first['token']], ['revoked-login-worker', $raceLogin['id'], $raceLogin['browser_secret']]] as $args) {
        $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $children[] = [$process, $pipes];
    }
    releaseWorkers($children);
    foreach ($children as $index => [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        tgCheck(proc_close($process) === 0, 'unlink race finishes without deadlock: ' . $err);
        $status = json_decode($out, true, 512, JSON_THROW_ON_ERROR)['status'];
        tgCheck($index === 0 ? $status === 200 : in_array($status, [200, 409], true), 'concurrent login is completed before unlink or rejected');
    }
    tgCheck($service->identity($raceId) === null, 'concurrent completion cannot restore unlinked identity');
    tgReject(fn() => $complete($raceLogin), 409, 'concurrent completed login cannot replay after unlink');
    foreach ([true, false] as $sameRequest) {
        $before = (int)(new Query())->from('user')->count();
        $requests = [$start(), $start()];
        $subject = $sameRequest ? '888' : '999';
        foreach ($requests as $request) $approve($request, $subject);
        if ($sameRequest) $requests[1] = $requests[0];
        $children = [];
        foreach ($requests as $request) {
            $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, 'worker', $request['id'], $request['browser_secret']],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $children[] = [$process, $pipes];
        }
        releaseWorkers($children);
        $ids = [];
        foreach ($children as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            tgCheck(proc_close($process) === 0, 'concurrent completion succeeds: ' . $err);
            $ids[] = json_decode($out, true, 512, JSON_THROW_ON_ERROR)['user_id'];
        }
        tgCheck($ids[0] === $ids[1] && (int)(new Query())->from('user')->count() === $before + 1,
            $sameRequest ? 'parallel replay creates one account' : 'two browser requests for same Telegram create one account');
    }
}
putenv('TELEGRAM_AUTH_INSTALL');
$blocked = false;
try { $installer->actionInstall(); } catch (RuntimeException $error) { $blocked = true; }
tgCheck($blocked, 'migration installer requires explicit deployment context');
putenv('TELEGRAM_AUTH_INSTALL=confirmed-test-checkout');
$accountsBeforeInstall = (new Query())->from('user')->count();
try {
    tgCheck($installer->actionInstall() === 0 && $installer->actionInstall() === 0, 'deployment migrations are repeatable');
    $versions = (new Query())->select('version')->from('migration')->where(['<>', 'version', 'm000000_000000_base'])->column();
    sort($versions);
    tgCheck($versions === ['m261104_120000_telegram_auth', 'm261106_120000_telegram_settings', 'm261106_140000_telegram_unlink'], 'only the three Telegram migrations enter deployment history');
    tgCheck((new Query())->from('user')->count() === $accountsBeforeInstall && !$settings->read()['enabled'], 'deployment preserves accounts and the disabled provider');
} finally { putenv('TELEGRAM_AUTH_INSTALL'); }
echo "Telegram authentication lifecycle passed.\n";
