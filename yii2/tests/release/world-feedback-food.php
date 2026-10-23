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
    $forest = NodeFactory::create(NodeTemplate::requireOne(['code' => 'forest-plot']), Node::requireOne($finiteCity), $finiteUser, 'Supply exclusion fixture', 'supply-forest', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active'], ['id' => $forest->id])->execute();
    economyCheck(!in_array('classic-log', array_column($finite->state($finiteUser)['items'], 'code'), true), 'a completed owned production source excludes its raw resource from daily supplies');
    EconomyRuntime::fact($db, $finiteUser, 'built:forest-plot', 1, $joined['operation_id']);
    $db->createCommand()->update('world_node', ['status' => 'archived'], ['id' => $forest->id])->execute();
    economyCheck(!in_array('classic-log', array_column($finite->state($finiteUser)['items'], 'code'), true), 'archiving production does not reopen the previously replaced daily resource');
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
    economyCheck($counts === [14, 12, 10, 8, 6] && $finite->state($finiteUser)['gather_exhausted'], 'daily quantities strictly decrease and stop after five actual claims');
    economyReject(static function () use ($claimFinite) { $claimFinite(bin2hex(random_bytes(16))); }, 'SUPPLIES_EXHAUSTED');
    $history = (new \common\modules\world\models\domain\EconomyHistory($db))->listing($finiteUser, 'commands', 1, 'снабжение');
    if (!$history['items']) $history = (new \common\modules\world\models\domain\EconomyHistory($db))->listing($finiteUser, 'commands', 1, 'gather');
    $details = array_merge(...array_column($history['items'], 'items'));
    economyCheck((bool)array_filter($details, static function ($item) { return $item['code'] === 'classic-log' && $item['name'] !== '' && $item['quantity'] === 6 && $item['direction'] === 'collected'; }), 'history exposes the gathered item, accepted quantity and collection direction');
    $nextProfile = EconomyRuntime::current($db)['raw_profile']; $nextProfile['daily']['claim_limit'] = 6;
    $publisher = new \common\modules\world\models\domain\EconomyProfilePublisher($db); $nextPreview = $publisher->preview($nextProfile);
    $publisher->publish($nextProfile, $nextPreview['digest'], 9001, 'Finite allowance compatibility check');
    economyCheck($finite->state($finiteUser)['gather_exhausted'], 'publishing a larger allowance never refills previously exhausted resources');
    $newItem = (new Query())->from('craft_item')->where(['code' => 'classic-log'])->one($db); unset($newItem['id']);
    $newItem['code'] = 'fixture-new-daily-resource'; $newItem['name'] = 'Новый ресурс снабжения'; $newItem['label'] = $newItem['name']; $newItem['gather_quantity'] = 1;
    $db->createCommand()->insert('craft_item', $newItem)->execute();
    $newState = $finite->state($finiteUser);
    economyCheck(array_column($newState['items'], 'code') === ['fixture-new-daily-resource'] && $newState['gather_available'], 'only the newly introduced catalogue resource reopens its own allowance');
    $claimFinite(bin2hex(random_bytes(16)));
    economyCheck(!(new Query())->from('world_supply_claim')->where(['user_id' => $finiteUser, 'item_code' => 'classic-log', 'ordinal' => 6])->exists($db), 'new catalogue resources cannot repeat the old resource grants');
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
    $fund = static function (int $node, string $amount, ?string $key = null) use ($run, $db) {
        return $run(9001, 'fixture.budget', ['node_id' => $node, 'amount' => $amount], static function ($op) use ($db, $node, $amount) {
            return (new \common\modules\world\modules\economy\models\domain\BudgetFunding($db))->contribute(9001, $node, \common\modules\world\modules\economy\value\Money::parse($amount), 'Fixture funds', $op);
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
    $nested = NodeFactory::create(NodeTemplate::requireOne(['code' => 'forest-plot']), $mine, $finiteUser, 'Hierarchy strength fixture', 'nested-strength-fixture', null, 'constructing');
    economyCheck($nested->max_condition < $mine->max_condition && $mine->max_condition < Node::requireOne($finiteBuild->node_id)->max_condition, 'strength follows actual placement depth even when the template permits different levels');
    (new \common\modules\world\modules\economy\models\domain\EconomyHierarchy($db))->provision((int)$mine->id, $joined['operation_id']);
    $db->createCommand()->insert('craft_storage', ['identity_key' => 'stockpile:fixture:paid-mine', 'kind' => 'stockpile', 'node_id' => $mine->id, 'owner_user_id' => $finiteUser, 'capacity' => 5])->execute();
    $stockId = (int)$db->getLastInsertID();
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
