<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** Moving a bed closes its lodging interval, keeping past-night evidence. */
final class FurnishingPlacement
{
    public static function hasBed(Connection $db, int $node): bool
    {
        return $db->schema->getTableSchema('world_furnishing_rule') && (new Query())->from(['i' => 'craft_inventory'])
            ->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')->innerJoin(['r' => 'world_furnishing_rule'], 'r.item_id=i.item_id')
            ->where(['s.node_id' => $node, 's.kind' => 'placement', 's.status' => 'active', 'r.lodging' => 1])->andWhere(['>', 'i.item_quantity', 0])->exists($db);
    }
    public static function synchronize(Connection $db, array $nodes, string $operation): void
    {
        if (!$db->schema->getTableSchema('world_furnishing_rule')) return;
        foreach (array_unique(array_filter($nodes)) as $node) {
            $installed = self::hasBed($db, (int)$node);
            $place = (new Query())->from('world_housing_place')->where(['room_id' => $node, 'ordinal' => 1])->one($db);
            if ($installed && !$place) {
                $site = (new Query())->select('n.id')->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], 'c.ancestor_id=n.id')
                    ->where(['c.descendant_id' => $node, 'n.plot_kind' => 'campsite', 'n.status' => 'active'])->orderBy(['c.distance' => SORT_ASC])->scalar($db);
                if ($site) $db->createCommand()->insert('world_housing_place', ['room_id' => $node, 'plot_id' => $site, 'operation_id' => $operation, 'purchase_id' => null, 'created_at' => time()])->execute();
            }
            if (!$installed && $place) $db->createCommand()->update('world_housing_interval', ['ended_at' => time(), 'active_place_id' => null, 'active_actor_id' => null], ['active_place_id' => $place['id'], 'ended_at' => null])->execute();
        }
    }
}
