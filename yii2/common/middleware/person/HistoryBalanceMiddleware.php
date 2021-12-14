<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 14:05
 */

namespace common\middleware\person;

use common\middleware\AbstractHistoryMiddleware;
use common\models\history\HistoryBalance;
use yii\db\ActiveRecord;

/**
 * Class HistoryBalanceMiddleware
 * @package common\middleware\person
 */
class HistoryBalanceMiddleware extends AbstractHistoryMiddleware
{
    /** @var HistoryBalance */
    public $model;

    public function getHistoryModel(): ActiveRecord
    {
        return $this->model ?? new HistoryBalance();
    }

    public function getHistoryValues(): array
    {
        if(!self::$data->changingBalance && !self::$data->changingCredit){
            return [];
        }

        return [
            'user_id' => self::$data->user->user_id,
            'balance' => self::$data->user->balance,
            'credit' => self::$data->user->credit,
            'balance_up' => self::$data->changingBalance,
            'credit_up' => self::$data->changingCredit,
            'type' => self::$data->historyType,
            'comment' => self::$data->historyComment,
        ];
    }
}
