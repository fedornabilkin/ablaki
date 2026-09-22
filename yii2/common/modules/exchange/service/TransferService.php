<?php

namespace common\modules\exchange\service;

use common\helpers\App;
use common\modules\exchange\models\CreditTransfer;
use common\services\user\CreditLedger;
use Yii;
use yii\web\UnprocessableEntityHttpException;

class TransferService
{
    public function create(CreditTransfer $model): array
    {
        $amount = $model->amount;
        $count = $model->count ?? 1;
        if (!is_numeric($amount) || !is_finite((float)$amount) || $amount < 1 || $amount > 1000000000
            || floor((float)$amount) !== (float)$amount
            || filter_var($count, FILTER_VALIDATE_INT) === false || $count < 1 || $count > 100) {
            throw new UnprocessableEntityHttpException('Укажите целое количество кредитов и количество переводов от 1 до 100.');
        }
        $userId = (int)App::user()->id;
        return Yii::$app->db->transaction(function () use ($amount, $count, $userId): array {
            $ledger = new CreditLedger(Yii::$app->db);
            $sender = $ledger->lock('persone', ['user_id' => $userId]);
            if (!$sender || !is_numeric($sender['credit']) || !is_finite((float)$sender['credit'])) throw new \RuntimeException('Account unavailable.');
            $created = min((int)$count, (int)floor((float)$sender['credit'] / (float)$amount));
            if ($created < 1) throw new UnprocessableEntityHttpException('Недостаточно кредитов даже для одного перевода.');
            $total = (float)$amount * $created;
            $ledger->change($userId, -$total, 'transfer', 'Create ' . $created . 'x' . $amount);
            for ($i = 0; $i < $created; $i++) {
                $written = Yii::$app->db->createCommand()->insert(CreditTransfer::tableName(), [
                    'user_id' => $userId, 'user_buyer' => 0, 'amount' => $amount,
                    'password' => Yii::$app->security->generateRandomString(32), 'created_at' => time(), 'updated_at' => time(),
                ])->execute();
                if ($written !== 1) throw new \RuntimeException('Could not create transfer.');
            }
            return ['created' => $created, 'requested' => (int)$count, 'amount' => (int)$amount, 'total' => $total];
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
            $accounts = [(int)$row['user_id'], $userId];
            sort($accounts, SORT_NUMERIC);
            $sender = $recipient = null;
            foreach ($accounts as $accountId) {
                $account = $ledger->lock('persone', ['user_id' => $accountId]);
                if (!$account) throw new \RuntimeException('Account unavailable.');
                if ($accountId === (int)$row['user_id']) $sender = $account;
                else $recipient = $account;
            }
            if (Yii::$app->db->createCommand()->update(CreditTransfer::tableName(), [
                'user_buyer' => $userId, 'updated_at' => time(),
            ], ['id' => $row['id'], 'user_buyer' => 0])->execute() !== 1) {
                throw new UnprocessableEntityHttpException('Перевод уже получен.');
            }
            $ledger->change($userId, (float)$row['amount'], 'transfer', 'Confirm #' . $row['id']);
            $rating = (float)$sender['rating'];
            // Eligibility is decided under both account locks, at receipt time.
            $recipientRating = (float)$recipient['rating'];
            if (!is_finite($rating) || !is_finite($recipientRating)) throw new \RuntimeException('Invalid rating.');
            if ($rating - $recipientRating < 50) return;
            if ((new \yii\db\Query())->from(CreditTransfer::tableName())
                ->where(['user_buyer' => (int)$row['user_id']])->andWhere(['>=', 'updated_at', time() - 7 * 86400])
                ->exists(Yii::$app->db)) return;
            // Same stake/current-rating formula as the credit games; reward only a received transfer.
            $denominator = $rating + ($rating < 0.99 ? 1.9 : 0);
            if (!is_finite($denominator) || $denominator <= 0) throw new \RuntimeException('Invalid rating.');
            $reward = round(((float)$row['amount'] / 50) / $denominator, 5);
            if (!is_finite($reward) || $reward < 0) throw new \RuntimeException('Invalid rating.');
            if ($reward === 0.0) return;
            if (Yii::$app->db->createCommand()->update('persone', ['rating' => $rating + $reward], ['user_id' => $row['user_id']])->execute() !== 1) {
                throw new \RuntimeException('Could not update rating.');
            }
            if (Yii::$app->db->createCommand()->insert('history_rating', [
                'user_id' => $row['user_id'], 'rating' => $rating + $reward, 'rating_up' => $reward,
                'type' => 'transfer', 'comment' => 'Received transfer #' . $row['id'], 'created_at' => time(),
            ])->execute() !== 1) throw new \RuntimeException('Could not record rating.');
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
