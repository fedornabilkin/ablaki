<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use yii\db\{Connection, Query};

/** Resource stockpiles are distinct from portable chests and full warehouse buildings. */
final class MiniStorage
{
    public const CAPACITY = 5;
    public const STACK_LIMIT = 100;

    public static function slotLimit(Connection $db, array $storage): ?int
    {
        if ($storage['kind'] !== 'stockpile' || empty($storage['node_id']) || !$db->schema->getTableSchema('world_node')->getColumn('hierarchy_level')) return null;
        return (new Query())->from('world_node')->where(['id' => $storage['node_id'], 'node_type' => 'CHEST'])->exists($db) ? self::STACK_LIMIT : null;
    }
    public static function permitsParent(Node $parent): bool
    {
        $settings = json_decode($parent->settings_json ?: '{}', true) ?: [];
        // Historical implicit warehouses have no capacity setting and must retain their upgrade path.
        return $parent->node_type !== 'CHEST' && empty($settings['storage_capacity']);
    }
}
