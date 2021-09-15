<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 20.07.2019
 * Time: 19:38
 */

namespace common\modules\games\models\repo;

use common\models\user\User;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "game_saper".
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_gamer
 * @property double $kon
 * @property int $kon_double
 * @property int $etap
 * @property int $pole1
 * @property int $pole2
 * @property int $pole3
 * @property int $pole4
 * @property int $pole5
 * @property int $hod1
 * @property int $hod2
 * @property int $hod3
 * @property int $hod4
 * @property int $hod5
 * @property int $time_start_at
 * @property int $time_over_at
 * @property int $created_at
 *
 * @property User $userGamer
 * @property User $user
 */
class Saper extends ActiveRecord
{

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'game_saper';
    }

    public function behaviors()
    {
        return array_merge(parent::behaviors(), [
            'TimestampBehavior' => [
                'class' => TimestampBehavior::class,
                'createdAtAttribute' => 'created_at',
                'updatedAtAttribute' => 'time_over_at',
            ],

        ]);
    }

    /**
     * @return array
     */
    public function rules()
    {
        return [
            [[
                'user_id', 'user_gamer', 'kon_double', 'etap',
                'pole1', 'pole2', 'pole3', 'pole4', 'pole5',
                'hod1', 'hod2', 'hod3', 'hod4', 'hod5',
                'time_start_at', 'time_over_at', 'created_at'
            ], 'integer'],
            [['kon'], 'required'],
            [['kon'], 'number'],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
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
