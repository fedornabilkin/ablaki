<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 22:30
 */

namespace common\models\history;

use common\models\core\UserQueryTrait;
use yii\db\ActiveQuery;

class HistoryRatingQuery extends ActiveQuery
{
    use UserQueryTrait;
}
