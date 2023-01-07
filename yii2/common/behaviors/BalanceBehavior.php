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
            ActiveRecord::EVENT_AFTER_INSERT => 'afterInsert',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
            ActiveRecord::EVENT_AFTER_DELETE => 'afterDelete',
        ]);
    }

    public function afterInsert($event){$this->changeBalance($event);}
    public function afterUpdate($event){$this->changeBalance($event);}
    public function afterDelete($event){$this->changeBalance($event);}

    protected function changeBalance($event)
    {
        $this->setBalance($event);
        $this->setPersone($event);

        $update = [
            'balance' => $this->changingBalance,
            'credit' => $this->changingCredit,
        ];

        if( ($this->person instanceof Person) && ($this->changingCredit or $this->changingBalance)){

            $transaction = $this->person::getDb()->beginTransaction();
            try {
                $this->person->updateCounters($update);
                $this->saveHistory();
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
        if ($model->attributes['amount'] > 0) {
            $model->save();
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
        $model->save();
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
