<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:13
 */

namespace api\modules\v1\models\forum;

use api\modules\v1\models\User;
use common\modules\forum\models\ForumComment;
use yii\db\ActiveQuery;

class Comment extends ForumComment
{
    public function extraFields(): array
    {
        return ['theme', 'user'];
    }

    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
