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
        $exact = $this->updatePerson();

        if (!$exact) $this->insertNext(new HistoryBalanceMiddleware());
        $this->insertNext(new HistoryRatingMiddleware());

        return parent::check();
    }

    public function updatePerson(): bool
    {
        $db = self::$data->user::getDb();
        \common\modules\economy\service\WalletMaintenance::writable($db);
        $exact = \common\modules\economy\service\WalletSchema::ready($db);
        if (self::$data->needUpdatePersonCounters()) {
            $person = self::$data->user;
            $current = (new \common\services\user\CreditLedger($db))->lock('persone', ['user_id' => (int)$person->user_id]);
            if (!$current) throw new \RuntimeException('Account unavailable.');
            $person::populateRecord($person, $current);
            $counters = self::$data->getUpdatePersonCounters();
            if (!$exact) foreach (['credit', 'balance'] as $currency) {
                if (!is_finite((float)$counters[$currency]) || !is_numeric($current[$currency]) || !is_finite((float)$current[$currency] + $counters[$currency])) throw new \RuntimeException('Invalid account amount.');
                if ($counters[$currency] < 0 && $current[$currency] < -$counters[$currency]) throw new \yii\web\UnprocessableEntityHttpException('Недостаточно средств.');
            }
            if ($exact) {
                // Requires the domain transaction: a local transaction here would leave the
                // exchange/game state committed when a later recipient or commission fails.
                (new \common\services\user\CreditLedger($db))->changeExactWithBalance(
                    (int)$person->user_id, \common\modules\economy\value\Money::fromLegacy($counters['credit'])->decimal(),
                    $counters['balance'], self::$data->historyType, self::$data->historyComment
                );
                unset($counters['credit'], $counters['balance']);
                if (!$person->refresh()) throw new \RuntimeException('Account unavailable.');
            }
            $counters = array_filter($counters, static function ($value) { return $value != 0; });
            if ($counters && !$person->updateCounters($counters)) throw new \RuntimeException('Could not update account counters.');
        }
        return $exact;
    }
}
