<?php
/** Disposable fixtures only: simultaneous batches, treasury switching and presence leases. */
require __DIR__ . '/world-balance.php';
set_exception_handler(static function (Throwable $e): void { fwrite(STDERR, (string)$e . PHP_EOL); exit(1); });

use common\modules\world\models\{Command, Mining, Node, NodeFactory, NodeTemplate};
use common\modules\world\models\domain\{EconomyQuota, WorldPresence, WorldAccessPolicy, WorldQuery};
use common\modules\world\modules\economy\models\domain\{EconomyHierarchy, TreasuryAvailability, TreasuryLedger};
use common\modules\world\modules\economy\value\Money;
use yii\db\Query;

require_once dirname(__DIR__, 2) . '/console/world-migrations/m261031_120000_world_presence.php';
$migration = new m261031_120000_world_presence(['db' => $db]);
$migration->up(); $migration->up();
$tx = $db->beginTransaction();
try {
    $presence = new WorldPresence($db);
    $visit = static function ($user, $node, $revision, $heartbeat = false, $key = null) use ($presence) {
        return Command::run($user, $key ?? bin2hex(random_bytes(16)), 'world.presence.visit', ['node_id' => $node, 'revision' => $revision, 'heartbeat' => $heartbeat],
            static fn() => $presence->visit($user, $node, $revision, $heartbeat));
    };
    $key = bin2hex(random_bytes(16));
    $first = $visit(9001, $siteId, 0, false, $key);
    economyCheck($visit(9001, $siteId, 0, false, $key) === $first, 'presence movement replay does not increment revision twice');
    $visit(9001, $siteId, 1, true);
    $visit(9001, $siteId, 1, true);
    economyCheck($presence->current(9001)['revision'] === 1, 'independent heartbeats within one second keep the movement revision');
    $visit($freshUser, $siteId, 0);
    economyCheck($presence->counts([$siteId])[$siteId] === 2, 'two characters share one node without altering population');
    $visit($freshUser, $forestId, 1);
    economyReject(static fn() => $visit($freshUser, $siteId, 1, true), 'PRESENCE_CHANGED');
    economyCheck($presence->counts([$siteId, $forestId]) === [$siteId => 1, $forestId => 1], 'moving removes presence from the previous place');
    $before = (new Query())->from('world_presence')->orderBy('user_id')->all($db);
    $presence->state($freshUser, $siteId);
    economyCheck($before === (new Query())->from('world_presence')->orderBy('user_id')->all($db), 'GET presence never changes location or lease');
    $db->createCommand()->update('world_presence', ['seen_at' => time() - WorldPresence::TTL], ['user_id' => 9001])->execute();
    economyCheck(!isset($presence->counts([$siteId])[$siteId]) && $presence->current(9001)['node_id'] === $siteId, 'expired visitor is not counted but character location persists');
    $db->createCommand()->update('world_node', ['visibility' => 'private'], ['id' => $forestId])->execute();
    economyReject(static fn() => $db->transaction(static fn() => $presence->visit(9002, $forestId, 0, false)), 'NODE_NOT_FOUND');
} finally { $tx->rollBack(); }

