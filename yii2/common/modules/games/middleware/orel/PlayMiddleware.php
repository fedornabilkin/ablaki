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
use yii\db\Exception;

class PlayMiddleware extends GameMiddleware
{
    /** @var GameOrel */
    private $model;

    /**
     * @return bool
     * @throws Exception
     */
    public function check(): bool
    {
        $this->model = self::$data->game;
        $this->model->user_gamer = self::$data->user->user_id;

        $this->updateData();
        $this->model->save();

        return parent::check();
    }

    public function updateData()
    {
        self::$data->historyType = self::$data->game->getHistoryType();
        self::$data->commissionAmount = $this->model->getCommissionAmount();

        if ($this->model->isWin()) {
            self::$data->changingCredit = $this->model->kon - self::$data->commissionAmount;
            self::$data->historyComment = 'Victory in the game #' . $this->model->id;
            self::$data->changingRating = $this->model->normalizeRating(self::$data->user->rating);
        } else {
            self::$data->changingCredit = 0 - $this->model->kon;
            self::$data->historyComment = 'Defeat in the game #' . $this->model->id;
        }

        $this->insertNext(new UpdatePersonMiddleware());
    }
}
