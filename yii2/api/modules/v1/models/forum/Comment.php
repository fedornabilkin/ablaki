<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 17.02.2023
 * Time: 21:13
 */

namespace api\modules\v1\models\forum;

use common\modules\forum\models\ForumComment;

class Comment extends ForumComment
{
    public function fields()
    {
        $fields = parent::fields();

        $fields['comment'] = static function ($model) {
            return trim($model->comment);
        };

        return $fields;
    }
}
