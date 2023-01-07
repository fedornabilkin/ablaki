<?php

namespace common\models;

use common\models\user\User;
use common\models\user\UserRelationInterface;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "log_ip".
 *
 * @property int $id
 * @property int $user_id
 * @property string $ip
 * @property string $agent
 * @property string $comment
 * @property int $created_at
 *
 * @property User $user
 */
class LogIp extends ActiveRecord implements UserRelationInterface
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'log_ip';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'created_at'], 'integer'],
            [['ip'], 'string', 'max' => 15],
            [['agent', 'comment'], 'string', 'max' => 255],
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
            'ip' => Yii::t('app', 'Ip'),
            'agent' => Yii::t('app', 'Agent'),
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
