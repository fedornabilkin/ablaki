<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** The same operational condition for every level and kind, including ancestors. */
final class NodeAvailability
{
    public static function activePath(Connection $db, int $node): bool
    {
        return !(new Query())->from(['n' => 'world_node'])->innerJoin(['p' => 'world_node_closure'], 'p.ancestor_id=n.id')
            ->where(['p.descendant_id' => $node])->andWhere(['or', ['<>', 'n.status', 'active'], ['<', 'n.condition', 1]])->exists($db);
    }
}
