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
        $this->consoleLog(self::class);

        $this->updatePerson();
        $this->historyBalance();
        $this->historyRating();

        return parent::check();
    }

    public function updatePerson(): void
    {
        $person = self::$data->user;
        $person->updateCounters(self::$data->getUpdatePersonCounters());
    }

    public function historyBalance(): void
    {
        $this->insertNext(new HistoryBalanceMiddleware());
    }

    public function historyRating(): void
    {
        $this->insertNext(new HistoryRatingMiddleware());
    }
}
