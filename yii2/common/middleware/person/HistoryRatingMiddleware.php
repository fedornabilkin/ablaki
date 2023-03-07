<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 13:55
 */

namespace common\middleware\person;

use common\middleware\AbstractHistoryMiddleware;
use common\models\history\HistoryRating;
use yii\db\ActiveRecord;

/**
 * Class HistoryRatingMiddleware
 * @package common\middleware\person
 */
class HistoryRatingMiddleware extends AbstractHistoryMiddleware
{
    /** @var HistoryRating */
    public $model;

    public function getHistoryModel(): ActiveRecord
    {
        return $this->model ?? new HistoryRating();
    }

    public function getHistoryValues(): array
    {
        if(!self::$data->changingRating){
            return [];
        }

        return [
            'user_id' => self::$data->user->user_id,
            'rating' => self::$data->user->rating,
            'rating_up' => self::$data->changingRating,
            'type' => self::$data->historyType,
            'comment' => self::$data->historyComment,
        ];
    }
}
