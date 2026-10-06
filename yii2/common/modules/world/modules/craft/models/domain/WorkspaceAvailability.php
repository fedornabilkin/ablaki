<?php
namespace common\modules\world\modules\craft\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** One inventory snapshot and one equipment lookup per distinct role, not per recipe. */
class WorkspaceAvailability
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function recipes(int $user, CraftStorage $store, array $catalog, array $recipes, array $storages): array
    {
        $definitions = (new Query())->from('craft_item')->indexBy('id')->all($this->db);
        $stations = (new Query())->from('craft_station')->indexBy('id')->all($this->db);
        $tools = (new Query())->from('craft_recipe_tool')->all($this->db);
        $trackedRoles = array_fill_keys(array_merge(array_filter(array_column($stations, 'item_id')), array_column($tools, 'item_id')), true);
        $storageById = array_column($storages, null, 'id');
        $rows = (new Query())->from('craft_inventory')->where(['storage_id' => array_keys($storageById), 'user_id' => $user])->andWhere(['>', 'item_quantity', 0])->all($this->db);
        $ids = array_column($rows, 'id');
        $reserved = (new Query())->select(['inventory_id', 'amount' => new \yii\db\Expression('SUM([[quantity]])')])->from('inventory_reservation')->where(['inventory_id' => $ids, 'released_at' => null])->groupBy('inventory_id')->indexBy('inventory_id')->all($this->db);
        $nonempty = (new Query())->select('s.container_inventory_id')->distinct()->from(['s' => 'craft_storage'])->innerJoin(['i' => 'craft_inventory'], '[[i.storage_id]]=[[s.id]]')->where(['s.container_inventory_id' => $ids])->andWhere(['>', 'i.item_quantity', 0])->column($this->db);
        $blocked = array_fill_keys(array_merge($nonempty, array_filter(array_column($storages, 'container_inventory_id'))), true);
        $units = (new Query())->from('craft_equipment_instance')->where(['inventory_id' => $ids, 'status' => 'active'])->all($this->db);
        $free = (new ProductionReservations($this->db))->freeEquipment($units); $freeByRow = []; $allByRow = [];
        foreach ($units as $unit) $allByRow[$unit['inventory_id']][] = $unit;
        foreach ($free as $unit) $freeByRow[$unit['inventory_id']][] = $unit;
        $stock = []; $freeByItem = [];
        foreach ($rows as $row) {
            if (isset($blocked[$row['id']]) || (int)$row['slot'] < 1 || (int)$row['slot'] > $storageById[$row['storage_id']]['capacity']) continue;
            $quantity = max(0, (int)$row['item_quantity'] - (int)($reserved[$row['id']]['amount'] ?? 0));
            $definition = $definitions[$row['item_id']] ?? null;
            if (!$definition || !(int)$definition['active']) continue;
            $tracked = $definition['storage_kind'] !== 'chest' && (in_array($definition['kind'], ['tool', 'station'], true) || isset($trackedRoles[$row['item_id']]) || $definition['code'] === \common\modules\world\models\domain\ShelterCatalog::CODE);
            if ($tracked) {
                if (count($allByRow[$row['id']] ?? []) !== (int)$row['item_quantity']) continue;
                $quantity = min($quantity, count($freeByRow[$row['id']] ?? []));
            }
            $stock[$row['item_id']] = ($stock[$row['item_id']] ?? 0) + $quantity;
            foreach ($freeByRow[$row['id']] ?? [] as $unit) $freeByItem[$row['item_id']][(int)$unit['id']] = true;
        }
        $ingredients = []; $roles = [];
        foreach ((new Query())->from('craft_recipe_item')->where(['recipe_id' => array_column($catalog, 'id')])->all($this->db) as $r) $ingredients[$r['recipe_id']][$r['item_id']] = (int)$r['item_quantity'];
        foreach ($tools as $r) $roles[$r['recipe_id']][$r['item_id']] = false;
        $candidates = []; $resolver = new StationResolver($store); $byId = array_column($catalog, null, 'id');
        $credit = (new Query())->select('credit')->from('persone')->where(['user_id' => $user])->scalar($this->db);
        $charged = (new CraftSettings($store))->chargeCredits();
        foreach ($recipes as &$recipe) {
            $source = $byId[$recipe['id']]; $missing = $recipe['locked_reasons']; $required = $ingredients[$recipe['id']] ?? [];
            $equipment = $roles[$recipe['id']] ?? [];
            if ($source['station_id'] !== null) {
                $station = $stations[$source['station_id']] ?? null;
                if (!$station || !(int)$station['active']) $missing[] = 'Станция недоступна.';
                elseif ($station['item_id'] !== null) $equipment[$station['item_id']] = true;
            }
            $retained = [];
            foreach ($equipment as $item => $isStation) {
                $key = $item . ':' . (int)$isStation;
                if (!isset($candidates[$key])) $candidates[$key] = $resolver->candidates($user, (int)$item, $isStation);
                $unit = $candidates[$key][0] ?? null;
                if (!$unit || $unit['durability'] < max(1, (new EquipmentExposure($this->db))->cost($unit, 0, 1, $isStation))) $missing[] = 'Нет оборудования: ' . ($definitions[$item]['name'] ?? $item);
                elseif (isset($freeByItem[$item][$unit['instance_id']])) $retained[$item] = 1;
            }
            if (!$required) $missing[] = 'Не настроены ингредиенты.';
            foreach ($required as $item => $quantity) if (empty($definitions[$item]['active']) || ($stock[$item] ?? 0) - ($retained[$item] ?? 0) < $quantity) $missing[] = 'Не хватает: ' . ($definitions[$item]['name'] ?? $item);
            if ($charged && \common\modules\world\modules\economy\value\Money::parse((string)$credit)->compare(\common\modules\world\modules\economy\value\Money::parse((string)$source['cost_credits'])) < 0) $missing[] = 'Не хватает кредитов.';
            $recipe['available'] = !$missing; $recipe['availability_reasons'] = array_values(array_unique($missing));
        }
        unset($recipe); return $recipes;
    }
}
