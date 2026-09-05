<?php

namespace common\modules\games\middleware\five;

use common\modules\games\middleware\AbstractRemoveMiddleware;

class RemoveGameMiddleware extends AbstractRemoveMiddleware
{
    /**
     * @inheritdoc
     */
    public function updateData(): void
    {
        parent::updateData();
        // возврат ставки, зарезервированной при создании партии
        self::$data->changingCredit = self::$data->getKon();
    }
}
