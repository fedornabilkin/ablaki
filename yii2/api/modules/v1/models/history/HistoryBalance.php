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
        return [
            'id',
            'user_id',
            'balance',
            'balance_up',
            'credit_up',
            'type' => static function (self $model) {
                return trim($model->type);
            },
            'comment' => static function (self $model) {
                return trim($model->comment);
            },
            'created_at',
        ];
    }
}
