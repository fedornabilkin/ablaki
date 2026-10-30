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
$economyController = new \common\modules\world\apiControllers\EconomyApiController('economy', $app->getModule('world'));
$rootIdentity = (new Query())->from('world_node')->where(['id' => EconomyRuntime::world($db)])->one($db);
$economyState = $economyController->actionIndex();
economyCheck($economyState['world_instance'] === $rootIdentity['id'] . ':' . $rootIdentity['created_at'], 'economy state identifies the active world instance independently of the current page');
$identityTx = $db->beginTransaction();
try {
    $db->createCommand()->update('world_node', ['created_at' => (int)$rootIdentity['created_at'] + 1], ['id' => $rootIdentity['id']])->execute();
    $recreatedState = $economyController->actionIndex();
    economyCheck($recreatedState['world_instance'] !== $economyState['world_instance'] && $recreatedState['profile_version'] === $economyState['profile_version'], 'recreating a root with the same numerical ID starts a separate browser checklist without changing the economy profile');
} finally { $identityTx->rollBack(); }

echo 'World feedback and food checks passed.' . PHP_EOL;

$overview = (new \common\modules\world\models\domain\WorldBalanceOverview($db))->report(2);
economyCheck($overview['starter_total_cr'] === '500.0000' && $overview['orders_total_cr'] === '820.0000'
    && $overview['goals_total_cr'] === '300.0000' && $overview['required_budget_cr'] === '1620.0000'
    && $overview['required_total_cr'] === '1620.0000', 'administrative overview exactly separates two-player starter transfers, finite orders and rewards');

