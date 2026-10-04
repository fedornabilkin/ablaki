<?php
// Isolated release fixture: in-memory SQLite or classic-craft allowlisted CI databases.
$yiiRoot = dirname(__DIR__, 2);
require __DIR__ . '/classic-craft.php';
Yii::setAlias('@console', $yiiRoot . '/console');
// The old production migration uses ALTER COLUMN, unsupported by SQLite. Rebuild
// only this disposable legacy fixture with its intended nullable owner first.
if ($db->driverName === 'sqlite') {
$schemaSql = $db->createCommand("SELECT sql FROM sqlite_master WHERE type='table' AND name='craft_inventory'")->queryScalar();
$schemaSql = preg_replace('/([`"\[]?user_id[`"\]]?\s+[^,]*?)\s+NOT NULL/i', '$1', $schemaSql);
$indexSql = $db->createCommand("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='craft_inventory' AND sql IS NOT NULL")->queryColumn();
$db->createCommand('ALTER TABLE craft_inventory RENAME TO fixture_inventory_old')->execute();
$db->createCommand($schemaSql)->execute();
$db->createCommand('INSERT INTO craft_inventory SELECT * FROM fixture_inventory_old')->execute();
$db->createCommand('DROP TABLE fixture_inventory_old')->execute();
foreach ($indexSql as $sql) $db->createCommand($sql)->execute();
}
$db->schema->refresh();
$app->setModule('world', ['class' => \common\modules\world\Module::class, 'flags' => ['world_read' => true, 'world_write' => true, 'storage_v2' => true, 'economy_tick' => true]]);
foreach (glob($yiiRoot . '/console/world-migrations/*.php') as $file) {
    require_once $file; $class = basename($file, '.php');
    if ($db->driverName === 'sqlite' && $class === 'm260928_003400_system_profession_author') {
        $sql = $db->createCommand("SELECT sql FROM sqlite_master WHERE type='table' AND name='profession_revision'")->queryScalar();
        $sql = preg_replace('/([`"\[]?author_user_id[`"\]]?\s+[^,]*?)\s+NOT NULL/i', '$1', $sql);
        $indexes = $db->createCommand("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name='profession_revision' AND sql IS NOT NULL")->queryColumn();
        $db->createCommand('ALTER TABLE profession_revision RENAME TO fixture_profession_old')->execute();
        $db->createCommand($sql)->execute(); $db->createCommand('INSERT INTO profession_revision SELECT * FROM fixture_profession_old')->execute();
        $db->createCommand('DROP TABLE fixture_profession_old')->execute(); foreach ($indexes as $index) $db->createCommand($index)->execute(); $db->schema->refresh();
    }
    ob_start(); try { $ok = (new $class(['db' => $db]))->up(); } finally { $migrationLog = ob_get_clean(); }
    if ($ok === false) throw new RuntimeException('Migration failed: ' . $class . PHP_EOL . $migrationLog);
    echo 'MIGRATED ' . $class . PHP_EOL;
}
$db->schema->refresh();
$ids = (new \common\modules\world\service\WorldSeeder($db))->seed();
$db->createCommand()->update('world_registry', ['schema_version' => \common\modules\world\service\WorldFlags::SCHEMA_VERSION, 'world_read' => 1, 'world_write' => 1, 'storage_v2' => 1, 'economy_tick' => 1], ['id' => 1])->execute();
$flags = new \common\modules\world\service\WorldFlags($db, $app->getModule('world'));
echo 'PASS world migrations and seed' . PHP_EOL;
$tree = new \common\modules\world\service\WorldTree($db);
$site = $db->transaction(function () use ($tree, $ids) { return $tree->create(['code' => 'fixture-site', 'slug' => 'fixture-site', 'name' => 'Усадьба', 'node_type' => 'PLOT', 'parent_id' => $ids['north-city'], 'owner_user_id' => 9001, 'visibility' => 'private'], ['plot_kind' => 'campsite', 'area' => 4, 'allow_building' => 1]); });
$house = $db->transaction(function () use ($tree, $site) { return $tree->create(['code' => 'fixture-house', 'slug' => 'fixture-house', 'name' => 'Дом', 'node_type' => 'BUILDING', 'parent_id' => (int)$site['id'], 'owner_user_id' => 9001, 'visibility' => 'private'], ['building_kind' => 'house', 'operational_status' => 'active']); });
$room = $db->transaction(function () use ($tree, $house) { return $tree->create(['code' => 'fixture-room', 'slug' => 'fixture-room', 'name' => 'Комната', 'node_type' => 'ROOM', 'parent_id' => (int)$house['id'], 'owner_user_id' => 9001, 'visibility' => 'private'], ['area' => 2, 'exposure_class' => 'indoor']); });
checkCraft((int)$site['map_width'] === 5 && (int)$house['map_width'] === 3 && (int)$room['map_width'] === 2 && (int)$room['map_height'] === 3, 'map defaults');
checkCraft(\common\modules\world\service\WorldHome::node($db, 9001, $ids['ablaki']) === (int)$house['id'], 'home chooses owned house');
checkCraft((new \common\modules\economy\service\EconomyHierarchy($db))->financialNode((int)$room['id']) === (int)$house['id'], 'rooms spend parent budget');
try { $tree->assertFreePosition((int)$site['id'], 5, 0); throw new RuntimeException('Outside cell accepted'); } catch (\common\services\game\GameError $e) { checkCraft($e->getMessage() !== '', 'bounds reject outside cell'); }
$garden = $db->transaction(function () use ($tree, $ids) { return $tree->create(['code' => 'fixture-garden', 'slug' => 'fixture-garden', 'name' => 'Огород', 'node_type' => 'PLOT', 'parent_id' => $ids['north-city'], 'owner_user_id' => 9001, 'visibility' => 'private'], ['plot_kind' => 'garden', 'area' => 10]); });
$beds = [];
for ($i = 1; $i <= 10; $i++) $beds[] = $db->transaction(function () use ($tree, $garden, $i) { return $tree->create(['code' => 'fixture-bed-' . $i, 'slug' => 'fixture-bed-' . $i, 'name' => 'Грядка ' . $i, 'node_type' => 'BED', 'parent_id' => (int)$garden['id'], 'owner_user_id' => 9001, 'visibility' => 'private'], ['garden_node_id' => (int)$garden['id'], 'ordinal' => $i, 'unlocked' => 1]); });
checkCraft(count(array_unique(array_map(static function ($b) { return $b['position_x'] . ':' . $b['position_y']; }, $beds))) === 10 && (int)min(array_column($beds, 'position_x')) === -2 && (int)max(array_column($beds, 'position_x')) === 2, 'ten beds occupy fixed 5 by 2 map');
$cycle = ['ready_at' => 300, 'water_due_at' => 120, 'water_missed' => 0]; $rules = ['water_window_seconds' => 60, 'harvest_window_seconds' => 600];
$clock = '\common\modules\world\service\CultivationClock';
checkCraft(!$clock::project($cycle, $rules, 179)['water_missed'] && $clock::project($cycle, $rules, 180)['yield_factor_bps'] === 5000, 'watering exact deadline halves yield');
checkCraft($clock::project($cycle, $rules, 300)['state'] === 'ripe' && $clock::project($cycle, $rules, 300)['ready_at'] === 300, 'missed watering never pauses growth');
checkCraft($clock::project($cycle, $rules, 899)['state'] === 'ripe' && $clock::project($cycle, $rules, 900)['state'] === 'expired', 'harvest exact expiry boundary');
$warehouse = $db->transaction(function () use ($tree, $site) { return $tree->create(['code' => 'fixture-warehouse', 'slug' => 'fixture-warehouse', 'name' => 'Склад', 'node_type' => 'BUILDING', 'parent_id' => (int)$site['id'], 'owner_user_id' => 9001, 'visibility' => 'private'], ['building_kind' => 'warehouse', 'operational_status' => 'active']); });
$warehouseState = (new \common\modules\world\service\WorldWarehouse($db, $flags))->state(9001, (int)$warehouse['id']);
checkCraft($warehouseState['capacity'] === 20 && $warehouseState['finance_node_id'] === (int)$site['id'], 'warehouse capacity and parent budget');
$reader = new \common\modules\world\service\WorldQuery($db, new \common\modules\world\service\WorldAccessPolicy(9001));
checkCraft(!$reader->node((int)$warehouse['id'])['has_finances'] && !$reader->node((int)$room['id'])['has_finances'] && $reader->node((int)$garden['id'])['has_finances'], 'only financial entities expose finances');
checkCraft($reader->map((int)$house['id'])['items'][0]['id'] === (int)$room['id'], 'house map contains room');
ob_start(); (new \m261003_120000_world_living_spaces(['db' => $db]))->up(); ob_end_clean();
checkCraft((new \yii\db\Query())->from('craft_storage')->where(['identity_key' => 'stockpile:building:' . $warehouse['id']])->count('*', $db) == 1, 'migration retry preserves stockpile identity');
echo "PASS living spaces scenarios\n";

