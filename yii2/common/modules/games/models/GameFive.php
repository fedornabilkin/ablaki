<?php

namespace common\modules\games\models;

use Yii;

/**
 * This is the model class for table "game_five".
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_gamer
 * @property double $kon
 * @property string $status
 * @property int $updated_at
 * @property int $created_at
 *
 * @property GameFiveHod[] $gameFiveHods
 */
class GameFive extends AbstractGame
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'game_five';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'user_gamer', 'updated_at', 'created_at'], 'integer'],
            [['kon'], 'required'],
            [['kon'], 'number'],
            [['status'], 'string', 'max' => 50],
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
            'status' => Yii::t('games', 'Status'),
            'updated_at' => Yii::t('games', 'Updated At'),
            'created_at' => Yii::t('games', 'Created At'),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getGameFiveHods()
    {
        return $this->hasMany(GameFiveHod::class, ['game_five_id' => 'id']);
    }
}
