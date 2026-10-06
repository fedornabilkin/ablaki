<?php
namespace common\modules\world\models\migration;

use yii\db\Connection;
use yii\db\Query;

final class SimpleContent
{
    public static function seed(Connection $db): void
    {
        $seed = (new Query())->from('craft_item')->where(['code' => 'world-carrot-seed'])->one($db);
        $carrot = (new Query())->from('craft_item')->where(['code' => 'world-carrot'])->one($db);
        $water = (new Query())->from('craft_item')->where(['code' => 'classic-water'])->one($db);
        if (!$seed || !$carrot || !$water) return; // WorldSeeder installs the shared catalogue first.
        if (!(new Query())->from('world_crop')->where(['code' => 'starter-carrot'])->exists($db)) {
            $db->createCommand()->insert('world_crop', ['code' => 'starter-carrot', 'name' => 'Морковь для начинающих'])->execute(); $id = (int)$db->getLastInsertID();
            $db->createCommand()->insert('world_crop_revision', ['crop_id' => $id, 'version' => 1, 'status' => 'published',
                'seed_item_id' => $seed['id'], 'yield_item_id' => $carrot['id'], 'water_item_id' => $water['id'],
                'seed_quantity' => 1, 'yield_quantity' => 4, 'water_quantity' => 1, 'grow_seconds' => 300,
                'water_interval_seconds' => 120, 'water_window_seconds' => 60, 'harvest_window_seconds' => 86400,
                'config_json' => '{}', 'published_at' => time()])->execute();
        }
        if (!(new Query())->from('craft_recipe')->where(['code' => 'starter-carrot-seeds'])->exists($db)) {
            $db->createCommand()->insert('craft_recipe', ['code' => 'starter-carrot-seeds', 'name' => 'Получить семена моркови',
                'description' => 'Оставьте часть урожая для следующего посева.', 'category_id' => $seed['category_id'], 'item_id' => $seed['id'],
                'output_quantity' => 2, 'cost_credits' => 0, 'experience' => 1, 'min_level' => 1, 'active' => 1])->execute();
            $recipe = (int)$db->getLastInsertID();
            $db->createCommand()->insert('craft_recipe_item', ['recipe_id' => $recipe, 'item_id' => $carrot['id'], 'item_quantity' => 1])->execute();
        }
    }
}
