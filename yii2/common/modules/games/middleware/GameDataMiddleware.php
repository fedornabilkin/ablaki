<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 26.01.2022
 * Time: 23:25
 */

namespace common\modules\games\middleware;

use common\middleware\AbstractDataMiddleware;
use common\modules\games\models\GameOrel;
use common\modules\games\models\GameSaper;

class GameDataMiddleware extends AbstractDataMiddleware
{
    /** @var GameSaper|GameOrel */
    public $game;

    public function getCount(): int
    {
        return $this->game->count;
    }

    public function getKon(): float
    {
        return $this->game->kon;
    }

    public function getNeedCredit(): int
    {
        return (int)$this->getKon() * $this->getCount();
    }

    public function getNeedBalance(): int
    {
        return (int)$this->getKon() * $this->getCount();
    }
}
