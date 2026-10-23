<?php
// Disposable, allowlisted database fixture. This test never loads application DB settings.
require __DIR__ . '/world-admin-entities.php';
$app->setModule('user', ['class' => \dektrium\user\Module::class]);

use common\modules\world\models\domain\EconomyProfile;
use common\modules\world\models\domain\EconomyProfilePublisher;
use common\modules\world\models\domain\EconomyRuntime;
use common\modules\world\models\domain\EconomyQuota;
use common\modules\world\support\GameError;
use yii\db\Query;

foreach (['m261008_120000_world_mvp_facilities', 'm261008_160000_world_construction_mining', 'm261008_210000_world_object_repair', 'm261009_170000_world_common_foundation'] as $migrationClass) {
    ob_start(); try { (new $migrationClass(['db' => $db]))->up(); } finally { ob_end_clean(); }
}
$db->schema->refresh();
require_once $yiiRoot . '/console/world-migrations/m261023_120000_world_starter_funding.php';
ob_start(); try { (new m261023_120000_world_starter_funding(['db' => $db]))->up(); } finally { ob_end_clean(); }
$db->schema->refresh();
require_once $yiiRoot . '/vendor/yiisoft/yii2/rbac/migrations/m140506_102106_rbac_init.php';
$app->set('authManager', ['class' => \yii\rbac\DbManager::class, 'db' => $db]);
ob_start(); try { (new m140506_102106_rbac_init(['db' => $db]))->up(); } finally { ob_end_clean(); }
$testPermission = $app->authManager->createPermission('world-closed-test'); $app->authManager->add($testPermission);
$testRole = $app->authManager->createRole('world-tester'); $app->authManager->add($testRole); $app->authManager->addChild($testRole, $testPermission);

function economyCheck(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
    echo 'PASS ' . $message . PHP_EOL;
}
function economyReject(callable $action, string $code): void
{
    try { $action(); } catch (GameError $error) { economyCheck($error->reason === $code, $code); return; }
    throw new RuntimeException('Expected ' . $code);
}

economyCheck(EconomyRuntime::current($db) === null, 'schema installation does not publish an economy profile');
$profile = EconomyProfile::load(); $publisher = new EconomyProfilePublisher($db);
$preview = $publisher->preview($profile);
economyCheck(!$preview['applied'] && EconomyRuntime::current($db) === null, 'profile preview is read-only');
$published = $publisher->publish($profile, $preview['digest'], 9001, 'Disposable MVP acceptance fixture');
economyCheck($published['changed'] && EconomyRuntime::current($db)['version'] === 1, 'explicit publication selects the immutable first revision');
$again = $publisher->preview($profile);
economyCheck(!$publisher->publish($profile, $again['digest'], 9001, 'Repeat fixture')['changed'], 'repeating installation preserves revision and administrative changes');
economyCheck(!EconomyRuntime::admission($db, 9002)['allowed'] && EconomyRuntime::admission($db, 9001, true)['allowed'], 'closed test admission is enforced separately from ordinary authentication');
$db->createCommand()->insert('world_test_participant', ['user_id' => 9001, 'cohort' => 'mvp-fixture', 'enabled' => 1, 'created_at' => time()])->execute();
economyCheck(!EconomyRuntime::admission($db, 9001)['allowed'], 'cohort metadata alone does not grant access');
$app->authManager->assign($testRole, 9001);
economyCheck(EconomyRuntime::admission($db, 9001)['allowed'], 'built-in RBAC role grants inherited closed-test permission');
$before = $publisher->preview($profile);
$db->createCommand()->update('craft_item', ['description' => 'Concurrent administrator edit'], ['code' => 'classic-log'])->execute();
economyReject(function () use ($publisher, $profile, $before) { $publisher->publish($profile, $before['digest'], 9001, 'Stale publication'); }, 'PROFILE_CHANGED');
$state = EconomyQuota::state($db, 9001, 'crafting_hourly');
foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $fixtureOperation) if (!(new Query())->from('game_operation')->where(['id' => str_repeat($fixtureOperation, 32)])->exists($db))
    $db->createCommand()->insert('game_operation', ['id' => str_repeat($fixtureOperation, 32), 'user_id' => 9001, 'type' => 'fixture.economy', 'created_at' => time()])->execute();
