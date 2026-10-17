<?php
// Strictly disposable loopback databases; worker mode never installs or resets schema.
$raceDsn = getenv('ABL_TEST_DSN');
if (!is_string($raceDsn) || !preg_match('/^(mysql|pgsql):host=127\.0\.0\.1;port=(3306|5432);dbname=ablakin_ci$/D', $raceDsn)) {
    throw new RuntimeException('Economy races require the dedicated loopback ablakin_ci database.');
}
if (($argv[1] ?? '') === 'economy-worker') {
    define('YII_ENABLE_ERROR_HANDLER', false);
    $root = dirname(__DIR__, 2);
    require $root . '/vendor/autoload.php'; require $root . '/vendor/yiisoft/yii2/Yii.php';
    require __DIR__ . '/worker-barrier.php';
    Yii::setAlias('@common', $root . '/common');
    error_reporting(E_ALL & ~E_DEPRECATED);
    $app = new yii\console\Application(['id' => 'economy-race-worker', 'basePath' => $root,
        'modules' => ['world' => ['class' => common\modules\world\Module::class, 'flags' => ['world_read' => true, 'world_write' => true, 'storage_v2' => true]]],
        'components' => ['db' => ['class' => yii\db\Connection::class, 'dsn' => $raceDsn, 'username' => 'ablakin_ci', 'password' => 'ci-only-password', 'charset' => 'utf8'],
            'authManager' => ['class' => yii\rbac\DbManager::class]]]);
    $app->db->open();
    $action = $argv[2]; $key = $argv[3]; $payload = json_decode(base64_decode($argv[4]), true, 32, JSON_THROW_ON_ERROR);
    workerReady();
    $started = microtime(true);
    try {
        $result = common\modules\world\models\Command::run(9004, $key, 'fixture.race.' . $action, $payload, function ($op) use ($app, $action, $payload) {
            if ($action === 'food') return common\modules\world\models\domain\WorldFood::consume($app->db, 9004, 'world-cooked-carrot', $payload['health_revision'], $op);
            if ($action === 'goal') return (new common\modules\world\models\domain\WorldGoals($app->db))->claim(9004, 'day-1', $op);
            common\modules\world\models\domain\EconomyQuota::consume($app->db, 9004, 'crafting_hourly', 1, $op);
            return ['accepted' => true];
        });
        echo json_encode(['status' => 'ok', 'operation_id' => $result['operation_id'], 'duration_ms' => (int)round((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR);
    } catch (common\modules\world\support\GameError $error) {
        echo json_encode(['status' => 'rejected', 'reason' => $error->reason, 'duration_ms' => (int)round((microtime(true) - $started) * 1000)], JSON_THROW_ON_ERROR);
    }
    exit;
}
require __DIR__ . '/world-economy-runtime.php';
use common\modules\world\models\Command;
use common\modules\world\models\domain\EconomyQuota;
use common\modules\world\models\domain\WorldFood;
use yii\db\Query;

function economyRace(string $action, bool $same, array $payload = []): array {
    $children = []; $shared = bin2hex(random_bytes(16));
    try {
        for ($index = 0; $index < 6; $index++) {
            $command = [PHP_BINARY]; if (php_ini_loaded_file()) { $command[] = '-c'; $command[] = php_ini_loaded_file(); }
            $command = array_merge($command, [__FILE__, 'economy-worker', $action, $same ? $shared : bin2hex(random_bytes(16)), base64_encode(json_encode($payload))]);
            $pipes = []; $process = proc_open(implode(' ', array_map('escapeshellarg', $command)), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
            if (!is_resource($process)) throw new RuntimeException('Could not start economy worker.');
            $children[] = [$process, $pipes];
        }
        releaseWorkers($children); $results = [];
        foreach ($children as list($process, $pipes)) {
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            if (proc_close($process) !== 0) throw new RuntimeException('Economy worker failed: ' . $err . $out);
            $results[] = json_decode($out, true, 32, JSON_THROW_ON_ERROR);
        }
        $durations = array_column($results, 'duration_ms'); sort($durations, SORT_NUMERIC);
        echo 'RACE ' . json_encode(['action' => $action, 'same_key' => $same, 'concurrency' => 6, 'p95_ms' => $durations[(int)ceil(count($durations) * .95) - 1]], JSON_THROW_ON_ERROR) . PHP_EOL;
        return $results;
    } catch (Throwable $error) {
        foreach ($children as list($process, $pipes)) if (is_resource($process)) { proc_terminate($process); foreach ($pipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($process); }
        throw $error;
    }
}
$where = ['user_id' => $freshUser, 'kind' => 'crafting_hourly'];
$db->createCommand()->delete('world_economy_usage', $where)->execute();
Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.quota-prime', [], function ($op) use ($db, $freshUser) { EconomyQuota::consume($db, $freshUser, 'crafting_hourly', 29, $op); return []; });
$beforeOperations = (int)(new Query())->from('game_operation')->count('*', $db);
$results = economyRace('quota', false);
economyCheck(count(array_filter($results, static function ($r) { return $r['status'] === 'ok'; })) === 1 && EconomyQuota::state($db, $freshUser, 'crafting_hourly')['remaining'] === 0, 'six competing commands consume only the final quota unit');
economyCheck((int)(new Query())->from('game_operation')->count('*', $db) === $beforeOperations + 1, 'rejected parallel commands leave no orphan operations');
$db->createCommand()->delete('world_economy_usage', $where)->execute();
$results = economyRace('quota', true);
economyCheck(count(array_unique(array_column($results, 'operation_id'))) === 1 && EconomyQuota::state($db, $freshUser, 'crafting_hourly')['remaining'] === 29, 'six simultaneous retries replay one committed operation');

$health = WorldFood::state($db, $freshUser); $revision = $health['revision'] + 1;
$db->createCommand()->update('world_player_health', ['hp' => 70, 'revision' => $revision], ['user_id' => $freshUser])->execute();
$raceFood = (new Query())->from('craft_item')->where(['code' => 'world-cooked-carrot'])->one($db);
$db->transaction(function () use ($freshStock, $freshUser, $raceFood) { $freshStock->move($freshUser, $raceFood, 2); });
$foodBefore = $freshStock->quantities($freshUser)[$raceFood['id']];
$results = economyRace('food', false, ['health_revision' => $revision]);
economyCheck(count(array_filter($results, static function ($r) { return $r['status'] === 'ok'; })) === 1
    && WorldFood::state($db, $freshUser)['hp'] === 85 && $freshStock->quantities($freshUser)[$raceFood['id']] === $foodBefore - 1, 'parallel food requests consume one item and retain the expected health revision');

$db->createCommand()->delete('world_goal_claim', ['user_id' => $freshUser, 'goal_code' => 'day-1'])->execute();
Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.race-goal-fund', [], function ($op) use ($goals, $city, $db, $freshUser) {
    (new common\modules\world\modules\economy\models\domain\BudgetFunding($db))->contribute($freshUser, $city, common\modules\world\modules\economy\value\Money::parse('50.0000'), 'Finite concurrency fixture funding', $op);
    return $goals->fund($city, 'day-1', 1, 9001, $op);
});
$results = economyRace('goal', false);
economyCheck(count(array_filter($results, static function ($r) { return $r['status'] === 'ok'; })) === 1
    && (int)(new Query())->from('world_goal_claim')->where(['user_id' => $freshUser, 'goal_code' => 'day-1'])->count('*', $db) === 1, 'concurrent goal claims pay one funded reward');

$db->createCommand()->delete('world_economy_usage', $where)->execute();
$beforeOperations = (int)(new Query())->from('game_operation')->count('*', $db); $failedKey = bin2hex(random_bytes(16));
try {
    Command::run($freshUser, $failedKey, 'fixture.failure-after-quota', [], function ($op) use ($db, $freshUser) {
        EconomyQuota::consume($db, $freshUser, 'crafting_hourly', 1, $op); throw new RuntimeException('Injected write failure');
    });
    throw new LogicException('Injected failure did not run.');
} catch (RuntimeException $error) { if ($error->getMessage() !== 'Injected write failure') throw $error; }
economyCheck(EconomyQuota::state($db, $freshUser, 'crafting_hourly')['remaining'] === 30
    && (int)(new Query())->from('game_operation')->count('*', $db) === $beforeOperations
    && !(new Query())->from('game_command')->where(['user_id' => $freshUser, 'request_key' => $failedKey])->exists($db), 'failure after quota write rolls back quota, operation and idempotency record');

// Simulate interrupted additive DDL by removing one constraint/index, retaining all data.
$table = 'world_economy_usage';
$beforeUsage = (new Query())->from($table)->orderBy('id')->all($db);
$index = null;
foreach ($db->schema->getTableIndexes($table, true) as $candidate) if ($candidate->isUnique && in_array('kind', $candidate->columnNames, true)) { $index = $candidate; break; }
if (!$index) throw new RuntimeException('Missing usage uniqueness before migration retry.');
// InnoDB uses the leading operation_id index to support this FK.
$removedForeign = [];
foreach ($db->schema->getTableForeignKeys($table, true) as $foreign) if (in_array('operation_id', $foreign->columnNames, true)) {
    $db->createCommand()->dropForeignKey($foreign->name, $table)->execute(); $removedForeign[] = $foreign->name;
}
$db->createCommand()->dropIndex($index->name, $table)->execute();
ob_start(); try { (new m261017_120000_world_economy_profiles(['db' => $db]))->up(); (new m261017_120000_world_economy_profiles(['db' => $db]))->up(); } finally { ob_end_clean(); }
$db->schema->refresh();
$found = false; foreach ($db->schema->getTableIndexes($table, true) as $candidate) if ($candidate->name === $index->name) $found = true;
$restoredForeign = array_map(static function ($foreign) { return $foreign->name; }, $db->schema->getTableForeignKeys($table, true));
economyCheck($found && !array_diff($removedForeign, $restoredForeign) && $beforeUsage === (new Query())->from($table)->orderBy('id')->all($db), 'partial DDL retry restores uniqueness and foreign keys while repeated migration preserves rows');
echo 'Economy concurrency checks passed.' . PHP_EOL;
