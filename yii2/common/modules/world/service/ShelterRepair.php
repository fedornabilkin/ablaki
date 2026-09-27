<?php
namespace common\modules\world\service;

use common\modules\craft\service\CraftStorage;
use common\modules\craft\service\EquipmentExposure;
use common\modules\craft\service\EquipmentInstances;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Manual patching: raw inputs of one current plank batch + one current rope batch at full damage. */
class ShelterRepair
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function recipe(): array
    {
        $materials = []; $recipes = []; $equipment = new EquipmentInstances($this->db);
        foreach (['classic-plank', 'classic-rope'] as $code) {
            $recipe = (new Query())->from('craft_recipe')->where(['code' => $code, 'active' => 1])->one($this->db);
            $output = $recipe ? (new Query())->from('craft_item')->where(['id' => $recipe['item_id'], 'active' => 1])->one($this->db) : null;
            if (!$recipe || !$output || $output['code'] !== $code || $recipe['station_id'] !== null || (int)$recipe['cost_credits'] !== 0 || (int)$recipe['output_quantity'] < 1
                || (new Query())->from('craft_recipe_tool')->where(['recipe_id' => $recipe['id']])->exists($this->db)) throw new GameError('SHELTER_REPAIR_RECIPE_UNAVAILABLE', 'Для ручного ремонта нужны действующие бесплатные рецепты досок и верёвки без станции и инструментов.');
            $ingredients = (new Query())->from('craft_recipe_item')->where(['recipe_id' => $recipe['id']])->orderBy(['item_id' => SORT_ASC])->limit(21)->all($this->db);
            if (!$ingredients || count($ingredients) > 20) throw new GameError('SHELTER_REPAIR_RECIPE_UNAVAILABLE', 'Материалы ручного ремонта требуют настройки.');
            foreach ($ingredients as $ingredient) {
                $item = (new Query())->from('craft_item')->where(['id' => $ingredient['item_id'], 'active' => 1])->one($this->db); $amount = (int)$ingredient['item_quantity'];
                if (!$item || $item['kind'] !== 'material' || $item['storage_kind'] !== 'none' || (int)$item['gather_quantity'] < 1 || $equipment->tracked($item) || $amount < 1 || $amount > 10000) throw new GameError('SHELTER_REPAIR_RECIPE_UNAVAILABLE', 'Ремонт стартового укрытия должен использовать доступное для бесплатного сбора сырьё.');
                $id = (int)$item['id'];
                if (!isset($materials[$id])) $materials[$id] = ['item_id' => $id, 'name' => trim($item['name']), 'icon' => trim($item['icon']), 'full_quantity' => 0];
                $materials[$id]['full_quantity'] += $amount;
                if ($materials[$id]['full_quantity'] > 20000) throw new GameError('SHELTER_REPAIR_RECIPE_UNAVAILABLE', 'Норма материала для ремонта слишком велика.');
            }
            $recipes[] = ['id' => (int)$recipe['id'], 'code' => $code];
        }
        if (count($materials) > 20) throw new GameError('SHELTER_REPAIR_RECIPE_UNAVAILABLE', 'Слишком много материалов для ручного ремонта.');
        ksort($materials, SORT_NUMERIC);
        return ['version' => 1, 'recipes' => $recipes, 'materials' => array_values($materials)];
    }
    public function quote(int $user, array $unit, CraftStorage $store, int $at): array
    {
        $current = (new EquipmentExposure($this->db))->projected($unit, $at); $maximum = (int)$unit['max_durability'];
        if ($maximum < 1 || $maximum > 100000 || (int)$current['durability'] < 0 || (int)$current['durability'] > $maximum) throw new GameError('SHELTER_REPAIR_UNAVAILABLE', 'Прочность шалаша требует сверки.');
        $damage = $maximum - (int)$current['durability']; $stock = $store->quantities($user); $rule = $this->recipe(); $materials = []; $reasons = [];
        if ($damage < 1) $reasons[] = 'Шалаш уже целый.';
        foreach ($rule['materials'] as $material) {
            $quantity = intdiv($material['full_quantity'] * $damage + $maximum - 1, $maximum); $have = (int)($stock[$material['item_id']] ?? 0);
            $material += ['quantity' => $quantity, 'have' => $have, 'available' => $have >= $quantity];
            $materials[] = $material;
            if (!$material['available']) $reasons[] = 'Не хватает: ' . $material['name'];
        }
        return ['version' => $rule['version'], 'recipes' => $rule['recipes'], 'durability_before' => (int)$current['durability'], 'durability_after' => $maximum, 'restore' => $damage,
            'materials' => $materials, 'tools' => [], 'station' => null, 'price' => '0.0000', 'available' => !$reasons, 'reasons' => $reasons];
    }
}
