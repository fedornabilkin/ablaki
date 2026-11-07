<?php
// Disposable world fixture only; never loads the site's connection configuration.
if (($argv[1] ?? '') === 'reward-worker') {
    $dsn = getenv('ABL_TEST_DSN');
    if (!is_string($dsn) || !preg_match('/^(mysql|pgsql):host=127\.0\.0\.1;port=(3306|5432|33316);dbname=ablakin_ci$/D', $dsn)) throw new RuntimeException('Isolated reward database required.');
    define('YII_ENABLE_ERROR_HANDLER', false);
    $root = dirname(__DIR__, 2);
    require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
    require __DIR__ . '/worker-barrier.php';
    Yii::setAlias('@common', $root . '/common');
    $app = new yii\console\Application(['id' => 'reward-race', 'basePath' => $root,
        'modules' => ['world' => ['class' => common\modules\world\Module::class, 'flags' => ['world_read' => true, 'world_write' => true, 'storage_v2' => true]]],
        'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => $dsn, 'username' => 'ablakin_ci', 'password' => 'ci-only-password', 'charset' => 'utf8'],
            'authManager' => ['class' => yii\rbac\DbManager::class]]]);
    $app->params['remote_db'] = $app->db;
    $app->setModule('user', ['class' => dektrium\user\Module::class]);
    $app->db->open(); workerReady();
    try {
        common\modules\world\models\Command::run((int)$argv[2], $argv[3], 'world.goal.claim', ['code' => 'telegram-link'],
            static fn($op) => (new common\modules\world\models\domain\WorldGoals($app->db))->claim((int)$argv[2], 'telegram-link', $op));
        echo 'ok';
    } catch (common\modules\world\support\GameError $e) { echo $e->reason; }
    exit;
}
require __DIR__ . '/world-economy-runtime.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . PHP_EOL); exit(1); });

use common\modules\world\models\Command;
use common\modules\world\models\domain\{TelegramReward, WorldGoals, EconomyRuntime, FiniteSupplies, SupplyGrant};
use common\modules\world\modules\craft\models\domain\CraftStorage;
use yii\db\Query;

