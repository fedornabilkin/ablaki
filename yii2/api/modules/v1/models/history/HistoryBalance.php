<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 22:18
 */

namespace api\modules\v1\models\history;

class HistoryBalance extends \common\models\history\HistoryBalance
{
    public function fields(): array
    {
        $parents = parent::fields();

        $fields = [
            'id',
            'user_id',
            'balance',
            'balance_up',
            'credit',
            'credit_up',
            'created_at',
        ];

        $fields['type'] = $parents['type'];
        $fields['comment'] = $parents['comment'];

        return $fields;
    }
}
