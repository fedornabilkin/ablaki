<?php
/** Full disposable world fixture; never loads the application database configuration. */
require __DIR__ . '/world-interaction.php';

use WorldInteractionFixture\Clock;
use common\modules\world\models\{Command, Node, NodeFactory, NodeTemplate};
use common\modules\world\models\domain\{EconomyRuntime, WorldFood, WorldGoals};
use common\modules\world\modules\craft\models\domain\CraftWorkspace;
use yii\db\Query;

$tx = $db->beginTransaction();
try {
    Clock::$at = (new DateTimeImmutable('2026-10-23 06:00:00', new DateTimeZone('Europe/Moscow')))->getTimestamp();
    $db->createCommand()->delete('world_health_event', ['user_id' => $freshUser])->execute();
    $db->createCommand()->update('world_player_health', ['hp' => 40, 'revision' => 1], ['user_id' => $freshUser])->execute();
    foreach (['world-cooked-carrot', 'world-vegetable-broth', 'world-travel-ration'] as $code) {
        $item = (new Query())->from('craft_item')->where(['code' => $code])->one($db); $freshStock->move($freshUser, $item, 8);
    }
    $eat = static function (string $code, ?int $kitchen = null, ?string $key = null) use ($db, $freshUser) {
        $payload = ['code' => $code, 'health_revision' => WorldFood::state($db, $freshUser)['revision'], 'kitchen' => $kitchen];
        return Command::run($freshUser, $key ?? bin2hex(random_bytes(16)), 'fixture.food', $payload, static function ($operation) use ($db, $freshUser, $code, $payload, $kitchen) {
            return WorldFood::consume($db, $freshUser, $code, $payload['health_revision'], $operation, $kitchen);
        });
    };
    $foods = array_column(WorldFood::state($db, $freshUser)['foods'], null, 'code');
    economyCheck([$foods['world-cooked-carrot']['restore_hp'], $foods['world-vegetable-broth']['restore_hp'], $foods['world-travel-ration']['restore_hp']] === [4, 8, 6]
        && [$foods['world-cooked-carrot']['repeat_limit'], $foods['world-vegetable-broth']['repeat_limit'], $foods['world-travel-ration']['repeat_limit']] === [2, 1, 3]
        && [$foods['world-cooked-carrot']['repeat_seconds'], $foods['world-vegetable-broth']['repeat_seconds'], $foods['world-travel-ration']['repeat_seconds']] === [21600, 28800, 43200], 'dishes have distinct restoration, repeat limits and rolling windows');
    $first = $eat('world-cooked-carrot'); $second = $eat('world-cooked-carrot');
    $foods = array_column($second['health']['foods'], null, 'code');
    economyCheck($first['restored_hp'] === 4 && $second['restored_hp'] === 4 && !$foods['world-cooked-carrot']['can_eat']
        && $foods['world-cooked-carrot']['restores_at'] === Clock::$at + 21600 && $foods['world-vegetable-broth']['can_eat'], 'repeat exhaustion blocks only that dish and exposes the exact reset time');
    economyCheck($eat('world-vegetable-broth')['restored_hp'] === 8, 'variety remains available without exceeding eight HP');
    $over = $eat('world-cooked-carrot');
    economyCheck($over['restored_hp'] === -4 && $over['health']['disorder_until'] === Clock::$at + 10800 && !$over['health']['can_eat'], 'bypassing the visible repeat limit causes a bounded food disorder');
    economyReject(static function () use ($eat) { $eat('world-travel-ration'); }, 'FOOD_DISORDER');
    $kitchens = [];
    foreach ([1, 2] as $number) {
        $kitchen = NodeFactory::create(NodeTemplate::requireOne(['code' => 'field-kitchen']), Node::requireOne($siteId), $freshUser, 'Kitchen fixture', 'kitchen-fixture-' . $number, null, 'constructing');
        $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $kitchen->max_condition], ['id' => $kitchen->id])->execute(); $kitchens[] = (int)$kitchen->id;
    }
    $illness = (new Query())->from('actor_night_health')->all($db);
    $breakfast = $eat('world-vegetable-broth', $kitchens[0]);
    economyCheck($breakfast['restored_hp'] === 30 && WorldFood::kitchen($db, $freshUser, $kitchens[1])['meal_used'] && $breakfast['health']['disorder_until'] !== null,
        'kitchen restores the full dish, shares its window across kitchens and preserves disorder');
    economyReject(static function () use ($eat, $kitchens) { $eat('world-travel-ration', $kitchens[1]); }, 'MEAL_UNAVAILABLE');
    economyReject(static function () use ($db, $freshUser, $siteId) { WorldFood::kitchen($db, $freshUser, $siteId); }, 'KITCHEN_UNAVAILABLE');
    $db->createCommand()->update('world_node', ['visibility' => 'private'], ['id' => $kitchens[0]])->execute();
    economyReject(static function () use ($db, $kitchens) { WorldFood::kitchen($db, 9002, $kitchens[0]); }, 'KITCHEN_UNAVAILABLE');
    $db->createCommand()->update('world_node', ['visibility' => 'public'], ['id' => $kitchens[0]])->execute();
    $start = Clock::$at; Clock::$at += 4 * 3600;
    economyCheck(WorldFood::mealWindow()['current'] === null, 'the breakfast end is exclusive');
    economyReject(static function () use ($eat, $kitchens) { $eat('world-travel-ration', $kitchens[0]); }, 'MEAL_UNAVAILABLE');
    Clock::$at = $start + 6 * 3600;
    economyCheck(WorldFood::mealWindow()['current']['code'] === 'lunch' && WorldFood::state($db, $freshUser)['disorder_until'] === null, 'lunch opens at noon and expired disorder disappears without a write');
    $lunch = $eat('world-travel-ration', $kitchens[0]);
    economyCheck($lunch['restored_hp'] <= 20 && $lunch['health']['hp'] <= 100, 'lunch respects the health ceiling');
    Clock::$at = $start + 12 * 3600; $eat('world-cooked-carrot', $kitchens[1]);
    Clock::$at = $start + 16 * 3600;
    economyCheck(WorldFood::mealWindow()['current'] === null && WorldFood::mealWindow()['next']['code'] === 'breakfast', 'after dinner the next window is tomorrow breakfast');
    Clock::$at = $start + 24 * 3600;
    economyCheck(!WorldFood::kitchen($db, $freshUser, $kitchens[0])['meal_used'], 'a new Moscow date opens a new breakfast');
    economyCheck((new Query())->from('actor_night_health')->all($db) === $illness, 'all meal and disorder paths preserve night illness');
    $db->createCommand()->delete('world_goal_claim', ['user_id' => $freshUser])->execute();
    $db->createCommand()->update('world_goal_pool', ['remaining_claims' => 0])->execute();
    $goal = (new WorldGoals($db))->state($freshUser)['goals'][0];
    economyCheck($goal['completed'] && !$goal['funded'] && !$goal['can_claim'] && strpos($goal['blocked_reason'], 'Организатору') !== false, 'completed unfunded goals explain the actual budget blocker');
} finally { $tx->rollBack(); Clock::$at = null; }
$tx = $db->beginTransaction();
try {
    // The preceding source suite intentionally submits more than a real minute of work instantly.
    $db->createCommand()->update('game_command', ['created_at' => time() - 120], ['user_id' => $freshUser])->execute();
    $log = (new Query())->from('craft_item')->where(['code' => 'classic-log'])->one($db);
    $freshStock->move($freshUser, $log, 2);
    $beforeLogs = $freshStock->quantities($freshUser)[(int)$log['id']];
    $recipe = (int)(new Query())->select('id')->from('craft_recipe')->where(['code' => 'classic-stick'])->scalar($db);
    $workspace = new CraftWorkspace($db, new \common\modules\world\models\domain\WorldFlags($db, $app->getModule('world')));
    $payload = ['node_id' => $siteId, 'recipe_id' => $recipe, 'quantity' => 2, 'source_storage_ids' => [$backpackId], 'output_storage_id' => $backpackId, 'equipment_instance_ids' => [], 'use_object_budget' => false];
    $quote = $workspace->preview($freshUser, $payload); $key = bin2hex(random_bytes(16));
    $result = $workspace->craft($freshUser, $key, $payload, $quote['quote_id'], (array)$quote['expected_revisions']);
    economyCheck($result['craft_result']['output']['quantity'] === 8 && $result['craft_result']['materials'] === [['item_id' => (int)$log['id'], 'name' => $log['name'], 'quantity' => 2]]
        && $result['craft_result']['price_cr'] === '0.0000' && $freshStock->quantities($freshUser)[(int)$log['id']] === $beforeLogs - 2, 'workspace reports the accepted output and actual log expense for two batches');
    economyCheck($workspace->craft($freshUser, $key, $payload, $quote['quote_id'], (array)$quote['expected_revisions']) === $result
        && $freshStock->quantities($freshUser)[(int)$log['id']] === $beforeLogs - 2, 'craft retry preserves its result without consuming materials again');
} finally { $tx->rollBack(); }
echo 'World feedback and food checks passed.' . PHP_EOL;
