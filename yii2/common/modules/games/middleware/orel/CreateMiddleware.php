<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 22:24
 */

namespace common\modules\games\middleware\orel;

use common\modules\games\middleware\GameCreateMiddleware;
use common\modules\games\models\GameOrel;

class CreateMiddleware extends GameCreateMiddleware
{
    /** @var GameOrel */
    protected $model;

    public function updateData(): void
    {
        parent::updateData();
        self::$data->changingCredit = 0 - self::$data->getKon() * $this->count();
    }

    public function getRow(): array
    {
        return array_merge(parent::getRow(), [
            'type' => $this->model->getRandomType(),
        ]);
    }
}
