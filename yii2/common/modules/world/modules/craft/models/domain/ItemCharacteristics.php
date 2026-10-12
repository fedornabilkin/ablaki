<?php
namespace common\modules\world\modules\craft\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** Public item properties; ownership and command identifiers are handled separately. */
class ItemCharacteristics
{
    public static function batch(Connection $db, array $definitions): array
    {
        if (!$definitions) return [];
        $ids = array_column($definitions, 'id');
        $furniture = $db->schema->getTableSchema('world_furnishing_rule') ? (new Query())->from('world_furnishing_rule')->where(['item_id' => $ids])->indexBy('item_id')->all($db) : [];
        $stations = $db->schema->getTableSchema('craft_station') ? (new Query())->from('craft_station')->where(['item_id' => $ids, 'active' => 1])->indexBy('item_id')->all($db) : [];
        $kinds = ['material' => 'Материал', 'tool' => 'Инструмент', 'station' => 'Станция', 'furniture' => 'Мебель', 'product' => 'Изделие', 'consumable' => 'Расходный предмет'];
        $result = [];
        foreach ($definitions as $item) {
            $properties = [['label' => 'Тип', 'value' => ($item['storage_kind'] ?? '') === 'chest' ? 'Сундук' : (isset($furniture[$item['id']]) ? 'Предмет обстановки' : ($kinds[$item['kind'] ?? ''] ?? 'Предмет'))]];
            if (isset($stations[$item['id']])) $properties[] = ['label' => 'Станция', 'value' => trim($stations[$item['id']]['name'])];
            if (!empty($furniture[$item['id']]['lodging'])) $properties[] = ['label' => 'Ночлег', 'value' => 'Позволяет оборудовать место для сна'];
            if (!empty($item['stack_size'])) $properties[] = ['label' => 'В одной ячейке', 'value' => (string)$item['stack_size']];
            $rarities = ['common' => 'Обычная', 'uncommon' => 'Необычная', 'rare' => 'Редкая', 'epic' => 'Эпическая', 'legendary' => 'Легендарная'];
            if (!empty($item['rarity'])) $properties[] = ['label' => 'Редкость', 'value' => $rarities[$item['rarity']] ?? (string)$item['rarity']];
            $result[$item['id']] = $properties;
        }
        return $result;
    }
}