$finiteUser = 9010;
$db->createCommand()->insert('user', ['id' => $finiteUser, 'username' => 'FiniteSupplyFixture'])->execute();
$db->createCommand()->insert('persone', ['id' => $finiteUser, 'user_id' => $finiteUser, 'credit' => '0.0000', 'balance' => '0.0000'])->execute();
if (!$app->authManager->getAssignment('world-tester', $finiteUser)) $app->authManager->assign($app->authManager->getRole('world-tester'), $finiteUser);
$finiteStore = new \common\modules\world\modules\craft\models\domain\CraftStorage($db);
$previousWorld = EconomyRuntime::world($db);
$db->transaction(static function () use ($db, &$finiteCity) {
    $root = NodeFactory::create(NodeTemplate::requireOne(['code' => 'world']), null, null, 'Finite supply test world', 'finite-supply-world');
    $region = NodeFactory::create(NodeTemplate::requireOne(['code' => 'region']), $root, null, 'Finite supply region', 'finite-supply-region');
    $city = NodeFactory::create(NodeTemplate::requireOne(['code' => 'city']), $region, null, 'Finite supply city', 'finite-supply-city');
    $finiteCity = (int)$city->id;
    $db->createCommand()->update('world_registry', ['active_world_id' => $root->id], ['id' => 1])->execute();
});
$joined = Command::run($finiteUser, bin2hex(random_bytes(16)), 'world.start', ['city_id' => $finiteCity], static function ($op) use ($finiteUser, $finiteCity) {
    return \common\modules\world\models\StarterPack::enter($finiteUser, $finiteCity, $op);
});
$finiteBuild = \common\modules\world\models\Build::requireOne($joined['build']['id']);
Command::run($finiteUser, bin2hex(random_bytes(16)), 'fixture.build.complete', [], static function ($op) use ($finiteBuild) {
    $finiteBuild->worked_seconds = $finiteBuild->required_seconds; $finiteBuild->finish($op); return [];
});
$claimFinite = static function ($key) use ($finiteUser, $finiteBuild) {
    $node = (int)$finiteBuild->node_id;
    return Command::run($finiteUser, $key, 'world.supplies.gather', ['node_id' => $node], static function ($op) use ($node, $finiteUser) {
        return \common\modules\world\models\Supplies::gather($node, $finiteUser, $op);
    });
};
$finite = new \common\modules\world\models\domain\FiniteSupplies($db);
$tx = $db->beginTransaction();
try {
    $ordinary = array_column($finite->state($finiteUser)['items'], 'quantity', 'code');
    $forest = NodeFactory::create(NodeTemplate::requireOne(['code' => 'forest-plot']), Node::requireOne($finiteBuild->node_id), $finiteUser, 'Supply exclusion fixture', 'supply-forest', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active'], ['id' => $forest->id])->execute();
    $reduced = array_column($finite->state($finiteUser)['items'], 'quantity', 'code');
    economyCheck($reduced['classic-log'] === intdiv($ordinary['classic-log'] + 1, 2), 'a completed source halves daily raw supplies instead of removing them');
    EconomyRuntime::fact($db, $finiteUser, 'built:forest-plot', 1, $joined['operation_id']);
    $db->createCommand()->update('world_node', ['status' => 'archived'], ['id' => $forest->id])->execute();
    economyCheck(array_column($finite->state($finiteUser)['items'], 'quantity', 'code')['classic-log'] === $reduced['classic-log'], 'archiving a source does not restore the full daily grant');
    $stoneMine = NodeFactory::create(NodeTemplate::requireOne(['code' => 'mine']), Node::requireOne($finiteBuild->node_id), $finiteUser, 'Daily stone source', 'daily-stone-source', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active'], ['id' => $stoneMine->id])->execute();
    $stonePlan = (new \common\modules\world\models\domain\SupplyGrant($db))->plan($finiteUser, 'gather');
    economyCheck(array_column($stonePlan['items'], 'quantity', 'code')['classic-stone'] === intdiv($ordinary['classic-stone'] + 1, 2), 'a built mine retains half the daily stone needed to make its first pickaxe');
} finally { $tx->rollBack(); }
$counts = [];
try {
    for ($day = 0; $day < 5; $day++) {
        Clock::$at = strtotime('2026-10-24T12:00:00+03:00') + $day * 86400;
        $key = bin2hex(random_bytes(16));
        $beforeCount = (int)(new Query())->from('world_supply_claim')->where(['user_id' => $finiteUser])->count('*', $db);
        $claimFinite($key);
        $claimed = (new Query())->from('world_supply_claim')->where(['user_id' => $finiteUser, 'item_code' => 'classic-log'])->orderBy(['ordinal' => SORT_DESC])->one($db);
        $counts[] = (int)$claimed['quantity'];
        $afterCount = (int)(new Query())->from('world_supply_claim')->where(['user_id' => $finiteUser])->count('*', $db);
        $claimFinite($key);
        economyCheck($afterCount > $beforeCount && (int)(new Query())->from('world_supply_claim')->where(['user_id' => $finiteUser])->count('*', $db) === $afterCount, 'finite supply retry never repeats item receipts: day ' . ($day + 1));
    }
    economyCheck($counts === [14, 12, 10, 8, 6] && $finite->state($finiteUser)['gather_minimum'] && !$finite->state($finiteUser)['gather_available'], 'five decreasing claims preserve the daily cooldown and switch to minimum supply');
    economyReject(static function () use ($claimFinite) { $claimFinite(bin2hex(random_bytes(16))); }, 'SUPPLIES_ALREADY_CLAIMED');
    $history = (new \common\modules\world\models\domain\EconomyHistory($db))->listing($finiteUser, 'commands', 1, 'снабжение');
    if (!$history['items']) $history = (new \common\modules\world\models\domain\EconomyHistory($db))->listing($finiteUser, 'commands', 1, 'gather');
    $details = array_merge(...array_column($history['items'], 'items'));
    economyCheck((bool)array_filter($details, static function ($item) { return $item['code'] === 'classic-log' && $item['name'] !== '' && $item['quantity'] === 6 && $item['direction'] === 'collected'; }), 'history exposes the gathered item, accepted quantity and collection direction');
    $nextProfile = EconomyRuntime::current($db)['raw_profile']; $nextProfile['daily']['claim_limit'] = 6;
    $publisher = new \common\modules\world\models\domain\EconomyProfilePublisher($db); $nextPreview = $publisher->preview($nextProfile);
    $publisher->publish($nextProfile, $nextPreview['digest'], 9001, 'Finite allowance compatibility check');
    economyCheck($finite->state($finiteUser)['gather_minimum'] && $finite->state($finiteUser)['gather_remaining'] === 0, 'publishing a larger allowance does not refill old resources above the minimum');
    Clock::$at += 86400;
    $minimumState = $finite->state($finiteUser);
    economyCheck($minimumState['gather_available'] && count($minimumState['items']) === 7 && array_unique(array_column($minimumState['items'], 'quantity')) === [1], 'next day retains one of every raw material, including sand and clay');
    $minimumKey = bin2hex(random_bytes(16)); $claimFinite($minimumKey); $claimFinite($minimumKey);
    economyCheck((int)(new Query())->from('world_supply_claim')->where(['user_id' => $finiteUser, 'ordinal' => 6])->count('*', $db) === 7 && !$finite->state($finiteUser)['gather_available'], 'minimum claim and replay apply once per resource and world day');
    $newItem = (new Query())->from('craft_item')->where(['code' => 'classic-log'])->one($db); unset($newItem['id']);
    $newItem['code'] = 'fixture-new-daily-resource'; $newItem['name'] = 'Новый ресурс снабжения'; $newItem['label'] = $newItem['name']; $newItem['gather_quantity'] = 1;
    $db->createCommand()->insert('craft_item', $newItem)->execute();
    $newState = $finite->state($finiteUser);
    economyCheck(array_column($newState['items'], 'code') === ['fixture-new-daily-resource'] && $newState['gather_available'], 'only the newly introduced catalogue resource reopens its own allowance');
    $claimFinite(bin2hex(random_bytes(16)));
    economyCheck((int)(new Query())->from('world_supply_claim')->where(['user_id' => $finiteUser, 'item_code' => 'classic-log', 'ordinal' => 6])->count('*', $db) === 1, 'a new catalogue resource cannot repeat even the minimum of an old resource');
} finally { Clock::$at = null; $db->createCommand()->update('world_registry', ['active_world_id' => $previousWorld], ['id' => 1])->execute(); }

$tx = $db->beginTransaction();
try {
    $worldId = \common\modules\world\models\domain\EconomyRuntime::world($db);
    $clockNights = new \common\modules\world\models\domain\WorldNights($db, new \common\modules\world\models\domain\WorldFlags($db, $app->getModule('world')));
    $clockNights->activate($worldId, ['day_seconds' => 86400, 'night_offset' => 57600, 'night_seconds' => 28800, 'max_severity' => 3, 'recovery_nights' => 2, 'mild_efficiency_bps' => 8000, 'severe_efficiency_bps' => 5000]);
    $clockMembership = (new Query())->from('world_membership')->where(['world_id' => $worldId, 'user_id' => $freshUser])->one($db);
    $clockNights->joined($clockMembership);
    $clockQueue = new \common\modules\world\support\JobQueue($db); $enrollment = $clockQueue->claim(['world.night.resolve']);
    economyCheck($enrollment && $clockQueue->perform($enrollment, static function ($payload, $job) use ($clockNights) { $clockNights->resolve($payload, $job); }), 'clock fixture enrolls a real member and queues the next night');
    $root = Node::requireOne($worldId); $clock = \common\modules\world\models\WorldTime::class; $instant = time();
    $beforeGame = $clock::gameAt($db, $instant, $worldId);
    $beforeProfile = EconomyRuntime::current($db)['profile'];
    $template = NodeTemplate::requireOne(['code' => 'starter-shelter']); $nominalWork = $template->worldWorkSeconds();
    $beforeBuilds = (new Query())->select(['id', 'required_seconds', 'worked_seconds'])->from('world_build')->orderBy('id')->all($db);
    $beforeTerms = \common\modules\world\models\domain\EconomyConstruction::terms($template);
    $oldPolicy = (new \common\modules\world\models\domain\WorldNights($db, new \common\modules\world\models\domain\WorldFlags($db, $app->getModule('world'))))->policy($worldId);
    $oldPeriods = (new Query())->from('world_night_period')->all($db);
    $settings = json_decode($root->settings_json ?: '{}', true); $oldClockSettings = $settings; $settings['world_time_rate'] = 2; $root->settings_json = json_encode($settings);
    economyCheck($root->save(false), 'world speed is changed through the existing serialized administrative model');
    $newClockSettings = json_decode($root->settings_json, true); $anchor = end($newClockSettings['world_clock_segments']);
    $instant = $anchor['real_at']; $beforeGame = $clock::gameAt($db, $instant, $worldId, $oldClockSettings);
    economyCheck($clock::gameAt($db, $instant, $worldId) === $beforeGame && $clock::gameAt($db, $instant + 60, $worldId) === $beforeGame + 120,
        'changing the global coefficient keeps world time continuous and advances it at the new rate');
    economyCheck($clock::realAt($db, $beforeGame + 120, $worldId) === $instant + 60, 'world deadlines map back to real timestamps');
    economyCheck($template->realWorkSeconds() === (int)ceil($nominalWork / 2)
        && $beforeBuilds === (new Query())->select(['id', 'required_seconds', 'worked_seconds'])->from('world_build')->orderBy('id')->all($db)
        && $beforeTerms['terms_digest'] !== \common\modules\world\models\domain\EconomyConstruction::terms($template)['terms_digest'], 'new construction uses the global rate, refreshes its quote and preserves accepted construction terms');
    if ($oldPolicy) {
        $policy = (new \common\modules\world\models\domain\WorldNights($db, new \common\modules\world\models\domain\WorldFlags($db, $app->getModule('world'))))->policy($worldId);
        $segment = end($policy['time_segments']); $first = (int)$segment['from_sequence'];
        economyCheck($segment['day_seconds'] === (int)ceil($oldPolicy['day_seconds'] / 2)
            && ($first === 0 || \common\modules\world\models\domain\NightCalendar::bounds($oldPolicy, $first - 1) === \common\modules\world\models\domain\NightCalendar::bounds($policy, $first - 1))
            && (new Query())->from('world_night_period')->all($db) === $oldPeriods, 'clock changes scale future nights and preserve earlier night bounds and persisted periods');
        foreach ((new Query())->from('game_job')->where(['type' => 'world.night.resolve', 'status' => 'pending'])->all($db) as $job) {
            $payload = json_decode($job['payload_json'], true);
            if ((int)$payload['policy_id'] !== (int)$policy['id'] || !(int)$payload['cursor']) continue;
            $cursor = (int)$payload['cursor'];
            $grace = (new Query())->select('grace_until')->from('actor_night_health')->where(['membership_id' => $payload['membership_id'], 'policy_id' => $policy['id']])->scalar($db);
            if ($grace !== false) $cursor = max($cursor, \common\modules\world\models\domain\NightCalendar::firstStartingAt($policy, (int)$grace));
            economyCheck((int)$job['available_at'] >= \common\modules\world\models\domain\NightCalendar::bounds($policy, $cursor)['ended_at'], 'a pending night worker keeps a future deadline instead of completing its unchanged cursor prematurely');
        }
    }
    $afterProfile = EconomyRuntime::current($db)['profile'];
    economyCheck($afterProfile['actions']['gather-log']['work_seconds'] === (int)ceil($beforeProfile['actions']['gather-log']['work_seconds'] / 2)
        && $afterProfile['objects']['house']['price_cr'] === $beforeProfile['objects']['house']['price_cr'], 'new work durations share the global coefficient without changing prices');
    $newSettings = json_decode($root->settings_json, true); $newSettings['world_time_rate'] = 1; $root->settings_json = json_encode($newSettings); $root->save(false);
    $restoredSettings = json_decode($root->settings_json, true); $restored = end($restoredSettings['world_clock_segments']);
    economyCheck($restored['game_at'] === $clock::gameAt($db, $restored['real_at'], $worldId, $newClockSettings)
        && $clock::gameAt($db, $restored['real_at'] + 60, $worldId) === $restored['game_at'] + 60, 'returning to 1:1 preserves the clock origin');
} finally { $tx->rollBack(); }
echo 'Local MVP clock, finite supplies, administrative finances and history checks passed.' . PHP_EOL;

// Topic 82: all money comes from existing budgets. Isolated fixture, rolled back as a whole.
$tx = $db->beginTransaction();
try {
    $db->createCommand()->update('world_registry', ['active_world_id' => Node::requireOne($finiteCity)->root_id], ['id' => 1])->execute();
    $workerUser = 9011;
    $db->createCommand()->insert('user', ['id' => $workerUser, 'username' => 'HiredWorkerFixture'])->execute();
    $db->createCommand()->insert('persone', ['id' => $workerUser, 'user_id' => $workerUser, 'credit' => '0.0000', 'balance' => '0.0000'])->execute();
    $app->authManager->assign($app->authManager->getRole('world-tester'), $workerUser);
    $db->createCommand()->update('persone', ['credit' => '10000.0000'], ['user_id' => 9001])->execute();
    $run = static function (int $user, string $type, array $payload, callable $action, ?string $key = null) {
        return Command::run($user, $key ?? bin2hex(random_bytes(16)), $type, $payload, $action);
    };
    $joinKey = bin2hex(random_bytes(16));
    $joinWorker = static function ($op) use ($workerUser, $finiteCity) { return \common\modules\world\models\StarterPack::enter($workerUser, $finiteCity, $op); };
    $workerStart = $run($workerUser, 'world.start', ['city_id' => $finiteCity], $joinWorker, $joinKey);
    $db->createCommand()->insert('world_player_health', ['world_id' => Node::requireOne($finiteCity)->root_id, 'user_id' => $workerUser, 'hp' => 100, 'revision' => 1])->execute();
    $funding = new \common\modules\world\models\domain\StarterFunding($db);
    economyCheck($funding->forUser($finiteUser)['status'] === 'pending' && $funding->forUser($workerUser)['status'] === 'pending' && !$funding->preview($finiteCity)['available'], 'unfunded starts disclose a FIFO budget obligation before joining');
    economyCheck($run($workerUser, 'world.start', ['city_id' => $finiteCity], $joinWorker, $joinKey) === $workerStart && (int)(new Query())->from('world_starter_funding')->where(['user_id' => $workerUser])->count('*', $db) === 1, 'first placement and its retry create one lifetime starter obligation');
    $total = static function () use ($db) {
        $money = \common\modules\world\modules\economy\value\Money::parse('0');
        foreach ([['persone', 'credit'], ['economy_account', 'amount']] as $source) foreach ((new Query())->select($source[1])->from($source[0])->column($db) as $value) $money = $money->add(\common\modules\world\modules\economy\value\Money::parse((string)$value));
        return $money->decimal();
    };
    $initialTotal = $total();
    $fund = static function (int $node, string $amount, ?string $key = null) use ($run, $db, $finiteCity) {
        return $run(9001, 'fixture.budget', ['node_id' => $node, 'amount' => $amount], static function ($op) use ($db, $node, $amount, $finiteCity) {
            $result = (new \common\modules\world\modules\economy\models\domain\BudgetFunding($db))->contribute(9001, $node, \common\modules\world\modules\economy\value\Money::parse($amount), 'Fixture funds', $op);
            if ($node === $finiteCity) (new \common\modules\world\models\domain\StarterReserve($db))->configure(9001, $node, '250.0000', $amount, $op, true);
            return $result;
        }, $key);
    };
    $fundKey = bin2hex(random_bytes(16)); $fund($finiteCity, '499.9999', $fundKey);
    economyCheck($funding->forUser($finiteUser)['status'] === 'paid' && $funding->forUser($workerUser)['status'] === 'pending', 'FIFO pays the first full starter amount and never partially funds the second');
    $fund($finiteCity, '499.9999', $fundKey); $fund($finiteCity, '0.0001');
    economyCheck($funding->forUser($workerUser)['status'] === 'paid' && $total() === $initialTotal, 'fourth decimal completes the second starter budget; contribution replay preserves total funds');
    $siteBudget = (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->accounts((int)$workerStart['node']['id'])['budget'];
    economyCheck(\common\modules\world\modules\economy\value\Money::parse((string)$siteBudget['amount'])->decimal() === '250.0000' && (float)(new Query())->select('credit')->from('persone')->where(['user_id' => $workerUser])->scalar($db) === 0.0, 'starter funding belongs to the campsite budget, not the personal wallet');
    $cancel = \common\modules\world\models\Build::requireOne($workerStart['build']['id']);
    $run($workerUser, 'fixture.cancel', ['build_id' => (int)$cancel->id], static function ($op) use ($cancel) { $cancel->finish($op, true); return []; });
    economyCheck($funding->forUser($workerUser)['status'] === 'cancelled' && $total() === $initialTotal && (float)(new Query())->select('credit')->from('persone')->where(['user_id' => $workerUser])->scalar($db) === 0.0, 'cancelling a financed campsite returns the parent grant without cashing it out');

    $mine = NodeFactory::create(NodeTemplate::requireOne(['code' => 'mine']), Node::requireOne($finiteBuild->node_id), $finiteUser, 'Paid mine fixture', 'paid-mine-fixture', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $mine->max_condition], ['id' => $mine->id])->execute(); $mine->refresh();
    // An explicit administrator edge tests custom placement depth independently of the built-in catalog.
    $db->createCommand()->insert('world_template_parent', ['template_id' => NodeTemplate::requireOne(['code' => 'forest-plot'])->id, 'parent_template_id' => $mine->template_id])->execute();
    $nested = NodeFactory::create(NodeTemplate::requireOne(['code' => 'forest-plot']), $mine, $finiteUser, 'Hierarchy strength fixture', 'nested-strength-fixture', null, 'constructing');
    economyCheck($nested->max_condition < $mine->max_condition && $mine->max_condition < Node::requireOne($finiteBuild->node_id)->max_condition, 'strength follows actual placement depth even when the template permits different levels');
    (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->provision((int)$mine->id, $joined['operation_id']);
    economyReject(static function () use ($mine, $finiteUser) { \common\modules\world\models\domain\HiredExtraction::configure($mine, $finiteUser, true, '2'); }, 'EMPLOYER_STORAGE_REQUIRED');
    $mini = NodeFactory::create(NodeTemplate::requireOne(['code' => 'mini-storage-level-' . ((int)$mine->hierarchy_level + 1)]), $mine, $finiteUser, 'Mine mini-storage', 'mine-mini-storage', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $mini->max_condition], ['id' => $mini->id])->execute();
    $db->createCommand()->insert('craft_storage', ['identity_key' => 'stockpile:fixture:paid-mine', 'kind' => 'stockpile', 'node_id' => $mini->id, 'owner_user_id' => $finiteUser, 'capacity' => 5])->execute();
    $stockId = (int)$db->getLastInsertID();
    $destinations = \common\modules\world\models\domain\ExtractionStorage::choices($mine, $finiteUser);
    economyCheck(array_column($destinations, 'kind') === ['backpack', 'stockpile'] && $destinations[1]['id'] === $stockId
        && \common\modules\world\models\domain\ExtractionStorage::ownerTarget($mine, $finiteUser, null) === $destinations[0]['id'], 'owner extraction defaults to backpack and offers only its own local mini-storage');
    economyCheck(\common\modules\world\models\domain\ExtractionStorage::ownerTarget($mine, $finiteUser, $stockId) === $stockId, 'owner can explicitly route extraction to the local mini-storage');
    economyReject(static function () use ($mine, $finiteUser) { \common\modules\world\models\domain\ExtractionStorage::ownerTarget($mine, $finiteUser, 2147483647); }, 'SOURCE_STORAGE_UNAVAILABLE');
    $workerStock = new \common\modules\world\modules\craft\models\domain\CraftStorage($db);
    $stoneId = (int)(new Query())->select('id')->from('craft_item')->where(['code' => 'classic-stone'])->scalar($db);
    $workerStoneBefore = $workerStock->quantities($workerUser)[$stoneId] ?? 0;
    $pickaxe = (new Query())->from('craft_item')->where(['code' => 'world-stone-pickaxe'])->one($db); $workerStock->move($workerUser, $pickaxe, 1);
    $settings = static function (bool $enabled, string $wage, ?int $target = null) use ($run, $mine, $finiteUser, $stockId) {
        return $run($finiteUser, 'world.mining.settings', ['mining_node_id' => (int)$mine->id, 'enabled' => $enabled, 'reward_cr' => $wage, 'storage_id' => $target ?? $stockId], static function () use ($mine, $finiteUser, $enabled, $wage, $target, $stockId) {
            return \common\modules\world\models\domain\HiredExtraction::configure(Node::requireOne($mine->id), $finiteUser, $enabled, $wage, $target ?? $stockId);
        });
    };
    $settings(true, '2.0000');
    foreach (['NaN', '0', '-1', '1.00001', '900000000000001'] as $badWage) economyReject(static function () use ($settings, $badWage) { $settings(true, $badWage); }, 'INVALID_WAGE');
    $startHired = static function (?string $key = null) use ($run, $mine, $workerUser) {
        return $run($workerUser, 'world.mining.start', ['mining_node_id' => (int)$mine->id, 'action_code' => 'mine-stone'], static function ($op) use ($mine, $workerUser) {
            return \common\modules\world\models\Mining::start((int)$mine->id, $workerUser, $op, 'mine-stone', null);
        }, $key);
    };
    economyReject($startHired, 'INSUFFICIENT_WAGE_BUDGET'); $fund((int)$mine->id, '6.0000');
    $db->createCommand()->update('world_player_health', ['hp' => 59], ['user_id' => $workerUser])->execute();
    economyReject($startHired, 'WORKER_HEALTH_REQUIRED');
    $db->createCommand()->update('world_player_health', ['hp' => 100], ['user_id' => $workerUser])->execute();
    $db->createCommand()->update('world_node', ['condition' => (int)floor($mine->max_condition * 0.49)], ['id' => $mine->id])->execute();
    economyReject($startHired, 'MINE_REPAIR_REQUIRED');
    $db->createCommand()->update('world_node', ['condition' => $mine->max_condition], ['id' => $mine->id])->execute(); $mine->refresh();
    $key = bin2hex(random_bytes(16)); $accepted = $startHired($key);
    $mineAccount = (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->accounts((int)$mine->id)['budget'];
    economyCheck($accepted['output_storage_id'] === $stockId && \common\modules\world\modules\economy\value\Money::parse((string)$mineAccount['reserved'])->decimal() === '2.0000' && $startHired($key) === $accepted, 'hired acceptance fixes employer stockpile and reserves exactly one batch wage');
    $settings(false, '5.0000');
    $heartbeat = static function (?string $key = null) use ($run, $mine, $workerUser) {
        return $run($workerUser, 'world.mining.heartbeat', ['mining_node_id' => (int)$mine->id], static function ($op) use ($mine, $workerUser) { return \common\modules\world\models\Mining::heartbeat((int)$mine->id, $workerUser, $op); }, $key);
    };
    $db->createCommand()->update('craft_storage', ['capacity' => 0], ['id' => $stockId])->execute();
    for ($i = 0; $i < 10; $i++) {
        $db->createCommand()->update('world_mining_worker', ['accounted_at' => time() - 60, 'last_seen_at' => time()], ['user_id' => $workerUser])->execute();
        $delivered = $heartbeat(); if ($delivered['pending_quantity']) break;
    }
    economyCheck($delivered['pending_quantity'] === 1 && (float)(new Query())->select('credit')->from('persone')->where(['user_id' => $workerUser])->scalar($db) === 0.0 && !(new Query())->from('craft_inventory')->where(['storage_id' => $stockId])->exists($db), 'full employer stockpile keeps earned work and reserved wage with no partial delivery or payout');
    $db->createCommand()->update('craft_storage', ['capacity' => 5], ['id' => $stockId])->execute();
    $failedKey = bin2hex(random_bytes(16));
    try {
        $run($workerUser, 'fixture.fail-after-wage', ['mining_node_id' => (int)$mine->id], static function ($op) use ($mine, $workerUser) {
            \common\modules\world\models\Mining::heartbeat((int)$mine->id, $workerUser, $op); throw new \RuntimeException('Injected failure after wage');
        }, $failedKey);
        throw new \LogicException('Missing injected wage failure.');
    } catch (\RuntimeException $error) { if ($error->getMessage() !== 'Injected failure after wage') throw $error; }
    economyCheck((float)(new Query())->select('credit')->from('persone')->where(['user_id' => $workerUser])->scalar($db) === 0.0 && !(new Query())->from('craft_inventory')->where(['storage_id' => $stockId])->exists($db)
        && !(new Query())->from('game_command')->where(['user_id' => $workerUser, 'request_key' => $failedKey])->exists($db), 'failure after inventory and wage rolls both back with the idempotency record');
    $payKey = bin2hex(random_bytes(16)); $result = $heartbeat($payKey);
    $stoneId = (int)(new Query())->select('id')->from('craft_item')->where(['code' => 'classic-stone'])->scalar($db);
    economyCheck($result['produced_quantity'] === 1 && (int)(new Query())->from('craft_inventory')->where(['storage_id' => $stockId, 'item_id' => $stoneId])->sum('item_quantity', $db) > 0
        && \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $workerUser])->scalar($db))->decimal() === '2.0000', 'delivery pays the accepted wage after hiring was disabled and the next wage changed');
    economyCheck($heartbeat($payKey) === $result && $total() === $initialTotal && ($workerStock->quantities($workerUser)[$stoneId] ?? 0) === $workerStoneBefore, 'paid batch replay preserves credits and resources remain solely in employer stock');
    $helper = 9012;
    $db->createCommand()->insert('user', ['id' => $helper, 'username' => 'BuilderCapFixture'])->execute();
    $db->createCommand()->insert('persone', ['id' => $helper, 'user_id' => $helper, 'credit' => '0.0000', 'balance' => '0.0000'])->execute();
    $app->authManager->assign($app->authManager->getRole('world-tester'), $helper);
    $projectNode = NodeFactory::create(NodeTemplate::requireOne(['code' => 'forest-plot']), Node::requireOne($finiteBuild->node_id), $finiteUser, 'Builder slots fixture', 'builder-slots-fixture', null, 'constructing');
    $nodeSettings = json_decode($projectNode->settings_json, true); $nodeSettings['builder_limit_enabled'] = true; $nodeSettings['accepted_build_minimum_cr'] = '2.0000';
    $db->createCommand()->update('world_node', ['settings_json' => json_encode($nodeSettings)], ['id' => $projectNode->id])->execute();
    $limitedBuild = new \common\modules\world\models\Build();
    $limitedBuild->setAttributes(['node_id' => $projectNode->id, 'parent_id' => $finiteBuild->node_id, 'owner_user_id' => $finiteUser, 'required_seconds' => 3600, 'worked_seconds' => 0, 'labor_budget' => '2.0000', 'materials_json' => '{}', 'status' => 'building', 'revision' => 1, 'created_at' => time()], false);
    if (!$limitedBuild->save(false)) throw new RuntimeException('Builder fixture failed.');
    $startBuilder = static function (int $user) use ($run, $limitedBuild) { return $run($user, 'world.build.start', ['build_id' => (int)$limitedBuild->id], static function () use ($limitedBuild, $user) { return \common\modules\world\models\BuildWork::start($limitedBuild, $user); }); };
    $startBuilder($workerUser); economyReject(static function () use ($startBuilder, $helper) { $startBuilder($helper); }, 'BUILDERS_LIMIT');
    $db->createCommand()->update('world_build_work', ['last_seen_at' => time() - 61, 'accounted_at' => time()], ['build_id' => $limitedBuild->id, 'user_id' => $workerUser])->execute();
    $startBuilder($helper);
    economyReject(static function () use ($run, $limitedBuild, $workerUser) { $run($workerUser, 'world.build.heartbeat', ['build_id' => (int)$limitedBuild->id], static function ($op) use ($limitedBuild, $workerUser) { return \common\modules\world\models\BuildWork::heartbeat($limitedBuild, $workerUser, $op, false); }); }, 'BUILDERS_LIMIT');
    economyCheck($limitedBuild->builderLimit() === 1 && \common\modules\world\models\BuildStrength::forTemplate(NodeTemplate::requireOne(['code' => 'starter-site'])) > \common\modules\world\models\BuildStrength::forTemplate(NodeTemplate::requireOne(['code' => 'house'])), 'two-credit builder cap also protects expired heartbeat resumption and campsite is stronger than its children');
    \common\modules\world\models\BuildWork::closeAll((int)$limitedBuild->id);
    $garden = NodeFactory::create(NodeTemplate::requireOne(['code' => 'garden']), Node::requireOne($finiteBuild->node_id), $finiteUser, 'Digging fixture', 'digging-fixture', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $garden->max_condition], ['id' => $garden->id])->execute();
    (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->provision((int)$garden->id, $joined['operation_id']);
    $bedFixture = static function (string $code) use ($db, $garden, $finiteUser) {
        $bed = NodeFactory::create(NodeTemplate::requireOne(['code' => 'garden-bed']), Node::requireOne($garden->id), $finiteUser, $code, $code, null, 'constructing');
        $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $bed->max_condition, 'unlocked' => 1], ['id' => $bed->id])->execute();
        $db->createCommand()->insert('world_build', ['node_id' => $bed->id, 'parent_id' => $garden->id, 'owner_user_id' => $finiteUser, 'required_seconds' => 30, 'worked_seconds' => 30, 'labor_budget' => '0.0000', 'materials_json' => '{}', 'status' => 'completed', 'revision' => 1, 'created_at' => time(), 'finished_at' => time()])->execute();
        return (int)$bed->id;
    };
    $ownBed = $bedFixture('free-dig-bed'); $hiredBed = $bedFixture('paid-dig-bed');
    $shovel = (new Query())->from('craft_item')->where(['code' => 'world-shovel'])->one($db);
    $finiteStore->move($finiteUser, $shovel, 1); $workerStock->move($workerUser, $shovel, 1);
    $flags = new \common\modules\world\models\domain\WorldFlags($db, $app->getModule('world'));
    $ownerCrop = new \common\modules\world\models\domain\WorldCultivation($db, $flags, new \common\modules\world\models\domain\WorldAccessPolicy($finiteUser));
    $workerCrop = new \common\modules\world\models\domain\WorldCultivation($db, $flags, new \common\modules\world\models\domain\WorldAccessPolicy($workerUser));
    $input = ['bed_id' => $ownBed, 'top_up' => false]; $q = $ownerCrop->preview($finiteUser, 'dig', $input);
    economyCheck($q['terms']['price_cr'] === '0.0000' && $q['terms']['worker_reward_cr'] === '0.0000', 'owner digging quote costs no credits and promises no wage');
    $ownerCrop->execute($finiteUser, 'dig', $input, bin2hex(random_bytes(16)), $q['quote_id'], (array)$q['expected_revisions']);
    $input = ['bed_id' => $hiredBed, 'top_up' => false];
    economyReject(static function () use ($workerCrop, $workerUser, $input) { $workerCrop->preview($workerUser, 'dig', $input); }, 'GARDEN_WAGE_UNAVAILABLE');
    $fund((int)$garden->id, '5'); $q = $workerCrop->preview($workerUser, 'dig', $input); $digKey = bin2hex(random_bytes(16));
    $dig = $workerCrop->execute($workerUser, 'dig', $input, $digKey, $q['quote_id'], (array)$q['expected_revisions']);
    economyCheck($q['terms']['worker_reward_cr'] === '1.0000' && $workerCrop->execute($workerUser, 'dig', $input, $digKey, $q['quote_id'], (array)$q['expected_revisions']) === $dig
        && \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $workerUser])->scalar($db))->decimal() === '3.0000' && $total() === $initialTotal, 'hired digging transfers one garden-funded wage and replay cannot pay it twice');
    economyReject(static function () use ($workerCrop, $workerUser, $input) { $workerCrop->preview($workerUser, 'sow', $input); }, 'BED_UNAVAILABLE');
    $tx->commit();
    $dugState = $workerCrop->state($workerUser, $hiredBed);
    economyCheck(!$dugState['owner'] && $dugState['dug'] && !$dugState['writable'] && $dugState['cycle'] === null, 'after hired digging the worker sees completion without access to owner crop operations');
    economyCheck((new \common\modules\world\models\domain\WorldBalanceOverview($db))->report(2)['paid_starter_cr'] === '250.0000', 'administrative finance overview includes settled and cancelled starter obligations');
} finally { if ($tx->isActive) $tx->rollBack(); }
echo 'Topic 82 starter funding and hired extraction checks passed.' . PHP_EOL;

