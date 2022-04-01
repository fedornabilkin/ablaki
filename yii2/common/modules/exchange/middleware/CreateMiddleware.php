<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 22:33
 */

namespace common\modules\exchange\middleware;

use common\middleware\AbstractCreateMiddleware;
use common\modules\exchange\api\requests\CreateRequest;
use yii\base\UserException;

class CreateMiddleware extends AbstractCreateMiddleware
{
    /** @var CreateRequest */
    protected $request;

    public function check(): bool
    {
        $this->request = self::$data->getRequest();
        $this->updateData();
        if (!$this->create()) {
            throw new UserException();
        }

        return parent::check();
    }

    public function updateData()
    {
//        self::$data->changingCredit = 0 - $this->request->credit * $this->getCount();
//        self::$data->changingBalance = 0 - $this->request->amount * $this->getCount();


//        self::$data->historyType = self::$data->getHistoryType();
//        self::$data->historyComment = 'Create ' . $this->getCount() . 'x' . $this->request->amount;
    }

    public function getRow(): array
    {
        return [
            'type' => $this->request->type,
            'amount' => $this->request->amount,
            'credit' => $this->request->credit,
            'user_id' => self::$data->user->user->id,
        ];
    }

    public function getCount(): int
    {
        return $this->request->count;
    }

    protected function getTableName(): string
    {
        return 'credit_exchange';
    }
}
