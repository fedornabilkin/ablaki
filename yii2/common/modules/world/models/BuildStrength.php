<?php
namespace common\modules\world\models;

/** One minute of construction work gives one hundred strength units. */
final class BuildStrength
{
    public static function maximum(int $seconds): int { return max(1, (int)ceil($seconds * 100 / 60)); }
    public static function current(int $worked, int $required, int $maximum): int
    { return min($maximum, (int)floor(max(0, $worked) * $maximum / max(1, $required))); }
    public static function synchronize(Build $build): void
    {
        $node = $build->node;
        $maximum = (int)$node->max_condition ?: self::maximum((int)$build->required_seconds);
        $current = self::forProject($build, (int)$build->worked_seconds);
        Node::updateAll(['condition' => $current, 'max_condition' => $maximum], ['id' => $node->id]);
        $node->condition = $current; $node->max_condition = $maximum;
        $instance = $build->isRepair() ? self::shelterInstance((int)$node->id) : null;
        if ($instance) {
            $db = Build::getDb(); $unit = (new \common\modules\world\modules\craft\models\domain\EquipmentExposure($db))->settle($instance);
            if ((int)$unit['durability'] !== $current) {
                $db->createCommand()->update('craft_equipment_instance', ['durability'=>$current,'revision'=>new \yii\db\Expression('[[revision]]+1')], ['id'=>$instance])->execute();
                (new \common\modules\world\modules\craft\models\domain\EquipmentWearHistory($db))->append($unit, $unit, $unit, 'repair', $unit['settled_at'], $unit['settled_at'], $current);
            }
        }
    }
    public static function shelterInstance(int $node): ?int
    {
        $db = Build::getDb();
        if (!$db->schema->getTableSchema('world_shelter_deployment')) return null;
        $id = (new \yii\db\Query())->select('active_instance_id')->from('world_shelter_deployment')->where(['node_id'=>$node])->scalar($db);
        return $id ? (int)$id : null;
    }
    public static function forProject(Build $build, int $worked): int
    {
        $initial = $build->isRepair() ? (int)$build->getAttribute('initial_condition') : 0;
        $maximum = (int)$build->node->max_condition;
        return min($maximum, $initial + self::current($worked, (int)$build->required_seconds, max(0,$maximum-$initial)));
    }
}
