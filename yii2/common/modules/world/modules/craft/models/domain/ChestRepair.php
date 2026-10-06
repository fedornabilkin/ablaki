<?php
namespace common\modules\world\modules\craft\models\domain;

use yii\db\Query;
use yii\web\ConflictHttpException;

/** Uses the chest's current recipe under the command's catalog and owner locks. */
class ChestRepair
{
    public const TOOL_DURABILITY = 100;
    private $store;
    public function __construct(CraftStorage $store) { $this->store = $store; }

    private function equipment(int $user, int $itemId, bool $station = false): array
    {
        if ($this->store->isCanonical()) {
            $unit = (new StationResolver($this->store))->select($user, $itemId, $station);
            return $unit ?: ['item_id' => $itemId, 'instance_id' => null, 'durability' => 0, 'max_durability' => 100, 'in_backpack' => false, 'is_station' => $station, 'exposure_class' => 'carried'];
        }
        // Tools and owned stations share the wear of their current inventory item.
        $wear = (int)(new Query())->select('wear')->from('craft_tool_wear')->where(['user_id' => $user, 'item_id' => $itemId])->scalar($this->store->db);
        return ['item_id' => $itemId, 'durability' => self::TOOL_DURABILITY - $wear, 'max_durability' => self::TOOL_DURABILITY];
    }

    public function quote(int $user, int $slotId): array
    {
        $db = $this->store->db;
        $inventory = new CraftInventory($this->store);
        $chest = $inventory->container($user, $slotId);
        $slot = (new Query())->from('craft_inventory')->where(['id' => $slotId, 'user_id' => $user])->one($db);
        $recipe = (new Query())->from('craft_recipe')->where(['item_id' => $slot['item_id'], 'active' => 1])->orderBy('id')->one($db);
        $damage = max(0, (int)$chest['max_durability'] - (int)$chest['durability']);
        $result = ['materials' => [], 'tools' => [], 'station' => null, 'reasons' => [], 'restore' => $damage];
        if (!$damage) $result['reasons'][] = 'Сундук уже целый.';
        if (!$recipe || (int)$recipe['output_quantity'] < 1) {
            $result['reasons'][] = 'Рецепт ремонта недоступен.';
            return $result;
        }
        $stock = $this->store->quantities($user);
        $items = [];
        foreach ($this->store->rows('craft_item') as $item) $items[(int)$item['id']] = $item;
        $required = [];
        $ingredients = $this->store->rows('craft_recipe_item', ['recipe_id' => $recipe['id']]);
        if (!$ingredients) $result['reasons'][] = 'В рецепте нет материалов для ремонта.';
        foreach ($ingredients as $ingredient) {
            $id = (int)$ingredient['item_id'];
            if ((int)$ingredient['item_quantity'] < 1 || (int)$ingredient['item_quantity'] > 10000) {
                $result['reasons'][] = 'Рецепт ремонта требует проверки.';
                continue;
            }
            $required[$id] = ($required[$id] ?? 0) + (int)$ingredient['item_quantity'];
        }
        foreach ($required as $id => $amount) {
            // A full repair costs half the materials of one chest, rounded up per material.
            $needed = (int)ceil($amount * $damage / (2 * max(1, (int)$chest['max_durability']) * (int)$recipe['output_quantity']));
            $required[$id] = $needed;
            $result['materials'][] = ['item_id' => $id, 'quantity' => $needed, 'have' => $stock[$id] ?? 0];
        }
        $reserve = [];
        foreach ($this->store->rows('craft_recipe_tool', ['recipe_id' => $recipe['id']]) as $tool) {
            $id = (int)$tool['item_id'];
            if (isset($reserve[$id])) continue;
            $reserve[$id] = 1;
            $result['tools'][] = $this->equipment($user, $id);
        }
        if ($recipe['station_id'] !== null) {
            $station = (new Query())->from('craft_station')->where(['id' => $recipe['station_id'], 'active' => 1])->one($db);
            if (!$station) $result['reasons'][] = 'Станция недоступна.';
            else {
                $result['station'] = ['id' => (int)$station['id'], 'name' => trim($station['name']), 'item_id' => $station['item_id'] === null ? null : (int)$station['item_id']];
                if ($station['item_id'] !== null) {
                    $reserve[(int)$station['item_id']] = 1;
                    $result['station'] += $this->equipment($user, (int)$station['item_id'], true);
                }
            }
        }
        if ($this->store->isCanonical()) {
            $equipment = [];
            foreach ($result['tools'] as $unit) $equipment[$unit['item_id']] = $unit;
            if ($result['station'] && $result['station']['item_id'] !== null) $equipment[$result['station']['item_id']] = $result['station'];
            foreach ($equipment as $id => $unit) {
                $cost = (new EquipmentExposure($db))->cost($unit, 1, 1, $unit['is_station']);
                if (!$unit['instance_id'] || $unit['durability'] < $cost) $result['reasons'][] = 'Недоступно или изношено оборудование: ' . trim($items[$id]['name'] ?? ('#' . $id));
                if (!$unit['in_backpack']) unset($reserve[$id]);
            }
        }
        foreach ($reserve as $id => $amount) $required[$id] = ($required[$id] ?? 0) + $amount;
        foreach ($required as $id => $amount) {
            if (empty($items[$id]['active'])) $result['reasons'][] = 'Предмет недоступен: '.trim($items[$id]['name'] ?? '#'.$id);
            elseif (($stock[$id] ?? 0) < $amount) $result['reasons'][] = 'Не хватает: '.trim($items[$id]['name']);
        }
        $available = static function (int $id) use ($items, $stock, $required): bool { return !empty($items[$id]['active']) && ($stock[$id] ?? 0) >= ($required[$id] ?? 1); };
        foreach (['materials', 'tools'] as $group) foreach ($result[$group] as &$entry) {
            $entry['available'] = $available($entry['item_id']);
            $entry['have'] = $stock[$entry['item_id']] ?? 0;
        }
        unset($entry);
        if ($result['station']) $result['station']['available'] = $result['station']['item_id'] === null || $available($result['station']['item_id']);
        if ($this->store->isCanonical()) {
            foreach ($result['tools'] as &$tool) $tool['available'] = !empty($tool['instance_id']) && $tool['durability'] >= 1;
            unset($tool);
            if ($result['station'] && $result['station']['item_id'] !== null) {
                $unit = $result['station'];
                $result['station']['available'] = !empty($unit['instance_id']) && $unit['durability'] >= (new EquipmentExposure($db))->cost($unit, 1, 1, true);
            }
        }
        foreach (['materials', 'tools'] as $group) foreach ($result[$group] as &$entry) $entry['name'] = trim($items[$entry['item_id']]['name'] ?? ('#' . $entry['item_id']));
        unset($entry);
        return $result;
    }