$tx = $db->beginTransaction();
try {
    $reserve = new \common\modules\world\models\domain\StarterReserve($db);
    $configure = static function (string $payout, string $addition, ?string $key = null) use ($db, $finiteCity) {
        return Command::run(9001, $key ?? bin2hex(random_bytes(16)), 'world.start.reserve', ['node_id' => $finiteCity, 'payout_cr' => $payout, 'add_amount_cr' => $addition], static function ($op) use ($db, $finiteCity, $payout, $addition) {
            return (new \common\modules\world\models\domain\StarterReserve($db))->configure(9001, $finiteCity, $payout, $addition, $op, true);
        });
    };
    $before = $total(); $key = bin2hex(random_bytes(16)); $set = $configure('100.0000', '0', $key);
    economyCheck($reserve->state($finiteCity)['available_places'] === 2 && $configure('100.0000', '0', $key) === $set && $total() === $before, 'per-settler rate changes available places without changing reserve or duplicating a command');
    $region = (int)Node::requireOne($finiteCity)->parent_id;
    economyCheck($reserve->availability($region, $finiteUser)['available_places'] === 2, 'region availability aggregates only funded settlement places');
    economyReject(static function () use ($db, $finiteCity, $workerUser) { Command::run($workerUser, bin2hex(random_bytes(16)), 'fixture.forbidden-reserve', [], static function ($op) use ($db, $finiteCity, $workerUser) { return (new \common\modules\world\models\domain\StarterReserve($db))->configure($workerUser, $finiteCity, '1', '0', $op); }); }, 'OWNER_REQUIRED');
    $account = (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->accounts($finiteCity)['budget'];
    economyCheck((new \common\modules\world\modules\economy\models\domain\BudgetSpending($db))->available((int)$account['id'])->isZero(), 'earmarked starter funds cannot be used by ordinary budget spending');
    economyReject(static function () use ($configure) { $configure('0', '0'); }, 'INVALID_STARTER_RESERVE');
    economyReject(static function () use ($configure) { $configure('100', '0.0001'); }, 'INSUFFICIENT_BUDGET');
    foreach ([9022, 9023] as $id) {
        $db->createCommand()->insert('user', ['id' => $id, 'username' => 'SettlementReserve' . $id])->execute();
        $db->createCommand()->insert('persone', ['id' => $id, 'user_id' => $id, 'credit' => '0.0000', 'balance' => '0.0000'])->execute(); $app->authManager->assign($app->authManager->getRole('world-tester'), $id);
    }
    $join = static function (int $user) use ($finiteCity) { return Command::run($user, bin2hex(random_bytes(16)), 'world.start', ['city_id' => $finiteCity], static function ($op) use ($finiteCity, $user) { return \common\modules\world\models\StarterPack::enter($user, $finiteCity, $op); }); };
    $join(9022); $join(9023);
    economyCheck((new \common\modules\world\models\domain\StarterFunding($db))->forUser(9022)['amount_cr'] === '100.0000' && $reserve->state($finiteCity)['reserve_cr'] === '50.0000' && $reserve->state($finiteCity)['available_places'] === 0, 'two settlers receive their configured amount and the remaining reserve cannot promise another place');
    $configure('80', '0'); $db->createCommand()->insert('user', ['id' => 9024, 'username' => 'QueuedReserve'])->execute();
    $db->createCommand()->insert('persone', ['id' => 9024, 'user_id' => 9024, 'credit' => '0.0000', 'balance' => '0.0000'])->execute(); $app->authManager->assign($app->authManager->getRole('world-tester'), 9024); $join(9024);
    $configure('25', '0');
    Command::run(9001, bin2hex(random_bytes(16)), 'fixture.free-budget-only', [], static function ($op) use ($db, $finiteCity) { return (new \common\modules\world\modules\economy\models\domain\BudgetFunding($db))->contribute(9001, $finiteCity, \common\modules\world\modules\economy\value\Money::parse('30'), 'Not earmarked yet', $op); });
    economyCheck((new \common\modules\world\models\domain\StarterFunding($db))->forUser(9024)['status'] === 'pending' && $reserve->state($finiteCity)['available_places'] === 0, 'unreserved budget inflow cannot pay or advertise an unfunded queued settler');
    $configure('25', '30');
    economyCheck((new \common\modules\world\models\domain\StarterFunding($db))->forUser(9024)['status'] === 'paid' && (new \common\modules\world\models\domain\StarterFunding($db))->forUser(9024)['amount_cr'] === '80.0000' && $total() === $before, 'earmarking funds pays the earlier accepted amount despite a later policy change and conserves all credits');

    $project = \common\modules\world\models\Build::requireOne($limitedBuild->id); $project->refresh();
    $db->createCommand()->delete('world_build_work', ['build_id' => $project->id])->execute();
    $edit = static function (string $amount, bool $topUp = false, ?string $key = null) use ($limitedBuild, $finiteUser) {
        return Command::run($finiteUser, $key ?? bin2hex(random_bytes(16)), 'world.build.settings', ['build_id' => (int)$limitedBuild->id, 'labor_budget' => $amount, 'top_up' => $topUp], static function ($op) use ($limitedBuild, $finiteUser, $amount, $topUp) { return \common\modules\world\models\Build::requireOne($limitedBuild->id)->configure($finiteUser, 'Budget fixture', $amount, $op, $topUp); });
    };
    $edit('0'); $project->refresh();
    economyCheck(\common\modules\world\modules\economy\value\Money::parse((string)$project->labor_budget)->isZero() && $project->commitment_id === null && $project->builderLimit() === 0, 'zero budget permits self construction and releases a vacant helper reserve');
    economyReject(static function () use ($project, $finiteUser, $joined) { $project->configure($finiteUser, 'Too small', '1.0000', $joined['operation_id']); }, 'MINIMUM_BUILD_BUDGET');
    $source = (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->accounts((int)$finiteBuild->node_id)['budget'];
    $spending = new \common\modules\world\modules\economy\models\domain\BudgetSpending($db); $available = $spending->available((int)$source['id']);
    $edit('4'); $edit('2');
    economyCheck($spending->available((int)$source['id'])->decimal() === $available->subtract(\common\modules\world\modules\economy\value\Money::parse('2'))->decimal(), 'decreasing a vacant helper budget reserves only the new amount');
    $edit('0');
    economyCheck($spending->available((int)$source['id'])->decimal() === $available->decimal(), 'clearing a vacant helper budget releases every reserved credit');
    $edit('2'); $project = \common\modules\world\models\Build::requireOne($limitedBuild->id);
    Command::run($workerUser, bin2hex(random_bytes(16)), 'world.build.start', ['build_id' => (int)$project->id], static function () use ($project, $workerUser) { return \common\modules\world\models\BuildWork::start($project, $workerUser); });
    economyReject(static function () use ($edit) { $edit('0'); }, 'BUILD_BUDGET_DECREASE');
    $edit('4');
    economyCheck(\common\modules\world\models\Build::requireOne($limitedBuild->id)->toArray()['can_reduce_budget'] === false, 'accepted helpers prevent a wage decrease while an increase remains possible');
    $desired = $available->add(\common\modules\world\modules\economy\value\Money::parse('1'))->decimal();
    economyReject(static function () use ($edit, $desired) { $edit($desired); }, 'INSUFFICIENT_BUDGET');
    // Seed an independent wallet-control fixture after the conservation assertions above.
    $db->createCommand()->update('persone', ['credit' => '5.0000'], ['user_id' => $finiteUser])->execute();
    $topUpKey = bin2hex(random_bytes(16)); $edited = $edit($desired, true, $topUpKey);
    economyCheck($edit($desired, true, $topUpKey) === $edited && \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $finiteUser])->scalar($db))->decimal() === '4.0000', 'checked top-up charges exactly the one-credit shortfall once when increasing the helper budget');
} finally { $tx->rollBack(); }
echo 'Settlement reserve and self-construction checks passed.' . PHP_EOL;

