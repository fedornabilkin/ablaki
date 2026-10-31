<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\{CanonicalInventory, CraftStorage, ProductionReservations, StorageAccessPolicy};
use yii\db\{Connection, Query};

/** Move existing raw stock out of source mini-stores; never discard or create goods. */
final class NpcStockLogistics
{
    public function __construct(private readonly Connection $db) {}

    /** Maintenance/source commands hold catalog, NPC owner and world registry locks. */
    public function consolidate(array $policy, array $nodes, string $operation, ?int $sourceNode = null): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('NPC logistics requires a locked transaction.');
        $owner = (int)$policy['bot_user_id'];
        $warehouses = $sources = [];
        foreach ($nodes as $node) {
            if ((int)$node['owner_user_id'] !== $owner || !NodeAvailability::activePath($this->db, (int)$node['id'])) continue;
            if (NpcInfrastructure::role($node) === 'warehouse') $warehouses[] = (int)$node['id'];
            if (NpcInfrastructure::role($node) === 'source' && ($sourceNode === null || (int)$node['id'] === $sourceNode)) $sources[] = (int)$node['id'];
        }
        if (!$warehouses || !$sources) return;
        $store = new CraftStorage($this->db); $store->operationId = $operation;
        $inventory = new CanonicalInventory($store); $access = new StorageAccessPolicy($this->db);
        $targets = (new Query())->from('craft_storage')->where(['owner_user_id' => $owner, 'node_id' => $warehouses, 'kind' => 'stockpile', 'status' => 'active'])->orderBy('id')->all($this->db);
        $rows = (new Query())->select(['i.*', 'd.code', 'd.kind', 'd.stack_size', 'd.storage_kind'])->from(['i' => 'craft_inventory'])
            ->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')->innerJoin(['d' => 'craft_item'], 'd.id=i.item_id')
            ->where(['i.user_id' => $owner, 's.owner_user_id' => $owner, 's.kind' => 'stockpile', 's.status' => 'active', 'n.parent_id' => $sources, 'n.status' => 'active', 'd.kind' => 'material', 'd.storage_kind' => 'none'])
            ->andWhere(['>', 'i.item_quantity', 0])->orderBy('i.id')->all($this->db);
        $reservations = new ProductionReservations($this->db);
        foreach ($rows as $row) {
            $left = (int)$row['item_quantity'] - $reservations->quantity((int)$row['id']);
            if ($left <= 0) continue;
            $item = $row; $item['id'] = $row['item_id'];
            foreach ($targets as $target) {
                $target = $access->storage($owner, (int)$target['id'], true);
                $plan = $inventory->planCraft([], $target, [], [], $item, $left);
                foreach ($plan['grant'] as $grant) {
                    $inventory->transfer($owner, (int)$row['id'], (int)$target['id'], (int)$grant['position'], (int)$grant['quantity']);
                    $left -= (int)$grant['quantity'];
                }
                if ($left === 0) break;
            }
        }
    }
}
