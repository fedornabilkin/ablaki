<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

class BuildingFacilities
{
    public static function stockpile(Connection $db, int $node, int $user, string $kind): ?int
    {
        if (!in_array($kind, ['forge', 'workshop', 'workroom', 'warehouse'], true)) return null;
        $key = 'stockpile:building:' . $node;
        $storage = (new Query())->from('craft_storage')->where(['identity_key' => $key])->one($db);
        if (!$storage) {
            $db->createCommand()->insert('craft_storage', ['identity_key' => $key, 'kind' => 'stockpile', 'node_id' => $node, 'owner_user_id' => $user, 'capacity' => $kind === 'warehouse' ? 20 : 4])->execute();
            $storage = ['id' => (int)$db->getLastInsertID(), 'capacity' => $kind === 'warehouse' ? 20 : 4];
        }
        if (!(new Query())->from('world_warehouse_policy')->where(['storage_id' => $storage['id']])->exists($db))
            $db->createCommand()->insert('world_warehouse_policy', ['storage_id' => $storage['id'], 'initial_capacity' => $storage['capacity'], 'max_capacity' => $kind === 'warehouse' ? 200 : 40, 'base_price' => '5.0000'])->execute();
        return (int)$storage['id'];
    }
}
