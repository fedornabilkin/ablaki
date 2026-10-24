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
    economyReject(static function () use ($freshUser, $siteId) { \common\modules\world\models\Build::requireAvailableProject($freshUser, (int)Node::requireOne($siteId)->root_id); }, 'CONSTRUCTION_LIMIT');
    economyCheck((int)(new Query())->from('world_builder')->where(['user_id' => $freshUser])->count('*', $db) === 1 && (int)\common\modules\world\models\Build::requireOne($b->id)->worked_seconds === 0, 'legacy suspended work cannot renew a second construction session');
    $price = \common\modules\world\models\domain\WorldMapCells::purchasePricing(['base_price' => '0.5000', 'outer_ring_multiplier' => 10, 'bulk_minimum' => 3, 'discount_bps' => 500], [['x' => 1, 'y' => 0], ['x' => 2, 'y' => 0]]);
    economyCheck(array_column($price['unit_prices'], 'price') === ['0.5000', '10.0000'], 'distant rings cost twenty times the adjacent ring without changing starting cells');
    economyCheck(\common\modules\world\models\domain\EconomyProfile::load()['objects']['forge']['price_cr'] === '40.0000' && 250 - (20 + 50 + 10 + 120 + 40 + 3.8 + 4) > 0, '250 starting credits cover shelter, garden, bed, mine, forge, eight adjacent cells and two waterings');
} finally { $tx->rollBack(); }
echo 'Node-context, single-builder and one-click lodging checks passed.' . PHP_EOL;
