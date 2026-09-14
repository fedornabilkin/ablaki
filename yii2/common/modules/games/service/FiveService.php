<?php

namespace common\modules\games\service;

use common\models\Commission;
use common\models\history\HistoryBalance;
use common\models\history\HistoryRating;
use common\models\user\Person;
use common\modules\games\models\GameFive;
use common\modules\games\models\GameFiveHod;
use RuntimeException;
use Yii;
use yii\db\ActiveRecord;
use yii\db\Query;
use yii\web\ConflictHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;

/** All game, score, balance and ledger changes share one transaction. */
class FiveService
{
    public function create(GameFive $game, Person $person): GameFive
    {
        return Yii::$app->db->transaction(function () use ($game, $person) {
            $this->lock($person);
            $this->requireFunds($person, (float)$game->kon);
            $game->user_id = $person->user_id;
            $game->user_gamer = 0;
            $game->user_amount = 0;
            $game->gamer_amount = 0;
            $game->status = GameFive::STATUS_FREE;
            $this->save($game);
            $this->createHod($game, (int)$game->ball);
            $this->changePerson($person, $game, -$game->kon, 0, 'Create game five #' . $game->id);
            return $game;
        });
    }

    public function move(GameFive $game, Person $person, int $ball, int $roundId = null)
    {
        if ($ball < 1 || $ball > 5) throw new ConflictHttpException('Invalid move.');
        return Yii::$app->db->transaction(function () use ($game, $person, $ball, $roundId) {
            $this->lock($game);
            $userId = (int)$person->user_id;
            if ($game->isFinished()) throw new ConflictHttpException('Game is finished.');
            if ($game->isFree() && $game->isCreator($userId)) throw new ForbiddenHttpException('Cannot join your own game.');
            if (!$game->isFree() && !$game->isCreator($userId) && !$game->isGamer($userId)) {
                throw new ForbiddenHttpException('Not your game.');
            }
            $last = $game->getLastHod();
            if ($roundId !== null && ($last === null || (int)$last->id !== $roundId)) {
                throw new ConflictHttpException('Round has changed. Refresh the game.');
            }
            // Stable account order also covers games involving the same pair in opposite roles.
            $ids = array_unique([(int)$game->user_id, $game->isFree() ? $userId : (int)$game->user_gamer]);
            sort($ids, SORT_NUMERIC);
            $people = [];
            foreach ($ids as $id) {
                $account = Person::findOne(['user_id' => $id]);
                if ($account === null) throw new NotFoundHttpException('Player not found.');
                $this->lock($account);
                $people[$id] = $account;
            }
            if ($game->isFree()) {
                $this->requireFunds($people[$userId], (float)$game->kon);
                $game->user_gamer = $userId;
                $game->status = GameFive::STATUS_PLAY;
                $this->changePerson($people[$userId], $game, -$game->kon, 0, 'Join game five #' . $game->id);
            } elseif ($game->isCreator($userId)) {
                if ($last !== null && $last->isWait()) throw new ConflictHttpException('Not your turn.');
                $this->createHod($game, $ball);
                $this->save($game);
                return null;
            }
            if ($last === null || !$last->isWait()) throw new ConflictHttpException('Not your turn.');
            $last->user_gamer = $game->user_gamer;
            $last->gamer_ball = $ball;
            $last->status = $last->getWinnerStatus();
            $amount = $last->getWinAmount();
            if ($last->status === GameFiveHod::STATUS_USER) {
                $last->user_amount = $amount;
                $game->user_amount += $amount;
            } elseif ($last->status === GameFiveHod::STATUS_GAMER) {
                $last->gamer_amount = $amount;
                $game->gamer_amount += $amount;
            }
            $this->save($last);
            if ($game->user_amount >= GameFive::WIN_POINTS || $game->gamer_amount >= GameFive::WIN_POINTS) {
                $game->status = $game->user_amount >= GameFive::WIN_POINTS ? GameFive::STATUS_USER : GameFive::STATUS_GAMER;
                $winner = $people[$game->status === GameFive::STATUS_USER ? (int)$game->user_id : (int)$game->user_gamer];
                $this->changePerson($winner, $game, $game->getBankAmount() - $game->getCommissionAmount(),
                    $game->normalizeRating($winner->rating), 'Victory in the game five #' . $game->id);
                $commission = new Commission();
                $commission->setAttributes(['amount' => $game->getCommissionAmount(), 'type' => $game->getHistoryType()]);
                $this->save($commission);
            }
            $this->save($game);
            return $last;
        });
    }

