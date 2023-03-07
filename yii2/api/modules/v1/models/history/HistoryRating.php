<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 22:28
 */

namespace api\modules\v1\models\history;

class HistoryRating extends \common\models\history\HistoryRating
{
    public function fields(): array
    {
        $parents = parent::fields();

        $fields = [
            'id',
            'user_id',
            'rating',
            'rating_up',
            'created_at',
        ];

        $fields['type'] = $parents['type'];
        $fields['comment'] = $parents['comment'];

        return $fields;
    }
}
