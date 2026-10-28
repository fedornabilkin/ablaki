<?php
namespace common\modules\world\modules\craft\models\domain;

use common\modules\world\models\domain\{NodeAvailability, NpcInfrastructure};
use yii\db\Query;

/** Lends placed stations in an explicitly public NPC workshop, never its inventory. */
final class NpcStations
{
    public static function candidates(CraftStorage $store, int $user, int $item, ?int $instance, int $limit): array
    {
        $node = $store->workspaceNodeId; $db = $store->db;
        if (!$node || !(new NpcInfrastructure($db))->publicWorkshop($node) || !NodeAvailability::activePath($db, $node)) return [];
        $place = (new Query())->from('world_node')->where(['id' => $node])->one($db);
        if (!(new Query())->from('world_membership')->where(['world_id' => $place['root_id'], 'user_id' => $user])->exists($db)) return [];
        $definition = (new Query())->from('craft_item')->where(['id' => $item, 'active' => 1])->one($db);
        if (!$definition) return [];
        $query = (new Query())->select('e.*')->from(['e' => 'craft_equipment_instance'])
            ->innerJoin(['i' => 'craft_inventory'], 'i.id=e.inventory_id')->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')
            ->where(['e.item_id' => $item, 'e.status' => 'active', 'i.user_id' => $place['owner_user_id'], 'i.item_quantity' => 1,
                's.owner_user_id' => $place['owner_user_id'], 's.node_id' => $node, 's.kind' => 'placement', 's.status' => 'active'])->orderBy('e.id');
        if ($instance !== null) $query->andWhere(['e.id' => $instance]);
        if ((new ProductionReservations($db))->installed()) $query->andWhere(['not in', 'e.id', (new Query())->select('instance_id')->from('equipment_reservation_guard')]);
        $rows = []; $exposure = new EquipmentExposure($db);
        foreach ($query->each(100, $db) as $unit) {
            if (in_array((int)$unit['id'], $store->protectedInstances, true)) continue;
            $inventory = (new Query())->from('craft_inventory')->where(['id' => $unit['inventory_id'], 'item_id' => $item])->one($db);
            if (!$inventory) continue;
            $storage = (new Query())->from('craft_storage')->where(['id' => $inventory['storage_id']])->one($db);
            $slot = (new Query())->from('world_slot')->where(['storage_id' => $storage['id'], 'position' => $inventory['slot'], 'status' => 'active'])->one($db);
            if (!$slot) continue;
            try { (new EquipmentPlacementPolicy($db))->assertAllowed($definition, $storage, $slot); }
            catch (\yii\web\HttpException $error) { continue; }
            $current = $exposure->projected($unit); if ((int)$current['durability'] < 1) continue;
            $rows[] = ['item_id' => $item, 'instance_id' => (int)$unit['id'], 'inventory_id' => (int)$inventory['id'], 'storage_id' => (int)$storage['id'],
                'in_backpack' => false, 'is_station' => true, 'durability' => (int)$current['durability'], 'max_durability' => (int)$unit['max_durability'], 'exposure_class' => $current['exposure_class']];
            if (count($rows) >= $limit) break;
        }
        return $rows;
    }
}
