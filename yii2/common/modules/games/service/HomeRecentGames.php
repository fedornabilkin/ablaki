<?php

namespace common\modules\games\service;

use common\modules\games\models\GameOrel;
use common\modules\games\models\GameSaper;
use common\modules\games\models\GameDuel;
use common\modules\games\models\GameFive;
use common\services\user\PublicProfile;
use yii\db\Expression;
use yii\web\BadRequestHttpException;

class HomeRecentGames
{
    public static function forKind($kind): array
    {
        $classes = ['orel' => GameOrel::class, 'saper' => GameSaper::class, 'duel' => GameDuel::class, 'five' => GameFive::class];
        if (!is_string($kind) || !isset($classes[$kind])) throw new BadRequestHttpException('Unknown game kind.');
        $query = $classes[$kind]::find()->andWhere(['>', 'user_gamer', 0]);
        if ($kind === 'orel' || $kind === 'saper') GameOverview::completed($query, $kind === 'saper');
        elseif ($kind === 'five') $query->andWhere(['in', new Expression('TRIM([[status]])'), ['user', 'gamer']]);
        else $query->andWhere(['u2' => [1, 2, 3]])->andWhere(['b2' => [1, 2, 3]]);
        $date = $kind === 'saper' ? 'time_over_at' : 'updated_at';
        $rows = $query->andWhere(['>', $date, 0])->orderBy([$date => SORT_DESC, 'id' => SORT_DESC])->limit(3)->with(['user.person', 'userGamer.person'])->all();
        return array_map(static function ($game) use ($kind, $date) {
            if ($kind === 'orel') $winner = $game->isWin() ? 'player' : 'creator';
            elseif ($kind === 'saper') $winner = (int)$game->etap === GameSaper::GAME_SAPER_ETAP_WIN ? 'player' : 'creator';
            else {
                $result = $kind === 'duel' ? $game->getWinnerStatus() : trim($game->status);
                $winner = ['user' => 'creator', 'gamer' => 'player', 'draw' => 'draw'][$result] ?? null;
            }
            return ['id' => (int)$game->id, 'kon' => $game->kon, 'completed_at' => (int)$game->$date, 'winner' => $winner,
                'creator' => PublicProfile::fromUser($game->user), 'player' => PublicProfile::fromUser($game->userGamer)];
        }, $rows);
    }
}
