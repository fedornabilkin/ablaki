<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 22:24
 */

namespace common\modules\games\middleware\orel;

use common\modules\games\middleware\AbstractCreateMiddleware;

class CreateMiddleware extends AbstractCreateMiddleware
{
    public function updateData(): void
    {
        parent::updateData();
        self::$data->changingCredit = 0 - self::$data->game->kon * self::$data->game->count;
    }

    public function getRow(): array
    {
        return [
            'kon' => self::$data->game->kon,
            'user_id' => self::$data->user->user_id,
            'user_gamer' => 0,
            'created_at' => time(),
            'type' => self::$data->game->getRandomType(),
        ];
    }
}
