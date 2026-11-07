<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\{CanonicalInventory, CraftStorage, ProductionReservations, StorageAccessPolicy};
use yii\db\{Connection, Query};

/** Periodic delivery leaves at least half the available stock for cheaper direct sales. */
final class MarketRestocking
{
    public function __construct(private readonly Connection $db) {}

    public function replenish(array $npc, array $nodes, string $operation): void
    {
        $policy = EconomyRuntime::current($this->db)['raw_profile']['market'] ?? null;
        if (!$policy || !$this->db->schema->getTableSchema('world_npc_market')) return;
        if (!$this->db->getTransaction()) throw new \LogicException('Market delivery requires a locked transaction.');
        $site = (new Query())->from('world_node')->where(['id' => $npc['site_node_id']])->one($this->db);
        $city = $site ? (new Query())->from('world_node')->where(['id' => $site['parent_id'], 'node_type' => 'SETTLEMENT', 'status' => 'active', 'owner_user_id' => $npc['bot_user_id']])->one($this->db) : null;
        if (!$city || !NodeAvailability::activePath($this->db, (int)$city['id'])) return;
        $market = (new Query())->from('world_npc_market')->where(['city_id' => $city['id']])->one($this->db);
        $owner = (int)$npc['bot_user_id'];
        if (!$market) {
            $this->db->createCommand()->insert('craft_storage', ['identity_key' => 'npc-market:city:' . $city['id'], 'kind' => 'stockpile',
                'node_id' => $city['id'], 'owner_user_id' => $owner, 'capacity' => $policy['capacity']])->execute();
            $storage = (int)$this->db->getLastInsertID();
            $this->db->createCommand()->insert('world_npc_market', ['city_id' => $city['id'], 'bot_user_id' => $owner, 'owner_user_id' => $owner, 'storage_id' => $storage])->execute();
            $market = ['id' => (int)$this->db->getLastInsertID(), 'storage_id' => $storage, 'last_restock_at' => 0];
        }
        if ((int)$market['last_restock_at'] + $policy['refill_seconds'] > time()) return;
        $warehouses = [];
        foreach ($nodes as $node) if (NpcInfrastructure::role($node) === 'warehouse' && NodeAvailability::activePath($this->db, (int)$node['id'])) $warehouses[] = (int)$node['id'];
        $store = new CraftStorage($this->db); $store->operationId = $operation;
        $inventory = new CanonicalInventory($store); $access = new StorageAccessPolicy($this->db); $reservations = new ProductionReservations($this->db);
        $rows = (new Query())->select(['i.*', 'd.code'])->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')
            ->innerJoin(['d' => 'craft_item'], 'd.id=i.item_id')->where(['s.node_id' => $warehouses, 's.kind' => 'stockpile', 's.status' => 'active',
                's.owner_user_id' => $owner, 'i.user_id' => $owner, 'd.code' => EconomyRuntime::rawCodes($this->db), 'd.active' => 1, 'd.kind' => 'material', 'd.storage_kind' => 'none'])
            ->andWhere(['>', 'i.item_quantity', 1])->orderBy('i.id')->all($this->db);
        foreach ($rows as $row) {
            $stock = (int)(new Query())->from('craft_inventory')->where(['storage_id' => $market['storage_id'], 'item_id' => $row['item_id']])->sum('item_quantity', $this->db);
            $amount = min($policy['stock_per_item'] - $stock, intdiv((int)$row['item_quantity'] - $reservations->quantity((int)$row['id']), 2));
            if ($amount < 1) continue;
            $target = $access->storage($owner, (int)$market['storage_id'], true);
            $item = (new Query())->from('craft_item')->where(['id' => $row['item_id']])->one($this->db);
            $plan = $inventory->planCraft([], $target, [], [], $item, $amount);
            foreach ($plan['grant'] as $grant) $inventory->transfer($owner, (int)$row['id'], (int)$target['id'], (int)$grant['position'], (int)$grant['quantity']);
        }
        $this->db->createCommand()->update('world_npc_market', ['last_restock_at' => time()], ['id' => $market['id']])->execute();
    }
}
