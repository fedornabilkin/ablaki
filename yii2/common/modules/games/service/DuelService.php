<?php

namespace common\modules\games\service;

use common\middleware\HistoryCommissionMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\models\user\Person;
use common\modules\games\middleware\GameDataMiddleware;
use common\modules\games\models\GameDuel;
use Throwable;
use Yii;
use yii\base\UserException;

/**
 * Игра «Дуэль» — одна схватка: удар + блок с каждой стороны.
 *
 * Создатель резервирует ставку при создании. Соперник платит по итогу:
 * победил только один — он забирает банк (две ставки) минус комиссия;
 * ничья (оба попали или оба в блок) — создателю возвращается эскроу,
 * соперник ничего не теряет.
 */
class DuelService
{
    /**
     * @throws UserException|Throwable
     */
    public function create(GameDuel $game, Person $person): GameDuel
    {
        if ($person->credit < $game->kon) {
            throw new UserException(Yii::t('games', 'Insufficient funds'));
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $game->user_id = $person->user_id;
            $game->user_gamer = 0;
            $game->save(false);

            $this->changePerson($person, $game, 0 - $game->kon, 0, 'Create game duel #' . $game->id);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $game;
    }

    /**
     * Ход соперника: схватка разыгрывается сразу.
     * @throws UserException|Throwable
     */
    public function play(GameDuel $game, Person $person): GameDuel
    {
        $userId = (int)$person->user_id;

        if ($game->isFinished()) {
            throw new UserException(Yii::t('games', 'No free game'));
        }

        if ($game->isCreator($userId)) {
            throw new UserException(Yii::t('games', 'Is my game'));
        }

        if ($person->credit < $game->kon) {
            throw new UserException(Yii::t('games', 'Insufficient funds'));
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $game->user_gamer = $person->user_id;
            $game->save(false);

            $this->settle($game, $person);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $game;
    }

    private function settle(GameDuel $game, Person $gamerPerson): void
    {
        $winner = $game->getWinnerStatus();
        $creatorPerson = $game->user->person;

        if ($winner === GameDuel::STATUS_DRAW) {
            // ничья: создателю возвращается эскроу, соперник ничего не платит
            $this->changePerson($creatorPerson, $game, $game->kon, 0, 'Draw in the game duel #' . $game->id);
            return;
        }

        $win = $game->getBankAmount() - $game->getCommissionAmount();

        if ($winner === GameDuel::STATUS_USER) {
            $data = $this->changePerson(
                $creatorPerson,
                $game,
                $win,
                $game->normalizeRating($creatorPerson->rating),
                'Victory in the game duel #' . $game->id
            );
            $this->changePerson($gamerPerson, $game, 0 - $game->kon, 0, 'Defeat in the game duel #' . $game->id);
        } else {
            // ставка соперника не списывалась — он получает банк минус своя ставка
            $data = $this->changePerson(
                $gamerPerson,
                $game,
                $win - $game->kon,
                $game->normalizeRating($gamerPerson->rating),
                'Victory in the game duel #' . $game->id
            );
        }

        $data->commissionAmount = $game->getCommissionAmount();
        $commission = new HistoryCommissionMiddleware();
        $commission::$data = $data;
        $commission->check();
    }

    private function changePerson(Person $person, GameDuel $game, float $credit, float $rating, string $comment): GameDataMiddleware
    {
        $data = new GameDataMiddleware([
            'game' => $game,
            'user' => $person,
        ]);
        $data->historyType = $game->getHistoryType();
        $data->historyComment = $comment;
        $data->changingCredit = $credit;
        $data->changingRating = $rating;

        $middleware = new UpdatePersonMiddleware();
        $middleware::$data = $data;
        $middleware->check();

        return $data;
    }
}
