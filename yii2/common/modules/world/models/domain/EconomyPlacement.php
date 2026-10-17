<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Counts installed physical units. Moving or removing an existing unit remains possible. */
final class EconomyPlacement
{
    public static function check(Connection $db, int $user, array $item, array $source, array $target): void
    {
        $revision = EconomyRuntime::current($db);
        if (!$revision || $target['kind'] !== 'placement' || $source['kind'] === 'placement') return;
        $maximum = $revision['profile']['limits']['objects'][$item['code']] ?? null;
        if ($maximum === null) return;
        $count = (int)(new Query())->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')
            ->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
            ->where(['i.user_id' => $user, 'i.item_id' => $item['id'], 's.kind' => 'placement', 's.status' => 'active', 'n.root_id' => EconomyRuntime::world($db)])
            ->andWhere(['<>', 'n.status', 'archived'])->sum('i.item_quantity', $db);
        if ($count >= $maximum) throw new GameError('OBJECT_LIMIT', 'Достигнут предел размещённых предметов этого вида.', 409, ['maximum' => $maximum]);
    }
}
