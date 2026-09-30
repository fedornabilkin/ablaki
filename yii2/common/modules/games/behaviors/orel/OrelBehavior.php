<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 15.07.2018
 * Time: 21:31
 */

namespace common\modules\games\behaviors\orel;


use common\modules\games\behaviors\AbstractGameBehavior;
use common\modules\games\models\GameOrel;
use Yii;
use yii\db\ActiveRecord;

/**
 * Class OrelBehavior
 * @package common\modules\games\behaviors
 * @deprecated
 */
class OrelBehavior extends AbstractGameBehavior
{

    /** @return array */
    public function events()
    {
        return array_merge(parent::events(), [
            ActiveRecord::EVENT_BEFORE_VALIDATE => 'beforeValidate',
            ActiveRecord::EVENT_AFTER_UPDATE => 'afterUpdate',
        ]);
    }

    public function beforeValidate()
    {
        /** @var GameOrel $model */
        $model = $this->owner;

        if($this->userCredit < $model->changeCredit){
            $model->addError('user_gamer', Yii::t('games', 'Insufficient funds'));
        }
    }

    public function afterUpdate()
    {
        /** @var GameOrel $model */
        $model = $this->owner;

        $text = ($model->isWin()) ? 'Win' : 'Lose';
        $model->addError($model->scenario, Yii::t('games', $text));
    }
}
