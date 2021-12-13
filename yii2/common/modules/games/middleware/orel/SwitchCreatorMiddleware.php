<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 03.08.2019
 * Time: 12:02
 */

namespace common\modules\games\middleware\orel;

use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\middleware\GameMiddleware;
use common\modules\games\models\GameOrel;
use yii\db\Exception;

class SwitchCreatorMiddleware extends GameMiddleware
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

        if (!$this->model->isWin()) {
            $this->updateData();
        }

        return parent::check();
    }

    public function updateData()
    {
        self::$data->user = $this->model->user->person; // Далее работаем с персоной создателя игры

        self::$data->changingCredit = $this->model->kon * 2 - self::$data->commissionAmount;
        self::$data->historyComment = 'Victory in the game #' . $this->model->id;
        self::$data->changingRating = $this->model->normalizeRating(self::$data->user->rating);

        $this->insertNext(new UpdatePersonMiddleware());
    }
}
