<?php

namespace common\models;

use common\models\user\User;
use common\models\user\UserRelationInterface;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "log_errors".
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property string $comment
 * @property int $created_at
 *
 * @property User $user
 */
class LogErrors extends ActiveRecord implements UserRelationInterface
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'log_errors';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'created_at'], 'integer'],
            [['type'], 'string', 'max' => 50],
            [['comment'], 'string', 'max' => 1000],
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
            'type' => Yii::t('app', 'Type'),
            'comment' => Yii::t('app', 'Comment'),
            'created_at' => Yii::t('app', 'Created At'),
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
