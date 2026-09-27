<?php

namespace common\modules\games\service;

use common\models\user\Person;
use common\services\user\CreditLedger;
use Yii;
use yii\web\ConflictHttpException;

/** Serializes legacy middleware with cancellation and other players. */
class GameParticipation
{
    public static function run(string $table, int $id, Person $person, callable $play)
    {
        return Yii::$app->db->transaction(function () use ($table, $id, $person, $play) {
            \common\modules\economy\service\WalletMaintenance::writable(Yii::$app->db);
            $ledger = new CreditLedger(Yii::$app->db);
            $game = $ledger->lock($table, ['id' => $id]);
            if (!$game) throw new ConflictHttpException('Игра уже удалена. Обновите список.');
            $ids = array_unique([(int)$game['user_id'], (int)$person->user_id]);
            sort($ids, SORT_NUMERIC);
            foreach ($ids as $userId) {
                $account = $ledger->lock('persone', ['user_id' => $userId]);
                if (!$account) throw new \RuntimeException('Player unavailable.');
                if ($userId === (int)$person->user_id) $person::populateRecord($person, $account);
            }
            return $play($game);
        });
    }
}