$operation = str_repeat('a', 32);
$db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => 9001, 'type' => 'fixture', 'created_at' => time()])->execute();
$db->createCommand()->insert('world_membership', ['user_id' => 9001, 'world_id' => $ids['ablaki'], 'starter_site_id' => $site['id'], 'joined_at' => time(), 'grace_until' => time() + 86400])->execute();
$db->createCommand()->insert('world_expansion_policy', ['node_id' => $garden['id'], 'kind' => 'garden_bed', 'initial_open' => 1, 'place_limit' => 10, 'base_price' => '10.0000', 'curve' => 'linear', 'operation_id' => $operation])->execute(); $policyId = (int)$db->getLastInsertID();
$bedId = (int)$beds[0]['id'];
$db->createCommand()->insert('world_expansion_entitlement', ['policy_id' => $policyId, 'node_id' => $bedId, 'ordinal' => 1, 'price' => '0.0000', 'policy_revision' => 1, 'operation_id' => $operation, 'created_at' => time()])->execute();
$db->createCommand()->insert('craft_storage', ['identity_key' => 'backpack:user:9001', 'kind' => 'backpack', 'owner_user_id' => 9001, 'capacity' => 20])->execute(); $backpack = (int)$db->getLastInsertID();
$db->createCommand()->update('craft_inventory', ['storage_id' => $backpack], ['user_id' => 9001])->execute();
$draft = (new \yii\db\Query())->from('world_crop_revision')->one($db);
$cultivation = new \common\modules\world\service\WorldCultivation($db, $flags, new \common\modules\world\service\WorldAccessPolicy(9001, true));
$publication = $cultivation->publication(['code' => 'fixture_carrot', 'name' => 'Морковь', 'reason' => 'Fixture', 'seed_item_id' => (int)$draft['seed_item_id'], 'yield_item_id' => (int)$draft['yield_item_id'], 'water_item_id' => (int)$draft['water_item_id'], 'seed_quantity' => 1, 'yield_quantity' => 4, 'water_quantity' => 1, 'grow_seconds' => 300, 'water_interval_seconds' => 120]);
function cropCommand($service, $action, $input) {
    $quote = $service->preview(9001, $action, $input); $key = bin2hex(random_bytes(16));
    $result = $service->execute(9001, $action, $input, $key, $quote['quote_id'], (array)$quote['expected_revisions']);
    $retry = $service->execute(9001, $action, $input, $key, $quote['quote_id'], (array)$quote['expected_revisions']);
    checkCraft($result === $retry, $action . ' retries are idempotent'); return $result;
}
$published = cropCommand($cultivation, 'publish', $publication);
try { $cultivation->preview(9001, 'sow', ['bed_id' => $bedId, 'crop_revision_id' => $published['crop_revision_id']]); throw new RuntimeException('Undug sow accepted'); } catch (\common\services\game\GameError $e) {}