economyCheck($state['remaining'] === 30, 'published hourly quota starts at thirty recipe batches');
$db->transaction(function () use ($db) {
    EconomyQuota::consume($db, 9001, 'crafting_hourly', 30, str_repeat('a', 32));
    EconomyQuota::consume($db, 9001, 'crafting_hourly', 30, str_repeat('a', 32));
});
economyCheck(EconomyQuota::state($db, 9001, 'crafting_hourly')['remaining'] === 0, 'same quota source is applied once');
economyReject(function () use ($db) { $db->transaction(function () use ($db) { EconomyQuota::consume($db, 9001, 'crafting_hourly', 1, str_repeat('b', 32)); }); }, 'ECONOMY_QUOTA_EXHAUSTED');
$daily = EconomyQuota::state($db, 9001, 'extraction_daily', strtotime('2026-10-17T23:59:59+03:00'));
economyCheck($daily['restores_at'] === strtotime('2026-10-18T00:00:00+03:00'), 'daily quota restores at Moscow midnight');
$db->transaction(function () use ($db) { EconomyQuota::consume($db, 9001, 'sales_daily', 1, str_repeat('c', 32), '499.9999'); });
economyReject(function () use ($db) { $db->transaction(function () use ($db) { EconomyQuota::consume($db, 9001, 'sales_daily', 1, str_repeat('d', 32), '0.0002'); }); }, 'ECONOMY_QUOTA_EXHAUSTED');
economyCheck(EconomyQuota::state($db, 9001, 'sales_daily')['gross_remaining_cr'] === '0.0001', 'sales limits preserve the fourth decimal without floats');
$db->transaction(function () use ($db) {
    EconomyRuntime::fact($db, 9001, 'gather-log', 2, str_repeat('e', 32));
    EconomyRuntime::fact($db, 9001, 'gather-log', 2, str_repeat('e', 32));
});
economyCheck((int)(new Query())->from('world_economy_fact')->where(['user_id' => 9001, 'code' => 'gather-log'])->sum('quantity', $db) === 2, 'replayed fact does not duplicate the server milestone');
$freshUser = 9004;
$db->createCommand()->insert('user', ['id' => $freshUser, 'username' => 'EconomyFresh'])->execute();
$db->createCommand()->insert('persone', ['id' => $freshUser, 'user_id' => $freshUser, 'credit' => '250.0000', 'balance' => '0.0000'])->execute();
$db->createCommand()->insert('world_test_participant', ['user_id' => $freshUser, 'cohort' => 'mvp-fixture', 'enabled' => 1, 'created_at' => time()])->execute();
$app->authManager->assign($testRole, $freshUser);
$city = (int)(new Query())->select('id')->from('world_node')->where(['root_id' => EconomyRuntime::world($db), 'hierarchy_level' => 3, 'status' => 'active'])->scalar($db);
$startKey = bin2hex(random_bytes(16));
$start = \common\modules\world\models\Command::run($freshUser, $startKey, 'world.start', ['city_id' => $city], function ($operation) use ($freshUser, $city) {
    return \common\modules\world\models\StarterPack::enter($freshUser, $city, $operation);
});
$replayedStart = \common\modules\world\models\Command::run($freshUser, $startKey, 'world.start', ['city_id' => $city], function () { throw new RuntimeException('Replay ran the handler.'); });
economyCheck($start === $replayedStart, 'joining replay returns the exact accepted result');
economyCheck(\common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $freshUser])->scalar($db))->decimal() === '250.0000', 'joining does not mint credits');
$startProject = \common\modules\world\models\Build::requireOne($start['build']['id']);
\common\modules\world\models\Command::run($freshUser, bin2hex(random_bytes(16)), 'world.build.start', ['build_id' => (int)$startProject->id], function () use ($startProject, $freshUser) { return \common\modules\world\models\BuildWork::start($startProject, $freshUser); });
$db->createCommand()->update('world_build_work', ['accounted_at' => time() - 30, 'last_seen_at' => time()], ['build_id' => $startProject->id, 'ended_at' => null])->execute();
\common\modules\world\models\Command::run($freshUser, bin2hex(random_bytes(16)), 'world.build.heartbeat', ['build_id' => (int)$startProject->id], function ($operation) use ($startProject, $freshUser) { return \common\modules\world\models\BuildWork::heartbeat($startProject, $freshUser, $operation, false); });
$siteId = (int)$startProject->node_id;
economyCheck((int)(new Query())->from('world_map_cell')->where(['parent_id' => $siteId, 'state' => 'open'])->count('*', $db) === 1, 'completed starter site opens only the initial cell and keeps exploration meaningful');
$freshStock = new \common\modules\world\modules\craft\models\domain\CraftStorage($db); $freshStock->workspaceNodeId = $siteId;
$logItem = (int)(new Query())->select('id')->from('craft_item')->where(['code' => 'classic-log'])->scalar($db);
economyCheck($freshStock->quantities($freshUser)[$logItem] === 4, 'new starter grant has four logs rather than the old oversized set');
$plankRecipe = (int)(new Query())->select('id')->from('craft_recipe')->where(['code' => 'classic-plank'])->scalar($db);
(new \common\modules\world\modules\craft\models\domain\Crafting($freshStock))->command($freshUser, bin2hex(random_bytes(16)), 'craft', ['id' => $plankRecipe, 'quantity' => 1]);
economyCheck(EconomyQuota::state($db, $freshUser, 'crafting_hourly')['remaining'] === 29, 'legacy crafting consumes the same recipe-batch quota');
$forestTemplate = \common\modules\world\models\NodeTemplate::requireOne(['code' => 'forest-plot']);
$forestInput = ['parent_id' => $siteId, 'template_id' => (int)$forestTemplate->id, 'name' => 'Fixture forest', 'labor_budget' => $forestTemplate->minimumLaborBudget(), 'top_up' => true,
    'terms_digest' => \common\modules\world\models\domain\EconomyConstruction::terms($forestTemplate)['terms_digest']];
