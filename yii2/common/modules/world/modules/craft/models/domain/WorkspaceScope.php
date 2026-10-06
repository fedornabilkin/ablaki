<?php
namespace common\modules\world\modules\craft\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** A campsite shares its own active premises, never another owner’s territory. */
class WorkspaceScope
{
    public static function nodes(Connection $db, int $node): array
    {
        $place = (new Query())->from('world_node')->where(['id' => $node])->one($db);
        if (!$place) return [$node];
        if ($place['node_type'] === 'PLOT' && (new Query())->from('world_plot')->where(['node_id' => $node, 'plot_kind' => 'campsite'])->exists($db)) {
            $rows = (new Query())->select(['n.id', 'n.parent_id', 'n.owner_user_id', 'n.status', 'b.operational_status', 'b.condition'])->from(['n' => 'world_node'])
                ->innerJoin(['p' => 'world_node_closure'], '[[p.descendant_id]]=[[n.id]]')->leftJoin(['b' => 'world_building'], '[[b.node_id]]=[[n.id]]')
                ->where(['p.ancestor_id' => $node])->orderBy(['p.distance' => SORT_ASC])->all($db);
            $allowed = [];
            foreach ($rows as $row) if ((int)$row['owner_user_id'] === (int)$place['owner_user_id'] && $row['status'] === 'active'
                && ($row['operational_status'] === null || ($row['operational_status'] === 'active' && (int)$row['condition'] > 0))
                && ((int)$row['id'] === $node || isset($allowed[$row['parent_id']]))) $allowed[$row['id']] = (int)$row['id'];
            return array_values($allowed);
        }
        $building = $place['node_type'] === 'BUILDING' ? $node : ($place['node_type'] === 'ROOM' ? (int)$place['parent_id'] : null);
        if (!$building) return [$node];
        return array_merge([$building], array_map('intval', (new Query())->select('id')->from('world_node')->where(['parent_id' => $building, 'node_type' => 'ROOM', 'status' => 'active', 'owner_user_id' => $place['owner_user_id']])->column($db)));
    }
}
