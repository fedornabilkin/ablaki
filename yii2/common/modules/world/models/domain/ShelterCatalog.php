<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Reserved gameplay item: never a recipe result, ingredient, tool or station. */
class ShelterCatalog
{
    public const CODE = 'world-starter-shelter';
    public static function seed(Connection $db): void
    {
        if ((new Query())->from('craft_item')->where(['code' => self::CODE])->exists($db)) return;
        $category = (new Query())->from('craft_category')->where(['code' => 'world-shelters'])->one($db);
        if (!$category) {
            $db->createCommand()->insert('craft_category', ['code' => 'world-shelters', 'name' => 'Укрытия', 'description' => 'Переносные места ночлега'])->execute();
            $category = ['id' => (int)$db->getLastInsertID()];
        }
        $db->createCommand()->insert('craft_item', ['code' => self::CODE, 'name' => 'Походный шалаш', 'label' => 'Походный шалаш', 'description' => 'Одно место ночлега. Не защищает станции и сундуки.',
            'category_id' => $category['id'], 'kind' => 'equipment', 'rarity' => 'common', 'icon' => 'tent', 'stack_size' => 1,
            'destroyable' => 0, 'use_xp' => 0, 'gather_quantity' => 0, 'active' => 1, 'storage_kind' => 'none'])->execute();
        $db->createCommand()->update('craft_meta', ['revision' => new Expression('[[revision]]+1')], ['id' => 1])->execute();
    }
}