$forestBuild = \common\modules\world\models\Command::run($freshUser, bin2hex(random_bytes(16)), 'world.build.create', $forestInput, function ($operation) use ($freshUser, $forestInput) {
    $build = \common\modules\world\models\Build::begin($freshUser, $forestInput, $operation); return ['id' => (int)$build->id];
});
economyCheck(\common\modules\world\modules\economy\value\Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $freshUser])->scalar($db))->decimal() === '218.0000', 'construction pays thirty credits and holds the two-credit minimum helper budget');
$forestProject = \common\modules\world\models\Build::requireOne($forestBuild['id']);
\common\modules\world\models\Command::run($freshUser, bin2hex(random_bytes(16)), 'world.build.fixture-finish', ['build_id' => (int)$forestProject->id], function ($operation) use ($forestProject) {
    $forestProject->worked_seconds = $forestProject->required_seconds; $forestProject->finish($operation); return ['completed' => true];
});
$forestId = (int)$forestProject->node_id;
\common\modules\world\models\Command::run($freshUser, bin2hex(random_bytes(16)), 'world.mining.start', ['mining_node_id' => $forestId, 'action_code' => 'gather-log'], function ($operation) use ($forestId, $freshUser) {
    return \common\modules\world\models\Mining::start($forestId, $freshUser, $operation, 'gather-log');
});
for ($tick = 0; $tick < 2; $tick++) {
    $db->createCommand()->update('world_mining_worker', ['accounted_at' => time() - 60, 'last_seen_at' => time()], ['user_id' => $freshUser])->execute();
    $sourceResult = \common\modules\world\models\Command::run($freshUser, bin2hex(random_bytes(16)), 'world.mining.heartbeat', ['mining_node_id' => $forestId], function ($operation) use ($forestId, $freshUser) {
        return \common\modules\world\models\Mining::heartbeat($forestId, $freshUser, $operation);
    });
}
economyCheck(!$sourceResult['working'] && $sourceResult['produced_quantity'] === 1 && $sourceResult['quota']['remaining'] === 19, 'one source batch completes through the existing mining heartbeat and consumes one daily quota');
economyCheck($freshStock->quantities($freshUser)[$logItem] === 4, 'one crafted-away log and one gathered log conserve actual inventory');
$cooked = (new Query())->from('craft_item')->where(['code' => 'world-cooked-carrot'])->one($db);
$db->transaction(function () use ($freshStock, $freshUser, $cooked) { $freshStock->move($freshUser, $cooked, 2); });
$fullFood = \common\modules\world\models\domain\WorldFood::state($db, $freshUser);
economyCheck($fullFood['can_eat'], 'available backpack food can maintain nutrition even at full health');
$foodNightOp = bin2hex(random_bytes(16));
\common\modules\world\models\Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.night', [], function ($operation) use ($db, $freshUser) {
    \common\modules\world\models\domain\WorldFood::night($db, $freshUser, false, $operation);
    \common\modules\world\models\domain\WorldFood::night($db, $freshUser, false, $operation);
    return ['health' => \common\modules\world\models\domain\WorldFood::state($db, $freshUser)];
});
$foodState = \common\modules\world\models\domain\WorldFood::state($db, $freshUser);
economyCheck($foodState['hp'] === 85 && $foodState['revision'] === 1, 'unprotected night decreases HP once without replacing night illness');
$foodKey = bin2hex(random_bytes(16)); $foodPayload = ['code' => 'world-cooked-carrot', 'health_revision' => 1];
$ate = \common\modules\world\models\Command::run($freshUser, $foodKey, 'world.food.consume', $foodPayload, function ($operation) use ($db, $freshUser) {
    return \common\modules\world\models\domain\WorldFood::consume($db, $freshUser, 'world-cooked-carrot', 1, $operation);
});
$ateAgain = \common\modules\world\models\Command::run($freshUser, $foodKey, 'world.food.consume', $foodPayload, function () { throw new RuntimeException('Food replay ran.'); });
economyCheck($ate === $ateAgain && $ate['health']['hp'] === 89 && $freshStock->quantities($freshUser)[$cooked['id']] === 1, 'food replay restores four HP and consumes exactly one item');
economyReject(function () use ($db, $freshUser) { $db->transaction(function () use ($db, $freshUser) { \common\modules\world\models\domain\WorldFood::consume($db, $freshUser, 'world-cooked-carrot', 1, str_repeat('f', 32)); }); }, 'HEALTH_CHANGED');
$goals = new \common\modules\world\models\domain\WorldGoals($db);
economyReject(function () use ($db, $goals, $freshUser) { $db->transaction(function () use ($goals, $freshUser) { $goals->claim($freshUser, 'day-1', str_repeat('f', 32)); }); }, 'GOAL_NOT_READY');
\common\modules\world\models\Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.goal-funding', [], function ($operation) use ($db, $freshUser, $city, $goals) {
    (new \common\modules\world\modules\economy\models\domain\BudgetFunding($db))->contribute($freshUser, $city, \common\modules\world\modules\economy\value\Money::parse('50.0000'), 'Fixture municipal funding', $operation);
    return $goals->fund($city, 'day-1', 1, 9001, $operation);
});
economyCheck($goals->state($freshUser)['goals'][0]['funded'] && !$goals->state($freshUser)['goals'][0]['can_claim'], 'prepaid milestone still requires all server facts');
\common\modules\world\models\Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.goal-facts', [], function ($operation) use ($db, $freshUser) {
    foreach (['map_cell_explored' => 3, 'built:starter-shelter' => 1, 'crafted:classic-hammer' => 1, 'source:gather-log' => 5,
        'source:gather-stone' => 5, 'placed:classic-campfire' => 1, 'built:garden' => 1, 'crafted:world-shovel' => 1, 'bed_dug' => 1, 'carrot_sown' => 1] as $code => $quantity)
        EconomyRuntime::fact($db, $freshUser, $code, $quantity, $operation);
    return ['prepared' => true];
});
$goalKey = bin2hex(random_bytes(16));
$claimed = \common\modules\world\models\Command::run($freshUser, $goalKey, 'world.goal.claim', ['code' => 'day-1'], function ($operation) use ($goals, $freshUser) { return $goals->claim($freshUser, 'day-1', $operation); });
economyCheck($claimed['earned_cr'] === '50.0000' && $goals->state($freshUser)['goals'][0]['claimed'], 'completed funded milestone records its once-only claim');
economyCheck($claimed === \common\modules\world\models\Command::run($freshUser, $goalKey, 'world.goal.claim', ['code' => 'day-1'], function () { throw new RuntimeException('Goal replay ran.'); }), 'goal claim replay does not pay twice');
economyReject(function () use ($db, $goals, $freshUser) { $db->transaction(function () use ($goals, $freshUser) { $goals->claim($freshUser, 'day-1', str_repeat('f', 32)); }); }, 'GOAL_NOT_READY');
function economySourceCommand(int $user, int $node, string $action, array $extra = []): array
{
    $payload = ['mining_node_id' => $node] + $extra;
    return \common\modules\world\models\Command::run($user, bin2hex(random_bytes(16)), 'world.mining.' . $action, $payload, function ($operation) use ($user, $node, $action, $extra) {
        if ($action === 'start') return \common\modules\world\models\Mining::start($node, $user, $operation, $extra['action_code'] ?? null, $extra['output_storage_id'] ?? null);
        return \common\modules\world\models\Mining::heartbeat($node, $user, $operation, $action === 'stop');
    });
}
function economySourceTick(int $user, int $node, int $seconds = 60, bool $stop = false): array
{
    $db = Yii::$app->db;
    $db->createCommand()->update('world_mining_worker', ['accounted_at' => time() - $seconds, 'last_seen_at' => time()], ['user_id' => $user])->execute();
    return economySourceCommand($user, $node, $stop ? 'stop' : 'heartbeat');
}
$sourceBefore = $freshStock->quantities($freshUser)[$logItem];
economySourceCommand($freshUser, $forestId, 'start');
$paused = economySourceTick($freshUser, $forestId, 30, true);
economyCheck(!$paused['working'] && $paused['worked_seconds'] === 30 && $paused['quota']['remaining'] === 19, 'pausing source work preserves seconds without consuming quota');
economySourceCommand($freshUser, $forestId, 'start');
$db->createCommand()->update('world_mining_worker', ['accounted_at' => time() - 3600, 'last_seen_at' => time() - 3600], ['user_id' => $freshUser])->execute();
$offline = economySourceCommand($freshUser, $forestId, 'heartbeat');
economyCheck(!$offline['working'] && $offline['worked_seconds'] === 90 && $freshStock->quantities($freshUser)[$logItem] === $sourceBefore, 'one offline hour adds at most sixty accepted seconds and does not deliver a batch');
economySourceCommand($freshUser, $forestId, 'start');
$resumed = economySourceTick($freshUser, $forestId, 30);
economyCheck($resumed['quota']['remaining'] === 18 && $freshStock->quantities($freshUser)[$logItem] === $sourceBefore + 1, 'resuming finishes only the remaining work');
economySourceCommand($freshUser, $forestId, 'start');
economySourceTick($freshUser, $forestId);
$db->createCommand()->update('craft_item', ['active' => 0], ['id' => $logItem])->execute();
$blocked = economySourceTick($freshUser, $forestId);
economyCheck($blocked['pending_quantity'] === 1 && $blocked['worked_seconds'] === 120 && $blocked['blocked_code'] === 'SOURCE_OUTPUT_UNAVAILABLE' && $blocked['quota']['remaining'] === 18, 'disabled output preserves the completed batch without spending quota');
$changedProfile = $profile; $changedProfile['actions']['gather-log']['work_seconds'] = 240;
$changedPreview = $publisher->preview($changedProfile);
$publisher->publish($changedProfile, $changedPreview['digest'], 9001, 'Fixture: new source duration');
economyCheck(\common\modules\world\models\Mining::state($forestId, $freshUser)['seconds_per_unit'] === 120, 'accepted source work retains its duration after a new profile publication');
$db->createCommand()->update('craft_item', ['active' => 1], ['id' => $logItem])->execute();
$delivered = economySourceCommand($freshUser, $forestId, 'heartbeat');
economyCheck($delivered['pending_quantity'] === 0 && $delivered['quota']['remaining'] === 17 && $freshStock->quantities($freshUser)[$logItem] === $sourceBefore + 2, 'blocked source delivery can be retried once without repeating earned work');
$rollbackPreview = $publisher->preview($profile);
$publisher->publish($profile, $rollbackPreview['digest'], 9001, 'Fixture: restore prior profile as a new revision');
economyCheck(EconomyRuntime::current($db)['version'] === 3 && (int)(new Query())->from('world_economy_profile')->count('*', $db) === 3, 'rollback creates a new immutable publication without deleting history');
$db->createCommand()->insert('craft_storage', ['identity_key' => 'fixture-full-source', 'kind' => 'stockpile', 'owner_user_id' => $freshUser, 'node_id' => $forestId, 'capacity' => 1])->execute();
$fullStorage = (int)$db->getLastInsertID();
$db->transaction(function () use ($db, $freshUser, $fullStorage, $freshStock) {
    $inventory = new \common\modules\world\modules\craft\models\domain\CanonicalInventory($freshStock);
    $target = (new Query())->from('craft_storage')->where(['id' => $fullStorage])->one($db);
    $item = (new Query())->from('craft_item')->where(['code' => 'classic-stone'])->one($db);
    $plan = $inventory->planCraft([], $target, [], [], $item, 1);
    $inventory->applyCraft($freshUser, $plan, $target, [], $item, 'fixture.full-storage');
});
economySourceCommand($freshUser, $forestId, 'start', ['output_storage_id' => $fullStorage]);
economySourceTick($freshUser, $forestId);
$full = economySourceTick($freshUser, $forestId);
economyCheck($full['pending_quantity'] === 1 && $full['blocked_code'] === 'SOURCE_STORAGE_FULL' && $full['quota']['remaining'] === 17, 'full storage preserves work and does not consume quota');
$db->createCommand()->update('craft_storage', ['capacity' => 2], ['id' => $fullStorage])->execute();
$retry = economySourceCommand($freshUser, $forestId, 'heartbeat');
economyCheck($retry['quota']['remaining'] === 16 && (int)(new Query())->select('item_quantity')->from('craft_inventory')->where(['storage_id' => $fullStorage, 'item_id' => $logItem])->scalar($db) === 1, 'delivery after freeing space credits exactly one batch');
$hammer = (new Query())->select('e.*')->from(['e' => 'craft_equipment_instance'])->innerJoin(['i' => 'craft_inventory'], 'i.id=e.inventory_id')
    ->where(['i.user_id' => $freshUser, 'e.status' => 'active'])->one($db);
