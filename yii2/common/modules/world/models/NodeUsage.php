<?php
namespace common\modules\world\models;

use yii\db\Query;

final class NodeUsage
{
    public static function hasReferences(int $id): bool
    {
        $db = Node::getDb();
        $editor = new domain\WorldNodeEditor($db, new domain\WorldFlags($db, \Yii::$app->getModule('world')));
        return (bool)$editor->usage($id, true, true);
    }
    public static function hasAssets(int $id): bool
    {
        return (new Query())->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')
            ->where(['s.node_id' => $id])->andWhere(['>', 'i.item_quantity', 0])->exists()
            || (new Query())->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')
            ->where(['s.node_id' => $id])->andWhere(['or', ['>', 'a.amount', 0], ['>', 'a.reserved', 0]])->exists();
    }
}