$dig = $cultivation->preview(9001, 'dig', ['bed_id' => $bedId]);
checkCraft(!$dig['terms']['tool']['available'], 'dig preview explains missing shovel');
try { cropCommand($cultivation, 'dig', ['bed_id' => $bedId]); throw new RuntimeException('Missing shovel accepted'); } catch (\common\services\game\GameError $e) {}
$db->transaction(function () use ($db, $backpack) {
    $shovel = (new \yii\db\Query())->from('craft_item')->where(['code' => 'world-shovel'])->one($db);
    $pack = (new \yii\db\Query())->from('craft_storage')->where(['id' => $backpack])->one($db);
    $s = new \common\modules\craft\service\CraftStorage($db); $s->operationId = str_repeat('a', 32);
    $inv = new \common\modules\craft\service\CanonicalInventory($s);
    $inv->applyCraft(9001, ['output_fits' => true, 'materials' => [], 'consume' => [], 'grant' => [['inventory_id' => null, 'position' => 10, 'quantity' => 1]]], $pack, [], $shovel);
});

cropCommand($cultivation, 'dig', ['bed_id' => $bedId]);
checkCraft($cultivation->state(9001, $bedId)['dug'], 'dig prepares bed');
checkCraft((int)(new \yii\db\Query())->select('durability')->from('craft_equipment_instance')->where(['item_id' => (new \yii\db\Query())->select('id')->from('craft_item')->where(['code'=>'world-shovel'])])->scalar($db) === 99, 'dig wears shovel exactly once including retry');
foreach ([(int)$draft['seed_item_id'], (int)$draft['water_item_id']] as $i => $itemId) $db->createCommand()->insert('craft_inventory', ['user_id' => 9001, 'item_id' => $itemId, 'item_quantity' => 10, 'slot' => $i + 1, 'storage_id' => $backpack])->execute();
$sown = cropCommand($cultivation, 'sow', ['bed_id' => $bedId, 'crop_revision_id' => $published['crop_revision_id']]);
checkCraft(!$cultivation->state(9001, $bedId)['dug'], 'sowing consumes preparation');
$db->createCommand()->update('world_crop_cycle', ['water_due_at' => time() - 5], ['id' => $sown['cycle_id']])->execute();
cropCommand($cultivation, 'water', ['bed_id' => $bedId]);
checkCraft(!$cultivation->state(9001, $bedId)['cycle']['water_missed'], 'timely watering retains full yield');
$db->createCommand()->update('world_crop_cycle', ['water_due_at' => time() - 120, 'ready_at' => time() - 1], ['id' => $sown['cycle_id']])->execute();
$harvestQuote = $cultivation->preview(9001, 'harvest', ['bed_id' => $bedId]);
checkCraft($harvestQuote['terms']['output']['quantity'] === 2, 'missed watering grants exactly half of four');
cropCommand($cultivation, 'harvest', ['bed_id' => $bedId]);
checkCraft($cultivation->state(9001, $bedId)['cycle'] === null, 'harvest closes cycle');
cropCommand($cultivation, 'dig', ['bed_id' => $bedId]);
$sown = cropCommand($cultivation, 'sow', ['bed_id' => $bedId, 'crop_revision_id' => $published['crop_revision_id']]);
$db->createCommand()->update('world_crop_cycle', ['ready_at' => time() - 86400], ['id' => $sown['cycle_id']])->execute();
checkCraft($cultivation->state(9001, $bedId)['cycle']['state'] === 'expired', 'expired cycle is visible');
try { $cultivation->preview(9001, 'harvest', ['bed_id' => $bedId]); throw new RuntimeException('Expired harvest accepted'); } catch (\common\services\game\GameError $e) {}
cropCommand($cultivation, 'cancel', ['bed_id' => $bedId]);
cropCommand($cultivation, 'withdraw', ['crop_id' => $published['crop_id']]);
echo "PASS cultivation lifecycle and retries\n";