    public function cancel(GameFive $game, Person $person): void
    {
        Yii::$app->db->transaction(function () use ($game, $person) {
            $this->lock($game);
            if (!$game->isCreator((int)$person->user_id)) throw new ForbiddenHttpException('Not your game.');
            if (!$game->isFree()) throw new ConflictHttpException('Game has already started.');
            $this->lock($person);
            $this->changePerson($person, $game, (float)$game->kon, 0, 'Remove game five #' . $game->id);
            GameFiveHod::deleteAll(['game_five_id' => $game->id]);
            if ($game->delete() !== 1) throw new RuntimeException('Failed to delete game.');
        });
    }

    private function createHod(GameFive $game, int $ball): void
    {
        $hod = new GameFiveHod();
        $hod->setAttributes(['game_five_id' => $game->id, 'user_id' => $game->user_id,
            'user_gamer' => (int)$game->user_gamer, 'user_ball' => $ball, 'gamer_ball' => 0,
            'user_amount' => 0, 'gamer_amount' => 0, 'status' => GameFiveHod::STATUS_WAIT]);
        $this->save($hod);
    }

    private function changePerson(Person $person, GameFive $game, float $credit, float $rating, string $comment): void
    {
        if (!$person->updateCounters(['credit' => $credit, 'rating' => $rating])) {
            throw new RuntimeException('Failed to update account.');
        }
        $values = ['user_id' => $person->user_id, 'type' => $game->getHistoryType(), 'comment' => $comment];
        $history = new HistoryBalance();
        $history->setAttributes($values + ['balance' => $person->balance, 'credit' => $person->credit,
            'balance_up' => 0, 'credit_up' => $credit]);
        $this->save($history);
        if ($rating != 0) {
            $history = new HistoryRating();
            $history->setAttributes($values + ['rating' => $person->rating, 'rating_up' => $rating]);
            $this->save($history);
        }
    }

    private function requireFunds(Person $person, float $stake): void
    {
        if (!is_finite($stake) || $stake < 1 || $person->credit < $stake) {
            throw new ConflictHttpException('Insufficient funds or invalid stake.');
        }
    }

    private function save(ActiveRecord $model): void
    {
        // Games validate input at the controller; internal fields are populated by this service.
        if (!$model->save(false)) throw new RuntimeException('Failed to save game operation.');
    }

    private function lock(ActiveRecord $model): void
    {
        $db = Yii::$app->db;
        $query = (new Query())->from($model::tableName())->where(['id' => $model->id]);
        if ($db->driverName === 'sqlite') {
            $db->createCommand('UPDATE ' . $db->quoteTableName($model::tableName()) .
                ' SET [[id]] = [[id]] WHERE [[id]] = :id', [':id' => $model->id])->execute();
            $row = $query->one($db);
        } elseif (in_array($db->driverName, ['pgsql', 'mysql'], true)) {
            $command = $query->createCommand($db);
            $row = $db->createCommand($command->sql . ' FOR UPDATE', $command->params)->queryOne();
        } else {
            throw new RuntimeException('Unsupported game database.');
        }
        if (!$row) throw new NotFoundHttpException('Game or player no longer exists.');
        // Use the locking read itself; a plain refresh may see an older MySQL snapshot.
        $model::populateRecord($model, $row);
    }
}
