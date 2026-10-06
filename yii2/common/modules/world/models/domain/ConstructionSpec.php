<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\CanonicalInventory;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\EquipmentInstances;
use common\modules\world\modules\craft\models\domain\StorageAccessPolicy;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

class ConstructionSpec
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public static function presentation(array $config): array
    {
        return ['delivery' => $config['delivery'] ?? 'ready', 'duration_seconds' => $config['duration_seconds'] ?? 0,
            'materials' => $config['materials'] ?? [], 'cancellation' => 'full_refund_before_completion'];
    }
    public function publication(array $input): array
    {
        $delivery = $input['delivery'] ?? 'ready'; $duration = $input['duration_seconds'] ?? 0; $materials = $input['materials'] ?? [];
        if (!in_array($delivery, ['ready', 'construction'], true) || !is_array($materials) || count($materials) > 8 || !is_int($duration)) throw new GameError('INVALID_CONSTRUCTION', 'Некорректные условия строительства.', 422);
        if ($delivery === 'ready') {
            if ($duration !== 0 || $materials !== []) throw new GameError('INVALID_CONSTRUCTION', 'У готовой постройки нет срока и материалов строительства.', 422);
            return self::presentation([]);
        }
        if ($duration < 60 || $duration > 604800 || !$materials) throw new GameError('INVALID_CONSTRUCTION', 'Срок стройки — от минуты до семи суток, нужен хотя бы один материал.', 422);
        return ['delivery' => $delivery, 'duration_seconds' => $duration, 'materials' => $this->materialInput($materials), 'cancellation' => 'full_refund_before_completion'];
    }
    /** Shared raw-material norms for construction and contracted maintenance. */
    public function materialInput(array $materials): array
    {
        if (!$materials || count($materials) > 8) throw new GameError('INVALID_MATERIALS', 'Укажите от одного до восьми материалов.', 422);
        $result = []; $seen = [];
        foreach ($materials as $material) {
            if (!is_array($material) || !is_int($material['item_id'] ?? null) || !is_int($material['quantity'] ?? null) || $material['item_id'] < 1 || $material['quantity'] < 1 || $material['quantity'] > 10000 || isset($seen[$material['item_id']])) throw new GameError('INVALID_CONSTRUCTION', 'Укажите до восьми разных материалов, от 1 до 10000 единиц каждого.', 422);
            $seen[$material['item_id']] = true;
            $result[] = ['item_id' => $material['item_id'], 'quantity' => $material['quantity']];
        }
        usort($result, static function (array $a, array $b): int { return $a['item_id'] <=> $b['item_id']; });
        return $result;
    }
    /** Resolve catalog state under CommandBus locks, after its completed-command replay check. */
    public function resolvedMaterials(array $materials): array
    {
        $result = []; $equipment = new EquipmentInstances($this->db);
        foreach ($materials as $material) {
            $item = (new Query())->from('craft_item')->where(['id' => $material['item_id'], 'active' => 1, 'kind' => 'material'])->one($this->db);
            if (!$item || ($item['storage_kind'] ?? 'none') !== 'none' || (int)$item['gather_quantity'] < 1 || $equipment->tracked($item)) throw new GameError('INVALID_CONSTRUCTION_MATERIAL', 'Для стройки доступно обычное добываемое сырьё действующего каталога.', 422);
            $result[] = ['item_id' => (int)$item['id'], 'name' => trim($item['name']), 'quantity' => $material['quantity']];
        }
        return $result;
    }
    public function materials(int $user, array $config): array
    {
        $inventory = new CanonicalInventory(new CraftStorage($this->db)); $backpack = $inventory->backpack($user);
        if (!$backpack) throw new GameError('CONSTRUCTION_MATERIALS_REQUIRED', 'Перенесите материалы строительства в рюкзак.');
        $storage = (new StorageAccessPolicy($this->db))->storage($user, (int)$backpack['id']);
        $rows = (new Query())->from('craft_inventory')->where(['storage_id' => $storage['id'], 'user_id' => $user])->andWhere(['between', 'slot', 1, $storage['capacity']])->andWhere(['>', 'item_quantity', 0])->orderBy(['id' => SORT_ASC])->all($this->db);
        $plan = []; $revisions = ['storage:' . $storage['id'] => (int)$storage['revision']];
        foreach ($config['materials'] as $material) {
            $left = $material['quantity'];
            foreach ($rows as $row) {
                if (!$left || (int)$row['item_id'] !== $material['item_id']) continue;
                $take = min($left, (int)$row['item_quantity']);
                $inventory->inspectOrderDelivery($user, (int)$row['id'], $material['item_id'], $take);
                $plan[] = ['inventory_id' => (int)$row['id'], 'storage_id' => (int)$storage['id'], 'item_id' => $material['item_id'], 'quantity' => $take];
                $revisions['inventory:' . $row['id']] = (int)$row['revision']; $left -= $take;
            }
            if ($left) throw new GameError('CONSTRUCTION_MATERIALS_REQUIRED', 'Не хватает материала «' . $material['name'] . '»: ' . $left . '.', 409, ['item_id' => $material['item_id'], 'missing' => $left]);
        }
        if (count($plan) > 64) throw new GameError('CONSTRUCTION_BATCH_LIMIT', 'Объедините материалы в рюкзаке: стройка использует не больше 64 ячеек.');
        return compact('plan', 'revisions');
    }
}
