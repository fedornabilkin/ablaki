<?php
namespace common\modules\world\models\domain {
    function time() { return \EconomyRaceClock::$at ?? \time(); }
}
namespace {
final class EconomyRaceClock { public static $at; }
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
    if (isset($payload['clock'])) EconomyRaceClock::$at = $payload['clock'];
    workerReady();
    $started = microtime(true);
    try {
        $user = (int)($payload['user_id'] ?? 9004);
        $result = common\modules\world\models\Command::run($user, $key, 'fixture.race.' . $action, $payload, function ($op) use ($app, $action, $payload, $user) {
            if ($action === 'paid-start') return common\modules\world\models\Mining::start($payload['mining_node_id'], $user, $op, 'mine-stone');
            if ($action === 'paid-delivery') return common\modules\world\models\Mining::heartbeat($payload['mining_node_id'], $user, $op);
            if ($action === 'supplies') return common\modules\world\models\Supplies::gather($payload['node_id'], 9004, $op);
            if ($action === 'kitchen') return common\modules\world\models\domain\WorldFood::consume($app->db, 9004, 'world-cooked-carrot', common\modules\world\models\domain\WorldFood::state($app->db, 9004)['revision'], $op, $payload['node_id']);
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
            $input = $payload;
            if (isset($payload['user_ids'])) { $input['user_id'] = $payload['user_ids'][$index % count($payload['user_ids'])]; unset($input['user_ids']); }
            $command = [PHP_BINARY]; if (php_ini_loaded_file()) { $command[] = '-c'; $command[] = php_ini_loaded_file(); }
            $command = array_merge($command, [__FILE__, 'economy-worker', $action, $same ? $shared : bin2hex(random_bytes(16)), base64_encode(json_encode($action === 'kitchen' ? array_merge($input, ['node_id' => $payload['node_ids'][$index % count($payload['node_ids'])]]) : $input))]);
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
$newSupply = static function (string $suffix) use ($db): string {
    $row = (new Query())->from('craft_item')->where(['code' => 'classic-log'])->one($db); unset($row['id']);
    $row['code'] = 'fixture-race-supply-' . $suffix; $row['name'] = 'Race supply ' . $suffix; $row['label'] = $row['name']; $row['gather_quantity'] = 1;
    $db->createCommand()->insert('craft_item', $row)->execute(); return $row['code'];
};
$supplyCode = $newSupply('fresh');
$supplyRace = economyRace('supplies', false, ['node_id' => $siteId]);
economyCheck(count(array_filter($supplyRace, static function ($row) { return $row['status'] === 'ok'; })) === 1
    && (int)(new Query())->from('world_supply_claim')->where(['user_id' => $freshUser, 'item_code' => $supplyCode])->count('*', $db) === 1,
    'six competing daily gathers consume one allowance and credit one resource receipt');
$replayCode = $newSupply('replay');
$supplyReplay = economyRace('supplies', true, ['node_id' => $siteId]);
economyCheck(count(array_unique(array_column($supplyReplay, 'operation_id'))) === 1
    && (int)(new Query())->from('world_supply_claim')->where(['user_id' => $freshUser, 'item_code' => $replayCode])->count('*', $db) === 1,
    'six simultaneous supply retries replay one committed grant without refilling the preceding resource');
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
    && WorldFood::state($db, $freshUser)['hp'] === 74 && $freshStock->quantities($freshUser)[$raceFood['id']] === $foodBefore - 1, 'parallel food requests consume one item and retain the expected health revision');

// Shared meal windows serialize across different kitchens, even with a fresh health revision.
$kitchenIds = [];
$db->transaction(static function () use ($db, $freshUser, $siteId, &$kitchenIds) {
foreach ([1, 2] as $number) {
    $kitchen = common\modules\world\models\NodeFactory::create(common\modules\world\models\NodeTemplate::requireOne(['code' => 'field-kitchen']), common\modules\world\models\Node::requireOne($siteId), $freshUser, 'Meal race kitchen', 'race-kitchen-' . $number, null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $kitchen->max_condition], ['id' => $kitchen->id])->execute(); $kitchenIds[] = (int)$kitchen->id;
}
});
$db->transaction(static function () use ($freshStock, $freshUser, $raceFood) { $freshStock->move($freshUser, $raceFood, 4); });
$mealAt = (new DateTimeImmutable('tomorrow 06:00:00', new DateTimeZone('Europe/Moscow')))->getTimestamp();
$beforeFood = $freshStock->quantities($freshUser)[$raceFood['id']];
$results = economyRace('kitchen', false, ['node_ids' => $kitchenIds, 'clock' => $mealAt]);
economyCheck(count(array_filter($results, static function ($r) { return $r['status'] === 'ok'; })) === 1 && $freshStock->quantities($freshUser)[$raceFood['id']] === $beforeFood - 1,
    'six fresh-revision requests across two kitchens share one breakfast and consume one portion');
$beforeFood = $freshStock->quantities($freshUser)[$raceFood['id']];
$results = economyRace('kitchen', true, ['node_ids' => [$kitchenIds[0]], 'clock' => $mealAt + 6 * 3600]);
economyCheck(count(array_unique(array_column($results, 'operation_id'))) === 1 && $freshStock->quantities($freshUser)[$raceFood['id']] === $beforeFood - 1,
    'six simultaneous meal retries return one result and consume one lunch');

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

// Two workers compete for one reserved wage, then six deliveries compete for that batch.
$db->transaction(static function () use ($db, $city, $freshUser, &$paidMine, &$paidStock) {
    foreach ([9020, 9021] as $id) {
        $db->createCommand()->insert('user', ['id' => $id, 'username' => 'PaidRace' . $id])->execute();
        $db->createCommand()->insert('persone', ['id' => $id, 'user_id' => $id, 'credit' => '0.0000', 'balance' => '0.0000'])->execute();
        Yii::$app->authManager->assign(Yii::$app->authManager->getRole('world-tester'), $id);
        Command::run($id, bin2hex(random_bytes(16)), 'world.start', ['city_id' => $city], static function ($op) use ($id, $city) { return common\modules\world\models\StarterPack::enter($id, $city, $op); });
        (new common\modules\world\modules\craft\models\domain\CraftStorage($db))->move($id, (new Query())->from('craft_item')->where(['code' => 'world-stone-pickaxe'])->one($db), 1);
    }
    $paidMine = common\modules\world\models\NodeFactory::create(common\modules\world\models\NodeTemplate::requireOne(['code' => 'mine']), common\modules\world\models\Node::requireOne($city), 9001, 'Hired race mine', 'hired-race-mine', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $paidMine->max_condition], ['id' => $paidMine->id])->execute(); $paidMine->refresh();
    $db->createCommand()->insert('craft_storage', ['identity_key' => 'stockpile:hired-race', 'kind' => 'stockpile', 'node_id' => $paidMine->id, 'owner_user_id' => 9001, 'capacity' => 5])->execute(); $paidStock = (int)$db->getLastInsertID();
    Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.hired-fund', [], static function ($op) use ($db, $freshUser, $paidMine) { return (new common\modules\world\modules\economy\models\domain\BudgetFunding($db))->contribute($freshUser, (int)$paidMine->id, common\modules\world\modules\economy\value\Money::parse('2'), 'Race wage', $op); });
    common\modules\world\models\domain\HiredExtraction::configure(common\modules\world\models\Node::requireOne($paidMine->id), 9001, true, '2', $paidStock);
});
$results = economyRace('paid-start', false, ['mining_node_id' => (int)$paidMine->id, 'user_ids' => [9020, 9021]]);
$accepted = (new Query())->from('world_source_work')->where(['node_id' => $paidMine->id])->all($db);
$paidAccount = (new common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->accounts((int)$paidMine->id)['budget'];
economyCheck(count($accepted) === 1 && count(array_filter($results, static function ($r) { return $r['status'] === 'rejected' && $r['reason'] === 'INSUFFICIENT_WAGE_BUDGET'; })) === 3
    && common\modules\world\modules\economy\value\Money::parse((string)$paidAccount['reserved'])->decimal() === '2.0000', 'two workers and six requests cannot overbook a single funded wage');
$paidWork = $accepted[0]; $definition = json_decode($paidWork['definition_json'], true);
$db->createCommand()->update('world_source_work', ['worked_seconds' => $definition['work_seconds']], ['id' => $paidWork['id']])->execute();
$results = economyRace('paid-delivery', false, ['mining_node_id' => (int)$paidMine->id, 'user_id' => (int)$paidWork['user_id']]);
$paidAccount = (new common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->accounts((int)$paidMine->id)['budget'];
economyCheck(count(array_filter($results, static function ($r) { return $r['status'] === 'ok'; })) === 6 && (int)(new Query())->from('economy_transfer')->where(['source_account_id' => $paidAccount['id'], 'kind' => 'resource_wage'])->count('*', $db) === 1
    && common\modules\world\modules\economy\value\Money::parse((string)$paidAccount['amount'])->isZero() && common\modules\world\modules\economy\value\Money::parse((string)$paidAccount['reserved'])->isZero()
    && (int)(new Query())->from('craft_inventory')->where(['storage_id' => $paidStock])->sum('item_quantity', $db) === array_sum($definition['output']), 'six competing delivery commands commit one resource batch and one wage, releasing the hold exactly once');

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

}
