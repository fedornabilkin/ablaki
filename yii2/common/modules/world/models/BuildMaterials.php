<?php
namespace common\modules\world\models;

use common\modules\world\modules\craft\models\CraftItem;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\{CanonicalInventory, StorageAccessPolicy, WorldStorage};
use common\modules\world\models\domain\WorldFlags;
use yii\db\Query;

/** Uses the existing inventory; no second item store. Material spending is part of the build transaction. */
final class BuildMaterials
{
    public static function sources(int $user, int $parent): array
    {
        $db = Node::getDb();
        $headers = (new WorldStorage($db, new WorldFlags($db, \Yii::$app->getModule('world'))))->list($user, $parent)['items'];
        $sources = []; foreach ($headers as $header) if (in_array($header['kind'], ['backpack', 'chest', 'stockpile'], true)) $sources[] = (new StorageAccessPolicy($db))->storage($user, $header['id'], false);
        return $sources;
    }
    public static function plan(int $user, int $parent, array $materials): array
    {
        if (!$materials) return ['materials' => []];
        $store = new CraftStorage(Node::getDb()); $inventory = new CanonicalInventory($store); $required = []; $items = [];
        $backpack = $inventory->backpack($user);
        if (!$backpack) throw new \common\modules\world\support\GameError('BACKPACK_UNAVAILABLE', 'Сначала вступите в мир, чтобы получить рюкзак.', 422);
        $definitions = CraftItem::find()->where(['code' => array_keys($materials), 'active' => 1, 'kind' => 'material', 'storage_kind' => 'none'])->indexBy('code')->asArray()->all();
        foreach ($materials as $code => $quantity) {
            $item = $definitions[$code] ?? null;
            if (!$item || $quantity < 1 || $quantity > 10000) throw new \common\modules\world\support\GameError('INVALID_MATERIAL', 'Материал строительства недоступен.', 422);
            $required[$item['id']] = $quantity; $items[$item['id']] = $item;
        }
        return $inventory->planCraft(self::sources($user, $parent), $backpack, $required, $items, reset($items), 0) + ['definitions' => $items];
    }
    public static function consume(int $user, array $materials, string $operation, int $parent): void
    {
        if (!$materials) return;
        $plan = self::plan($user, $parent, $materials); $store = new CraftStorage(Node::getDb()); $store->operationId = $operation; $inventory = new CanonicalInventory($store);
        $inventory->applyCraft($user, $plan, $inventory->backpack($user), $plan['definitions'], reset($plan['definitions']), 'construction.consume');
    }
    public static function refund(int $user, array $materials, string $operation, int $parent): void
    {
        if (!$materials) return;
        $store = new CraftStorage(Node::getDb()); $store->operationId = $operation;
        $inventory = new CanonicalInventory($store); $targets = self::sources($user, $parent);
        ksort($materials);
        foreach ($materials as $code => $quantity) {
            $item = CraftItem::find()->where(['code' => $code])->asArray()->one();
            if (!$item || $quantity < 1 || $quantity > 10000 || $item['storage_kind'] !== 'none' || $item['kind'] !== 'material')
                throw new \common\modules\world\support\GameError('INVALID_MATERIAL', 'Материал строительства недоступен.', 422);
            // Cancellation always loses 30%, including jobs with no recorded work.
            $quantity = intdiv((int)$quantity * 70, 100);
            if (!$quantity) continue;
            foreach ($targets as $target) {
                $plan = $inventory->planCraft([], $target, [], [], $item, $quantity);
                $fits = $quantity - $plan['output_missing_quantity'];
                if ($fits > 0) { $plan = $inventory->planCraft([], $target, [], [], $item, $fits); $inventory->applyCraft($user, $plan, $target, [], $item, 'construction.refund'); $quantity -= $fits; }
                if (!$quantity) break;
            }
            if ($quantity) throw new \common\modules\world\support\GameError('BACKPACK_FULL', 'Освободите место в рюкзаке или складах для возврата материалов.', 422);
        }
    }
}
