<?php

namespace common\modules\exchange\service;

use common\helpers\App;
use common\modules\exchange\models\CreditTransfer;
use common\services\user\CreditLedger;
use Yii;
use yii\web\UnprocessableEntityHttpException;

class TransferService
{
    public function create(CreditTransfer $model): void
    {
        $amount = $model->amount;
        $count = $model->count ?? 1;
        if (!is_numeric($amount) || !is_finite((float)$amount) || $amount < 1 || $amount > 1000000000
            || floor((float)$amount) !== (float)$amount
            || filter_var($count, FILTER_VALIDATE_INT) === false || $count < 1 || $count > 100) {
            throw new UnprocessableEntityHttpException('Укажите целое количество кредитов и количество переводов от 1 до 100.');
        }
        $userId = (int)App::user()->id;
        Yii::$app->db->transaction(function () use ($amount, $count, $userId): void {
            $ledger = new CreditLedger(Yii::$app->db);
            $ledger->change($userId, -round((float)$amount * $count, 2), 'transfer', 'Create ' . $count . 'x' . $amount);
            for ($i = 0; $i < $count; $i++) {
                $written = Yii::$app->db->createCommand()->insert(CreditTransfer::tableName(), [
                    'user_id' => $userId, 'user_buyer' => 0, 'amount' => $amount,
                    'password' => Yii::$app->security->generateRandomString(32), 'created_at' => time(), 'updated_at' => time(),
                ])->execute();
                if ($written !== 1) throw new \RuntimeException('Could not create transfer.');
            }
        });
    }

    public function confirm(CreditTransfer $model, string $password = ''): void
    {
        $userId = (int)App::user()->id;
        Yii::$app->db->transaction(function () use ($model, $password, $userId): void {
            $ledger = new CreditLedger(Yii::$app->db);
            $row = $ledger->lock(CreditTransfer::tableName(), ['id' => $model->id]);
            if (!$row || (int)$row['user_buyer'] !== 0 || (int)$row['user_id'] === $userId
                || trim($password) === '' || !hash_equals(trim((string)$row['password']), trim($password))) {
                throw new UnprocessableEntityHttpException('Перевод недоступен или хэш указан неверно.');
            }
            $this->checkAmount($row);
            if (Yii::$app->db->createCommand()->update(CreditTransfer::tableName(), [
                'user_buyer' => $userId, 'updated_at' => time(),
            ], ['id' => $row['id'], 'user_buyer' => 0])->execute() !== 1) {
                throw new UnprocessableEntityHttpException('Перевод уже получен.');
            }
            $ledger->change($userId, (float)$row['amount'], 'transfer', 'Confirm #' . $row['id']);
        });
        $model->refresh();
    }

    public function delete(CreditTransfer $model): void
    {
        $userId = (int)App::user()->id;
        Yii::$app->db->transaction(function () use ($model, $userId): void {
            $ledger = new CreditLedger(Yii::$app->db);
            $row = $ledger->lock(CreditTransfer::tableName(), ['id' => $model->id]);
            if (!$row || (int)$row['user_buyer'] !== 0 || (int)$row['user_id'] !== $userId) {
                throw new UnprocessableEntityHttpException('Перевод недоступен для отмены.');
            }
            $this->checkAmount($row);
            if (Yii::$app->db->createCommand()->delete(CreditTransfer::tableName(), ['id' => $row['id'], 'user_buyer' => 0])->execute() !== 1) {
                throw new UnprocessableEntityHttpException('Перевод уже обработан.');
            }
            $ledger->change($userId, (float)$row['amount'], 'transfer', 'Delete #' . $row['id']);
        });
    }

    private function checkAmount(array $row): void
    {
        if (!is_numeric($row['amount']) || !is_finite((float)$row['amount']) || $row['amount'] <= 0) {
            throw new UnprocessableEntityHttpException('Некорректная сумма перевода.');
        }
    }
}