require_once $yiiRoot . '/console/migrations/m261104_120000_telegram_auth.php';
$db->createCommand()->addColumn('user', 'email', 'varchar(255) NULL')->execute();
ob_start(); try { (new m261104_120000_telegram_auth(['db' => $db]))->up(); } finally { ob_end_clean(); }
$db->schema->refresh();
$rewards = new TelegramReward($db);
$newUser = static function (int $id, bool $linked = true) use ($db, $app, $testRole): int {
    $db->createCommand()->insert('user', ['id' => $id, 'username' => 'Reward' . $id])->execute();
    $db->createCommand()->insert('persone', ['id' => $id, 'user_id' => $id, 'credit' => '250.0000', 'balance' => '0.0000'])->execute();
    $app->authManager->assign($testRole, $id);
    if ($linked) $db->createCommand()->insert('auth_identity', ['provider' => 'telegram', 'subject' => (string)(100000000 + $id), 'user_id' => $id, 'created_at' => time()])->execute();
    return $id;
};
$claim = static function (int $user, ?string $key = null) use ($db): array {
    return Command::run($user, $key ?? bin2hex(random_bytes(16)), 'world.goal.claim', ['code' => 'telegram-link'],
        static fn($operation) => (new WorldGoals($db))->claim($user, 'telegram-link', $operation));
};
$store = new CraftStorage($db);
$catalog = \common\modules\world\models\domain\EconomyProfile::load();
foreach (TelegramReward::PACKS as $pack => $items) {
    $value = \common\modules\world\modules\economy\value\Money::parse('0');
    foreach ($items as $code => $quantity) $value = $value->add(\common\modules\world\modules\economy\value\Money::parse($catalog['items'][$code]['sale_price_cr'])->multiply($quantity));
    economyCheck($value->compare(\common\modules\world\modules\economy\value\Money::parse('120')) >= 0
        && $value->compare(\common\modules\world\modules\economy\value\Money::parse('126')) <= 0, 'gift value within five percent: ' . $pack);
}
$seen = [];
for ($id = 30001; count($seen) < 4; $id++) {
    $pack = TelegramReward::pack($id);
    if (isset($seen[$pack])) continue;
    $seen[$pack] = $newUser($id);
    $before = $rewards->state($id);
    economyCheck($before === $rewards->state($id) && !(new Query())->from('world_telegram_reward')->where(['user_id' => $id])->exists($db), 'stable read-only offer: ' . $pack);
    $key = bin2hex(random_bytes(16)); $result = $claim($id, $key);
    economyCheck($result === $claim($id, $key), 'same request replays exact reward: ' . $pack);
    foreach (TelegramReward::PACKS[$pack] as $code => $quantity) {
        $item = (int)(new Query())->select('id')->from('craft_item')->where(['code' => $code])->scalar($db);
        economyCheck(($store->quantities($id)[$item] ?? 0) === $quantity, 'exact inventory gift: ' . $code);
    }
    economyReject(static fn() => $claim($id), 'REWARD_NOT_AVAILABLE');
    economyCheck((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $id])->scalar($db) == '250.0000', 'gift does not mint or spend credits');
}
$first = reset($seen);
$identity = (new Query())->from('auth_identity')->where(['user_id' => $first])->one($db);
$second = $newUser(31000, false);
$db->createCommand()->update('auth_identity', ['user_id' => $second], ['id' => $identity['id']])->execute();
economyReject(static fn() => $claim($second), 'REWARD_NOT_AVAILABLE');
$db->createCommand()->update('auth_identity', ['user_id' => null], ['id' => $identity['id']])->execute();
economyReject(static fn() => $claim($first), 'TELEGRAM_NOT_LINKED');
$db->createCommand()->insert('auth_identity', ['provider' => 'telegram', 'subject' => '200000001', 'user_id' => $first, 'created_at' => time()])->execute();
economyReject(static fn() => $claim($first), 'REWARD_NOT_AVAILABLE');
economyCheck($rewards->state($first)['pack'] === TelegramReward::pack($first), 'unlink and new identity do not reroll or reset gift');

// Two-item gift: the first fits, the second fails. Neither may remain after rollback.
for ($id = 32000; TelegramReward::pack($id) !== 'supplies'; $id++) {}
$fullUser = $newUser($id);
$stone = (new Query())->from('craft_item')->where(['code' => 'classic-stone'])->one($db);
$db->transaction(static function () use ($store, $fullUser, $stone) { $store->move($fullUser, $stone, 19 * (int)$stone['stack_size']); });
$stockBefore = $store->quantities($fullUser);
try { $claim($fullUser); throw new RuntimeException('Full bag accepted'); }
catch (\yii\web\ConflictHttpException $e) { economyCheck(true, 'full backpack reports a retryable capacity error'); }
economyCheck($store->quantities($fullUser) === $stockBefore && $rewards->state($fullUser)['can_claim'], 'partial items and receipt roll back; gift stays available');
$db->transaction(static function () use ($store, $fullUser, $stone) { $store->move($fullUser, $stone, -(int)$stone['stack_size']); });
economyCheck($claim($fullUser)['telegram_reward']['claimed'], 'gift is claimable after freeing space');

