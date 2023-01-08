<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 14:33
 */

namespace common\modules\games\middleware\saper;

use common\modules\games\middleware\GameCreateMiddleware;
use common\modules\games\models\GameSaper;

class CreateMiddleware extends GameCreateMiddleware
{
    /** @var GameSaper */
    protected $model;

    public function updateData(): void
    {
        parent::updateData();
        self::$data->changingBalance = 0 - self::$data->getKon() * $this->count();
    }

    public function getRow(): array
    {
        return array_merge(parent::getRow(), [
            'pole1' => $this->model->getRandomType(),
            'pole2' => $this->model->getRandomType(),
            'pole3' => $this->model->getRandomType(),
            'pole4' => $this->model->getRandomType(),
            'pole5' => $this->model->getRandomType(),
            'etap' => $this->model::GAME_SAPER_ETAP_NEW,
        ]);
    }
}
