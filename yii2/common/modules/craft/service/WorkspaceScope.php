<?php
namespace common\modules\craft\service;

use yii\db\Connection;
use yii\db\Query;

/** A building and its rooms share a workshop; estates do not reach into other buildings. */
class WorkspaceScope
{
    public static function nodes(Connection $db, int $node): array
    {
        $place = (new Query())->from('world_node')->where(['id' => $node])->one($db);
        if (!$place) return [$node];
        $building = $place['node_type'] === 'BUILDING' ? $node : ($place['node_type'] === 'ROOM' ? (int)$place['parent_id'] : null);
        if (!$building) return [$node];
        return array_merge([$building], array_map('intval', (new Query())->select('id')->from('world_node')->where(['parent_id' => $building, 'node_type' => 'ROOM', 'status' => 'active', 'owner_user_id' => $place['owner_user_id']])->column($db)));
    }
}
