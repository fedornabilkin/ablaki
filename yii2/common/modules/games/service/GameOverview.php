<?php

namespace common\modules\games\service;

use common\models\history\HistoryBalance;
use common\modules\games\models\GameSaper;
use DateTimeImmutable;
use DateTimeZone;
use yii\db\Expression;

/** Read-only game totals. Money comes from the ledger, never an estimated payout. */
class GameOverview
{
    public static function completed($query, $saper)
    {
        $query->andWhere(['>', 'user_gamer', 0]);
        return $saper
            ? $query->andWhere(['etap' => [GameSaper::GAME_SAPER_ETAP_WIN, GameSaper::GAME_SAPER_ETAP_LOSE]])
            : $query->andWhere(['hod' => [1, 2]]);
    }

    public static function summary($modelClass, $userId, $timezone, $now = null): array
    {
        $saper = is_a($modelClass, GameSaper::class, true);
        $day = (new DateTimeImmutable('@' . ($now === null ? time() : $now)))
            ->setTimezone(new DateTimeZone($timezone))->setTime(0, 0);
        $from = $day->getTimestamp();
        $until = $day->modify('+1 day')->getTimestamp();
        $dateColumn = $saper ? 'time_over_at' : 'updated_at';
        $games = self::completed($modelClass::find(), $saper)
            ->andWhere(['or', ['user_id' => $userId], ['user_gamer' => $userId]])
            ->andWhere(['>=', $dateColumn, $from])->andWhere(['<', $dateColumn, $until]);
        $won = $saper ? ['etap' => GameSaper::GAME_SAPER_ETAP_WIN] : new Expression('[[type]] = [[hod]]');
        $lost = $saper ? ['etap' => GameSaper::GAME_SAPER_ETAP_LOSE] : new Expression('[[type]] <> [[hod]]');
        $wins = (clone $games)->andWhere(['or',
            ['and', ['user_gamer' => $userId], $won],
            ['and', ['user_id' => $userId], $lost],
        ]);
        $own = $modelClass::find()->andWhere(['user_id' => $userId, 'user_gamer' => 0]);
        $ledger = HistoryBalance::find()->andWhere([
            'user_id' => $userId, 'type' => $saper ? 'game_saper' : 'game_orel',
        ])->andWhere(['>=', 'created_at', $from])->andWhere(['<', 'created_at', $until]);

        return [
            'today' => [
                'played' => (int)(clone $games)->count(),
                'wins' => (int)$wins->count(),
                'balance' => (float)$ledger->sum($saper ? 'balance_up' : 'credit_up'),
                'date' => $day->format('Y-m-d'),
                'timezone' => $timezone,
            ],
            'own' => ['count' => (int)(clone $own)->count(), 'amount' => (float)$own->sum('kon')],
        ];
    }
}
