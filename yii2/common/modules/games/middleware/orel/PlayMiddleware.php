<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 03.08.2019
 * Time: 10:12
 */

namespace common\modules\games\middleware\orel;

use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\middleware\GameMiddleware;
use common\modules\games\models\GameOrel;

class PlayMiddleware extends GameMiddleware
{
    /** @var GameOrel */
    private $model;

    /**
     * @return bool
     * @throws \yii\db\Exception
     */
    public function check(): bool
    {
        $this->model = self::$data->game;
        $this->model->user_gamer = self::$data->user->user_id;

        $this->updateData();
        $this->saveGame();

        return parent::check();
    }

    public function updateData()
    {
        self::$data->historyType = self::$data->game::HISTORY_TYPE;
        self::$data->commissionAmout = $this->model->getCommissionAmount($this->model->kon * 2);

        if ($this->model->isWin()) {
            self::$data->changingCredit = $this->model->kon - self::$data->commissionAmout;
            self::$data->historyComment = 'Victory in the game #' . $this->model->id;
            self::$data->changingRating = $this->model->normalizeRating(self::$data->user, $this->model->kon);
        } else {
            self::$data->changingCredit = 0 - $this->model->kon;
            self::$data->historyComment = 'Defeat in the game #' . $this->model->id;
        }

        $this->insertNext(new UpdatePersonMiddleware());
    }

    public function saveGame()
    {
        $this->model->save();
    }
}
