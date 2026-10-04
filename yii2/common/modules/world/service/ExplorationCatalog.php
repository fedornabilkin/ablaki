<?php
namespace common\modules\world\service;

use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Additive content: existing administrator definitions are never overwritten. */
class ExplorationCatalog
{
    public const ELIXIR = 'world-explorer-elixir';
    public static function seed(Connection $db): void
    {
        $category = (new Query())->from('craft_category')->where(['code' => 'classic-alchemy'])->one($db);
        if (!$category) {
            $db->createCommand()->insert('craft_category', ['code' => 'classic-alchemy', 'name' => 'Алхимия', 'description' => 'Зелья и эликсиры'])->execute();
            $category = ['id' => (int)$db->getLastInsertID()];
        }
        $ids = []; $changed = false;
        foreach ([['classic-bottle', 'Бутылка', 'product', 0], ['world-magic-herb', 'Волшебные травы', 'material', 3], [self::ELIXIR, 'Эликсир исследователя', 'consumable', 0]] as $spec) {
            $item = (new Query())->from('craft_item')->where(['code' => $spec[0]])->one($db);
            if (!$item) $item = (new Query())->from('craft_item')->where(['name' => $spec[1]])->one($db);
            if (!$item) {
                $row = ['code' => $spec[0], 'name' => $spec[1], 'label' => $spec[1], 'description' => $spec[0] === self::ELIXIR ? 'Однократно исследует одну ячейку своей стоянки или её потомка без требуемого уровня исследователя.' : 'Материал для изготовления эликсира исследователя.', 'category_id' => $category['id'], 'kind' => $spec[2], 'rarity' => 'common', 'icon' => $spec[2] === 'material' ? 'leaf' : 'flask', 'stack_size' => 100, 'destroyable' => 1, 'use_xp' => 0, 'gather_quantity' => $spec[3], 'active' => 1, 'storage_kind' => 'none'];
                $db->createCommand()->insert('craft_item', array_intersect_key($row, $db->schema->getTableSchema('craft_item')->columns))->execute();
                $item = ['id' => (int)$db->getLastInsertID()]; $changed = true;
            }
            $ids[$spec[0]] = (int)$item['id'];
        }
        if (!(new Query())->from('craft_recipe')->where(['code' => self::ELIXIR])->exists($db)) {
            $db->createCommand()->insert('craft_recipe', ['code' => self::ELIXIR, 'name' => 'Создать: Эликсир исследователя', 'description' => 'Бутылка и три волшебные травы. Расходуется при исследовании ячейки.', 'category_id' => $category['id'], 'item_id' => $ids[self::ELIXIR], 'output_quantity' => 1, 'cost_credits' => 0, 'experience' => 20, 'min_level' => 1, 'active' => 1])->execute();
            $recipe = (int)$db->getLastInsertID();
            foreach (['classic-bottle' => 1, 'world-magic-herb' => 3] as $code => $quantity)
                $db->createCommand()->insert('craft_recipe_item', ['recipe_id' => $recipe, 'item_id' => $ids[$code], 'item_quantity' => $quantity])->execute();
            $changed = true;
        }
        if ($changed) $db->createCommand()->update('craft_meta', ['revision' => new Expression('[[revision]]+1')], ['id' => 1])->execute();
        if (!(new Query())->from('profession')->where(['code' => 'explorer'])->exists($db)) {
            $db->createCommand()->insert('profession', ['code' => 'explorer', 'name' => 'Исследователь', 'status' => 'published'])->execute();
            $profession = (int)$db->getLastInsertID();
            $db->createCommand()->insert('profession_revision', ['profession_id' => $profession, 'version' => 1, 'status' => 'published', 'max_level' => 3, 'config_json' => '{"sources":{"world.map.explore":{"xp":10,"daily_max":1000}}}', 'published_at' => time()])->execute();
            $revision = (int)$db->getLastInsertID();
            foreach ([0, 30, 100] as $index => $xp) $db->createCommand()->insert('profession_level', ['revision_id' => $revision, 'level' => $index + 1, 'required_xp' => $xp, 'limits_json' => '{}'])->execute();
            $db->createCommand()->insert('profession_current', ['profession_id' => $profession, 'revision_id' => $revision])->execute();
        }
    }
}
