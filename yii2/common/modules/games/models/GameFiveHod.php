<?php

namespace common\modules\games\models;

use Yii;

/**
 * This is the model class for table "game_five_hod".
 *
 * @property int $id
 * @property int $game_five_id
 * @property int $user_id
 * @property int $user_gamer
 * @property int $user_ball
 * @property int $gamer_ball
 * @property string $status
 * @property int $created_at
 *
 * @property GameFive $gameFive
 */
class GameFiveHod extends AbstractGame
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'game_five_hod';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['game_five_id', 'user_id', 'user_gamer', 'user_ball', 'gamer_ball', 'created_at'], 'integer'],
            [['user_ball', 'gamer_ball'], 'required'],
            [['status'], 'string', 'max' => 50],
//            [['game_five_id'], 'exist', 'skipOnError' => true, 'targetClass' => GameFive::className(), 'targetAttribute' => ['game_five_id' => 'id']],
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
            'game_five_id' => Yii::t('games', 'Game Five ID'),
            'user_id' => Yii::t('games', 'User ID'),
            'user_gamer' => Yii::t('games', 'User Gamer'),
            'user_ball' => Yii::t('games', 'User Ball'),
            'gamer_ball' => Yii::t('games', 'Gamer Ball'),
            'status' => Yii::t('games', 'Status'),
            'created_at' => Yii::t('games', 'Created At'),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getGameFive()
    {
        return $this->hasOne(GameFive::class, ['id' => 'game_five_id']);
    }
}
