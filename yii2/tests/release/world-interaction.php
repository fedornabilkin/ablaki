<?php
/** Disposable database only, using the same guarded fixture as the weekly route. */
namespace WorldInteractionFixture {
    final class Clock { public static $at; public static function now(): int { return self::$at ?? \time(); } }
}
namespace common\modules\world\models\domain { function time() { return \WorldInteractionFixture\Clock::now(); } }
namespace {
require __DIR__ . '/world-economy-sources.php';

use WorldInteractionFixture\Clock;
use common\modules\world\models\{Command, Mining, NodeTemplate};
use common\modules\world\models\domain\{EconomyRuntime, WorldFood, WorldGoals, WorldFlags};
use common\modules\world\modules\craft\models\domain\WorldStorage;
use yii\db\Query;

$template = NodeTemplate::requireOne(['code' => 'house']);
economyCheck($template->toArray()['description'] === $profile['objects']['house']['description'], 'published object descriptions reach the template API');

// Reconciliation reads existing membership and possessions even without the newer fact journal.
$tx = $db->beginTransaction();
try {
    $db->createCommand()->delete('world_economy_fact', ['user_id' => $freshUser])->execute();
    $db->createCommand()->delete('world_goal_claim', ['user_id' => $freshUser])->execute();
    $goals = (new WorldGoals($db))->state($freshUser);
    $steps = []; foreach ($goals['goals'] as $goal) foreach ($goal['steps'] as $step) $steps[$step['code']] = $step;
    economyCheck($steps['city_joined']['completed'] && $steps['starter_site_active']['completed'] && $steps['hammer_crafted']['completed'], 'existing member, active campsite and owned hammer complete the appropriate steps');
    $stockLogs = (int)(new Query())->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')
        ->where(['i.user_id' => $freshUser, 'i.item_id' => $logItem, 's.owner_user_id' => $freshUser, 's.status' => 'active'])->andWhere(['>', 'i.item_quantity', 0])->sum('i.item_quantity', $db);
    $historicalLogs = 0;
    foreach ((new Query())->select('definition_json')->from('world_source_work')->where(['user_id' => $freshUser, 'status' => 'completed', 'action_code' => 'gather-log'])->column($db) as $json)
        $historicalLogs += array_sum(json_decode($json, true)['output']);
    economyCheck($steps['gather_5_logs']['quantity'] === max($stockLogs, $historicalLogs), 'stock and completed source history are reconciled without counting the same logs twice');
} finally { $tx->rollBack(); }
$claimedGoals = (new WorldGoals($db))->state($freshUser);
foreach ($claimedGoals['goals'] as $goal) if ($goal['claimed'])
    economyCheck(!array_filter($goal['steps'], static function ($step) { return !$step['completed']; }), 'claimed milestones remain completed after materials have been spent');

$tx = $db->beginTransaction();
try {
    economySourceCommand($freshUser, $forestId, 'start');
    $hammerRows = (new Query())->select('i.id')->from(['i' => 'craft_inventory'])->innerJoin(['d' => 'craft_item'], 'd.id=i.item_id')
        ->where(['i.user_id' => $freshUser, 'd.code' => 'classic-hammer'])->column($db);
    $db->createCommand()->update('craft_equipment_instance', ['durability' => 0], ['inventory_id' => $hammerRows])->execute();
    $source = Mining::state($forestId, $freshUser);
    economyCheck(!empty($source['unavailable_reason']) && !empty($source['actions'][0]['unavailable_reason']), 'source read explains the unusable accepted tool before a start command');
} finally { $tx->rollBack(); }

$storageService = new WorldStorage($db, new WorldFlags($db, $app->getModule('world')));
$savedCache = $db->queryCache; $savedEnabled = $db->enableQueryCache;
$headers = $storageService->list($freshUser, $siteId);
$originalHeader = array_values(array_filter($headers['items'], static function ($row) use ($backpackId) { return $row['id'] === $backpackId; }))[0];
economyCheck($db->queryCache === $savedCache && $db->enableQueryCache === $savedEnabled, 'inventory read restores the connection cache settings');
$tx = $db->beginTransaction();
try {
    $db->createCommand()->update('craft_storage', ['revision' => $originalHeader['revision'] + 1], ['id' => $backpackId])->execute();
    $updated = $storageService->list($freshUser, $siteId);
    $backpack = array_values(array_filter($updated['items'], static function ($row) use ($backpackId) { return $row['id'] === $backpackId; }))[0];
    economyCheck($backpack['revision'] === $originalHeader['revision'] + 1, 'request-scoped inventory cache sees writes in the next read');
} finally { $tx->rollBack(); }
$denied = false;
try { $storageService->list(9002, $siteId); }
catch (\Throwable $error) { $denied = $error instanceof \common\modules\world\support\GameError || $error instanceof \yii\web\HttpException; }
economyCheck($denied && $db->queryCache === $savedCache && $db->enableQueryCache === $savedEnabled, 'foreign inventory stays denied and failed reads restore cache settings');

// Advance only the domain clock; no sleep and no change to a running world's time.
$worldId = EconomyRuntime::world($db);
$baseline = max(
    (int)(new Query())->from('world_economy_profile')->min('created_at', $db),
    (int)(new Query())->select('joined_at')->from('world_membership')->where(['world_id' => $worldId, 'user_id' => $freshUser])->scalar($db),
    (int)(new Query())->from('world_health_event')->where(['user_id' => $freshUser])->andWhere(['like', 'kind', 'food:%', false])->max('created_at', $db)
);
$before = WorldFood::state($db, $freshUser);
$hunger = static function (int $at) use ($db, $freshUser) {
    Clock::$at = $at;
    $db->transaction(static function () use ($db, $freshUser) { WorldFood::hunger($db, $freshUser); });
    return WorldFood::state($db, $freshUser);
};
economyCheck($hunger($baseline + 259200)['hp'] === $before['hp'], 'exactly seventy-two hours without food does not reduce health');
$first = $hunger($baseline + 259201);
economyCheck($first['hp'] === $before['hp'] - 2 && $first['revision'] === $before['revision'] + 1, 'after seventy-two hours the first hunger day costs two HP');
economyCheck($hunger(Clock::$at)['revision'] === $first['revision'], 'repeating hunger resolution charges the period only once');
$caughtUp = $hunger($baseline + 259201 + 2 * 86400);
economyCheck($caughtUp['hp'] === $before['hp'] - 6, 'returning player catches up only the unpaid hunger days');
$illness = (new Query())->from('actor_night_health')->all($db);
$protected = Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.protected-night', [], static function ($operation) use ($db, $freshUser) {
    WorldFood::night($db, $freshUser, true, $operation); return ['health' => WorldFood::state($db, $freshUser)];
});
economyCheck($protected['health']['hp'] === $caughtUp['hp'], 'protected night does not add illness damage to hunger');
$food = (new Query())->from('craft_item')->where(['code' => 'world-cooked-carrot'])->one($db);
$db->transaction(static function () use ($freshStock, $freshUser, $food) { $freshStock->move($freshUser, $food, 4); });
$foodQuantity = $freshStock->quantities($freshUser)[$food['id']];
$foodKey = bin2hex(random_bytes(16)); $payload = ['code' => 'world-cooked-carrot', 'health_revision' => $caughtUp['revision']];
$meal = Command::run($freshUser, $foodKey, 'world.food.consume', $payload, static function ($operation) use ($db, $freshUser, $caughtUp) {
    return WorldFood::consume($db, $freshUser, 'world-cooked-carrot', $caughtUp['revision'], $operation);
});
$replay = Command::run($freshUser, $foodKey, 'world.food.consume', $payload, static function () { throw new \RuntimeException('Food replay repeated.'); });
economyCheck($meal === $replay && $meal['health']['hp'] === 100 && $freshStock->quantities($freshUser)[$food['id']] === $foodQuantity - 1, 'food resets hunger and replay consumes exactly one portion');
economyCheck((new Query())->from('actor_night_health')->all($db) === $illness, 'food and hunger preserve the independent night illness state');
$mealAt = Clock::$at;
economyCheck($hunger($mealAt + 259200)['hp'] === 100 && $hunger($mealAt + 259201)['hp'] === 98, 'food restarts the full three-day grace period');
$fullMeal = Command::run($freshUser, bin2hex(random_bytes(16)), 'world.food.consume', ['fixture' => 'restore'], static function ($operation) use ($db, $freshUser) {
    return WorldFood::consume($db, $freshUser, 'world-cooked-carrot', WorldFood::state($db, $freshUser)['revision'], $operation);
});
Clock::$at += 86400;
$maintenance = Command::run($freshUser, bin2hex(random_bytes(16)), 'world.food.consume', ['fixture' => 'maintenance'], static function ($operation) use ($db, $freshUser) {
    return WorldFood::consume($db, $freshUser, 'world-cooked-carrot', WorldFood::state($db, $freshUser)['revision'], $operation);
});
economyCheck($maintenance['health']['hp'] === 100 && $maintenance['restored_hp'] === 0 && $maintenance['health']['last_food_at'] === Clock::$at, 'a full-health player may eat once per day to maintain nutrition');
$zero = $hunger(Clock::$at + 100 * 86400);
economyCheck($zero['hp'] === 0 && $hunger(Clock::$at)['revision'] === $zero['revision'], 'long absence clamps health at zero without duplicate charges');
echo 'World interaction checks passed.' . PHP_EOL;
}
