<?php
namespace common\modules\world\models\domain;

use yii\db\{Connection, Query};

/** Benefits follow the current occupied home, never the number of owned buildings. */
final class HousingBenefits
{
    public static function extractionBonus(Connection $db, int $user, array $profile): int
    {
        $bonus = $profile['housing_benefits']['house']['extraction_daily_bonus'] ?? 0;
        if (!$bonus) return 0;
        $actor = (new Query())->select('id')->from('game_actor')->where(['user_id' => $user, 'kind' => 'player'])->scalar($db);
        if (!$actor) return 0;
        $lodging = (new LodgingAssignments($db))->current((int)$actor);
        if (!$lodging || $lodging['kind'] !== 'house') return 0;
        $room = $lodging['node_id'];
        if (!(new Query())->from('world_node')->where(['id' => $room, 'owner_user_id' => $user])->exists($db)) return 0;
        $home = (new Query())->select('n.id')->from(['n' => 'world_node'])
            ->innerJoin(['c' => 'world_node_closure'], 'c.ancestor_id=n.id')
            ->innerJoin(['t' => 'world_template'], 't.id=n.template_id')
            ->where(['c.descendant_id' => $room, 'n.root_id' => EconomyRuntime::world($db), 'n.owner_user_id' => $user, 't.code' => 'house'])->scalar($db);
        if (!$home || !NodeAvailability::activePath($db, $room) || !FurnishingPlacement::hasBed($db, $room)) return 0;
        if ((new Query())->from(['b' => 'world_building'])->innerJoin(['c' => 'world_node_closure'], 'c.ancestor_id=b.node_id')
            ->where(['c.descendant_id' => $room])->andWhere(['or', ['<>', 'b.operational_status', 'active'], ['<', 'b.condition', 1]])->exists($db)) return 0;
        return $bonus;
    }
}
