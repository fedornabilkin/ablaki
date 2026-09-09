<?php

namespace common\modules\craft\models;

use common\models\core\UserQueryTrait;
use yii\db\ActiveQuery;

/**
 * This is the ActiveQuery class for [[CraftInventory]].
 *
 * @see CraftInventory
 */
class CraftInventoryQuery extends ActiveQuery
{
    use UserQueryTrait;
}
