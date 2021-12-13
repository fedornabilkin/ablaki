<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 14:33
 */

namespace common\modules\games\middleware\saper;

use common\modules\games\middleware\AbstractCreateMiddleware;

class CreateMiddleware extends AbstractCreateMiddleware
{
    public function updateData(): void
    {
        parent::updateData();
        self::$data->changingBalance = 0 - self::$data->game->kon * self::$data->game->count;
    }

    public function getRow(): array
    {
        return [
            'kon' => self::$data->game->kon,
            'user_id' => self::$data->user->user_id,
            'user_gamer' => 0,
            'created_at' => time(),
            'pole1' => self::$data->game->getRandomType(),
            'pole2' => self::$data->game->getRandomType(),
            'pole3' => self::$data->game->getRandomType(),
            'pole4' => self::$data->game->getRandomType(),
            'pole5' => self::$data->game->getRandomType(),
            'etap' => self::$data->game::GAME_SAPER_ETAP_NEW,
        ];
    }
}
