<?php

namespace common\services\game;

use common\models\user\User;
use common\modules\games\models\GameDuel;
use common\modules\games\models\GameOrel;
use common\services\user\CreditLedger;
use Yii;
use yii\db\Query;

/** Replenishes open bot games in the existing cron; repeated/concurrent runs share the account lock. */
class GameCreateService
{
    public function execute(): void
    {
        foreach ($this->plan() as $userId => $userPlan) {
            Yii::$app->db->transaction(function () use ($userId, $userPlan): void {
                $ledger = new CreditLedger(Yii::$app->db);
                $person = $ledger->lock('persone', ['user_id' => $userId]);
                if (!$person) return;
                $available = (float)$person['credit'];
                foreach ($userPlan as $kon => $planned) {
                    foreach (['orel', 'duel'] as $kind) {
                        $table = $kind === 'orel' ? GameOrel::tableName() : GameDuel::tableName();
                        $existing = (int)(new Query())->from($table)->where(['user_id' => $userId, 'kon' => $kon, 'user_gamer' => 0])->count('*', Yii::$app->db);
                        $count = min(max(0, $planned - $existing), max(0, (int)floor($available / $kon)));
                        for ($i = 0; $i < $count; $i++) {
                            $values = ['kon' => $kon, 'user_id' => $userId, 'user_gamer' => 0, 'created_at' => time()];
                            if ($kind === 'orel') $values['type'] = (new GameOrel())->getRandomType();
                            else $values += ['u1' => random_int(1, 3), 'b1' => random_int(1, 3), 'u2' => 0, 'b2' => 0];
                            if (Yii::$app->db->createCommand()->insert($table, $values)->execute() !== 1) {
                                throw new \RuntimeException('Could not create bot game.');
                            }
                        }
                        if ($count > 0) {
                            $ledger->change((int)$userId, -$kon * $count, 'game_' . $kind, 'Create game ' . $kind . ' ' . $count . 'x' . $kon);
                            $available -= $kon * $count;
                        }
                    }
                }
            });
        }
    }

    protected function plan(): array
    {
        $user = User::find()->where(['username' => 'bot'])->one();
        return $user ? [(int)$user->id => [1 => 20, 2 => 10, 3 => 5, 5 => 3, 7 => 1]] : [];
    }
}
