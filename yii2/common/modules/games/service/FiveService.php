<?php

namespace common\modules\games\service;

use common\middleware\HistoryCommissionMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\models\user\Person;
use common\modules\games\middleware\GameDataMiddleware;
use common\modules\games\models\GameFive;
use common\modules\games\models\GameFiveHod;
use Throwable;
use Yii;
use yii\base\UserException;

/**
 * Игра «5 яблок»: партия до GameFive::WIN_POINTS очков.
 *
 * Создатель ставит kon (эскроу) и делает скрытый ход. Соперник вступает
 * со своей ставкой (эскроу) и отвечает — раунд разыгрывается. Дальше ходы
 * чередуются: создатель открывает раунд скрытым ходом, соперник закрывает.
 * Победитель партии забирает банк (две ставки) за вычетом комиссии.
 */
class FiveService
{
    /**
     * @throws UserException|Throwable
     */
    public function create(GameFive $game, Person $person): GameFive
    {
        if ($person->credit < $game->kon) {
            throw new UserException(Yii::t('games', 'Insufficient funds'));
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $game->user_id = $person->user_id;
            $game->user_gamer = 0;
            $game->user_amount = 0;
            $game->gamer_amount = 0;
            $game->status = GameFive::STATUS_FREE;
            $game->save(false);

            $this->createHod($game, (int)$game->ball);

            $this->changePerson($person, $game, 0 - $game->kon, 0, 'Create game five #' . $game->id);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $game;
    }

    /**
     * Ход в партии.
     * @return GameFiveHod|null разыгранный раунд, если ход его закрыл
     * @throws UserException|Throwable
     */
    public function move(GameFive $game, Person $person, int $ball)
    {
        $userId = (int)$person->user_id;

        if ($game->isFinished()) {
            throw new UserException(Yii::t('games', 'Game is finished'));
        }

        if ($game->isFree() && $game->isCreator($userId)) {
            throw new UserException(Yii::t('games', 'Is my game'));
        }

        if (!$game->isFree() && !$game->isCreator($userId) && !$game->isGamer($userId)) {
            throw new UserException(Yii::t('games', 'Not your game'));
        }

        $transaction = Yii::$app->db->beginTransaction();
        try {
            $hod = $this->processMove($game, $person, $ball);
            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            throw $e;
        }

        return $hod;
    }

    /**
     * @return GameFiveHod|null
     * @throws UserException
     */
    private function processMove(GameFive $game, Person $person, int $ball)
    {
        $userId = (int)$person->user_id;

        // вступление соперника в свободную игру
        if ($game->isFree()) {
            if ($person->credit < $game->kon) {
                throw new UserException(Yii::t('games', 'Insufficient funds'));
            }

            $game->user_gamer = $person->user_id;
            $game->status = GameFive::STATUS_PLAY;

            $this->changePerson($person, $game, 0 - $game->kon, 0, 'Join game five #' . $game->id);

            return $this->playRound($game, $ball);
        }

        if ($game->isGamer($userId)) {
            if ($game->getTurn() !== GameFive::TURN_GAMER) {
                throw new UserException(Yii::t('games', 'Not your turn'));
            }
            return $this->playRound($game, $ball);
        }

        // создатель открывает новый раунд скрытым ходом
        if ($game->getTurn() !== GameFive::TURN_USER) {
            throw new UserException(Yii::t('games', 'Not your turn'));
        }

        $this->createHod($game, $ball);
        $game->save(false);

        return null;
    }

    /**
     * Соперник закрывает раунд — начисляем очки, при 21+ завершаем партию.
     * @throws UserException
     */
    private function playRound(GameFive $game, int $ball): GameFiveHod
    {
        $hod = $game->getLastHod();
        if ($hod === null || !$hod->isWait()) {
            throw new UserException(Yii::t('games', 'Not your turn'));
        }

        $hod->user_gamer = $game->user_gamer;
        $hod->gamer_ball = $ball;
        $hod->status = $hod->getWinnerStatus();

        $amount = $hod->getWinAmount();
        if ($hod->status === GameFiveHod::STATUS_USER) {
            $hod->user_amount = $amount;
            $game->user_amount += $amount;
        } elseif ($hod->status === GameFiveHod::STATUS_GAMER) {
            $hod->gamer_amount = $amount;
            $game->gamer_amount += $amount;
        }

        $hod->save(false);

        if ($game->user_amount >= GameFive::WIN_POINTS) {
            $this->finish($game, GameFive::STATUS_USER);
        } elseif ($game->gamer_amount >= GameFive::WIN_POINTS) {
            $this->finish($game, GameFive::STATUS_GAMER);
        }

        $game->save(false);

        return $hod;
    }

    /**
     * Победитель забирает банк за вычетом комиссии.
     */
    private function finish(GameFive $game, string $winner): void
    {
        $game->status = $winner;

        $person = $winner === GameFive::STATUS_USER
            ? $game->user->person
            : $game->userGamer->person;

        $win = $game->getBankAmount() - $game->getCommissionAmount();

        $data = $this->changePerson(
            $person,
            $game,
            $win,
            $game->normalizeRating($person->rating),
            'Victory in the game five #' . $game->id
        );

        $data->commissionAmount = $game->getCommissionAmount();
        $commission = new HistoryCommissionMiddleware();
        $commission::$data = $data;
        $commission->check();
    }

    private function createHod(GameFive $game, int $ball): GameFiveHod
    {
        $hod = new GameFiveHod();
        $hod->game_five_id = $game->id;
        $hod->user_id = $game->user_id;
        $hod->user_gamer = (int)$game->user_gamer;
        $hod->user_ball = $ball;
        $hod->gamer_ball = 0;
        $hod->user_amount = 0;
        $hod->gamer_amount = 0;
        $hod->status = GameFiveHod::STATUS_WAIT;
        $hod->save(false);

        return $hod;
    }

    private function changePerson(Person $person, GameFive $game, float $credit, float $rating, string $comment): GameDataMiddleware
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
