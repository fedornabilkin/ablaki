<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 21.07.2018
 * Time: 19:47
 */

namespace frontend\modules\advertising\behaviors;


use common\behaviors\BalanceBehavior;
use frontend\modules\advertising\models\Advertising;
use yii\db\ActiveRecord;

/**
 * Class UserBalanceBehavior
 *
 * Изменение баланса при пополнении рекламной кампании
 *
 * @package frontend\modules\games\behaviors
 */
class UserBalanceBehavior extends BalanceBehavior
{
    private $historyValues = [];

    protected function setBalance($event)
    {
        /** @var Advertising $model */
        $model = $event->sender;

        // при пополнении
        if($model->scenario == $model::SCENARIO_PAYMENT) {
            $this->changingCredit = 0 - $model->changeCredit;
            $this->historyValues['comment'] = \Yii::t('advertising', 'Payment for advertising campaign #{attr}', ['attr' => $model->id]);
        }

        // удаление
        if($event->name == ActiveRecord::EVENT_AFTER_DELETE){
            $this->changingCredit = $model->changeCredit;
            $this->historyValues['comment'] = \Yii::t('advertising', 'Remove the campaign #{attr}', ['attr' => $model->id]);
        }

    }

    protected function getHistoryValues()
    {
        $this->historyValues['type'] = 'advertising';
        return array_merge(parent::getHistoryValues(), $this->historyValues);
    }
}