$hammerDurability = (int)$hammer['durability'];
$db->createCommand()->update('craft_equipment_instance', ['durability' => 0], ['id' => $hammer['id']])->execute();
economyReject(function () use ($freshUser, $forestId) { economySourceCommand($freshUser, $forestId, 'start'); }, 'SOURCE_TOOL_REQUIRED');
economyCheck(EconomyQuota::state($db, $freshUser, 'extraction_daily')['remaining'] === 16, 'broken tool rejection leaves quota unchanged');
$replacementWallet = (string)(new Query())->select('credit')->from('persone')->where(['user_id' => $freshUser])->scalar($db);
foreach (['classic-plank', 'classic-stick', 'classic-hammer'] as $code) {
    $recipeId = (int)(new Query())->select('id')->from('craft_recipe')->where(['code' => $code])->scalar($db);
    (new \common\modules\world\modules\craft\models\domain\Crafting($freshStock))->command($freshUser, bin2hex(random_bytes(16)), 'craft', ['id' => $recipeId, 'quantity' => 1]);
}
economyCheck((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $freshUser])->scalar($db) === $replacementWallet
    && (int)(new Query())->select('durability')->from('craft_equipment_instance')->where(['id' => $hammer['id']])->scalar($db) === 0
    && economySourceCommand($freshUser, $forestId, 'start')['working'], 'broken hammer can be replaced through existing recipes without credits, starter replay or repairing the broken instance');
