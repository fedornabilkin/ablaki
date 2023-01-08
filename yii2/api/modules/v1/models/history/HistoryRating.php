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
        return [
            'id',
            'user_id',
            'rating',
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
