<?php
namespace common\modules\world\service;

use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Add definitions to the shared catalogue, never free supplies, prices or a second inventory. */
class CultivationCatalog
{
    public static function seed(Connection $db): void
    {
        $category = (new Query())->from('craft_category')->where(['code' => 'world-cultivation'])->one($db);
        if (!$category) {
            $db->createCommand()->insert('craft_category', ['code' => 'world-cultivation', 'name' => 'Огород', 'description' => 'Семена, вода и урожай'])->execute();
            $category = ['id' => (int)$db->getLastInsertID()];
        }
        $ids = []; $changed = false;
        foreach ([['world-carrot-seed', 'Семена моркови', 'seedling'], ['world-carrot', 'Морковь', 'carrot'], ['water', 'Вода', 'droplet']] as $definition) {
            $item = (new Query())->from('craft_item')->where(['code' => $definition[0]])->one($db);
            if (!$item) {
                $db->createCommand()->insert('craft_item', ['code' => $definition[0], 'name' => $definition[1], 'description' => 'Ресурс выращивания. Источник приобретения и тариф настраиваются отдельно.', 'category_id' => $category['id'], 'kind' => 'material', 'rarity' => 'common', 'icon' => $definition[2], 'stack_size' => 100, 'destroyable' => 1, 'use_xp' => 0, 'gather_quantity' => 0, 'active' => 1, 'storage_kind' => 'none'])->execute();
                $item = ['id' => (int)$db->getLastInsertID()]; $changed = true;
            }
            $ids[] = (int)$item['id'];
        }
        if (!(new Query())->from('world_crop')->where(['code' => 'carrot'])->exists($db)) {
            $db->createCommand()->insert('world_crop', ['code' => 'carrot', 'name' => 'Морковь'])->execute(); $crop = (int)$db->getLastInsertID();
            // A draft does not select unapproved growth rates or activate a free source of seeds.
            $db->createCommand()->insert('world_crop_revision', ['crop_id' => $crop, 'version' => 1, 'status' => 'draft', 'seed_item_id' => $ids[0], 'yield_item_id' => $ids[1], 'water_item_id' => $ids[2], 'seed_quantity' => 0, 'yield_quantity' => 0, 'grow_seconds' => 0, 'water_quantity' => 0, 'water_interval_seconds' => 0, 'config_json' => '{"requires_publication":true}'])->execute();
        }
        if ($changed) $db->createCommand()->update('craft_meta', ['revision' => new Expression('[[revision]]+1')], ['id' => 1])->execute();
    }
}
