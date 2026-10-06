<?php
namespace common\modules\world\models;

use common\modules\world\modules\craft\models\CraftItem;
use common\modules\world\modules\craft\models\domain\CraftStorage;

/** Uses the existing inventory; no second item store. Material spending is part of the build transaction. */
final class BuildMaterials
{
    public static function consume(int $user, array $materials, string $operation): void { self::change($user, $materials, $operation, -1); }
    public static function refund(int $user, array $materials, string $operation): void { self::change($user, $materials, $operation, 1); }
    private static function change(int $user, array $materials, string $operation, int $direction): void
    {
        $store = new CraftStorage(Node::getDb()); $store->operationId = $operation;
        ksort($materials);
        foreach ($materials as $code => $quantity) {
            $item = CraftItem::find()->where(['code' => $code])->asArray()->one();
            if (!$item || $quantity < 1 || $quantity > 10000 || $item['storage_kind'] !== 'none' || $item['kind'] !== 'material')
                throw new \common\modules\world\support\GameError('INVALID_MATERIAL', 'Материал строительства недоступен.', 422);
            $store->move($user, $item, $direction * $quantity);
        }
    }
}
