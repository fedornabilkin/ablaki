<?php

namespace common\modules\games\service;

use common\models\history\HistoryBalance;
use common\modules\games\models\GameSaper;
use common\modules\games\models\GameDuel;
use common\modules\games\models\GameFive;
use DateTimeImmutable;
use DateTimeZone;
use yii\db\Expression;

/** Read-only game totals. Money comes from the ledger, never an estimated payout. */
class GameOverview
{
    public static function completed($query, $saper)
    {
        $query->andWhere(['>', 'user_gamer', 0]);
        if (is_a($query->modelClass, GameDuel::class, true)) {
            return $query->andWhere(['u2' => [1, 2, 3]])->andWhere(['b2' => [1, 2, 3]]);
        }
        if (is_a($query->modelClass, GameFive::class, true)) {
            return $query->andWhere(['in', new Expression('TRIM([[status]])'), [GameFive::STATUS_USER, GameFive::STATUS_GAMER]]);
        }
        return $saper
            ? $query->andWhere(['etap' => [GameSaper::GAME_SAPER_ETAP_WIN, GameSaper::GAME_SAPER_ETAP_LOSE]])
            : $query->andWhere(['hod' => [1, 2]]);
    }

    public static function summary($modelClass, $userId, $timezone, $now = null): array
    {
        $saper = is_a($modelClass, GameSaper::class, true);
        $duel = is_a($modelClass, GameDuel::class, true);
        $five = is_a($modelClass, GameFive::class, true);
        $day = (new DateTimeImmutable('@' . ($now === null ? time() : $now)))
            ->setTimezone(new DateTimeZone($timezone))->setTime(0, 0);
        $from = $day->getTimestamp();
        $until = $day->modify('+1 day')->getTimestamp();
        $dateColumn = $saper ? 'time_over_at' : 'updated_at';
        $games = self::completed($modelClass::find(), $saper)
            ->andWhere(['or', ['user_id' => $userId], ['user_gamer' => $userId]])
            ->andWhere(['>=', $dateColumn, $from])->andWhere(['<', $dateColumn, $until]);
        if ($duel) {
            $won = ['and', new Expression('[[u1]] = [[b2]]'), new Expression('[[u2]] <> [[b1]]')];
            $lost = ['and', new Expression('[[u1]] <> [[b2]]'), new Expression('[[u2]] = [[b1]]')];
        } elseif ($five) {
            $won = ['status' => GameFive::STATUS_GAMER];
            $lost = ['status' => GameFive::STATUS_USER];
        } else {
            $won = $saper ? ['etap' => GameSaper::GAME_SAPER_ETAP_WIN] : new Expression('[[type]] = [[hod]]');
            $lost = $saper ? ['etap' => GameSaper::GAME_SAPER_ETAP_LOSE] : new Expression('[[type]] <> [[hod]]');
        }
        $wins = (clone $games)->andWhere(['or',
            ['and', ['user_gamer' => $userId], $won],
            ['and', ['user_id' => $userId], $lost],
        ]);
        $own = $modelClass::find()->andWhere(['user_id' => $userId, 'user_gamer' => 0]);
        if ($five) $own->andWhere(['status' => GameFive::STATUS_FREE]);
        $ledger = HistoryBalance::find()->andWhere([
            'user_id' => $userId, 'type' => $saper ? 'game_saper' : ($duel ? 'game_duel' : ($five ? 'game_five' : 'game_orel')),
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

    /** State returned with a successful game command so the page needs no follow-up GETs. */
    public static function snapshot($modelClass, $userId, $timezone): array
    {
        $saper = is_a($modelClass, GameSaper::class, true);
        $dateColumn = $saper ? 'time_over_at' : 'updated_at';
        $recent = self::completed($modelClass::find(), $saper)
            ->with(['user.person', 'userGamer.person'])
            ->orderBy([$dateColumn => SORT_DESC, 'id' => SORT_DESC])
            ->limit(5)->all();

        return [
            'summary' => self::summary($modelClass, $userId, $timezone),
            'recent' => array_map(static function ($game) { return $game->toArray(); }, $recent),
        ];
    }
}