economySourceCommand($freshUser, $forestId, 'stop');
$history = new \common\modules\world\models\domain\EconomyHistory($db);
$healthHistory = $history->listing($freshUser, 'health', 1, '');
economyCheck($healthHistory['_meta']['totalCount'] === 2 && count($healthHistory['items']) === 2 && $history->listing(9002, 'health', 1, '')['_meta']['totalCount'] === 0, 'history filters ownership before calculating totals and pages');
economyCheck($history->listing($freshUser, 'health', 1, 'food:')['items'][0]['quantity'] === 4, 'history exposes actual food health delta');
foreach (['commands', 'credits', 'items', 'transfers', 'health'] as $source) {
    $historyPage = $history->listing($freshUser, $source, 1, '');
    economyCheck(count($historyPage['items']) <= 20 && strpos(json_encode($historyPage), 'password') === false, 'bounded private history: ' . $source);
}
$creditEntry = $history->listing($freshUser, 'credits', 1, '')['items'][0];
economyCheck($creditEntry['balance_before_cr'] !== null && $creditEntry['profile_version'] !== null
    && $creditEntry['actor_user_id'] === $freshUser, 'journal correlates exact before/after balances, actor and persisted profile version');
$deliveredHistory = (new Query())->from('world_source_work')->where(['user_id' => $freshUser, 'status' => 'completed', 'profile_id' => 1])->one($db);
$foundHistory = false;
for ($p = 1; $p <= $history->listing($freshUser, 'items', 1, '')['_meta']['pageCount']; $p++) foreach ($history->listing($freshUser, 'items', $p, '')['items'] as $entry)
    if ($entry['operation_id'] === $deliveredHistory['operation_id']) $foundHistory = $entry['profile_version'] === 1 && $entry['item_id'] !== null && $entry['quantity_before'] === null;
