<?php

namespace common\models;

use Yii;

/**
 * This is the model class for table "credit_exchange".
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_buyer
 * @property double $credit
 * @property double $amount
 * @property string $type
 * @property int $updated_at
 * @property int $created_at
 *
 * @property User $userBuyer
 * @property User $user
 */
class CreditExchange extends \yii\db\ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'credit_exchange';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'user_buyer', 'updated_at', 'created_at'], 'integer'],
            [['credit', 'amount'], 'required'],
            [['credit', 'amount'], 'number'],
            [['type'], 'string', 'max' => 50],
            [['user_buyer'], 'exist', 'skipOnError' => true, 'targetClass' => User::className(), 'targetAttribute' => ['user_buyer' => 'id']],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::className(), 'targetAttribute' => ['user_id' => 'id']],
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
            'user_buyer' => Yii::t('app', 'User Buyer'),
            'credit' => Yii::t('app', 'Credit'),
            'amount' => Yii::t('app', 'Amount'),
            'type' => Yii::t('app', 'Type'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_at' => Yii::t('app', 'Created At'),
        ];
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUserBuyer()
    {
        return $this->hasOne(User::className(), ['id' => 'user_buyer']);
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::className(), ['id' => 'user_id']);
    }
}
