<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 21.07.2018
 * Time: 11:52
 */

namespace common\behaviors;


use common\models\Commission;
use common\models\history\HistoryBalance;
use common\models\user\Person;
use Throwable;
use yii\db\ActiveRecord;

class BalanceBehavior extends AbstractBehavior
{
    /** @var float */
    protected $changingCredit = 0;
    /** @var float */
    protected $changingBalance = 0;
    /** @var float */
    protected $commission = 0;
    /** @var Person */
    protected $person;

    /** @return array */
    public function events()
    {
        $events = parent::events();
        return array_merge($events, [
            ActiveRecord::EVENT_BEFORE_INSERT => 'requireTransaction',
            ActiveRecord::EVENT_BEFORE_UPDATE => 'requireTransaction',
            ActiveRecord::EVENT_BEFORE_DELETE => 'requireTransaction',
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ]);
    }

    public function requireTransaction($event): void
    {
        $db = $event->sender::getDb();
        \common\modules\economy\service\WalletMaintenance::writable($db);
        if (\common\modules\economy\service\WalletSchema::ready($db) && !$db->getTransaction()) throw new \LogicException('Financial behavior requires an outer model transaction.');
    }

    public function afterInsert($event){$this->changeBalance($event);}
    public function afterUpdate($event){$this->changeBalance($event);}
    public function afterDelete($event){$this->changeBalance($event);}

    protected function changeBalance($event)
    {
        $this->changingCredit = $this->changingBalance = $this->commission = 0;
        $this->person = null;
        $this->setBalance($event);
        $this->setPersone($event);

        $update = [
            'balance' => $this->changingBalance,
            'credit' => $this->changingCredit,
        ];

        if( ($this->person instanceof Person) && ($this->changingCredit or $this->changingBalance)){

            $transaction = $this->person::getDb()->beginTransaction();
            try {
                $db = $this->person::getDb();
                \common\modules\economy\service\WalletMaintenance::writable($db);
                if (\common\modules\economy\service\WalletSchema::ready($db)) {
                    $history = $this->getHistoryValues();
                    (new \common\services\user\CreditLedger($db))->changeExactWithBalance(
                        (int)$this->person->user_id, \common\modules\economy\value\Money::fromLegacy($this->changingCredit)->decimal(),
                        $this->changingBalance, (string)$history['type'], (string)($history['comment'] ?? '')
                    );
                    if (!$this->person->refresh()) throw new \RuntimeException('Account unavailable.');
                } else {
                    if (!$this->person->updateCounters($update)) throw new \RuntimeException('Could not update account.');
                    $this->saveHistory();
                }
                $this->saveCommission();

                $transaction->commit();
            } catch (Throwable $e) {
                // todo log file or DB
                $transaction->rollBack();
                throw $e;
            }
        }
    }

    protected function setBalance($event)
    {
        $this->changingCredit = $event->sender->changeCredit;
        $this->changingBalance = $event->sender->changeBalance;
    }

    protected function setPersone($event)
    {
        $this->person = $event->sender->user->person;
    }

    protected function setCommission($amount)
    {
        return $amount * 0.05;
    }

    protected function saveCommission()
    {
        $model = new Commission();

        $model->attributes = $this->getCommissionValues();
        if (($model->attributes['amount'] ?? 0) > 0) {
            if (!$model->save()) throw new \RuntimeException('Could not record commission.');
        }
    }

    protected function getCommissionValues()
    {
        return [];
    }

    protected function saveHistory()
    {
        $model = new HistoryBalance();

        $model->attributes = $this->getHistoryValues();
        if (!$model->save()) throw new \RuntimeException('Could not record balance history.');
    }

    protected function getHistoryValues()
    {
        return [
            'user_id' => $this->person->user_id,
            'balance' => $this->person->balance,
            'credit' => $this->person->credit,
            'balance_up' => $this->changingBalance,
            'credit_up' => $this->changingCredit,
            'type' => 'other',
        ];
    }
}
