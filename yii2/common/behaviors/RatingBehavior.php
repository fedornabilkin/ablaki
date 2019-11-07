<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 21.07.2018
 * Time: 15:27
 */

namespace common\behaviors;


use common\models\HistoryRating;
use common\models\user\Person;
use yii\db\ActiveRecord;

class RatingBehavior extends AbstractBehavior
{
    /** @var float */
    protected $changingRating = 0;
    /** @var Person */
    protected $person;

    /** @return array */
    public function events()
    {
        return array_merge(parent::events(), [
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
        ]);
    }

    public function afterUpdate($event){$this->changeRating($event);}

    public function changeRating($event)
    {
        $this->setPersone($event);
        $this->setRating($event);

        $update = [
            'rating' => $this->changingRating,
        ];

        if( ($this->person instanceof Person) && $this->changingRating){

            $transaction = $this->person::getDb()->beginTransaction();
            try {
                $this->person->updateCounters($update);
                $this->saveHistory();

                $transaction->commit();
            } catch(\Throwable $e) {
                // todo log file or DB
                $transaction->rollBack();
                throw $e;
            }
        }
    }

    public function setRating($event)
    {
        $this->changingRating = $event->sender->getChangeRating($this->person, $event->sender->kon);
    }

    protected function setPersone($event)
    {
        $this->person = $event->sender->user->person;
    }

    protected function saveHistory()
    {
        $history = new HistoryRating();

        $history->attributes = $this->getHistoryValues();
        $history->save();
    }

    protected function getHistoryValues()
    {
        return [
            'user_id' => $this->person->user_id,
            'rating' => $this->changingRating,
            'type' => 'other',
        ];
    }
}