    public function repair(int $user, int $slotId, int $itemId): int
    {
        $db = $this->store->db;
        if (!$db->getTransaction()) throw new \RuntimeException('Repair transaction required.');
        if (!(new Query())->from('craft_inventory')->where(['id' => $slotId, 'user_id' => $user, 'item_id' => $itemId])->exists($db)) throw new ConflictHttpException('Выберите свой сундук.');
        $quote = $this->quote($user, $slotId);
        if ($quote['reasons']) throw new ConflictHttpException(implode(' ', $quote['reasons']));
        $inventory = new CraftInventory($this->store);
        $chest = $inventory->container($user, $slotId);
        if ($this->store->isCanonical()) {
            $units = [];
            foreach ($quote['tools'] as $tool) $units[$tool['item_id']] = $tool;
            if ($quote['station'] && $quote['station']['item_id'] !== null) $units[$quote['station']['item_id']] = $quote['station'];
            $this->store->protectedInstances = array_column($units, 'instance_id');
            foreach ($units as $unit) (new EquipmentExposure($db))->use($unit['instance_id'], 1, 1, $unit['is_station']);
        }
        foreach ($quote['materials'] as $material) {
            $item = (new Query())->from('craft_item')->where(['id' => $material['item_id']])->one($db);
            $this->store->move($user, $item, -$material['quantity']);
        }
        $equipment = [];
        foreach ($quote['tools'] as $tool) $equipment[$tool['item_id']] = $tool;
        if ($quote['station'] && $quote['station']['item_id'] !== null) $equipment[$quote['station']['item_id']] = $quote['station'];
        foreach ($equipment as $tool) {
            if ($this->store->isCanonical()) continue;
            $where = ['user_id' => $user, 'item_id' => $tool['item_id']];
            $wear = self::TOOL_DURABILITY - $tool['durability'] + 1;
            if ($wear >= self::TOOL_DURABILITY) {
                $item = (new Query())->from('craft_item')->where(['id' => $tool['item_id']])->one($db);
                $this->store->move($user, $item, -1);
                $wear = 0;
            }
            if ((new Query())->from('craft_tool_wear')->where($where)->exists($db)) {
                if ($db->createCommand()->update('craft_tool_wear', ['wear' => $wear], $where)->execute() !== 1) throw new \RuntimeException('Tool wear write failed.');
            } elseif ($db->createCommand()->insert('craft_tool_wear', $where + ['wear' => $wear])->execute() !== 1) throw new \RuntimeException('Tool wear insert failed.');
        }
        if ($db->createCommand()->update('craft_container', ['durability' => (int)$chest['max_durability']], ['id' => $slotId, 'user_id' => $user])->execute() !== 1) throw new \RuntimeException('Repair write failed.');
        if ($this->store->isCanonical()) $db->createCommand()->update('craft_storage', ['revision' => new \yii\db\Expression('[[revision]]+1')], ['container_inventory_id' => $slotId])->execute();
        return $quote['restore'];
    }
}