economyCheck($foundHistory, 'journal keeps the accepted source profile after later publications and leaves unavailable historical quantities null');
$app->authManager->revoke($testRole, $freshUser);
economyCheck(!EconomyRuntime::admission($db, $freshUser)['allowed'], 'revoking the built-in role immediately removes closed-test admission');
economyReject(function () use ($freshUser, $forestId) { economySourceCommand($freshUser, $forestId, 'start'); }, 'CLOSED_TEST_ACCESS_REQUIRED');
economyCheck($claimed === \common\modules\world\models\Command::run($freshUser, $goalKey, 'world.goal.claim', ['code' => 'day-1'], function () { throw new RuntimeException('Revoked replay ran.'); }), 'completed command remains replayable without authorizing new writes');
$app->authManager->assign($testRole, $freshUser);

$expansions = new \common\modules\world\models\domain\EconomyStorage($db, $flags);
$backpackId = (int)(new \common\modules\world\modules\craft\models\domain\CanonicalInventory($freshStock))->backpack($freshUser)['id'];
$expandedPreview = $expansions->preview($freshUser, $backpackId, 'backpack-10');
economyCheck($expandedPreview['terms']['capacity_before'] === 20 && $expandedPreview['terms']['capacity_after'] === 30 && $expandedPreview['terms']['price_cr'] === '90.0000', 'storage package preview binds permanent capacity and exact total');
$expandedKey = bin2hex(random_bytes(16));
$expanded = $expansions->execute($freshUser, $backpackId, 'backpack-10', $expandedKey, $expandedPreview['quote_id'], (array)$expandedPreview['expected_revisions']);
economyCheck($expanded['capacity_after'] === 30 && $expansions->state($freshUser, $backpackId)['capacity'] === 30, 'storage package adds permanent slots without replacing existing rights');
economyCheck($expanded === $expansions->execute($freshUser, $backpackId, 'backpack-10', $expandedKey, $expandedPreview['quote_id'], (array)$expandedPreview['expected_revisions'])
    && (int)(new Query())->from('world_storage_expansion')->where(['user_id' => $freshUser])->count('*', $db) === 1, 'storage expansion replay debits and expands exactly once');
