<?php

namespace common\models;

use common\models\user\User;
use common\models\user\UserRelationInterface;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "credit_transfer".
 *
 * @property int $id
 * @property int $user_id
 * @property int $recepient
 * @property double $amount
 * @property string $password
 * @property int $updated_at
 * @property int $created_at
 *
 * @property User $recepient0
 * @property User $user
 */
class CreditTransfer extends ActiveRecord implements UserRelationInterface
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'credit_transfer';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'recepient', 'updated_at', 'created_at'], 'integer'],
            [['amount'], 'required'],
            [['amount'], 'number'],
            [['password'], 'string', 'max' => 60],
            [['recepient'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['recepient' => 'id']],
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
            'recepient' => Yii::t('app', 'Recepient'),
            'amount' => Yii::t('app', 'Amount'),
            'password' => Yii::t('app', 'Password'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_at' => Yii::t('app', 'Created At'),
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getRecepient(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'recepient']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
