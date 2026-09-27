<?php
namespace common\modules\world\service;

use common\modules\economy\value\Money;
use common\services\game\GameError;
use yii\db\Connection;

/** Optional immutable repair contract in a purchased premises template. */
class BuildingRepairSpec
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function publication($value): ?array
    {
        if ($value === null) return null;
        if (!is_array($value) || !is_string($value['full_price'] ?? null) || !is_array($value['materials'] ?? null)) throw new GameError('INVALID_REPAIR_POLICY', 'Укажите цену и материалы полного ремонта.', 422);
        try { $price = Money::parse($value['full_price']); }
        catch (\Exception $e) { throw new GameError('INVALID_REPAIR_POLICY', 'Некорректная цена полного ремонта.', 422); }
        if ($price->isZero() || $price->isNegative()) throw new GameError('INVALID_REPAIR_POLICY', 'Цена полного ремонта должна быть положительной.', 422);
        return ['full_price' => $price->decimal(), 'materials' => (new ConstructionSpec($this->db))->materialInput($value['materials'])];
    }
    public function resolve(array $rule): array
    {
        $rule = $this->publication($rule);
        $rule['materials'] = (new ConstructionSpec($this->db))->resolvedMaterials($rule['materials']);
        return $rule;
    }
    public function cost(array $rule, int $condition, int $maximum): array
    {
        if ($maximum < 1 || $maximum > 1000000 || $condition < 0 || $condition > $maximum) throw new GameError('BUILDING_CONDITION_INVALID', 'Прочность здания требует сверки.');
        $damage = $maximum - $condition; $materials = [];
        foreach ($rule['materials'] as $material) {
            $materials[] = ['item_id' => $material['item_id'], 'name' => $material['name'], 'full_quantity' => $material['quantity'],
                'quantity' => intdiv($material['quantity'] * $damage + $maximum - 1, $maximum)];
        }
        return ['condition_before' => $condition, 'condition_after' => $maximum, 'restore' => $damage,
            'price' => Money::parse($rule['full_price'])->ratioCeil($damage, $maximum)->decimal(), 'materials' => $materials];
    }
}