economyReject(function () use ($expansions, $freshUser, $backpackId, $expandedPreview) { $expansions->execute($freshUser, $backpackId, 'backpack-10', bin2hex(random_bytes(16)), $expandedPreview['quote_id'], (array)$expandedPreview['expected_revisions']); }, 'QUOTE_ALREADY_USED');

$adminPermission = $app->authManager->createPermission('p-admin'); $app->authManager->add($adminPermission); $app->authManager->assign($adminPermission, 9001);
$app->user->switchIdentity(new CraftIdentity());
$profilePage = adminPage('world/craft/catalog/economy');
economyCheck(strpos($profilePage, 'economy-document') !== false && strpos($profilePage, 'world-tester') !== false, 'existing catalog administration exposes profile settings and built-in RBAC instructions');
$profilePage = adminPage('world/craft/catalog/economy', [], [], ['document' => json_encode($profile), 'reason' => 'Fixture form preview']);
economyCheck(strpos($profilePage, 'name="digest"') !== false && EconomyRuntime::current($db)['version'] === 3, 'profile form preview exposes a bound publication digest without changing the profile');
adminReject(400, function () use ($profile) { adminPage('world/craft/catalog/economy', [], [], ['document' => json_encode($profile), 'reason' => 'Missing CSRF'], false); }, 'profile publication requires CSRF');
$app->authManager->revoke($adminPermission, 9001); $app->user->switchIdentity(new CraftIdentity());
adminReject(403, function () { adminPage('world/craft/catalog/economy'); }, 'profile administration requires built-in administrator permission');

