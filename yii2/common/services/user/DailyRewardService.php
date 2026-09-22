<?php

namespace common\services\user;

use common\models\history\HistoryBalance;
use common\models\history\HistoryRating;
use common\models\user\Person;
use RuntimeException;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Daily history and its balance change must either both commit or both roll back. */
class DailyRewardService
{
    private $db;

    public function __construct(Connection $db)
    {
        $this->db = $db;
    }

    public function claimCredit(int $userId, float $amount): bool
    {
        return $this->claim($userId, $amount, 'credit');
    }

    public function claimRating(int $userId, float $amount): bool
    {
        return $this->claim($userId, $amount, 'rating');
    }

    public function available(int $userId): array
    {
        $now = time();
        list($start, $end) = PresenceService::dayBounds($now);
        $items = [];
        foreach (['bonus' => HistoryBalance::tableName(), 'rating' => HistoryRating::tableName()] as $kind => $table) {
            $claimed = (new Query())->from($table)->where(['user_id' => $userId, 'type' => 'everyday'])
                ->andWhere(['>=', 'created_at', $start])->andWhere(['<', 'created_at', $end])->exists($this->db);
            if (!$claimed) $items[] = ['id' => $kind];
        }
        return ['items' => $items, 'refresh_at' => $end];
    }

    private function claim(int $userId, float $amount, string $counter): bool
    {
        if ($userId <= 0 || !is_finite($amount) || $amount <= 0) {
            throw new \InvalidArgumentException('Invalid daily reward.');
        }

        return $this->db->transaction(function () use ($userId, $amount, $counter) {
            $person = $this->lockPerson($userId);
            // Calculate the day after obtaining the lock, including requests spanning midnight.
            $now = time();
            list($start, $end) = PresenceService::dayBounds($now);
            $historyTable = $counter === 'credit' ? HistoryBalance::tableName() : HistoryRating::tableName();

            if ((new Query())->from($historyTable)
                ->where(['user_id' => $userId, 'type' => 'everyday'])
                ->andWhere(['>=', 'created_at', $start])
                ->andWhere(['<', 'created_at', $end])->exists($this->db)) {
                return false;
            }

            $history = [
                'user_id' => $userId,
                'type' => 'everyday',
                'comment' => 'everyday',
                'created_at' => $now,
            ];
            if ($counter === 'credit') {
                $history += ['balance' => $person['balance'], 'credit' => (float)$person['credit'] + $amount,
                    'balance_up' => 0, 'credit_up' => $amount];
            } else {
                $history += ['rating' => (float)$person['rating'] + $amount, 'rating_up' => $amount];
            }

            if ($this->db->createCommand()->insert($historyTable, $history)->execute() !== 1) {
                throw new RuntimeException('Failed to record daily reward.');
            }
            $expression = new Expression($this->db->quoteColumnName($counter) . ' + :reward', [':reward' => $amount]);
            if ($this->db->createCommand()->update(Person::tableName(), [$counter => $expression],
                ['id' => $person['id']])->execute() !== 1) {
                throw new RuntimeException('Failed to apply daily reward.');
            }
            return true;
        });
    }

    private function lockPerson(int $userId): array
    {
        $query = (new Query())->from(Person::tableName())->where(['user_id' => $userId]);
        if ($this->db->driverName === 'sqlite') {
            // SQLite has no FOR UPDATE; acquire its write lock before reading reward history.
            $this->db->createCommand('UPDATE ' . $this->db->quoteTableName(Person::tableName()) .
                ' SET [[credit]] = [[credit]] WHERE [[user_id]] = :userId', [':userId' => $userId])->execute();
            $person = $query->one($this->db);
        } elseif (in_array($this->db->driverName, ['pgsql', 'mysql'], true)) {
            $command = $query->createCommand($this->db);
            $person = $this->db->createCommand($command->sql . ' FOR UPDATE', $command->params)->queryOne();
        } else {
            throw new RuntimeException('Unsupported daily reward database.');
        }
        if (!$person) throw new RuntimeException('Person not found.');
        return $person;
    }
}
