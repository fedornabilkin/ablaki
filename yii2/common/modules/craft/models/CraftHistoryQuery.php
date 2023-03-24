<?php

namespace common\modules\craft\models;

use common\models\core\UserQueryTrait;
use yii\db\ActiveQuery;

/**
 * This is the ActiveQuery class for [[CraftHistory]].
 *
 * @see CraftHistory
 */
class CraftHistoryQuery extends ActiveQuery
{
    use UserQueryTrait;
}
