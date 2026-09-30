<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 21.07.2018
 * Time: 0:40
 */

namespace common\modules\games\models;


use common\models\AbstractModel;
use common\models\user\User;
use yii\behaviors\TimestampBehavior;

class AbstractGame extends AbstractModel
{

    public function behaviors()
    {
        return array_merge_recursive(parent::behaviors(), [
            'TimestampBehavior' => [
                'class' => TimestampBehavior::class,
            ],
        ]);
    }

    protected function getChangeBalance()
    {
        return 0;
    }

    protected function getChangeCredit()
    {
        return 0;
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUserGamer()
    {
        return $this->hasOne(User::class, ['id' => 'user_gamer']);
    }
}