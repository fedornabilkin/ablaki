<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 22:33
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractCreateMiddleware;
use common\modules\exchange\api\models\CreditExchange;
use yii\base\UserException;

class CreateMiddleware extends AbstractCreateMiddleware
{
    /** @var CreditExchange */
    protected $model;

    public function check(): bool
    {
        $this->model = self::$data->getModel();

        $this->updateData();
        if (!$this->create()) {
            throw new UserException();
        }

        return parent::check();
    }

    public function updateData(): void
    {
        $money = 0;
        if ($this->model->isBuy()) {
            self::$data->changingCredit = 0 - $this->model->credit * $this->getCount();
            $money = $this->model->credit;
        }

        if ($this->model->isSell()) {
            self::$data->changingBalance = 0 - $this->model->amount * $this->getCount();
            $money = $this->model->amount;
        }

        self::$data->historyComment = 'Create ' . $this->getCount() . 'x' . $money;
        self::$data->historyType = $this->model->getHistoryType();
    }

    public function getRow(): array
    {
        return [
            'type' => $this->model->type,
            'amount' => $this->model->amount,
            'credit' => $this->model->credit,
            'user_id' => self::$data->user->user->id,
            'created_at' => time(),
        ];
    }

    public function getCount(): int
    {
        return $this->model->count;
    }

    protected function getTableName(): string
    {
        return $this->model::tableName();
    }
}
