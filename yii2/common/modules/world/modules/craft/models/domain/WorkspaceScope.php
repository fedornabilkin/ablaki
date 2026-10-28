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
        if (isset($place['hierarchy_level'])) {
            $ancestors = (new Query())->select('n.*')->from(['n' => 'world_node'])->innerJoin(['p' => 'world_node_closure'], 'p.ancestor_id=n.id')
                ->where(['p.descendant_id' => $node])->orderBy(['p.distance' => SORT_ASC])->all($db);
            $root = $node;
            foreach ($ancestors as $ancestor) {
                if ($ancestor['status'] !== 'active' || (int)$ancestor['owner_user_id'] !== (int)$place['owner_user_id'] || (int)$ancestor['condition'] < 1) break;
                $root = (int)$ancestor['id'];
            }
            $rows = (new Query())->select('n.*')->from(['n' => 'world_node'])->innerJoin(['p' => 'world_node_closure'], 'p.descendant_id=n.id')
                ->where(['p.ancestor_id' => $root])->orderBy(['p.distance' => SORT_ASC, 'n.id' => SORT_ASC])->all($db);
            return \common\modules\world\models\domain\WorldComposite::tree($rows, $root)->accept(new \common\modules\world\models\domain\WorkspaceVisitor((int)$place['owner_user_id']));
        }
        $scope = $node;
        if ($place['node_type'] === 'ROOM') $scope = (int)$place['parent_id'];
        if ($place['node_type'] === 'PLACE') {
            $room = (new Query())->from('world_node')->where(['id' => $place['parent_id'], 'node_type' => 'ROOM', 'status' => 'active', 'owner_user_id' => $place['owner_user_id']])->one($db);
            if ($room) $scope = (int)$room['parent_id'];
        }
        if (in_array($place['node_type'], ['BUILDING', 'ROOM', 'PLACE'], true)
            || ($place['node_type'] === 'PLOT' && (new Query())->from('world_plot')->where(['node_id' => $node, 'plot_kind' => 'campsite'])->exists($db))) {
            $rows = (new Query())->select(['n.id', 'n.parent_id', 'n.owner_user_id', 'n.status', 'b.operational_status', 'b.condition'])->from(['n' => 'world_node'])
                ->innerJoin(['p' => 'world_node_closure'], '[[p.descendant_id]]=[[n.id]]')->leftJoin(['b' => 'world_building'], '[[b.node_id]]=[[n.id]]')
                ->where(['p.ancestor_id' => $scope])->orderBy(['p.distance' => SORT_ASC])->all($db);
            $allowed = [];
            foreach ($rows as $row) if ((int)$row['owner_user_id'] === (int)$place['owner_user_id'] && $row['status'] === 'active'
                && ($row['operational_status'] === null || ($row['operational_status'] === 'active' && (int)$row['condition'] > 0))
                && ((int)$row['id'] === $scope || isset($allowed[$row['parent_id']]))) $allowed[$row['id']] = (int)$row['id'];
            return array_values($allowed);
        }
        return [$node];
    }
}