// Adapt only the disposable legacy wallet fixture to the deployed exact-money schema.
foreach (['persone', 'history_balance'] as $table) {
    if ($db->driverName !== 'sqlite') {
        foreach (['credit', 'balance'] as $column) $db->createCommand()->alterColumn($table, $column, 'decimal(19,4)')->execute();
        if ($table === 'history_balance') foreach (['credit_up', 'balance_up'] as $column) $db->createCommand()->alterColumn($table, $column, 'decimal(19,4)')->execute();
        continue;
    }
    $sql = $db->createCommand("SELECT sql FROM sqlite_master WHERE type='table' AND name=:table", [':table' => $table])->queryScalar();
    $sql = str_replace('decimal(18,5)', 'decimal(19,4)', $sql);
    $indexes = $db->createCommand("SELECT sql FROM sqlite_master WHERE type='index' AND tbl_name=:table AND sql IS NOT NULL", [':table' => $table])->queryColumn();
    $db->createCommand('ALTER TABLE ' . $table . ' RENAME TO fixture_wallet_old')->execute();
    $db->createCommand($sql)->execute(); $db->createCommand('INSERT INTO ' . $table . ' SELECT * FROM fixture_wallet_old')->execute();
    $db->createCommand('DROP TABLE fixture_wallet_old')->execute(); foreach ($indexes as $index) $db->createCommand($index)->execute();
}
$db->schema->refresh();
$db->createCommand()->update('economy_registry', ['wallet_ready' => 1], ['id' => 1])->execute();
$db->createCommand()->insert('economy_wallet_rollout', ['id' => 1, 'run_id' => str_repeat('b', 32), 'phase' => 'active', 'started_at' => time(), 'updated_at' => time()])->execute();
putenv('WORLD_TEST_MODE=1');
$accounts = $db->transaction(function () use ($db, $garden, $operation) { return (new \common\modules\economy\service\EconomyHierarchy($db))->provision((int)$garden['id'], $operation); });
$market = new \common\modules\world\service\GardenHarvest($db, $flags);
$gardenId = (int)$garden['id'];
$marketState = $market->state(9001, $gardenId);
checkCraft($marketState['capacity'] === 5 && count($marketState['items']) === 1 && $marketState['items'][0]['quantity'] === 2, 'harvest goes to five-slot garden warehouse');
checkCraft($reader->node($bedId)['details']->harvested_quantity === 2 && $reader->node($gardenId)['details']->harvested_quantity === 2, 'harvest statistics aggregate bed and garden');
$lotId = $marketState['items'][0]['inventory_id'];
function marketCommand($service, $user, $garden, $action, $input) {
    $q = $service->preview($user, $garden, $action, $input); $key = bin2hex(random_bytes(16));
    $result = $service->execute($user, $garden, $action, $input, $key, $q['quote_id'], (array)$q['expected_revisions']);
    checkCraft($result === $service->execute($user, $garden, $action, $input, $key, $q['quote_id'], (array)$q['expected_revisions']), 'market ' . $action . ' retry returns same receipt');
    return $result;
}
function rejectsMarket(callable $fn, $message) { try { $fn(); } catch (\common\services\game\GameError $e) { checkCraft(true, $message); return; } throw new RuntimeException('Expected rejection: ' . $message); }
rejectsMarket(function () use ($market, $gardenId, $lotId) { $market->preview(9002, $gardenId, 'price', ['inventory_id' => $lotId, 'price' => '1']); }, 'foreign player cannot set price');
rejectsMarket(function () use ($market, $gardenId, $lotId) { $market->preview(9001, $gardenId, 'buy', ['inventory_id' => $lotId, 'quantity' => 1]); }, 'owner cannot buy own harvest');
$db->createCommand()->insert('craft_storage', ['identity_key' => 'backpack:user:9002', 'kind' => 'backpack', 'owner_user_id' => 9002, 'capacity' => 20])->execute(); $buyerPack = (int)$db->getLastInsertID();
$db->createCommand()->update('persone', ['credit' => '100.0000'], ['user_id' => 9002])->execute();
rejectsMarket(function () use ($market, $gardenId, $lotId) { $market->preview(9002, $gardenId, 'buy', ['inventory_id' => $lotId, 'quantity' => 1]); }, 'unpriced harvest cannot be bought');
marketCommand($market, 9001, $gardenId, 'price', ['inventory_id' => $lotId, 'price' => '2.5000']);
$stale = $market->preview(9002, $gardenId, 'buy', ['inventory_id' => $lotId, 'quantity' => 1]);
marketCommand($market, 9001, $gardenId, 'price', ['inventory_id' => $lotId, 'price' => '3.0000']);
rejectsMarket(function () use ($market, $gardenId, $lotId, $stale) { $market->execute(9002, $gardenId, 'buy', ['inventory_id' => $lotId, 'quantity' => 1], bin2hex(random_bytes(16)), $stale['quote_id'], (array)$stale['expected_revisions']); }, 'changed sale price invalidates buyer quote');
for ($pos = 1; $pos <= 100; $pos++) $db->createCommand()->insert('craft_inventory', ['user_id' => 9002, 'storage_id' => $buyerPack, 'slot' => $pos, 'item_id' => $draft['seed_item_id'], 'item_quantity' => 1])->execute();
rejectsMarket(function () use ($market, $gardenId, $lotId) { marketCommand($market, 9002, $gardenId, 'buy', ['inventory_id' => $lotId, 'quantity' => 1]); }, 'full backpack rejects purchase');
checkCraft((float)(new \yii\db\Query())->select('credit')->from('persone')->where(['user_id' => 9002])->scalar($db) === 100.0 && $market->state(9001, $gardenId)['items'][0]['quantity'] === 2, 'rejected purchase preserves money and harvest');
$db->createCommand()->delete('craft_inventory', ['storage_id' => $buyerPack, 'item_id' => $draft['seed_item_id']])->execute();
marketCommand($market, 9002, $gardenId, 'buy', ['inventory_id' => $lotId, 'quantity' => 1]);
checkCraft((float)(new \yii\db\Query())->select('credit')->from('persone')->where(['user_id' => 9002])->scalar($db) === 97.0, 'buyer pays exactly once');
checkCraft((float)(new \yii\db\Query())->select('amount')->from('economy_account')->where(['id' => $accounts['treasury']['id']])->scalar($db) === 3.0, 'purchase enters garden treasury');
checkCraft((new \yii\db\Query())->from('economy_treasury_receipt')->where(['account_id' => $accounts['treasury']['id']])->count('*', $db) == 1, 'treasury receipt is recorded once');
checkCraft((int)(new \yii\db\Query())->select('item_quantity')->from('craft_inventory')->where(['storage_id' => $buyerPack, 'item_id' => $draft['yield_item_id']])->scalar($db) === 1, 'buyer receives exactly one unit');
marketCommand($market, 9001, $gardenId, 'withdraw', ['inventory_id' => $lotId, 'quantity' => 1]);
checkCraft(!$market->state(9001, $gardenId)['items'], 'empty lot frees its slot');

