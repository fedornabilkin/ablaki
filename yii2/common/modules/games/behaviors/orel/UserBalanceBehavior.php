<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 21.07.2018
 * Time: 19:47
 */

namespace common\modules\games\behaviors\orel;


use common\behaviors\BalanceBehavior;
use common\modules\games\models\GameOrel;
use Yii;
use yii\db\ActiveRecord;

/**
 * Class UserBalanceBehavior
 *
 * Изменение баланса создателя игры
 *
 * @package common\modules\games\behaviors
 * @deprecated
 */
class UserBalanceBehavior extends BalanceBehavior
{
    private $historyValues = [];

    protected function setBalance($event)
    {
        /** @var GameOrel $model */
        $model = $event->sender;

        // удаление игры
        if($event->name == ActiveRecord::EVENT_AFTER_DELETE){
            $this->changingCredit = $model->changeCredit;
            $this->historyValues['comment'] = Yii::t('games', 'Remove the game #{attr}', ['attr' => $model->id]);
        }

        // когда кто-то проиграл
        elseif($model->scenario == $model::SCENARIO_PLAY){
            if(!$model->isWin()){
                $this->commission = $this->setCommission($model->changeCredit * 2);
                $this->changingCredit = $model->changeCredit * 2 - $this->commission;
                $this->historyValues['comment'] = Yii::t('games', 'Victory in the game #{attr}', ['attr' => $model->id]);
            }
        }

        // при создании игры
        else{
            $this->changingCredit = 0 - $model->changeCredit;
            $kon = $model->count > 1 ? $model->changeCredit / $model->count : $model->changeCredit;
            $this->historyValues['comment'] = Yii::t('games', 'Create game {count}x{kon}', ['count' => $model->count, 'kon' => $kon]);
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
