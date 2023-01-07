<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 04.08.2019
 * Time: 14:34
 */

namespace common\modules\games\middleware\saper;

use common\modules\games\middleware\AbstractRemoveMiddleware;

class RemoveGameMiddleware extends AbstractRemoveMiddleware
{
    /**
     * @inheritdoc
     */
    public function updateData(): void
    {
        parent::updateData();
        self::$data->changingBalance = self::$data->getKon();
    }
}
