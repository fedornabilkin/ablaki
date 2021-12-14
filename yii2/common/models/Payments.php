<?php

namespace common\models;

use common\models\user\User;
use common\models\user\UserRelationInterface;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "payments".
 *
 * @property int $id
 * @property int $user_id
 * @property double $amount
 * @property string $wmz
 * @property string $paysystem
 * @property string $type
 * @property int $status
 * @property string $buy_sell
 * @property string $comment
 * @property int $created_at
 *
 * @property User $user
 */
class Payments extends ActiveRecord implements UserRelationInterface
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'payments';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'status', 'created_at'], 'integer'],
            [['amount'], 'required'],
            [['amount'], 'number'],
            [['wmz'], 'string', 'max' => 13],
            [['paysystem'], 'string', 'max' => 255],
            [['type', 'buy_sell'], 'string', 'max' => 50],
            [['comment'], 'string', 'max' => 500],
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
            'amount' => Yii::t('app', 'Amount'),
            'wmz' => Yii::t('app', 'Wmz'),
            'paysystem' => Yii::t('app', 'Paysystem'),
            'type' => Yii::t('app', 'Type'),
            'status' => Yii::t('app', 'Status'),
            'buy_sell' => Yii::t('app', 'Buy Sell'),
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