$db->transaction(function () use ($db, $siteId, $freshUser, &$greenhouse, &$greenhouseBed) {
    $greenhouse = \common\modules\world\models\NodeFactory::create(\common\modules\world\models\NodeTemplate::requireOne(['code' => 'small-greenhouse']), \common\modules\world\models\Node::requireOne($siteId), $freshUser, 'Existing fixture greenhouse', 'fixture-greenhouse', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $greenhouse->max_condition], ['id' => $greenhouse->id])->execute(); $greenhouse->refresh();
    $greenhouseBed = \common\modules\world\models\NodeFactory::create(\common\modules\world\models\NodeTemplate::requireOne(['code' => 'garden-bed']), $greenhouse, $freshUser, 'Existing fixture greenhouse bed', 'fixture-greenhouse-bed', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $greenhouseBed->max_condition], ['id' => $greenhouseBed->id])->execute();
});
$environment = \common\modules\world\models\domain\CultivationEnvironment::snapshot($db, (int)$greenhouseBed->id, $freshUser);
$protectedRules = \common\modules\world\models\domain\CultivationEnvironment::rules(['grow_seconds' => 1800, 'water_interval_seconds' => 900, 'water_window_seconds' => 900], $environment);
economyCheck($protectedRules['grow_seconds'] === 1440 && $protectedRules['water_interval_seconds'] === 720, 'greenhouse reduces growth and watering intervals by twenty percent');
$event = new \common\modules\world\models\WorldEvent(); $event->loadDefaultValues();
$event->setAttributes(['name' => 'Fixture light weather', 'scope_node_id' => $greenhouse->id, 'starts_at' => time() - 10, 'ends_at' => time() + 3600, 'status' => 'active', 'rules_json' => '{"crop_damage":{"code":"light_crop_damage","loss_bps":2000}}'], false);
if (!$event->save(false)) throw new RuntimeException('Weather fixture failed.');
$fixtureCycle = ['bed_id' => (int)$greenhouseBed->id, 'planted_at' => time() - 100, 'ready_at' => time() + 1340, 'terms_json' => json_encode(['environment' => $environment])];
$protectedDamage = \common\modules\world\models\domain\CultivationEnvironment::damage($db, $fixtureCycle, $environment, time());
$plainDamage = \common\modules\world\models\domain\CultivationEnvironment::damage($db, $fixtureCycle, ['event_protection' => null], time());
economyCheck($protectedDamage['loss_bps'] === 1000 && $plainDamage['loss_bps'] === 2000, 'greenhouse halves actual light-weather damage using existing world events');
$db->createCommand()->update('world_node', ['status' => 'archived'], ['id' => $greenhouse->id])->execute();
economyCheck(\common\modules\world\models\domain\CultivationEnvironment::accepted($fixtureCycle)['grow_duration_bps'] === 8000
    && \common\modules\world\models\domain\CultivationEnvironment::snapshot($db, (int)$greenhouseBed->id, $freshUser)['grow_duration_bps'] === 10000, 'accepted growing cycle retains greenhouse benefits while future sowing requires an active greenhouse');

echo 'Economy runtime checks passed.' . PHP_EOL;