// Distinct batches must not merge and must keep independent dates/prices.
$harvestStore = (new \yii\db\Query())->from('craft_storage')->where(['identity_key' => 'harvest:garden:' . $gardenId])->one($db);
$cropItem = (new \yii\db\Query())->from('craft_item')->where(['id' => $draft['yield_item_id']])->one($db);
for ($batch = 0; $batch < 5; $batch++) $db->transaction(function () use ($db, $market, $harvestStore, $cropItem, $operation) {
    $s = new \common\modules\craft\service\CraftStorage($db); $s->operationId = $operation; $inv = new \common\modules\craft\service\CanonicalInventory($s);
    $plan = $inv->planCraft([], $harvestStore, [], [], $cropItem, 10);
    checkCraft($plan['output_fits'], 'batch fits a distinct slot');
    $inv->applyCraft(9001, $plan, $harvestStore, [], $cropItem); $market->recordHarvest($harvestStore, $plan, $operation);
});
$inv = new \common\modules\craft\service\CanonicalInventory(new \common\modules\craft\service\CraftStorage($db));
checkCraft(!$inv->planCraft([], $harvestStore, [], [], $cropItem, 1)['output_fits'], 'sixth batch cannot bypass five-slot limit');
$lotId = $market->state(9001, $gardenId)['items'][0]['inventory_id'];
$at = time() - \common\modules\world\service\GardenHarvest::FRESH_SECONDS;
checkCraft(\common\modules\world\service\GardenHarvest::spoiled(['harvested_at' => $at, 'spoiled_at' => null], 10, $at + 1209599) === 0, 'fresh until exact fourteen-day boundary');
$db->createCommand()->update('world_harvest_lot', ['harvested_at' => $at], ['inventory_id' => $lotId])->execute();
checkCraft($market->state(9001, $gardenId)['items'][0]['quantity'] === 7, 'after fourteen days thirty percent is lost');
marketCommand($market, 9001, $gardenId, 'price', ['inventory_id' => $lotId, 'price' => '1.0000']);
checkCraft($market->state(9001, $gardenId)['items'][0]['quantity'] === 7, 'spoilage is applied only once');
marketCommand($market, 9002, $gardenId, 'buy', ['inventory_id' => $lotId, 'quantity' => 7]);
checkCraft(count($market->state(9001, $gardenId)['items']) === 4, 'spoiled lot sells only remaining quantity');
rejectsMarket(function () use ($market, $gardenId, $lotId) { $market->preview(9002, $gardenId, 'buy', ['inventory_id' => $lotId, 'quantity' => 1]); }, 'sold-out lot cannot be sold twice');
echo "PASS forum 80 harvest market and exact storage expiry\n";

