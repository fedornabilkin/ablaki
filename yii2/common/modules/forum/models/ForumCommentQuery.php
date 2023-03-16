<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 16.03.2023
 * Time: 20:21
 */

namespace common\modules\forum\models;

use common\models\core\UserQueryTrait;
use yii\db\ActiveQuery;

class ForumCommentQuery extends ActiveQuery
{
    use UserQueryTrait;
}