$tx = $db->beginTransaction();
try {
    $catalogCodes = static function (int $parent) { return (new \common\modules\world\models\TemplateSearch())->search([], $parent)->query->select('code')->column(); };
    $siteCodes = $catalogCodes($siteId);
    economyCheck(in_array('house', $siteCodes, true) && in_array('forge', $siteCodes, true) && in_array('trap', $siteCodes, true) && !in_array('garden-bed', $siteCodes, true), 'campsite catalog includes its buildings and excludes beds for cultivation');
    $fixtures = Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.node-context', [], static function ($op) use ($db, $freshUser, $siteId) {
        $ids = [];
        foreach (['shelter-a' => 'starter-shelter', 'shelter-b' => 'starter-shelter', 'forge' => 'forge', 'garden' => 'garden'] as $key => $code) {
            $node = NodeFactory::create(NodeTemplate::requireOne(['code' => $code]), Node::requireOne($siteId), $freshUser, $key, 'context-' . $key, null, 'constructing');
            $db->createCommand()->update('world_node', ['status' => 'active', 'operational_status' => 'active', 'condition' => $node->max_condition], ['id' => $node->id])->execute(); $node->refresh();
            $storage = (new \common\modules\world\models\domain\PlacementProvisioner($db))->interior($node); $ids[$key] = (int)$node->id;
            if (strpos($key, 'shelter-') !== 0) continue;
            $db->createCommand()->insert('world_build', ['node_id' => $node->id, 'parent_id' => $siteId, 'owner_user_id' => $freshUser, 'required_seconds' => 60, 'worked_seconds' => 60, 'labor_budget' => '0.0000', 'materials_json' => '{}', 'status' => 'completed', 'revision' => 1, 'created_at' => time(), 'finished_at' => time()])->execute();
            $bed = (new Query())->from('craft_item')->where(['code' => 'classic-bed'])->one($db);
            $position = (0 - (int)$node->map_origin_y) * (int)$node->map_width - (int)$node->map_origin_x + 1;
            $db->createCommand()->insert('craft_inventory', ['user_id' => $freshUser, 'item_id' => $bed['id'], 'item_quantity' => 1, 'storage_id' => $storage, 'slot' => $position, 'revision' => 1])->execute();
            \common\modules\world\models\domain\FurnishingPlacement::synchronize($db, [$node->id], $op);
        }
        return $ids;
    });
    economyCheck(in_array('garden-bed', $catalogCodes($fixtures['garden']), true) && !in_array('house', $catalogCodes($fixtures['garden']), true) && !in_array('forge', $catalogCodes($fixtures['garden']), true), 'garden catalog contains cultivation beds and no campsite buildings');
    economyCheck(!NodeTemplate::requireOne(['code' => 'house'])->permits(Node::requireOne($fixtures['garden'])) && !NodeTemplate::requireOne(['code' => 'garden-bed'])->permits(Node::requireOne($siteId)), 'the same parent restrictions protect direct build commands');
    $edges = (new Query())->from('world_template_parent')->orderBy(['template_id' => SORT_ASC, 'parent_template_id' => SORT_ASC])->all($db);
    \common\modules\world\models\domain\TemplateParents::seed($db); \common\modules\world\models\domain\TemplateParents::seed($db);
    economyCheck($edges === (new Query())->from('world_template_parent')->orderBy(['template_id' => SORT_ASC, 'parent_template_id' => SORT_ASC])->all($db), 'parent-context backfill is repeatable and retains configured links');
    $store = new \common\modules\world\modules\craft\models\domain\WorldStorage($db, new \common\modules\world\models\domain\WorldFlags($db, $app->getModule('world')));
    $bedId = (int)(new Query())->select('id')->from('craft_item')->where(['code' => 'classic-bed'])->scalar($db);
    $benchId = (int)(new Query())->select('id')->from('craft_item')->where(['code' => 'classic-bench'])->scalar($db);
    $outdoor = $store->placementOptions($freshUser, $siteId)['items'];
    $interior = $store->placementOptions($freshUser, $fixtures['shelter-a'])['items'];
    $working = $store->placementOptions($freshUser, $fixtures['forge'])['items'];
    $contains = static function (array $rows, string $name) { return (bool)array_filter($rows, static function ($row) use ($name) { return $row['name'] === $name; }); };
    $bedName = trim((string)(new Query())->select('name')->from('craft_item')->where(['id' => $bedId])->scalar($db));
    $benchName = trim((string)(new Query())->select('name')->from('craft_item')->where(['id' => $benchId])->scalar($db));
    economyCheck(!$contains($outdoor, $bedName) && !$contains($outdoor, $benchName) && $contains($interior, $bedName) && $contains($working, $benchName), 'placement catalog filters exposure and workstation context even before crafting');
    $walletBefore = (string)(new Query())->select('credit')->from('persone')->where(['user_id' => $freshUser])->scalar($db);
    // Earlier fixture commands represent past play, outside this UI operation's rate window.
    $db->createCommand()->update('game_command', ['created_at' => time() - 120], ['user_id' => $freshUser])->execute();
    $housing = new \common\modules\world\models\domain\WorldHousing($db, new \common\modules\world\models\domain\WorldFlags($db, $app->getModule('world')));
    $assign = static function (int $node) use ($housing, $freshUser) { $input = ['node_id' => $node, 'replace_current' => true]; $q = $housing->preview($freshUser, $input, 'lodge'); $key = bin2hex(random_bytes(16)); $result = $housing->execute($freshUser, $key, $input, $q['quote_id'], (array)$q['expected_revisions'], 'lodge'); return [$key, $input, $q, $result]; };
    $assign($fixtures['shelter-a']); $firstLodging = $housing->state($freshUser, $fixtures['shelter-a'])['lodging'];
    [$key, $input, $q, $assigned] = $assign($fixtures['shelter-b']);
    economyCheck($housing->execute($freshUser, $key, $input, $q['quote_id'], (array)$q['expected_revisions'], 'lodge') === $assigned && $housing->state($freshUser, $fixtures['shelter-b'])['lodging'] !== null && $housing->state($freshUser, $fixtures['shelter-a'])['lodging'] === null
        && (new Query())->select('ended_at')->from('world_housing_interval')->where(['id' => $firstLodging['id']])->scalar($db) !== null && (string)(new Query())->select('credit')->from('persone')->where(['user_id' => $freshUser])->scalar($db) === $walletBefore, 'one-click lodging replacement closes only the old interval, preserves history and replays without a debit');
    $buildIds = [];
    foreach (['shelter-a', 'shelter-b'] as $key) {
        $id = $fixtures[$key]; $db->createCommand()->update('world_node', ['status' => 'constructing'], ['id' => $id])->execute();
        $db->createCommand()->update('world_build', ['status' => 'building', 'worked_seconds' => 0, 'finished_at' => null], ['node_id' => $id])->execute();
        $buildIds[] = (int)(new Query())->select('id')->from('world_build')->where(['node_id' => $id])->scalar($db);
    }
    $a = \common\modules\world\models\Build::requireOne($buildIds[0]); $b = \common\modules\world\models\Build::requireOne($buildIds[1]);
    \common\modules\world\models\BuildWork::start($a, $freshUser);
    economyReject(static function () use ($b, $freshUser) { \common\modules\world\models\BuildWork::start($b, $freshUser); }, 'BUILDER_BUSY');
    $db->createCommand()->insert('world_build_work', ['build_id' => $b->id, 'user_id' => $freshUser, 'started_at' => time(), 'last_seen_at' => time(), 'accounted_at' => time(), 'seconds' => 0, 'paid_amount' => '0.0000'])->execute();
    economyReject(static function () use ($b, $freshUser, $fixtures) { \common\modules\world\models\BuildWork::heartbeat($b, $freshUser, $fixtures['operation_id'], false); }, 'BUILDER_BUSY');
    economyCheck((int)(new Query())->from('world_builder')->where(['user_id' => $freshUser])->count('*', $db) === 1 && (int)\common\modules\world\models\Build::requireOne($b->id)->worked_seconds === 0, 'legacy suspended work cannot renew a second construction session');
    $price = \common\modules\world\models\domain\WorldMapCells::purchasePricing(['base_price' => '0.5000', 'outer_ring_multiplier' => 10, 'bulk_minimum' => 3, 'discount_bps' => 500], [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0]]);
    economyCheck(array_column($price['unit_prices'], 'price') === ['0.5000', '10.0000'], 'distant rings cost twenty times the adjacent ring without changing starting cells');
    economyCheck(\common\modules\world\models\domain\EconomyProfile::load()['objects']['forge']['price_cr'] === '40.0000' && 250 - (20 + 50 + 10 + 120 + 40 + 3.8 + 4) > 0, '250 starting credits cover shelter, garden, bed, mine, forge, eight adjacent cells and two waterings');
} finally { $tx->rollBack(); }
$tx = $db->beginTransaction();
try {
    // Real placement and payroll, isolated from the manual/local world and later fixtures.
    $helpers = [9030, 9031];
    foreach ($helpers as $helper) {
        $db->createCommand()->insert('user', ['id' => $helper, 'username' => 'JointBuilder' . $helper])->execute();
        $db->createCommand()->insert('persone', ['id' => $helper, 'user_id' => $helper, 'credit' => '0.0000', 'balance' => '0.0000'])->execute();
        $app->authManager->assign($app->authManager->getRole('world-tester'), $helper);
    }
    foreach ((new Query())->select('build_id')->from('world_build_work')->where(['user_id' => $freshUser, 'ended_at' => null])->column($db) as $id) \common\modules\world\models\BuildWork::closeAll((int)$id);
    $db->createCommand()->delete('world_mining_worker', ['user_id' => $freshUser])->execute();
    $db->createCommand()->update('persone', ['credit' => '1000.0000'], ['user_id' => $freshUser])->execute();
    $log = (new Query())->from('craft_item')->where(['code' => 'classic-log'])->one($db);
    $freshStock->move($freshUser, $log, 40);
    $materialQuantity = static function () use ($db, $freshUser, $log) { return (int)(new Query())->from('craft_inventory')->where(['user_id' => $freshUser, 'item_id' => $log['id']])->sum('item_quantity', $db); };
    $materialBefore = $materialQuantity();
    $definition = new NodeTemplate();
    $attributes = NodeTemplate::requireOne(['code' => 'forest-plot'])->getAttributes(); unset($attributes['id']);
    $definition->setAttributes(array_replace($attributes, ['code' => 'fixture-joint-building', 'name' => 'Joint building', 'build_seconds' => 100, 'materials_json' => '{"classic-log":10}', 'defaults_json' => '{"price_cr":"5.0000"}']), false);
    if (!$definition->save(false)) throw new RuntimeException('Joint template write failed.');
    $db->createCommand()->insert('world_template_parent', ['template_id' => $definition->id, 'parent_template_id' => Node::requireOne($siteId)->template_id])->execute();
    $projects = [];
    for ($i = 0; $i < 3; $i++) {
        $cell = (new \common\modules\world\models\domain\WorldTree($db))->nextPosition($siteId);
        $where = ['parent_id' => $siteId] + $cell;
        if ((new Query())->from('world_map_cell')->where($where)->exists($db)) $db->createCommand()->update('world_map_cell', ['state' => 'open'], $where)->execute();
        else $db->createCommand()->insert('world_map_cell', $where + ['state' => 'open', 'price' => '0.0000', 'created_at' => time(), 'updated_at' => time()])->execute();
        $input = ['parent_id' => $siteId, 'template_id' => (int)$definition->id, 'name' => 'Joint project ' . $i, 'labor_budget' => '10', 'top_up' => true, 'terms_digest' => \common\modules\world\models\domain\EconomyConstruction::terms($definition)['terms_digest']] + $cell;
        $result = Command::run($freshUser, bin2hex(random_bytes(16)), 'world.build.create', $input, static function ($op) use ($freshUser, $input) { return ['build' => \common\modules\world\models\Build::begin($freshUser, $input, $op)->toArray()]; });
        $projects[] = \common\modules\world\models\Build::requireOne($result['build']['id']);
    }
    economyCheck(count($projects) === 3 && array_reduce($projects, static function ($ok, $build) { return $ok && $build->status === 'building'; }, true)
        && \common\modules\world\models\BuildRequirements::availability($freshUser, $siteId, $definition)['available'], 'three pending placements and the catalog remain available before any project is completed');
    economyCheck($materialQuantity() === $materialBefore - 30, 'every pending project consumes its own accepted materials on placement');
    $project = $projects[0]; $at = time();
    foreach ([$freshUser => 20, $helpers[0] => 30, $helpers[1] => 50] as $builder => $seconds) {
        \common\modules\world\models\BuildWork::start($project, $builder);
        $db->createCommand()->update('world_build_work', ['accounted_at' => $at - $seconds, 'last_seen_at' => $at, 'started_at' => $at - $seconds], ['build_id' => $project->id, 'user_id' => $builder])->execute();
    }
    economyReject(static function () use ($projects, $freshUser) { \common\modules\world\models\BuildWork::start($projects[1], $freshUser); }, 'BUILDER_BUSY');
    $payload = ['build_id' => (int)$project->id];
    Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.joint.finish', $payload, static function ($op) use ($project, $at) { \common\modules\world\models\BuildClock::checkpoint($project, $at); $project->finish($op); return ['build' => $project->toArray()]; });
    $receipt = $project->result();
    $oldSettings = json_decode($project->node->settings_json, true); unset($oldSettings['accepted_build_price_cr']);
    $db->createCommand()->update('world_node', ['settings_json' => json_encode($oldSettings)], ['id' => $project->node_id])->execute(); $project->refresh();
    economyCheck($project->result()['object_price_cr'] === '5.0000', 'historical completed projects recover the actual object price from their creation ledger');
    economyCheck($receipt['paid_cr'] === '8.0000' && $receipt['released_cr'] === '2.0000' && $receipt['spent_cr'] === '13.0000' && array_column($receipt['participants'], 'seconds') === [20, 30, 50]
        && array_column($receipt['participants'], 'paid_cr') === ['0.0000', '3.0000', '5.0000'], 'owner and two helpers receive the exact proportional payroll and the unused owner share is released');
    foreach ($helpers as $helper) {
        $key = bin2hex(random_bytes(16));
        $late = static function () use ($payload, $helper, $key) { return Command::run($helper, $key, 'world.build.heartbeat', $payload, static function ($op) use ($payload, $helper) { return \common\modules\world\models\BuildWork::heartbeat(\common\modules\world\models\Build::requireOne($payload['build_id']), $helper, $op, false); }); };
        $result = $late();
        economyCheck($result === $late() && $result['build']['status'] === 'completed' && $result['work']['ended_at'] !== null && $result['build']['result']['paid_cr'] === '8.0000', 'late helper heartbeat and replay return the settled result without a second wage');
        $list = \common\modules\world\models\Build::listing($helper, ['node_id' => $project->node_id])->getModels();
        economyCheck(count($list) === 1 && $list[0]->result() === $receipt, 'a helper can reload their completed construction receipt');
        $view = (new \common\modules\world\models\domain\WorldQuery($db, new \common\modules\world\models\domain\WorldAccessPolicy($helper)))->node((int)$project->node_id);
        economyCheck(((array)$view['details'])['last_build_id'] === (int)$project->id, 'the completed node snapshot retains the helper result panel');
    }
    $second = $projects[1]; \common\modules\world\models\BuildWork::start($second, $helpers[0]);
    $db->createCommand()->update('world_build_work', ['accounted_at' => $at - 60, 'last_seen_at' => $at - 60], ['build_id' => $second->id])->execute();
    $cancelKey = bin2hex(random_bytes(16));
    $cancel = static function () use ($freshUser, $second, $cancelKey) { return Command::run($freshUser, $cancelKey, 'world.build.cancel', ['build_id' => (int)$second->id], static function ($op) use ($second) { $build = \common\modules\world\models\Build::requireOne($second->id); $build->finish($op, true); return ['build' => $build->toArray()]; }); };
    $cancelled = $cancel(); $second->refresh();
    economyCheck($second->result()['paid_cr'] === '6.0000' && $second->result()['released_cr'] === '4.0000' && $second->result()['object_price_cr'] === '5.0000', 'cancellation pays completed helper work, releases unused labor funds and keeps the paid object price');
    economyCheck($cancel() === $cancelled && $materialQuantity() === $materialBefore - 23 && $second->result()['material_refund_percent'] === 70, 'cancelling one of three projects returns exactly seven of ten logs once and never returns all consumed materials');
    $third = $projects[2]; $helper = $helpers[1];
    foreach ([60, 40] as $seconds) {
        \common\modules\world\models\BuildWork::start($third, $helper);
        $db->createCommand()->update('world_build_work', ['accounted_at' => time() - $seconds, 'last_seen_at' => time() - $seconds], ['build_id' => $third->id, 'ended_at' => null])->execute();
        $closed = Command::run($helper, bin2hex(random_bytes(16)), 'world.build.stop', ['build_id' => (int)$third->id], static function ($op) use ($third, $helper) { return \common\modules\world\models\BuildWork::heartbeat($third, $helper, $op, true); });
    }
    economyCheck($closed['build']['status'] === 'completed' && $third->result()['paid_cr'] === '10.0000' && $third->result()['released_cr'] === '0.0000'
        && $third->result()['participants'] === [['user_id' => $helper, 'name' => 'JointBuilder' . $helper, 'owner' => false, 'seconds' => 100, 'paid_cr' => '10.0000']],
        'a helper alone completes the project across two sessions and receives the full budget exactly once in the combined receipt');
    $map = new \common\modules\world\models\domain\MapExploration($db);
    foreach ([[2,0],[-2,0],[0,2],[0,-2],[2,2],[-2,-2],[3,1]] as [$x,$y]) {
        $db->createCommand()->delete('world_map_cell', ['parent_id' => $siteId, 'x' => $x, 'y' => $y])->execute();
        $needed = \common\modules\world\models\domain\MapExploration::predecessors($x, $y);
        foreach ($needed as $point) $db->createCommand()->delete('world_map_cell', ['parent_id' => $siteId] + $point)->execute();
        economyReject(static function () use ($map, $siteId, $x, $y) { $map->requirePredecessors($siteId, $x, $y); }, 'MAP_PREDECESSOR_REQUIRED');
        foreach ($needed as $index => $point) {
            $db->createCommand()->insert('world_map_cell', ['parent_id' => $siteId] + $point + ['state' => 'discovered', 'price' => '0', 'created_at' => time(), 'updated_at' => time()])->execute();
            if (count($needed) === 2 && $index === 0) economyReject(static function () use ($map, $siteId, $x, $y) { $map->requirePredecessors($siteId, $x, $y); }, 'MAP_PREDECESSOR_REQUIRED');
        }
        $map->requirePredecessors($siteId, $x, $y);
    }
    economyCheck(true, 'both sides of every diagonal and the nearer predecessor on every axis are required, including negative coordinates and farther rings');
    $explorationNode = Node::requireOne($projects[0]->node_id);
    $mapService = new \common\modules\world\models\domain\WorldMapCells($db, new \common\modules\world\models\domain\WorldFlags($db, $app->getModule('world')), new \common\modules\world\models\domain\WorldAccessPolicy($freshUser));
    $input = $mapService->input((int)$explorationNode->id, ['cells' => [['x' => 2, 'y' => 2]], 'top_up' => false, 'use_elixir' => true]);
    economyReject(static function () use ($mapService, $freshUser, $input) { $mapService->preview($freshUser, $input, 'explore'); }, 'MAP_PREDECESSOR_REQUIRED');
    $db->createCommand()->insert('world_map_cell', ['parent_id' => $explorationNode->id, 'x' => 1, 'y' => 2, 'state' => 'discovered', 'price' => '0', 'created_at' => time(), 'updated_at' => time()])->execute();
    economyReject(static function () use ($mapService, $freshUser, $input) { $mapService->preview($freshUser, $input, 'explore'); }, 'MAP_PREDECESSOR_REQUIRED');
    $apiUser = (int)$app->user->id;
    $db->createCommand()->delete('world_mining_worker', ['user_id' => $apiUser])->execute();
    $db->createCommand()->insert('world_mining_worker', ['user_id' => $apiUser, 'node_id' => $mine->id, 'accounted_at' => time(), 'last_seen_at' => time()])->execute();
    $progression = $economyController->actionProgression();
    economyCheck($progression['extraction_work']['node_id'] === (int)$mine->id && is_int($progression['extraction_work']['last_seen_at']), 'character progression includes only the authenticated character extraction assignment');
} finally { $tx->rollBack(); }
$tx = $db->beginTransaction();
try {
    $mine = Node::requireOne($mine->id);
    $db->createCommand()->update('world_registry', ['active_world_id' => $mine->root_id], ['id' => 1])->execute();
    $db->createCommand()->update('game_command', ['created_at' => time() - 120], ['user_id' => [$finiteUser, $workerUser]])->execute();
    $db->createCommand()->update('world_node', ['position_x' => 2, 'position_y' => 2], ['id' => $mini->id])->execute();
    $db->createCommand()->update('craft_storage', ['capacity' => 1], ['id' => $stockId])->execute();
    $clear = static function (int $storage) use ($db) { $db->createCommand()->update('craft_inventory', ['item_id' => null, 'item_quantity' => 0, 'slot' => null], ['storage_id' => $storage])->execute(); };
    $clear($stockId);
    $nearNode = NodeFactory::create(NodeTemplate::requireOne(['code' => 'mini-storage-level-' . ((int)$mine->hierarchy_level + 1)]), $mine, $finiteUser, 'Nearest free mini-storage', 'nearest-free-mini', ['x' => 1, 'y' => 0], 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $nearNode->max_condition], ['id' => $nearNode->id])->execute();
    $db->createCommand()->insert('craft_storage', ['identity_key' => 'stockpile:fixture:nearest-free', 'kind' => 'stockpile', 'node_id' => $nearNode->id, 'owner_user_id' => $finiteUser, 'capacity' => 1])->execute();
    $nearId = (int)$db->getLastInsertID();
    $definition = \common\modules\world\models\domain\WorldSources::options($mine)['mine-stone'];
    $nearest = static function (array $batch) use ($mine) { return \common\modules\world\models\domain\ExtractionStorage::nearest($mine, $batch); };
    economyCheck((int)$nearest($definition)['id'] === $nearId, 'nearest storage uses map distance before storage creation order');
    economyCheck(!\common\modules\world\models\domain\ExtractionStorage::fits(['output' => ['classic-stone' => 1, 'classic-log' => 1]], $nearest($definition)), 'automatic storage requires room for the whole multi-item batch');
    $putLog = static function (int $storage) use ($db, $finiteUser) {
        $inventory = new \common\modules\world\modules\craft\models\domain\CanonicalInventory(new \common\modules\world\modules\craft\models\domain\CraftStorage($db));
        $target = (new Query())->from('craft_storage')->where(['id' => $storage])->one($db); $log = (new Query())->from('craft_item')->where(['code' => 'classic-log'])->one($db);
        $inventory->applyCraft($finiteUser, $inventory->planCraft([], $target, [], [], $log, 1), $target, [], $log, 'fixture.fill-mini');
    };
    $putLog($nearId);
    economyCheck((int)$nearest($definition)['id'] === $stockId, 'automatic storage skips the closer full mini-storage');
    $ownerStock = new \common\modules\world\modules\craft\models\domain\CraftStorage($db);
    $ownerStock->move($finiteUser, (new Query())->from('craft_item')->where(['code' => 'world-stone-pickaxe'])->one($db), 1);
    $ownerStart = $run($finiteUser, 'world.mining.start', ['mining_node_id' => (int)$mine->id, 'action_code' => 'mine-stone', 'output_destination' => 'mini_storage'], static function ($op) use ($mine, $finiteUser) {
        return \common\modules\world\models\Mining::start((int)$mine->id, $finiteUser, $op, 'mine-stone', null, 'mini_storage');
    });
    economyCheck($ownerStart['output_storage_id'] === $stockId && $ownerStart['output_destination'] === 'mini_storage', 'owner accepts automatic storage mode without choosing a warehouse ID');
    $putLog($stockId); $db->createCommand()->update('world_source_work', ['worked_seconds' => $definition['work_seconds']], ['id' => $ownerStart['work_id']])->execute();
    $ownerBeat = static function (?string $key = null) use ($run, $mine, $finiteUser) { return $run($finiteUser, 'fixture.auto-heartbeat', ['mining_node_id' => (int)$mine->id], static function ($op) use ($mine, $finiteUser) { return \common\modules\world\models\Mining::heartbeat((int)$mine->id, $finiteUser, $op); }, $key); };
    $quotaBefore = \common\modules\world\models\domain\EconomyQuota::state($db, $finiteUser, 'extraction_daily')['remaining'];
    $blocked = $ownerBeat();
    economyCheck($blocked['pending_quantity'] === 1 && $blocked['blocked_code'] === 'SOURCE_STORAGE_FULL' && \common\modules\world\models\domain\EconomyQuota::state($db, $finiteUser, 'extraction_daily')['remaining'] === $quotaBefore, 'all full mini-storages preserve earned work without consuming extraction quota');
    $clear($nearId); $ownerKey = bin2hex(random_bytes(16)); $ownerDone = $ownerBeat($ownerKey);
    economyCheck($ownerBeat($ownerKey) === $ownerDone && (int)(new Query())->select('target_storage_id')->from('world_source_work')->where(['id' => $ownerStart['work_id']])->scalar($db) === $nearId
        && (int)(new Query())->from('craft_inventory')->where(['storage_id' => $nearId, 'item_id' => $stoneId])->sum('item_quantity', $db) === array_sum($definition['output']), 'owner delivery reselects the nearest free mini-storage and replay never duplicates output');
    $db->createCommand()->update('world_player_health', ['hp' => 100], ['user_id' => $workerUser])->execute();
    $settings(true, '2'); $workerBefore = (float)(new Query())->select('credit')->from('persone')->where(['user_id' => $workerUser])->scalar($db);
    $hired = $startHired();
    economyCheck($hired['output_storage_id'] === $nearId, 'hired batches also choose the nearest storage with enough stack space');
    $db->createCommand()->update('craft_storage', ['capacity' => 0], ['id' => $nearId])->execute(); $clear($stockId);
    $db->createCommand()->update('world_source_work', ['worked_seconds' => $definition['work_seconds']], ['id' => $hired['work_id']])->execute();
    $hiredKey = bin2hex(random_bytes(16)); $paid = $heartbeat($hiredKey);
    economyCheck($heartbeat($hiredKey) === $paid && (int)(new Query())->select('target_storage_id')->from('world_source_work')->where(['id' => $hired['work_id']])->scalar($db) === $stockId
        && (float)(new Query())->select('credit')->from('persone')->where(['user_id' => $workerUser])->scalar($db) === $workerBefore + 2, 'hired delivery changes a full target and pays the accepted wage once');
    $legacy = $definition + ['employment' => ['storage_id' => $stockId]];
    economyCheck((int)\common\modules\world\models\domain\HiredExtraction::target($mine, $workerUser, $legacy)['id'] === $stockId, 'historical fixed storage assignments remain fixed');
} finally { $tx->rollBack(); }

