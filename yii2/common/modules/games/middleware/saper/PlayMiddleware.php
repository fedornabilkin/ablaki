<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 14:36
 */

namespace common\modules\games\middleware\saper;

use common\middleware\HistoryCommissionMiddleware;
use common\middleware\person\UpdatePersonMiddleware;
use common\modules\games\middleware\GameMiddleware;
use common\modules\games\models\GameSaper;

class PlayMiddleware extends GameMiddleware
{
    /** @var GameSaper */
    private $model;

    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->model = self::$data->game;
        self::$data->historyType = self::$data->game->getHistoryType();
        self::$data->commissionAmount = $this->model->getCommissionAmount();

        $checkHod = $this->checkHod();
        $this->updateModel();

        if (!$checkHod) {
            $next = new SwitchCreatorMiddleware();
            $next->insertNext(new HistoryCommissionMiddleware());
            $this->insertNext($next);
        } else {
            $this->updateData();
        }

        return parent::check();
    }

    public function updateData()
    {
        if ($this->model->isWin()) {
            self::$data->changingBalance = $this->model->kon - self::$data->commissionAmount;
            self::$data->historyComment = 'Victory in the game #' . $this->model->id;
            self::$data->changingRating = $this->model->normalizeRating(self::$data->user, $this->model->kon);

            $next = new UpdatePersonMiddleware();
            $next->insertNext(new HistoryCommissionMiddleware());
            $this->insertNext($next);
        }
    }

    protected function updateModel()
    {
        $hod = 'hod' . $this->model->row;
        $this->model->$hod = $this->model->col;

        $this->model->etap--; // изменяем этап игры
        $this->model->save();
    }

    /**
     * @return bool
     */
    protected function checkHod(): bool
    {
        $pole = 'pole' . $this->model->row;

        // если переданное значение не равно значению из таблицы, значит поле прошли успешно
        $check = $this->model->$pole !== (int) $this->model->col;

        if (!$check) {
            $this->model->etap = $this->model::GAME_SAPER_ETAP_LOSE;
        }

        return $check;
    }
}
