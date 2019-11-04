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

        $this->execute();

        return parent::check();
    }

    public function execute()
    {
        $this->updatePerson();
        $this->historyBalance();
        $this->historyRating();
    }

    public function updatePerson()
    {
        $person = self::$data->user;
        $person->updateCounters(self::$data->getUpdatePersonCounters());
    }

    public function historyBalance()
    {
        $this->insertNext(new HistoryBalanceMiddleware());
    }

    public function historyRating()
    {
        $this->insertNext(new HistoryRatingMiddleware());
    }
}
