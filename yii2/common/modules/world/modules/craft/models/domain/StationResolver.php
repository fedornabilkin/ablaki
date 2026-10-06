<?php
namespace common\modules\world\modules\craft\models\domain;

use yii\db\Query;
use yii\web\HttpException;

class StationResolver
{
    private $s;
    public function __construct(CraftStorage $store) { $this->s = $store; }
    public function select(int $user, int $item, bool $station = false, ?int $instance = null): ?array
    {
        $rows = $this->candidates($user, $item, $station, $instance, 1);
        return $rows[0] ?? null;
    }
    public function candidates(int $user, int $item, bool $station = false, ?int $instance = null, int $limit = 100): array
    {
        $joined = $station && (new Query())->from('world_membership')->where(['user_id' => $user])->exists($this->s->db);
        // The legacy craft command has no location argument. Bind it to the active
        // starter site; explicit workspaces may use another owned location.
        $home = $joined && $this->s->workspaceNodeId === null ? (new Query())->select('m.starter_site_id')
            ->from(['m' => 'world_membership'])->innerJoin(['registry' => 'world_registry'], '[[registry.active_world_id]]=[[m.world_id]]')
            ->where(['m.user_id' => $user])->scalar($this->s->db) : null;
        $query = (new Query())->select('e.*')->from(['e' => 'craft_equipment_instance'])
            ->innerJoin(['definition' => 'craft_item'], '[[definition.id]]=[[e.item_id]]')
            ->innerJoin(['i' => 'craft_inventory'], '[[i.id]]=[[e.inventory_id]]')->innerJoin(['s' => 'craft_storage'], '[[s.id]]=[[i.storage_id]]')
            ->where(['e.item_id' => $item, 'e.status' => 'active', 's.owner_user_id' => $user, 's.status' => 'active', 's.kind' => $joined ? 'placement' : 'backpack'])
            ->andWhere(['definition.active' => 1])->andWhere(['>', 'i.item_quantity', 0])->orderBy(['e.id' => SORT_ASC]);
        if ($instance !== null) $query->andWhere(['e.id' => $instance]);
        if ((new ProductionReservations($this->s->db))->installed()) $query->andWhere(['not in', 'e.id', (new Query())->select('instance_id')->from('equipment_reservation_guard')]);
        $result = []; $exposure = new EquipmentExposure($this->s->db);
        $definition = $station ? (new Query())->from('craft_item')->where(['id' => $item, 'active' => 1])->one($this->s->db) : null;
        if ($station && !$definition) return [];
        $active = (new CraftInventory($this->s))->capacity($user)['active_slots'];
        foreach ($query->each(100, $this->s->db) as $unit) {
            if (in_array((int)$unit['id'], $this->s->protectedInstances, true)) continue;
            $row = (new Query())->from('craft_inventory')->where(['id' => $unit['inventory_id']])->one($this->s->db);
            if ((int)$row['user_id'] !== $user || (int)$row['item_id'] !== $item || (int)$row['slot'] < 1) continue;
            if (!$joined && (int)$row['slot'] > $active) continue;
            try { $storage = (new StorageAccessPolicy($this->s->db))->storage($user, (int)$row['storage_id'], true); }
            catch (HttpException $error) { continue; }
            catch (\common\modules\world\support\GameError $error) { continue; }
            if ($joined && $this->s->workspaceNodeId !== null && !in_array((int)$storage['node_id'], WorkspaceScope::nodes($this->s->db, $this->s->workspaceNodeId), true)) continue;
            if ($joined && $this->s->workspaceNodeId === null && (!$home || !(new Query())->from('world_node_closure')
                ->where(['ancestor_id' => (int)$home, 'descendant_id' => (int)$storage['node_id']])->exists($this->s->db))) continue;
            if ($joined && (int)$row['item_quantity'] !== 1) continue;
            if ($joined && $station) {
                $slot = (new Query())->from('world_slot')->where(['storage_id' => $storage['id'], 'position' => $row['slot'], 'status' => 'active'])->one($this->s->db);
                if (!$slot) continue;
                try { (new EquipmentPlacementPolicy($this->s->db))->assertAllowed($definition, $storage, $slot); }
                catch (HttpException $error) { continue; }
            }
            $current = $exposure->projected($unit); if ((int)$current['durability'] < 1) continue;
            $result[] = ['item_id' => $item, 'instance_id' => (int)$unit['id'], 'inventory_id' => (int)$row['id'], 'storage_id' => (int)$storage['id'], 'in_backpack' => $storage['kind'] === 'backpack', 'is_station' => $station,
                'durability' => (int)$current['durability'], 'max_durability' => (int)$unit['max_durability'], 'exposure_class' => $current['exposure_class']];
            if (count($result) >= $limit) break;
        }
        return $result;
    }
}
