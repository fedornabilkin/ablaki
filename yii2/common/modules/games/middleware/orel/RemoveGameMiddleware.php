<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 17:09
 */

namespace common\modules\games\middleware\orel;

use common\modules\games\middleware\AbstractRemoveMiddleware;

class RemoveGameMiddleware extends AbstractRemoveMiddleware
{
    /**
     * @inheritdoc
     */
    public function updateData(): void
    {
        parent::updateData();
        self::$data->changingCredit = self::$data->game->kon;
    }
}
