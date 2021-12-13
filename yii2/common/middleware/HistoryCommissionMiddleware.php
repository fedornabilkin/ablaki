<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 14:33
 */

namespace common\middleware;

use common\models\Commission;
use yii\db\ActiveRecord;

class HistoryCommissionMiddleware extends AbstractHistoryMiddleware
{
    /** @var Commission */
    public $model;

    public function getHistoryModel(): ActiveRecord
    {
        return $this->model ?? new Commission();
    }

    public function getHistoryValues(): array
    {
        if (!self::$data->commissionAmount) {
            return [];
        }
        return [
            'amount' => self::$data->commissionAmount,
            'type' => self::$data->historyType,
        ];
    }
}
