<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 20:33
 */

namespace common\modules\games\models\repo;

use common\models\user\User;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "game_orel".
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_gamer
 * @property double $kon
 * @property int $type
 * @property int $hod
 * @property int $updated_at
 * @property int $created_at
 *
 * @property User $user
 * @property User $userGamer
 */
class Orel extends ActiveRecord
{

    /**
     * @return string
     */
    public static function tableName()
    {
        return 'game_orel';
    }

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'TimestampBehavior' => [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'updated_at',
            ],
        ]);
    }

    public function rules()
    {
        return [
            [['user_id', 'user_gamer', 'type', 'updated_at', 'created_at'], 'integer'],
            [['kon'], 'required'],
            [['kon'], 'number', 'min' => 1],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    public static function find()
    {
        return new OrelQuery(get_called_class());
    }

    /**
     * @return ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUserGamer()
    {
        return $this->hasOne(User::class, ['id' => 'user_gamer']);
    }
}
