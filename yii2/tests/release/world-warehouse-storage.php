<?php
/** Warehouse transfers on disposable fixtures, including an over-capacity backpack. */
require __DIR__ . '/world-terminal-nodes.php';
set_exception_handler(static function (Throwable $error): void { fwrite(STDERR, (string)$error . PHP_EOL . json_encode($error->details ?? [], JSON_UNESCAPED_UNICODE) . PHP_EOL); exit(1); });

use common\modules\world\models\{BuildFacilities, Node, NodeFactory, NodeTemplate};
use common\modules\world\modules\craft\models\domain\{CraftStorage, CanonicalInventory, StorageAccessPolicy, WorldStorage};
use yii\db\Query;

$tx = $db->beginTransaction();
try {
    $template = NodeTemplate::find()->where(['like', 'code', 'storage-building%', false])->orderBy('id')->one();
    $warehouse = NodeFactory::create($template, Node::requireOne($siteId), $freshUser, 'Warehouse fixture', 'warehouse-transfer-fixture', null, 'constructing');
    $db->createCommand()->update('world_node', ['status' => 'active', 'condition' => $warehouse->max_condition], ['id' => $warehouse->id])->execute();
    $warehouse->refresh();
    $operation = bin2hex(random_bytes(16));
    $db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $freshUser, 'type' => 'fixture.warehouse', 'created_at' => time()])->execute();
    BuildFacilities::provision($warehouse, $operation);
    $api = new WorldStorage($db, $flags);
    $headers = $api->list($freshUser, (int)$warehouse->id)['items'];
    $stock = array_values(array_filter($headers, static fn($row) => $row['kind'] === 'stockpile' && $row['node_id'] === (int)$warehouse->id))[0];
    economyCheck($stock['capacity'] === 50, 'a completed warehouse exposes its fifty stock slots through the shared storage API');
    $inventory = new CanonicalInventory(new CraftStorage($db));
    $backpack = $inventory->backpack($freshUser);
    $capacity = (new StorageAccessPolicy($db))->storage($freshUser, (int)$backpack['id'])['capacity'];
    $stone = (new Query())->from('craft_item')->where(['code' => 'classic-stone'])->one($db);
    $plank = (new Query())->from('craft_item')->where(['code' => 'classic-plank'])->one($db);
    $insert = static function (int $storage, int $position, array $item, int $quantity) use ($db, $freshUser): int {
        $db->createCommand()->insert('craft_inventory', ['user_id' => $freshUser, 'storage_id' => $storage, 'container_id' => null, 'item_id' => $item['id'], 'item_quantity' => $quantity, 'slot' => $position])->execute();
        return (int)$db->getLastInsertID();
    };
    // Fill only empty slots, preserving the existing starter inventory and equipment.
    $occupied = array_column($inventory->rows((int)$backpack['id']), 'slot');
    for ($position = 1; $position <= $capacity; $position++) {
        if (!in_array($position, $occupied)) $insert((int)$backpack['id'], $position, $plank, (int)$plank['stack_size']);
    }
    $overflow = max($capacity, ...$occupied) + 1;
    $source = $insert((int)$backpack['id'], $overflow, $stone, 7);
    $total = static fn() => (int)(new Query())->from('craft_inventory')->where(['user_id' => $freshUser, 'item_id' => $stone['id']])->sum('item_quantity', $db);
    $before = $total();
    $payload = ['inventory_id' => $source, 'source_storage_id' => (int)$backpack['id'], 'destination_storage_id' => $stock['id'], 'position' => 1, 'quantity' => 7, 'instance_id' => null];
    $quote = $api->preview($freshUser, $payload); $key = bin2hex(random_bytes(16));
    $result = $api->transfer($freshUser, $key, $payload, $quote['quote_id'], (array)$quote['expected_revisions']);
    economyCheck($api->transfer($freshUser, $key, $payload, $quote['quote_id'], (array)$quote['expected_revisions']) === $result && $total() === $before,
        'an overflow stack can leave a full backpack, and replay preserves its quantity exactly');
    $stored = (new Query())->from('craft_inventory')->where(['storage_id' => $stock['id'], 'slot' => 1, 'item_id' => $stone['id']])->one($db);
    economyCheck((int)$stored['item_quantity'] === 7 && !(new Query())->from('craft_inventory')->where(['id' => $source])->andWhere(['>', 'item_quantity', 0])->exists($db), 'the whole stack arrives at the warehouse and leaves its old slot');
    $return = ['inventory_id' => (int)$stored['id'], 'source_storage_id' => $stock['id'], 'destination_storage_id' => (int)$backpack['id'], 'position' => $capacity + 1, 'quantity' => 7, 'instance_id' => null];
    rejectsCraft(static fn() => $api->preview($freshUser, $return), 'a full backpack cannot accept a new stack beyond its capacity');
    $occupiedSlot = $insert($stock['id'], 2, $plank, 1);
    $blocked = array_replace($return, ['destination_storage_id' => $stock['id'], 'position' => 2]);
    rejectsCraft(static fn() => $api->preview($freshUser, $blocked), 'a warehouse slot occupied by another item rejects the transfer');
    rejectsCraft(static fn() => $api->preview(9002, $return), 'another player cannot withdraw private warehouse inventory');
    economyCheck($total() === $before && (int)(new Query())->select('item_quantity')->from('craft_inventory')->where(['id' => $stored['id']])->scalar($db) === 7, 'all rejected transfers leave the stack intact');
    // Free an ordinary slot using the same command, then return the stored stone there.
    $ordinary = $inventory->rows((int)$backpack['id'])[0];
    $move = ['inventory_id' => (int)$ordinary['id'], 'source_storage_id' => (int)$backpack['id'], 'destination_storage_id' => $stock['id'], 'position' => 3, 'quantity' => (int)$ordinary['item_quantity'], 'instance_id' => null];
    $quote = $api->preview($freshUser, $move);
    $api->transfer($freshUser, bin2hex(random_bytes(16)), $move, $quote['quote_id'], (array)$quote['expected_revisions']);
    $return['position'] = (int)$ordinary['slot']; $quote = $api->preview($freshUser, $return);
    $api->transfer($freshUser, bin2hex(random_bytes(16)), $return, $quote['quote_id'], (array)$quote['expected_revisions']);
    economyCheck($total() === $before && (new Query())->from('craft_inventory')->where(['storage_id' => $backpack['id'], 'slot' => $return['position'], 'item_id' => $stone['id'], 'item_quantity' => 7])->exists($db), 'withdrawal to the newly freed backpack slot preserves all seven items');
} finally { $tx->rollBack(); }
echo "World warehouse storage checks passed.\n";
