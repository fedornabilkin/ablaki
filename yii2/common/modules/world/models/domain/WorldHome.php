<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

class WorldHome
{
    public static function node(Connection $db, int $user, int $world): ?int
    {
        $homes = (new Query())->select('n.id')->from(['n' => 'world_node'])
            ->innerJoin(['b' => 'world_building'], '[[b.node_id]]=[[n.id]]')
            ->where(['n.owner_user_id' => $user, 'n.root_id' => $world, 'n.status' => 'active', 'b.building_kind' => 'house', 'b.operational_status' => 'active'])
            ->andWhere(['not exists', (new Query())->from(['s' => 'world_shelter_deployment'])->where('[[s.node_id]]=[[n.id]]')])
            ->andWhere(['not exists', (new Query())->from(['c' => 'world_node_closure'])->innerJoin(['a' => 'world_node'], '[[a.id]]=[[c.ancestor_id]]')->where('[[c.descendant_id]]=[[n.id]]')->andWhere(['<>', 'a.status', 'active'])]);
        $assigned = (clone $homes)->innerJoin(['r' => 'world_node'], '[[r.parent_id]]=[[n.id]]')->innerJoin(['p' => 'world_housing_place'], '[[p.room_id]]=[[r.id]]')
            ->innerJoin(['l' => 'world_housing_interval'], '[[l.active_place_id]]=[[p.id]]')
            ->innerJoin(['a' => 'game_actor'], '[[a.id]]=[[l.actor_id]]')->andWhere(['a.user_id' => $user, 'l.ended_at' => null])->orderBy(['l.started_at' => SORT_DESC])->scalar($db);
        $id = $assigned ?: $homes->orderBy(['n.id' => SORT_ASC])->scalar($db);
        if (!$id) $id = (new Query())->select('starter_site_id')->from('world_membership')->where(['user_id' => $user, 'world_id' => $world])->scalar($db);
        return $id ? (int)$id : null;
    }
}
