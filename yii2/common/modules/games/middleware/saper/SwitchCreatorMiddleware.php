<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 14:35
 */

namespace common\modules\games\middleware\saper;

use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\middleware\GameMiddleware;
use common\modules\games\models\GameSaper;

class SwitchCreatorMiddleware extends GameMiddleware
{
    /** @var GameSaper */
    private $model;

    /**
     * @return bool
     * @throws \yii\db\Exception
     */
    public function check(): bool
    {
        $this->model = self::$data->game;

        $this->updateData();

        return parent::check();
    }

    public function updateData()
    {
        self::$data->user = $this->model->user->person; // Далее работаем с персоной создателя игры

        self::$data->changingBalance = $this->model->kon * 2 - self::$data->commissionAmout;
        self::$data->historyComment = 'Victory in the game #' . $this->model->id;
        self::$data->changingRating = $this->model->normalizeRating(self::$data->user, $this->model->kon);

        $this->insertNext(new UpdatePersonMiddleware());
    }
}
