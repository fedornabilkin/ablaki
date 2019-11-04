<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 21.07.2018
 * Time: 21:50
 */

namespace common\modules\games\behaviors\orel;


use common\behaviors\RatingBehavior;

class UserRatingBehavior extends RatingBehavior
{
    private $historyValues = [];

    public function setRating($event)
    {
        if (!$event->sender->isWin()) {
            parent::setRating($event);
            $this->historyValues['comment'] = \Yii::t('games', 'Victory in the game #{attr}', ['attr' => $event->sender->id]);
        }
    }

    protected function getHistoryValues()
    {
        $this->historyValues['type'] = 'game_orel';
        return array_merge(parent::getHistoryValues(), $this->historyValues);
    }
}