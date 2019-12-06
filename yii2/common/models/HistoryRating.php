<?php

namespace common\models;

use common\models\user\User;
use Yii;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "history_rating".
 *
 * @property int $id
 * @property int $user_id
 * @property double $rating
 * @property string $type
 * @property string $comment
 * @property int $created_at
 *
 * @property User $user
 */
class HistoryRating extends \yii\db\ActiveRecord
{

    public function behaviors()
    {
        return array_merge_recursive(parent::behaviors(), [
            'TimestampBehavior' => [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => 'created_at',
            ],
        ]);
    }
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'history_rating';
    }


    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'created_at'], 'integer'],
            [['rating'], 'required'],
            [['rating'], 'number'],
            [['type'], 'string', 'max' => 50],
            [['comment'], 'string', 'max' => 255],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'rating' => Yii::t('app', 'Rating'),
            'type' => Yii::t('app', 'Type'),
            'comment' => Yii::t('app', 'Comment'),
            'created_at' => Yii::t('app', 'Created At'),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
