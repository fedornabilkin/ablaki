<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 21.07.2019
 * Time: 17:43
 */

namespace common\middleware\person;

use common\middleware\AbstractMiddleware;

class UpdatePersonMiddleware extends AbstractMiddleware
{
    /**
     * @inheritDoc
     */
    public function check(): bool
    {
        $this->updatePerson();

        $this->insertNext(new HistoryBalanceMiddleware());
        $this->insertNext(new HistoryRatingMiddleware());

        return parent::check();
    }

    public function updatePerson(): void
    {
        if (self::$data->needUpdatePersonCounters()) {
            $person = self::$data->user;
            $person->updateCounters(self::$data->getUpdatePersonCounters());
        }
    }
}
