<?php
namespace common\modules\craft\service;

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
        $query = (new Query())->select('e.*')->from(['e' => 'craft_equipment_instance'])
            ->innerJoin(['definition' => 'craft_item'], '[[definition.id]]=[[e.item_id]]')
            ->innerJoin(['i' => 'craft_inventory'], '[[i.id]]=[[e.inventory_id]]')->innerJoin(['s' => 'craft_storage'], '[[s.id]]=[[i.storage_id]]')
            ->where(['e.item_id' => $item, 'e.status' => 'active', 's.owner_user_id' => $user, 's.status' => 'active', 's.kind' => $joined ? 'placement' : 'backpack'])
            ->andWhere(['definition.active' => 1])->andWhere(['>', 'i.item_quantity', 0])->orderBy(['e.id' => SORT_ASC]);
        if ($instance !== null) $query->andWhere(['e.id' => $instance]);
        if ((new ProductionReservations($this->s->db))->installed()) $query->andWhere(['not in', 'e.id', (new Query())->select('instance_id')->from('equipment_reservation_guard')]);
        $result = []; $exposure = new EquipmentExposure($this->s->db);
        $active = (new CraftInventory($this->s))->capacity($user)['active_slots'];
        foreach ($query->each(100, $this->s->db) as $unit) {
            if (in_array((int)$unit['id'], $this->s->protectedInstances, true)) continue;
            $row = (new Query())->from('craft_inventory')->where(['id' => $unit['inventory_id']])->one($this->s->db);
            if ((int)$row['user_id'] !== $user || (int)$row['item_id'] !== $item || (int)$row['slot'] < 1) continue;
            if (!$joined && (int)$row['slot'] > $active) continue;
            try { $storage = (new StorageAccessPolicy($this->s->db))->storage($user, (int)$row['storage_id'], true); }
            catch (HttpException $error) { continue; }
            catch (\common\services\game\GameError $error) { continue; }
            if ($joined && $this->s->workspaceNodeId !== null && (int)$storage['node_id'] !== $this->s->workspaceNodeId) continue;
            if ($joined && ((int)$row['item_quantity'] !== 1 || !(new Query())->from('world_slot')->where(['storage_id' => $storage['id'], 'position' => $row['slot'], 'status' => 'active'])->exists($this->s->db))) continue;
            $current = $exposure->projected($unit); if ((int)$current['durability'] < 1) continue;
            $result[] = ['item_id' => $item, 'instance_id' => (int)$unit['id'], 'inventory_id' => (int)$row['id'], 'storage_id' => (int)$storage['id'], 'in_backpack' => $storage['kind'] === 'backpack', 'is_station' => $station,
                'durability' => (int)$current['durability'], 'max_durability' => (int)$unit['max_durability'], 'exposure_class' => $current['exposure_class']];
            if (count($result) >= $limit) break;
        }
        return $result;
    }
}
