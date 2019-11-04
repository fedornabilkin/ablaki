<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 21.07.2018
 * Time: 20:05
 */

namespace common\modules\games\behaviors\orel;


use common\behaviors\BalanceBehavior;
use common\modules\games\models\GameOrel;

/**
 * Class GamerBalanceBehavior
 *
 * Изменение баланса игрока
 *
 * @package common\modules\games\behaviors
 */
class GamerBalanceBehavior extends BalanceBehavior
{
    private $historyValues = [];

    protected function setBalance($event)
    {
        /** @var GameOrel $model */
        $model = $event->sender;

        // когда не поиграл
        if($model->scenario != $model::SCENARIO_PLAY){
            return;
        }


        $param = 0 - $model->changeCredit;
        if($model->isWin()){
            $this->commission = $this->setCommission($model->changeCredit * 2);
            $param = $model->changeCredit - $this->commission;
            $this->historyValues['comment'] = \Yii::t('games', 'Victory in the game #{attr}', ['attr' => $model->id]);

        }else{
            $this->historyValues['comment'] = \Yii::t('games', 'Defeat in the game #{attr}', ['attr' => $model->id]);
        }


        $this->changingCredit = $param;
    }

    protected function setPersone($event)
    {
        if ($event->sender->userGamer) {
            $this->person = $event->sender->userGamer->person;
        }
    }

    protected function getCommissionValues()
    {
        return array_merge(parent::getHistoryValues(), [
            'amount' => $this->commission,
            'type' => 'credit',
        ]);
    }

    protected function getHistoryValues()
    {
        $this->historyValues['type'] = 'game_orel';
        return array_merge(parent::getHistoryValues(), $this->historyValues);
    }
}
