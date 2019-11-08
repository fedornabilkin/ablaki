<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 21.07.2018
 * Time: 21:51
 */

namespace common\modules\games\behaviors\orel;


use common\behaviors\RatingBehavior;

class GamerRatingBehavior extends RatingBehavior
{
    private $historyValues = [];

    public function setRating($event)
    {
        if ($event->sender->isWin()) {
            parent::setRating($event);
            $this->historyValues['comment'] = \Yii::t('games', 'Victory in the game #{attr}', ['attr' => $event->sender->id]);
        }
    }

    protected function setPersone($event)
    {
        $this->person = $event->sender->userGamer->person;
    }

    protected function getHistoryValues()
    {
        $this->historyValues['type'] = 'game_orel';
        return array_merge(parent::getHistoryValues(), $this->historyValues);
    }
}
