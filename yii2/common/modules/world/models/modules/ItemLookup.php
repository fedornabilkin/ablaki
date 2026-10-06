<?php
namespace common\modules\world\models\modules;

use common\modules\world\modules\craft\models\CraftItem;

/** Catalog validation without coupling object templates to inventory operations. */
final class ItemLookup
{
    public static function exists(string $code): bool { return CraftItem::find()->where(['code' => $code, 'active' => 1])->exists(); }
}