$tx = $db->beginTransaction();
try {
    $template = NodeTemplate::requireOne(['code' => 'house']);
    $house = NodeFactory::create($template, Node::requireOne($siteId), $freshUser, 'Treasury fixture', 'treasury-fixture', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'operational_status' => 'active'], ['id' => $house->id])->execute(); $house->refresh();
    $availability = new TreasuryAvailability($db); $id = (int)$house->id;
    economyCheck(!$availability->enabled($id), 'leaf treasury is off by default');
    $room = NodeFactory::create(NodeTemplate::requireOne(['code' => 'bedroom']), $house, $freshUser, 'Room', 'treasury-room', null, 'constructing');
    economyCheck($availability->enabled($id), 'adding a child enables a permitted treasury');
    $settings = $template->defaults(); $settings['treasury_enabled'] = false;
    $template->defaults_json = json_encode($settings, JSON_THROW_ON_ERROR);
    economyCheck($template->save(), 'template accepts boolean treasury setting');
    economyCheck(!$availability->enabled($id) && !(new WorldQuery($db, new WorldAccessPolicy($freshUser)))->node($id)['treasury_enabled'], 'template disabling affects existing nodes and their API');
    Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.accounts', ['node_id' => $id], static fn($operation) => (new EconomyHierarchy($db))->provision($id, $operation));
    $accounts = (new EconomyHierarchy($db))->accounts($id); $before = $npcTotal();
    $pay = static function () use ($db, $id, $freshUser) {
        return Command::run($freshUser, bin2hex(random_bytes(16)), 'fixture.direct-receipt', ['node_id' => $id], static function ($operation) use ($db, $id, $freshUser) {
            (new TreasuryLedger($db))->receivePersonalPayment($freshUser, $id, Money::parse('1'), $operation, 'Fixture direct income'); return [];
        });
    };
    $pay(); $after = (new EconomyHierarchy($db))->accounts($id);
    economyCheck($after['treasury']['amount'] == 0 && Money::parse((string)$after['budget']['amount'])->compare(Money::parse((string)$accounts['budget']['amount'])->add(Money::parse('1'))) === 0 && $npcTotal() === $before, 'disabled treasury immediately settles new income, preserving money and tax reservations');
    $settings['treasury_enabled'] = true; $template->defaults_json = json_encode($settings); $template->save(); $pay();
    economyCheck((new EconomyHierarchy($db))->accounts($id)['treasury']['amount'] == 1, 'enabled treasury accumulates receipts again');
    $db->createCommand()->update('world_node', ['status' => 'archived'], ['id' => $room->id])->execute();
    economyCheck(!$availability->enabled($id) && (new EconomyHierarchy($db))->accounts($id)['treasury']['amount'] == 1, 'last child archival disables treasury without deleting its accumulated money');
    $settings['treasury_enabled'] = 'false'; $template->defaults_json = json_encode($settings);
    economyCheck(!$template->validate(['defaults_json']), 'template rejects non-boolean treasury switches');
} finally { $tx->rollBack(); }