// Reproduce legacy ten-column coordinates, then replay the additive migration.
$oldCells = (new \yii\db\Query())->from('world_map_cell')->where(['parent_id' => $gardenId])->orderBy('id')->all($db);
$db->createCommand()->delete('world_map_cell', ['parent_id' => $gardenId])->execute();
foreach ($beds as $i => $bed) {
    $db->createCommand()->update('world_node', ['position_x' => $i, 'position_y' => 0], ['id' => $bed['id']])->execute();
    foreach ($oldCells as $cell) if ((int)$cell['x'] === (int)$bed['position_x'] && (int)$cell['y'] === (int)$bed['position_y']) {
        $cell['x'] = $i; $cell['y'] = 0; $db->createCommand()->insert('world_map_cell', $cell)->execute();
    }
}
foreach ([1, 2] as $pass) {
    ob_start(); (new \m261004_100000_garden_harvest(['db' => $db]))->up(); ob_end_clean();
    $cells = (new \yii\db\Query())->from('world_map_cell')->where(['parent_id' => $gardenId])->orderBy('id')->all($db);
    checkCraft(array_column($cells, 'id') === array_column($oldCells, 'id'), 'garden migration preserves cell identities on pass ' . $pass);
    foreach ($beds as $i => $bed) { $current = $tree->get((int)$bed['id']); checkCraft((int)$current['position_x'] === $i % 5 - 2 && (int)$current['position_y'] === ($i < 5 ? 0 : -1), 'bed stays in its ordinal 5 by 2 position'); }
    checkCraft(count($market->state(9001, $gardenId)['items']) === 4, 'migration retry retains existing harvest batches');
}
$memberId = (new \yii\db\Query())->select('id')->from('world_membership')->where(['user_id' => 9001])->scalar($db);
$transfer = (new \yii\db\Query())->select('id')->from('economy_transfer')->where(['kind' => 'crop_purchase'])->scalar($db);
$db->createCommand()->insert('world_garden_offer', ['settlement_id' => $ids['north-city'], 'active_settlement_id' => null, 'name' => 'Fixture garden', 'price' => '1.0000', 'base_price' => '1.0000', 'operation_id' => $operation, 'created_at' => time()])->execute(); $offerId = (int)$db->getLastInsertID();
$db->createCommand()->insert('world_garden_purchase', ['node_id' => $gardenId, 'membership_id' => $memberId, 'offer_id' => $offerId, 'transfer_id' => $transfer, 'operation_id' => $operation, 'terms_json' => '{}', 'created_at' => time()])->execute();
$financialParent = (new \common\modules\economy\service\EconomyHierarchy($db))->current($gardenId)['parent_node_id'];
foreach ([1, 2] as $pass) $db->transaction(function () use ($tree, $gardenId, $site) { $tree->attachPurchasedGarden($gardenId, (int)$site['id']); });
checkCraft((int)$tree->get($gardenId)['parent_id'] === (int)$site['id'], 'purchased garden moves below its own campsite');
checkCraft((new \yii\db\Query())->from('world_node_closure')->where(['ancestor_id' => $site['id'], 'descendant_id' => $bedId, 'distance' => 2])->exists($db), 'garden move preserves descendant closure');
checkCraft((new \common\modules\economy\service\EconomyHierarchy($db))->current($gardenId)['parent_node_id'] === $financialParent, 'physical move preserves published financial parent');
echo "PASS forum 80 repeatable garden migration and estate repair\n";