echo 'Node-context, single-builder and one-click lodging checks passed.' . PHP_EOL;

// Full NPC acceptance follows the legacy interaction fixtures.
{
    $npcWorld = (int)(new Query())->select('root_id')->from('world_node')->where(['code' => 'north-city'])->scalar($db);
    $db->createCommand()->update('world_registry', ['active_world_id' => $npcWorld], ['id' => 1])->execute();
    $db->createCommand()->update('game_command', ['created_at' => time() - 120], ['user_id' => $freshUser])->execute();
    $npcUser = 9099;
    $db->createCommand()->insert('user', ['id' => $npcUser, 'username' => 'bot'])->execute();
    $db->createCommand()->insert('persone', ['id' => $npcUser, 'user_id' => $npcUser, 'credit' => '1000.0000', 'balance' => '0.0000'])->execute();
    $adminPermission = $app->authManager->getPermission('p-admin');
    if (!$adminPermission) { $adminPermission = $app->authManager->createPermission('p-admin'); $app->authManager->add($adminPermission); }
    if (!$app->authManager->getAssignment('p-admin', 9001)) $app->authManager->assign($adminPermission, 9001);
    $npcTotal = static function () use ($db): string {
        return \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->from('persone')->sum('credit', $db))
            ->add(\common\modules\world\modules\economy\value\Money::parse((string)(new Query())->from('economy_account')->sum('amount', $db)))->decimal();
    };
    $npcMoneyBefore = $npcTotal();
    $infrastructure = new \common\modules\world\models\domain\NpcInfrastructure($db);
    economyReject(static function () use ($infrastructure, $freshUser) { $infrastructure->install($freshUser); }, 'NPC_ADMIN_REQUIRED');
    $installed = $infrastructure->install(9001); $policy = $infrastructure->policy();
    economyCheck($installed['changed'] && count($infrastructure->nodes($policy)) === 25 && $npcTotal() === $npcMoneyBefore, 'NPC setup builds the complete starting infrastructure without a redundant mini inside the warehouse, using existing money only');
    economyCheck(!$infrastructure->install(9001)['changed'] && $npcTotal() === $npcMoneyBefore, 'NPC setup is repeatable without duplicate buildings, equipment or funding');
    $npcWarehouse = Node::requireOne(['code' => 'npc-bot-warehouse']);
    $miniTemplate = NodeTemplate::requireOne(['code' => 'mini-storage-level-6']);
    economyCheck(!$miniTemplate->permits($npcWarehouse) && !(new Query())->from('world_node')->where(['parent_id' => $npcWarehouse->id, 'node_type' => 'CHEST', 'status' => 'active'])->exists($db), 'a full warehouse neither contains nor permits a mini-storage');
    economyCheck(!(new Query())->from('world_node')->where(['root_id' => $policy['world_id'], 'node_type' => ['WORLD', 'REGION', 'SETTLEMENT']])->andWhere(['or', ['owner_user_id' => null], ['<>', 'owner_user_id', $npcUser]])->exists($db), 'world, regions and settlements belong to bot');
    if (!$app->authManager->getAssignment('world-tester', $freshUser)) $app->authManager->assign($testRole, $freshUser);
    $db->createCommand()->update('world_player_health', ['hp' => 100], ['user_id' => $freshUser])->execute();
    $waterNode = \common\modules\world\models\Node::requireOne(['code' => 'npc-bot-water']);
    $wageWallet = \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $freshUser])->scalar($db));
    $startedNpc = economySourceCommand($freshUser, (int)$waterNode->id, 'start', ['action_code' => 'gather-water']);
    economyCheck($startedNpc['working'] && $startedNpc['employment']['enabled'] && !$startedNpc['employment']['owner'], 'a participant can accept paid work at a public NPC source');
    $db->createCommand()->update('world_mining_worker', ['accounted_at' => time() - 60, 'last_seen_at' => time()], ['user_id' => $freshUser])->execute();
    $npcBeatKey = bin2hex(random_bytes(16)); $npcBeatPayload = ['mining_node_id' => (int)$waterNode->id];
    $npcBeat = static function ($op) use ($waterNode, $freshUser) { return \common\modules\world\models\Mining::heartbeat((int)$waterNode->id, $freshUser, $op); };
    $completedNpc = \common\modules\world\models\Command::run($freshUser, $npcBeatKey, 'world.mining.heartbeat', $npcBeatPayload, $npcBeat);
    economyCheck(!$completedNpc['working'] && \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $freshUser])->scalar($db))->compare($wageWallet->add(\common\modules\world\modules\economy\value\Money::parse('1'))) === 0, 'one finished source batch pays the reserved one-credit wage');
    economyCheck(\common\modules\world\models\Command::run($freshUser, $npcBeatKey, 'world.mining.heartbeat', $npcBeatPayload, $npcBeat) === $completedNpc && $npcTotal() === $npcMoneyBefore, 'source completion replay preserves wages and total money');
    $waterStorage = \common\modules\world\models\domain\HiredExtraction::storages($waterNode)[0];
    economyCheck((int)$waterStorage['capacity'] === 5 && !NodeTemplate::requireOne(['code' => 'mini-storage-level-7'])->permits(Node::requireOne((int)$waterStorage['node_id'])), 'mini-storages have five resource cells and cannot contain another mini-storage');
    $shop = new \common\modules\world\models\domain\GardenHarvest($db, $flags); $shopState = $shop->state($freshUser, (int)$waterStorage['node_id']);
    economyCheck($shopState['npc_supply'] && !$shopState['owned_by_me'] && count($shopState['items']) === 1 && $shopState['items'][0]['quantity'] === 2 && $shopState['items'][0]['price'] === '2.0000' && !$shopState['items'][0]['spoiled'], 'actual hired output goes into the NPC mini-storage and receives a nonperishable public sale price');
    economyCheck($shopState['capacity'] === 5 && $shopState['slot_limit'] === 100, 'public stock response describes five slots of up to one hundred units');
    economyCheck(!(new \common\modules\world\models\domain\WorldQuery($db, new \common\modules\world\models\domain\WorldAccessPolicy($freshUser)))->map((int)$waterNode->id)['can_expand'], 'public mining access never grants exploration or purchase rights over the NPC map');
    $saleInput = ['inventory_id' => $shopState['items'][0]['inventory_id'], 'quantity' => 1];
    $saleQuote = $shop->preview($freshUser, (int)$waterStorage['node_id'], 'buy', $saleInput); $saleKey = bin2hex(random_bytes(16));
    $sold = $shop->execute($freshUser, (int)$waterStorage['node_id'], 'buy', $saleInput, $saleKey, $saleQuote['quote_id'], (array)$saleQuote['expected_revisions']);
    economyCheck($shop->execute($freshUser, (int)$waterStorage['node_id'], 'buy', $saleInput, $saleKey, $saleQuote['quote_id'], (array)$saleQuote['expected_revisions']) === $sold && $npcTotal() === $npcMoneyBefore && $shop->state($freshUser, (int)$waterStorage['node_id'])['items'][0]['quantity'] === 1, 'NPC sale transfers real stock and buyer funds exactly once');
    // Reproduce the previously shipped 50-slot NPC layout, including the empty nested warehouse.
    $tx = $db->beginTransaction();
    try {
        $layoutOperation = bin2hex(random_bytes(16));
        $db->createCommand()->insert('game_operation', ['id' => $layoutOperation, 'user_id' => 9001, 'type' => 'fixture.storage-layout', 'created_at' => time()])->execute();
        $db->createCommand()->update('craft_storage', ['capacity' => 50], ['id' => $waterStorage['id']])->execute();
        $db->createCommand()->update('world_warehouse_policy', ['initial_capacity' => 50, 'max_capacity' => 200], ['storage_id' => $waterStorage['id']])->execute();
        $warehouseSettings = $npcWarehouse->settings_json;
        $db->createCommand()->update('world_node', ['settings_json' => '{}'], ['id' => $npcWarehouse->id])->execute(); $npcWarehouse->refresh();
        $redundant = NodeFactory::create($miniTemplate, $npcWarehouse, $npcUser, 'Historical redundant mini', 'npc-bot-mini-' . $npcWarehouse->id, null, 'constructing');
        $db->createCommand()->update('world_node', ['settings_json' => $warehouseSettings], ['id' => $npcWarehouse->id])->execute(); $npcWarehouse->refresh();
        $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $redundant->max_condition, 'settings_json' => '{"npc_role":"store","storage_capacity":50,"allow_sales":true}'], ['id' => $redundant->id])->execute(); $redundant->refresh();
        (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->provision((int)$redundant->id, $layoutOperation);
        $redundantStock = \common\modules\world\models\domain\BuildingFacilities::stockpile($db, (int)$redundant->id, $npcUser, 'warehouse');
        $stockBefore = (new Query())->from('craft_inventory')->where(['storage_id' => $waterStorage['id']])->orderBy('id')->all($db);
        $lotsBefore = (new Query())->from('world_harvest_lot')->where(['inventory_id' => array_column($stockBefore, 'id')])->orderBy('inventory_id')->all($db);
        $db->createCommand()->update('craft_inventory', ['slot' => 6], ['id' => $saleInput['inventory_id']])->execute();
        economyReject(static fn() => $infrastructure->install(9001), 'NPC_STORAGE_REVIEW_REQUIRED');
        economyCheck((int)(new Query())->select('capacity')->from('craft_storage')->where(['id' => $waterStorage['id']])->scalar($db) === 50 && $npcTotal() === $npcMoneyBefore, 'an incompatible old layout is preserved atomically instead of hiding stock or losing money');
        $db->createCommand()->update('craft_inventory', ['slot' => 1], ['id' => $saleInput['inventory_id']])->execute();
        $guard = $db->beginTransaction();
        try {
            (new \common\modules\world\modules\economy\models\domain\BudgetFunding($db))->contribute($npcUser, (int)$redundant->id, \common\modules\world\modules\economy\value\Money::parse('1'), 'Fixture protected money', $layoutOperation);
            economyReject(static fn() => $infrastructure->install(9001), 'NPC_STORAGE_REVIEW_REQUIRED');
            economyCheck(Node::requireOne((int)$redundant->id)->status === 'active' && $npcTotal() === $npcMoneyBefore, 'layout correction cannot archive a nested storage containing existing money');
        } finally { $guard->rollBack(); }
        economyCheck($infrastructure->install(9001)['changed'], 'existing fifty-slot NPC layout is repaired without reinstalling the world');
        economyCheck((int)(new Query())->select('capacity')->from('craft_storage')->where(['id' => $waterStorage['id']])->scalar($db) === 5
            && (new Query())->from('craft_inventory')->where(['storage_id' => $waterStorage['id']])->orderBy('id')->all($db) === $stockBefore
            && (new Query())->from('world_harvest_lot')->where(['inventory_id' => array_column($stockBefore, 'id')])->orderBy('inventory_id')->all($db) === $lotsBefore
            && Node::requireOne((int)$redundant->id)->status === 'archived' && $npcTotal() === $npcMoneyBefore, 'layout repair preserves inventory identities, quantities, sale lots and all money while archiving the empty nested mini');
        economyCheck(!$infrastructure->install(9001)['changed'], 'the corrected NPC storage layout is repeatable without another write');
    } finally { $tx->rollBack(); }
    // A catalogue stack larger than 100 must still obey the physical mini-storage limit.
    $tx = $db->beginTransaction();
    try {
        $capacityOperation = bin2hex(random_bytes(16));
        $db->createCommand()->insert('game_operation', ['id' => $capacityOperation, 'user_id' => $npcUser, 'type' => 'fixture.storage-capacity', 'created_at' => time()])->execute();
        $stone = (new Query())->from('craft_item')->where(['code' => 'classic-stone'])->one($db);
        $db->createCommand()->update('craft_item', ['stack_size' => 500], ['id' => $stone['id']])->execute(); $stone['stack_size'] = 500;
        $mineStock = \common\modules\world\models\domain\HiredExtraction::storages(Node::requireOne(['code' => 'npc-bot-mine']))[0];
        $inventoryStore = new \common\modules\world\modules\craft\models\domain\CraftStorage($db); $inventoryStore->operationId = $capacityOperation;
        $inventory = new \common\modules\world\modules\craft\models\domain\CanonicalInventory($inventoryStore);
        $plan = $inventory->planCraft([], $mineStock, [], [], $stone, 500);
        economyCheck($plan['output_fits'] && array_column($plan['grant'], 'quantity') === [100, 100, 100, 100, 100] && !$inventory->planCraft([], $mineStock, [], [], $stone, 501)['output_fits'], 'mini-storage plans exactly five hundred resources in five stacks even when the catalogue permits larger stacks');
        $inventory->applyCraft($npcUser, $plan, $mineStock, [], $stone, 'fixture.capacity');
        economyCheck(!$inventory->planCraft([], $mineStock, [], [], $stone, 1)['output_fits'], 'full mini-storage refuses another crafted or extracted unit');
        $inventory->initialize($npcUser); $backpack = $inventory->backpack($npcUser);
        $inventoryStore->move($npcUser, $stone, 20);
        $source = (new Query())->from('craft_inventory')->where(['storage_id' => $backpack['id'], 'item_id' => $stone['id']])->andWhere(['>', 'item_quantity', 0])->one($db);
        $cell = (new Query())->from('craft_inventory')->where(['storage_id' => $mineStock['id'], 'slot' => 1])->one($db);
        $inventory->consumeHarvest($npcUser, (int)$cell['id'], 10, 'harvest.withdrawn');
        try { $inventory->inspectTransfer($npcUser, (int)$source['id'], (int)$mineStock['id'], 1, 11); throw new RuntimeException('Oversized mini-storage transfer accepted.'); }
        catch (\yii\web\ConflictHttpException $error) { economyCheck(true, 'manual transfer cannot exceed one hundred resources per mini-storage slot'); }
        economyCheck($inventory->inspectTransfer($npcUser, (int)$source['id'], (int)$mineStock['id'], 1, 11, null, true)['quantity'] === 10, 'partial transfer reports only the remaining physical room');
        $inventory->transfer($npcUser, (int)$source['id'], (int)$mineStock['id'], 1, 10);
        economyCheck((int)(new Query())->select('item_quantity')->from('craft_inventory')->where(['id' => $cell['id']])->scalar($db) === 100, 'the actual transfer fills the mini-storage slot to its exact limit');
        $warehouseStock = (new Query())->from('craft_storage')->where(['node_id' => $npcWarehouse->id, 'kind' => 'stockpile', 'status' => 'active'])->one($db);
        economyCheck($inventory->planCraft([], $warehouseStock, [], [], $stone, 500)['grant'][0]['quantity'] === 500, 'the mini-storage stack cap does not change a full warehouse catalogue stack');
    } finally { $tx->rollBack(); }
    $forgeNode = \common\modules\world\models\Node::requireOne(['code' => 'npc-bot-forge']);
    $workspace = new \common\modules\world\modules\craft\models\domain\CraftWorkspace($db, $flags);
    $ingotRecipe = (int)(new Query())->select('id')->from('craft_recipe')->where(['code' => 'classic-ingot'])->scalar($db);
    $workshopState = $workspace->state($freshUser, (int)$forgeNode->id, $ingotRecipe);
    economyCheck(!$workshopState['object_budget_payment'] && count($workshopState['equipment'][0]['candidates']) === 1 && !in_array((int)$waterStorage['id'], array_column($workshopState['storages'], 'id'), true), 'NPC forge lends its station while hiding its budget and inventory');
    $db->transaction(static function () use ($db, $freshStock, $freshUser) { foreach (['classic-ore', 'classic-coal'] as $material) $freshStock->move($freshUser, (new Query())->from('craft_item')->where(['code' => $material])->one($db), 2); });
    $forgeInput = ['node_id' => (int)$forgeNode->id, 'recipe_id' => $ingotRecipe, 'quantity' => 1, 'source_storage_ids' => [$backpackId], 'output_storage_id' => $backpackId,
        'equipment_instance_ids' => [(int)$workshopState['equipment'][0]['candidates'][0]['instance_id']], 'use_object_budget' => false];
    $forgeQuote = $workspace->preview($freshUser, $forgeInput);
    economyCheck(!$forgeQuote['terms']['reasons'], 'public NPC forge accepts the participant materials and placed station');
    $forgeKey = bin2hex(random_bytes(16)); $forged = $workspace->craft($freshUser, $forgeKey, $forgeInput, $forgeQuote['quote_id'], (array)$forgeQuote['expected_revisions']);
    economyCheck($workspace->craft($freshUser, $forgeKey, $forgeInput, $forgeQuote['quote_id'], (array)$forgeQuote['expected_revisions']) === $forged && $forged['craft_result']['output']['quantity'] === 1 && $npcTotal() === $npcMoneyBefore, 'NPC station craft and replay produce one ingot without taking NPC materials or money');
    $forbiddenForge = $forgeInput; $forbiddenForge['source_storage_ids'] = [(int)$waterStorage['id']];
    try { $workspace->preview($freshUser, $forbiddenForge); throw new \RuntimeException('NPC inventory access was accepted.'); }
    catch (\yii\web\HttpException $error) { economyCheck(in_array($error->statusCode, [403, 404], true), 'public workshop does not grant access to NPC stock'); }
    // A new participant has no pick: the working NPC rig supplies the equipment.
    $drillUser = 9098;
    $db->createCommand()->insert('user', ['id' => $drillUser, 'username' => 'DrillWorkerFixture'])->execute();
    $db->createCommand()->insert('persone', ['id' => $drillUser, 'user_id' => $drillUser, 'credit' => '0.0000', 'balance' => '0.0000'])->execute();
    $app->authManager->assign($testRole, $drillUser);
    $drillJoin = Command::run($drillUser, bin2hex(random_bytes(16)), 'world.start', ['city_id' => (int)$policy['city_node_id']], static function ($op) use ($drillUser, $policy) { return \common\modules\world\models\StarterPack::enter($drillUser, (int)$policy['city_node_id'], $op); });
    $drillSite = \common\modules\world\models\Build::requireOne($drillJoin['build']['id']);
    Command::run($drillUser, bin2hex(random_bytes(16)), 'fixture.complete-site', ['build_id' => (int)$drillSite->id], static function ($op) use ($drillSite) { $drillSite->worked_seconds = $drillSite->required_seconds; $drillSite->finish($op); return []; });
    economyCheck((int)Node::requireOne($drillSite->node_id)->owner_user_id === $drillUser && $npcTotal() === $npcMoneyBefore, 'new participant can settle in a public bot-owned city without receiving invented credits');
    economyReject(static function () use ($drillUser, $policy) { Command::run($drillUser, bin2hex(random_bytes(16)), 'fixture.foreign-build', ['parent_id' => (int)$policy['site_node_id']], static function ($op) use ($drillUser, $policy) {
        return \common\modules\world\models\Build::begin($drillUser, ['parent_id' => (int)$policy['site_node_id'], 'template_id' => (int)NodeTemplate::requireOne(['code' => 'mine'])->id, 'name' => 'Forbidden foreign mine', 'labor_budget' => '0'], $op)->toArray();
    }); }, 'OWNER_REQUIRED');
    $mineNpc = Node::requireOne(['code' => 'npc-bot-mine']); $rigNpc = Node::requireOne(['code' => 'npc-bot-drilling']);
    $plainMine = Node::requireOne($sourceNodes['mine']);
    $plainActions = \common\modules\world\models\domain\WorldSources::options($plainMine);
    economyCheck($plainActions['mine-stone']['output']['classic-stone'] === 3 && $plainActions['mine-stone']['tools'] === ['world-stone-pickaxe'], 'a drill in a different mine does not replace this mine pick or increase its output');
    foreach ([['status' => 'constructing'], ['condition' => 0], ['owner_user_id' => $freshUser]] as $unavailableRig) {
        $rigTx = $db->beginTransaction();
        try {
            $db->createCommand()->update('world_node', $unavailableRig, ['id' => $rigNpc->id])->execute();
            $withoutRig = \common\modules\world\models\domain\WorldSources::options($mineNpc);
            economyCheck($withoutRig['mine-stone']['tools'] === ['world-stone-pickaxe'] && $withoutRig['mine-stone']['output']['classic-stone'] === 3
                && \common\modules\world\models\domain\HiredExtraction::rates($mineNpc)['reward_cr'] === '1.0000', 'unfinished, broken or foreign equipment cannot grant drill bonuses');
        } finally { $rigTx->rollBack(); }
    }
    $pickItems = (new Query())->select('id')->from('craft_item')->where(['code' => ['world-stone-pickaxe', 'classic-pickaxe']])->column($db);
    economyCheck((int)(new Query())->from('craft_inventory')->where(['user_id' => $drillUser, 'item_id' => (new Query())->select('id')->from('craft_item')->where(['code' => 'world-stone-pickaxe'])])->sum('item_quantity', $db) === 1, 'new participants receive one starter stone pick');
    Command::run($drillUser, bin2hex(random_bytes(16)), 'fixture.repeat-starter', [], static function ($operation) use ($drillUser) {
        \common\modules\world\models\StarterPack::claim($drillUser, $operation); return [];
    });
    economyCheck((int)(new Query())->select('quantity')->from('world_economy_fact')->where(['user_id' => $drillUser, 'code' => 'acquired:world-stone-pickaxe'])->scalar($db) === 1
        && (int)(new Query())->from('craft_inventory')->where(['user_id' => $drillUser, 'item_id' => (new Query())->select('id')->from('craft_item')->where(['code' => 'world-stone-pickaxe'])])->sum('item_quantity', $db) === 1, 'retrying starter claim preserves a single pick and its acquisition fact');
    Command::run($drillUser, bin2hex(random_bytes(16)), 'fixture.consume-starter-pick', [], static function ($operation) use ($db, $drillUser) {
        $store = new \common\modules\world\modules\craft\models\domain\CraftStorage($db); $store->operationId = $operation;
        $item = (new Query())->from('craft_item')->where(['code' => 'world-stone-pickaxe'])->one($db);
        $store->move($drillUser, $item, -1); return [];
    });
    economyCheck(!(new Query())->from('craft_inventory')->where(['user_id' => $drillUser, 'item_id' => $pickItems])->andWhere(['>', 'item_quantity', 0])->exists($db), 'drill worker starts without either kind of pick');
    $drillStock = \common\modules\world\models\domain\HiredExtraction::storages($mineNpc)[0];
    $stoneItem = (int)(new Query())->select('id')->from('craft_item')->where(['code' => 'classic-stone'])->scalar($db);
    $stoneBefore = (int)(new Query())->from('craft_inventory')->where(['storage_id' => $drillStock['id'], 'item_id' => $stoneItem])->sum('item_quantity', $db);
    $quotaBeforeDrill = \common\modules\world\models\domain\EconomyQuota::state($db, $drillUser, 'extraction_daily')['remaining'];
    $drillStarted = economySourceCommand($drillUser, (int)$mineNpc->id, 'start', ['action_code' => 'mine-stone']);
    $drillChoice = array_column($drillStarted['actions'], null, 'code')['mine-stone'];
    economyCheck($drillStarted['working'] && $drillChoice['tools'] === [] && $drillChoice['output'][0]['quantity'] === 15
        && $drillStarted['employment']['base_reward_cr'] === '1.0000' && $drillStarted['employment']['accepted_reward_cr'] === '3.0000', 'a pick-free drill batch accepts fifteen stone and reserves the triple wage');
    $mineBudget = (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->accounts((int)$mineNpc->id)['budget'];
    economyCheck(\common\modules\world\modules\economy\value\Money::parse($mineBudget['reserved'])->decimal() === '3.0000', 'accepted drill wages reserve exactly three existing credits');
    $savedProfile = EconomyRuntime::current($db)['raw_profile']; $changedDrill = $savedProfile;
    $changedDrill['source_equipment']['drilling-rig']['output_multiplier'] = 7; $changedDrill['source_equipment']['drilling-rig']['reward_multiplier'] = 4;
    $drillPublisher = new \common\modules\world\models\domain\EconomyProfilePublisher($db); $drillPreview = $drillPublisher->preview($changedDrill);
    $drillPublisher->publish($changedDrill, $drillPreview['digest'], 9001, 'Drill accepted batch compatibility fixture');
    $acceptedDrill = \common\modules\world\models\Mining::state((int)$mineNpc->id, $drillUser);
    $acceptedChoice = array_column($acceptedDrill['actions'], null, 'code')['mine-stone'];
    economyCheck($acceptedChoice['output'][0]['quantity'] === 15 && $acceptedChoice['drilling']['output_multiplier'] === 5
        && $acceptedDrill['employment']['reward_cr'] === '4.0000' && $acceptedDrill['employment']['accepted_reward_cr'] === '3.0000', 'profile changes affect future drill batches while the active batch retains its quantity and wage');
    $db->createCommand()->update('world_node', ['condition' => 0], ['id' => $rigNpc->id])->execute();
    for ($tick = 0; $tick < 3; $tick++) $blockedDrill = economySourceTick($drillUser, (int)$mineNpc->id);
    economyCheck($blockedDrill['pending_quantity'] === 1 && $blockedDrill['blocked_code'] === 'SOURCE_EQUIPMENT_REQUIRED'
        && \common\modules\world\models\domain\EconomyQuota::state($db, $drillUser, 'extraction_daily')['remaining'] === $quotaBeforeDrill
        && \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $drillUser])->scalar($db))->isZero(), 'a broken drill preserves earned progress and wage reserve without paying or consuming quota');
    $db->createCommand()->update('world_node', ['condition' => $rigNpc->max_condition], ['id' => $rigNpc->id])->execute();
    $drillFinishKey = bin2hex(random_bytes(16)); $drillFinishPayload = ['mining_node_id' => (int)$mineNpc->id];
    $drillFinish = static function ($op) use ($mineNpc, $drillUser) { return \common\modules\world\models\Mining::heartbeat((int)$mineNpc->id, $drillUser, $op); };
    $drillDone = Command::run($drillUser, $drillFinishKey, 'fixture.drill.finish', $drillFinishPayload, $drillFinish);
    economyCheck(Command::run($drillUser, $drillFinishKey, 'fixture.drill.finish', $drillFinishPayload, $drillFinish) === $drillDone
        && (int)(new Query())->from('craft_inventory')->where(['storage_id' => $drillStock['id'], 'item_id' => $stoneItem])->sum('item_quantity', $db) - $stoneBefore === 15
        && \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $drillUser])->scalar($db))->decimal() === '3.0000'
        && \common\modules\world\models\domain\EconomyQuota::state($db, $drillUser, 'extraction_daily')['remaining'] === $quotaBeforeDrill - 1
        && $npcTotal() === $npcMoneyBefore, 'repaired drill delivers the accepted fifteen stone and three credits exactly once without minting money');
    $restorePreview = $drillPublisher->preview($savedProfile); $drillPublisher->publish($savedProfile, $restorePreview['digest'], 9001, 'Restore drill fixture profile');
    $db->transaction(static function () use ($db, $plainMine, $freshUser) {
        $ownedRig = NodeFactory::create(NodeTemplate::requireOne(['code' => 'drilling-rig']), $plainMine, $freshUser, 'Owned drill fixture', 'owned-drill-fixture', null, 'constructing');
        $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $ownedRig->max_condition], ['id' => $ownedRig->id])->execute();
    });
    foreach (['2.0000' => '6.0000', '3.0000' => '9.0000'] as $baseWage => $effectiveWage) {
        $settings = Command::run($freshUser, bin2hex(random_bytes(16)), 'world.mining.settings', ['mining_node_id' => (int)$plainMine->id, 'reward_cr' => $baseWage], static function () use ($plainMine, $freshUser, $baseWage) { return \common\modules\world\models\domain\HiredExtraction::configure(Node::requireOne($plainMine->id), $freshUser, false, $baseWage); });
        economyCheck($settings['employment']['base_reward_cr'] === $baseWage && $settings['employment']['reward_cr'] === $effectiveWage, 'configured base wages are multiplied once by the owned drill');
    }
    $npcPrivate = static function () use ($db, $npcUser): array {
        return (new Query())->select(['a.id', 'a.amount', 'a.reserved'])->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
            ->where(['not', ['n.owner_user_id' => $npcUser]])->orderBy('a.id')->all($db);
    };
    $privateBefore = $npcPrivate();
    $maintenance = new \common\modules\world\models\domain\NpcMaintenance($db);
    $npcRefresh = static function () use ($db, $maintenance, $policy) {
        return Command::run(9001, bin2hex(random_bytes(16)), 'fixture.npc.refresh', ['node_id' => (int)$policy['world_id']], static function ($operation) use ($db, $maintenance, $policy) {
            $nodes = (new \common\modules\world\models\domain\NpcInfrastructure($db))->nodes($policy);
            (new \common\modules\world\models\domain\NpcStockLogistics($db))->consolidate($policy, $nodes, $operation);
            $maintenance->refresh($policy, $operation);
            return [];
        });
    };
    $npcStock = static function () use ($db, $npcUser): array {
        return (new Query())->select(['item_id', 'quantity' => new \yii\db\Expression('SUM(item_quantity)')])->from('craft_inventory')
            ->where(['user_id' => $npcUser])->andWhere(['>', 'item_quantity', 0])->groupBy('item_id')->orderBy('item_id')->all($db);
    };
    $stockBeforeLogistics = $npcStock();
    $sourceQuantity = static fn() => (int)(new Query())->from('craft_inventory')->where(['storage_id' => $drillStock['id'], 'item_id' => $stoneItem])->sum('item_quantity', $db);
    economyCheck($sourceQuantity() > 0, 'the source mini-store contains delivered mining output before maintenance');
    $npcRefresh();
    economyCheck($npcStock() === $stockBeforeLogistics, 'NPC consolidation preserves every resource quantity');
    economyCheck($sourceQuantity() === 0, 'NPC logistics frees source slots by moving delivered output to its warehouse');
    $layoutAfterLogistics = (new Query())->select(['id', 'storage_id', 'slot', 'item_quantity'])->from('craft_inventory')->where(['user_id' => $npcUser])->andWhere(['>', 'item_quantity', 0])->orderBy('id')->all($db);
    $npcRefresh();
    economyCheck($layoutAfterLogistics === (new Query())->select(['id', 'storage_id', 'slot', 'item_quantity'])->from('craft_inventory')->where(['user_id' => $npcUser])->andWhere(['>', 'item_quantity', 0])->orderBy('id')->all($db), 'repeated NPC consolidation does not duplicate or reshuffle stock');
    economyCheck($npcTotal() === $npcMoneyBefore && $npcPrivate() === $privateBefore
        && \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('amount')->from('economy_account')->where(['subject_id' => (new Query())->select('id')->from('economy_subject')->where(['node_id' => $waterStorage['node_id']]), 'role' => 'treasury'])->scalar($db))->isZero(), 'maintenance collects NPC sale proceeds without touching private accounts or issuing credits');
    economySourceCommand($freshUser, (int)$waterNode->id, 'start', ['action_code' => 'gather-water']);
    $npcRefresh();
    $waterAccounts = (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->accounts((int)$waterNode->id);
    economyCheck(\common\modules\world\modules\economy\value\Money::parse($waterAccounts['budget']['reserved'])->decimal() === '1.0000'
        && (new \common\modules\world\modules\economy\models\domain\BudgetSpending($db))->available((int)$waterAccounts['budget']['id'])->decimal() === '20.0000', 'refill tops up free funds while preserving the accepted wage reserve');
    $db->createCommand()->update('world_mining_worker', ['accounted_at' => time() - 60, 'last_seen_at' => time()], ['user_id' => $freshUser])->execute();
    \common\modules\world\models\Mining::discover();
    economyCheck(!\common\modules\world\models\Mining::state((int)$waterNode->id, $freshUser)['working'] && !EconomyRuntime::admission($db, $npcUser)['allowed'] && $npcTotal() === $npcMoneyBefore, 'trusted background completion works for bot without granting it participant access');
    $db->createCommand()->update('world_npc_policy', ['last_refill_at' => 0], ['id' => $policy['id']])->execute();
    $maintenance->discover(); $queue = new \common\modules\world\support\JobQueue($db); $job = $queue->claim(['world.npc.maintain']);
    economyCheck($job !== null && $queue->perform($job, static function ($payload, $row) use ($maintenance) { $maintenance->perform($payload, $row); }) && $npcTotal() === $npcMoneyBefore, 'recurring NPC maintenance runs through the leased job queue');
    Command::run(9001, bin2hex(random_bytes(16)), 'fixture.npc.exhaust-free-pool', ['node_id' => (int)$policy['world_id']], static function ($operation) use ($db, $npcUser, $policy, $maintenance) {
        $wallet = \common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $npcUser])->scalar($db));
        if (!$wallet->isZero()) (new \common\modules\world\modules\economy\models\domain\BudgetFunding($db))->contribute($npcUser, (int)$policy['world_id'], $wallet, 'Fixture existing money', $operation);
        $spending = new \common\modules\world\modules\economy\models\domain\BudgetSpending($db);
        $accounts = (new Query())->select(['a.id', 'n.node_type', 'n.settings_json'])->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')->where(['n.root_id' => $policy['world_id'], 'n.owner_user_id' => $npcUser, 'a.role' => 'budget'])->all($db);
        $reserve = static function ($id) use ($db, $npcUser, $policy, $spending) {
            $free = $spending->available((int)$id); if ($free->isZero()) return;
            $holdOperation = bin2hex(random_bytes(16));
            $db->createCommand()->insert('game_operation', ['id' => $holdOperation, 'user_id' => $npcUser, 'type' => 'fixture.npc.reserve', 'created_at' => time()] + EconomyRuntime::operationContext($db, ['node_id' => (int)$policy['world_id']]))->execute();
            $spending->reserve((int)$id, $free, 'Fixture protected reserve', $holdOperation);
        };
        foreach ($accounts as $account) if (in_array($account['node_type'], ['WORLD', 'REGION', 'SETTLEMENT'], true) || \common\modules\world\models\domain\NpcInfrastructure::role($account) === 'source') $reserve($account['id']);
        $maintenance->refresh($policy, $operation);
        $sources = array_filter((new \common\modules\world\models\domain\NpcInfrastructure($db))->nodes($policy), static fn($node) => \common\modules\world\models\domain\NpcInfrastructure::role($node) === 'source');
        foreach ($sources as $source) {
            $budget = (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->accounts((int)$source['id'])['budget'];
            $minimumWage = \common\modules\world\models\domain\HiredExtraction::rates(Node::requireOne($source['id']))['reward_cr'];
            economyCheck($spending->available((int)$budget['id'])->compare(\common\modules\world\modules\economy\value\Money::parse($minimumWage)) >= 0, 'idle NPC buffers fund at least one new batch without consuming reserved money: ' . $source['code']);
        }
        foreach ($accounts as $account) $reserve($account['id']);
        $maintenance->refresh($policy, $operation); return [];
    });
    economyCheck(\common\modules\world\modules\economy\value\Money::parse($infrastructure->policy()['last_shortfall_cr'])->compare(\common\modules\world\modules\economy\value\Money::parse('0')) > 0
        && \common\modules\world\models\domain\HiredExtraction::configuration($waterNode)['enabled'] && $npcTotal() === $npcMoneyBefore && $npcPrivate() === $privateBefore, 'finite pool exhaustion reports a shortfall while retaining public work policy, private money and every protected reserve');
    $overviewNpc = (new \common\modules\world\models\domain\WorldBalanceOverview($db))->report()['npc'];
    economyCheck($overviewNpc['available_pool_cr'] === '0.0000' && $overviewNpc['protected_cr'] !== '0.0000' && $overviewNpc['shortfall_cr'] !== '0.0000', 'admin overview separates spendable NPC funds from protected obligations and funding shortfall');
}