$previous = EconomyRuntime::current($db)['profile'];
$existingDailyUser = $newUser(32999, false);
Command::run($existingDailyUser, bin2hex(random_bytes(16)), 'fixture.daily', [], static function ($operation) use ($db, $existingDailyUser) {
    $store = new CraftStorage($db); $store->operationId = $operation;
    return ['message' => (new SupplyGrant($db))->grant($store, $existingDailyUser, 'gather')];
});
ob_start(); try { (new m261106_181000_world_daily_carrot(['db' => $db]))->up(); } finally { ob_end_clean(); }
$current = EconomyRuntime::current($db);
economyCheck($current['profile']['daily']['fixed_items']['world-carrot'] === 1 && $current['profile']['objects'] === $previous['objects'], 'migration adds only daily safety carrot, preserving prices');
ob_start(); try { (new m261106_181000_world_daily_carrot(['db' => $db]))->up(); } finally { ob_end_clean(); }
economyCheck(EconomyRuntime::current($db)['id'] === $current['id'], 'daily migration is restartable without duplicate publication');
$onlyNew = (new FiniteSupplies($db))->state($existingDailyUser)['items'];
economyCheck(count($onlyNew) === 1 && $onlyNew[0]['code'] === 'world-carrot' && $onlyNew[0]['quantity'] === 1, 'already collected daily bundle exposes only the new carrot, never the old resources twice');
$dailyUser = $newUser(33000, false);
$finite = new FiniteSupplies($db);
for ($day = 1; $day <= 8; $day++) {
    $state = $finite->state($dailyUser);
    $carrots = array_values(array_filter($state['items'], static fn($item) => $item['code'] === 'world-carrot'));
    economyCheck(count($carrots) === 1 && $carrots[0]['quantity'] === 1, 'exactly one carrot on day ' . $day);
    Command::run($dailyUser, bin2hex(random_bytes(16)), 'fixture.daily', [], static function ($operation) use ($db, $dailyUser) {
        $store = new CraftStorage($db); $store->operationId = $operation;
        return ['message' => (new SupplyGrant($db))->grant($store, $dailyUser, 'gather')];
    });
    economyCheck(!$finite->state($dailyUser)['gather_available'], 'no second daily receipt on day ' . $day);
    $db->createCommand()->update('world_supply_claim', ['period_key' => '199901' . sprintf('%02d', $day)], ['user_id' => $dailyUser, 'period_key' => $state['period_key']])->execute();
}
$carrotId = (int)(new Query())->select('id')->from('craft_item')->where(['code' => 'world-carrot'])->scalar($db);
economyCheck(($store->quantities($dailyUser)[$carrotId] ?? 0) === 8, 'eight days grant eight carrots including after the allowance ends');

if ($db->driverName !== 'sqlite') {
    foreach ([false, true] as $sameKey) {
        $user = $newUser($sameKey ? 34001 : 34000);
        $shared = bin2hex(random_bytes(16)); $children = [];
        for ($index = 0; $index < 6; $index++) {
            $command = [PHP_BINARY];
            if (php_ini_loaded_file()) $command = array_merge($command, ['-c', php_ini_loaded_file()]);
            $command = array_merge($command, [__FILE__, 'reward-worker', (string)$user, $sameKey ? $shared : bin2hex(random_bytes(16))]);
            $pipes = [];
            $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new RuntimeException('Cannot start reward worker');
            $children[] = [$process, $pipes];
        }
        releaseWorkers($children); $results = [];
        foreach ($children as [$process, $pipes]) {
            $out = trim(stream_get_contents($pipes[1])); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException($out . $err);
            $results[] = $out;
        }
        economyCheck(count(array_filter($results, static fn($r) => $r === 'ok')) === ($sameKey ? 6 : 1), 'six concurrent requests: ' . ($sameKey ? 'same key replays' : 'only one distinct key wins'));
        economyCheck((int)(new Query())->from('world_telegram_reward')->where(['user_id' => $user])->count('*', $db) === 1
            && (int)(new Query())->from('craft_event')->where(['user_id' => $user, 'action' => 'telegram_reward'])->count('*', $db) === 1, 'one reward receipt and one inventory event under race');
        foreach (TelegramReward::PACKS[TelegramReward::pack($user)] as $code => $quantity) {
            $item = (int)(new Query())->select('id')->from('craft_item')->where(['code' => $code])->scalar($db);
            economyCheck(($store->quantities($user)[$item] ?? 0) === $quantity, 'race preserves exact reward quantities');
        }
    }
}
echo "Telegram reward and daily carrot checks passed.\n";
