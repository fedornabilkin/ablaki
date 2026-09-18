<?php

namespace common\services\user;

use common\models\Commission;
use common\models\user\Person;
use yii\db\Connection;
use yii\db\Query;

/** Read-only estimate; never changes the fixed daily reward or user balances. */
class PrizeFundService
{
    private $db;

    public function __construct(Connection $db) { $this->db = $db; }

    public function summary(int $userId = null, int $now = null): array
    {
        $now = $now ?? time();
        list($start, $end) = PresenceService::dayBounds($now);
        $today = $this->sum($start - 86400, $start);
        $tomorrow = $this->sum($start, min($end, $now + 1));
        $totalRating = (float)(new Query())->from(Person::tableName())->where(['>', 'rating', 0])->sum('rating', $this->db);
        $rating = $userId === null ? 0 : max(0, (float)(new Query())->from(Person::tableName())->where(['user_id' => $userId])->select('rating')->scalar($this->db));
        return [
            'date' => (new \DateTimeImmutable('@' . $start))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d'),
            'timezone' => 'Europe/Moscow', 'today' => $today, 'tomorrow' => $tomorrow,
            'user_today' => $userId === null ? null : ($totalRating > 0 ? ceil($rating * $today / $totalRating) : 0),
            'user_tomorrow' => $userId === null ? null : ($totalRating > 0 ? ceil($rating * $tomorrow / $totalRating) : 0),
        ];
    }

    private function sum(int $start, int $end): float
    {
        // Exchange commissions mix kg and credits in one type, so cannot join a credit fund.
        $amount = (float)(new Query())->from(Commission::tableName())
            ->where(['type' => ['credit', 'game_orel', 'game_duel', 'game_five']])
            ->andWhere(['>', 'amount', 0])->andWhere(['>=', 'created_at', $start])
            ->andWhere(['<', 'created_at', $end])->sum('amount', $this->db);
        return round($amount, 2);
    }
}
