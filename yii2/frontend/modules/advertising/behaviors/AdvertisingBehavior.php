<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 02.08.2018
 * Time: 19:59
 */

namespace frontend\modules\advertising\behaviors;


use common\behaviors\AbstractBehavior;
use frontend\modules\advertising\models\Advertising;
use yii\db\ActiveRecord;

class AdvertisingBehavior extends AbstractBehavior
{


    /** @return array */
    public function events()
    {
        return array_merge(parent::events(), [
            ActiveRecord::EVENT_BEFORE_UPDATE => 'beforeUpdate',
        ]);
    }

    public function beforeUpdate()
    {
        /** @var Advertising $model */
        $model = $this->owner;
        if($model->scenario != $model::SCENARIO_PAYMENT){
            $model->approve = $model::ADV_APPROVE_NO;
        }
    }
}