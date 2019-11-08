<?php

namespace common\modules\games\models;

use common\models\user\User;
use Yii;

/**
 * This is the model class for table "game_duel".
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_gamer
 * @property double $kon
 * @property int $u1
 * @property int $u2
 * @property int $b1
 * @property int $b2
 * @property int $time_over_at
 * @property int $created_at
 *
 * @property User $userGamer
 * @property User $user
 */
class GameDuel extends AbstractGame
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'game_duel';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'user_gamer', 'u1', 'u2', 'b1', 'b2', 'updated_at', 'created_at'], 'integer'],
            [['kon'], 'required'],
            [['kon'], 'number'],
//            [['user_gamer'], 'exist', 'skipOnError' => true, 'targetClass' => User::className(), 'targetAttribute' => ['user_gamer' => 'id']],
//            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::className(), 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('games', 'ID'),
            'user_id' => Yii::t('games', 'User ID'),
            'user_gamer' => Yii::t('games', 'User Gamer'),
            'kon' => Yii::t('games', 'Kon'),
            'u1' => Yii::t('games', 'U1'),
            'u2' => Yii::t('games', 'U2'),
            'b1' => Yii::t('games', 'B1'),
            'b2' => Yii::t('games', 'B2'),
            'updated_at' => Yii::t('games', 'Updated At'),
            'created_at' => Yii::t('games', 'Created At'),
        ];
    }
}
