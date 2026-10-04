<?php
namespace common\modules\world\service;

use common\modules\craft\service\CanonicalInventory;
use common\modules\craft\service\CraftStorage;
use common\modules\craft\service\EquipmentExposure;
use common\modules\craft\service\ProductionReservations;
use common\modules\craft\service\StorageAccessPolicy;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

class GardenTools
{
    public static function seed(Connection $db): void
    {
        $item = (new Query())->from('craft_item')->where(['code' => 'world-shovel'])->one($db);
        if ($item) return;
        $category = (new Query())->select('id')->from('craft_category')->where(['code' => 'world-cultivation'])->scalar($db);
        $db->createCommand()->insert('craft_item', ['code' => 'world-shovel', 'name' => 'Огородная лопата', 'label' => 'Лопата', 'description' => 'Для вскапывания грядок. Одно вскапывание расходует 1 прочности.', 'category_id' => $category, 'kind' => 'tool', 'rarity' => 'common', 'icon' => 'hammer', 'stack_size' => 10, 'active' => 1, 'destroyable' => 1, 'use_xp' => 0, 'gather_quantity' => 0, 'storage_kind' => 'none'])->execute();
        $itemId = (int)$db->getLastInsertID();
        $materials = (new Query())->from('craft_item')->where(['code' => ['classic-plank', 'classic-stone'], 'active' => 1])->indexBy('code')->all($db);
        if (count($materials) === 2) {
            $db->createCommand()->insert('craft_recipe', ['code' => 'world-shovel', 'name' => 'Создать: огородная лопата', 'description' => 'Две доски и один камень.', 'category_id' => $category, 'item_id' => $itemId, 'active' => 1, 'output_quantity' => 1, 'cost_credits' => 0, 'experience' => 20, 'min_level' => 1])->execute();
            $recipe = (int)$db->getLastInsertID();
            foreach (['classic-plank' => 2, 'classic-stone' => 1] as $code => $quantity) $db->createCommand()->insert('craft_recipe_item', ['recipe_id' => $recipe, 'item_id' => $materials[$code]['id'], 'item_quantity' => $quantity])->execute();
        }
        $db->createCommand()->update('craft_meta', ['revision' => new Expression('[[revision]]+1')], ['id' => 1])->execute();
    }
    public static function shovel(Connection $db, int $user): array
    {
        $backpack = (new CanonicalInventory(new CraftStorage($db)))->backpack($user);
        $result = ['instance_id' => null, 'name' => 'Лопата', 'durability' => 0, 'wear' => 1, 'available' => false]; $revisions = [];
        if (!$backpack) return compact('result', 'revisions');
        $backpack = (new StorageAccessPolicy($db))->storage($user, (int)$backpack['id']);
        $revisions['storage:' . $backpack['id']] = (int)$backpack['revision'];
        $units = (new Query())->select('e.*')->from(['e' => 'craft_equipment_instance'])->innerJoin(['i' => 'craft_inventory'], '[[i.id]]=[[e.inventory_id]]')->innerJoin(['d' => 'craft_item'], '[[d.id]]=[[i.item_id]]')
            ->where(['i.user_id' => $user, 'i.storage_id' => $backpack['id'], 'e.status' => 'active', 'd.active' => 1, 'd.code' => ['world-shovel', 'classic-shovel', 'shovel']])
            ->andWhere(['between', 'i.slot', 1, (int)$backpack['capacity']])->andWhere(['>', 'i.item_quantity', 0])->orderBy(['e.id' => SORT_ASC])->all($db);
        foreach ((new EquipmentExposure($db))->projectedBatch((new ProductionReservations($db))->freeEquipment($units)) as $unit) {
            if ((int)$unit['durability'] < 1) continue;
            $result = ['instance_id' => (int)$unit['id'], 'name' => 'Лопата', 'durability' => (int)$unit['durability'], 'wear' => 1, 'available' => true];
            $revisions['instance:' . $unit['id']] = (int)$unit['revision']; break;
        }
        return compact('result', 'revisions');
    }
}
