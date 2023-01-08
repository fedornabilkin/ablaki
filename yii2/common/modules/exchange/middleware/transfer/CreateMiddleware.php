<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 22:33
 */

namespace common\modules\exchange\middleware\transfer;

use common\middleware\AbstractCreateMiddleware;
use common\modules\exchange\models\CreditTransfer;
use Yii;
use yii\base\UserException;

class CreateMiddleware extends AbstractCreateMiddleware
{
    /** @var CreditTransfer */
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
        self::$data->changingCredit = 0 - $this->amount() * $this->count();
        self::$data->historyComment = 'Create ' . $this->count() . 'x' . $this->amount();
        self::$data->historyType = $this->model->getHistoryType();
    }

    public function getRow(): array
    {
        return [
            'amount' => $this->model->amount,
            'user_id' => self::$data->user->user->id,
            'password' => Yii::$app->security->generateRandomString(8),
            'created_at' => time(),
        ];
    }

    public function count(): int
    {
        return $this->model->count;
    }

    public function amount(): int
    {
        return $this->model->amount;
    }

    protected function getTableName(): string
    {
        return $this->model::tableName();
    }
}