$tx = $db->beginTransaction();
try {
    $mine = Node::requireOne(['code' => 'npc-bot-mine']); $users = [$freshUser, 9002];
    // Independent finite concurrency fixture after the earlier fund-exhaustion checks.
    $db->createCommand()->update('persone', ['credit' => '1000.0000'], ['user_id' => $npcUser])->execute();
    $quota = []; $wallets = []; $works = [];
    foreach ($users as $user) {
        if (!$app->authManager->getAssignment('world-tester', $user)) $app->authManager->assign($testRole, $user);
        $db->createCommand()->update('world_player_health', ['hp' => 100], ['user_id' => $user])->execute();
        $db->createCommand()->delete('world_mining_worker', ['user_id' => $user])->execute();
        $db->createCommand()->update('world_source_work', ['active_key' => null], ['user_id' => $user])->execute();
        $db->createCommand()->delete('world_economy_usage', ['user_id' => $user])->execute();
        $wallets[$user] = Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $user])->scalar($db));
        $works[$user] = economySourceCommand($user, (int)$mine->id, 'start', ['action_code' => 'mine-stone']);
        $quota[$user] = EconomyQuota::state($db, $user, 'extraction_daily')['used'];
    }
    // Both batches were accepted before other stock filled the local and central stores.
    $warehouseNode = Node::requireOne(['code' => 'npc-bot-warehouse']);
    $warehouseStore = (new Query())->from('craft_storage')->where(['node_id' => $warehouseNode->id, 'kind' => 'stockpile'])->one($db);
    $miniStore = \common\modules\world\models\domain\ExtractionStorage::local($mine)[0];
    $oreId = (int)(new Query())->select('id')->from('craft_item')->where(['code' => 'classic-ore'])->scalar($db);
    foreach ([$warehouseStore, $miniStore] as $storage) {
        for ($slot = 1; $slot <= (int)$storage['capacity']; $slot++) {
            $row = (new Query())->from('craft_inventory')->where(['storage_id' => $storage['id'], 'slot' => $slot])->one($db);
            if ($row) $db->createCommand()->update('craft_inventory', ['item_quantity' => 100], ['id' => $row['id']])->execute();
            else $db->createCommand()->insert('craft_inventory', ['user_id' => $npcUser, 'item_id' => $oreId, 'item_quantity' => 100, 'storage_id' => $storage['id'], 'slot' => $slot, 'revision' => 1])->execute();
        }
    }
    $goodsBefore = (int)(new Query())->from('craft_inventory')->where(['user_id' => $npcUser])->sum('item_quantity', $db);
    $expansionsBefore = (int)(new Query())->from('economy_transfer')->where(['kind' => 'warehouse_expansion'])->count('*', $db);
    $before = $npcTotal();
    $db->createCommand()->update('world_mining_worker', ['accounted_at' => time() - 60, 'last_seen_at' => time()], ['user_id' => $users])->execute();
    $key = bin2hex(random_bytes(16));
    $beat = static fn($operation) => Mining::heartbeat((int)$mine->id, $freshUser, $operation);
    $result = Command::run($freshUser, $key, 'world.mining.heartbeat', ['mining_node_id' => (int)$mine->id], $beat);
    economyCheck(Command::run($freshUser, $key, 'world.mining.heartbeat', ['mining_node_id' => (int)$mine->id], $beat) === $result, 'joint completion retry is idempotent');
    $operations = [];
    foreach ($users as $user) {
        $work = (new Query())->from('world_source_work')->where(['id' => $works[$user]['work_id']])->one($db); $operations[] = $work['operation_id'];
        $wallet = Money::parse((string)(new Query())->select('credit')->from('persone')->where(['user_id' => $user])->scalar($db));
        economyCheck($work['status'] === 'completed' && EconomyQuota::state($db, $user, 'extraction_daily')['used'] === $quota[$user] + 1
            && $wallet->compare($wallets[$user]->add(Money::parse($works[$user]['employment']['accepted_reward_cr']))) === 0, 'each simultaneous worker receives precisely one wage, output and quota receipt');
    }
    economyCheck(count(array_unique($operations)) === 2 && $npcTotal() === $before, 'joint checkpoint uses distinct delivery journals and conserves money');
    economyCheck((int)(new Query())->select('capacity')->from('craft_storage')->where(['id' => $warehouseStore['id']])->scalar($db) === (int)$warehouseStore['capacity'] + 1
        && (int)(new Query())->from('economy_transfer')->where(['kind' => 'warehouse_expansion'])->count('*', $db) === $expansionsBefore + 1,
        'NPC buys precisely one central warehouse slot through the ordinary paid expansion');
    $delivered = 0;
    foreach ($users as $user) {
        $definition = json_decode((new Query())->select('definition_json')->from('world_source_work')->where(['id' => $works[$user]['work_id']])->scalar($db), true);
        $delivered += array_sum($definition['output']);
    }
    economyCheck((int)(new Query())->from('craft_inventory')->where(['user_id' => $npcUser])->sum('item_quantity', $db) === $goodsBefore + $delivered,
        'NPC logistics preserves every stored item and adds only accepted source output');
    // With no accepted work left, a full source must become available via the worker too.
    foreach ([$warehouseStore, $miniStore] as $storage) {
        $capacity = (int)(new Query())->select('capacity')->from('craft_storage')->where(['id' => $storage['id']])->scalar($db);
        for ($slot = 1; $slot <= $capacity; $slot++) {
            $row = (new Query())->from('craft_inventory')->where(['storage_id' => $storage['id'], 'slot' => $slot])->one($db);
            if ($row) $db->createCommand()->update('craft_inventory', ['item_quantity' => 100], ['id' => $row['id']])->execute();
            else $db->createCommand()->insert('craft_inventory', ['user_id' => $npcUser, 'item_id' => $oreId, 'item_quantity' => 100, 'storage_id' => $storage['id'], 'slot' => $slot, 'revision' => 1])->execute();
        }
    }
    $stockBefore = (int)(new Query())->from('craft_inventory')->where(['user_id' => $npcUser])->sum('item_quantity', $db);
    $moneyBefore = $npcTotal();
    $db->createCommand()->update('world_npc_policy', ['last_refill_at' => 0])->execute();
    $policy = (new \common\modules\world\models\domain\NpcInfrastructure($db))->policy();
    (new \common\modules\world\models\domain\NpcMaintenance($db))->perform(['world_id' => $policy['world_id']], ['owner_user_id' => $npcUser]);
    $mine->refresh();
    $definition = \common\modules\world\models\domain\WorldSources::options($mine)['mine-stone'];
    economyCheck(\common\modules\world\models\domain\ExtractionStorage::nearest($mine, $definition, true) !== null,
        'periodic maintenance reopens a full source before any new batch is accepted');
    economyCheck((int)(new Query())->select('capacity')->from('craft_storage')->where(['id' => $warehouseStore['id']])->scalar($db) > (int)$warehouseStore['capacity'] + 1,
        'periodic reopening buys capacity even without a pending delivery');
    economyCheck($moneyBefore === $npcTotal() && $stockBefore === (int)(new Query())->from('craft_inventory')->where(['user_id' => $npcUser])->sum('item_quantity', $db),
        'periodic storage expansion neither issues money nor discards stock');
} finally { $tx->rollBack(); }
echo "World cohort, treasury and presence checks passed.\n";
