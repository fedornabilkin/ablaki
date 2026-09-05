<?php

namespace common\modules\games\middleware\duel;

use common\modules\games\middleware\AbstractRemoveMiddleware;

class RemoveGameMiddleware extends AbstractRemoveMiddleware
{
    /**
     * @inheritdoc
     */
    public function updateData(): void
    {
        parent::updateData();
        // возврат ставки, зарезервированной при создании схватки
        self::$data->changingCredit = self::$data->getKon();
    }
}
