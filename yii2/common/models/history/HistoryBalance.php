<?php

namespace common\models\history;

use common\models\user\User;
use common\models\user\UserRelationInterface;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "history_balance".
 *
 * @property int $id
 * @property int $user_id
 * @property double $balance
 * @property double $credit
 * @property double $balance_up
 * @property double $credit_up
 * @property string $type
 * @property string $comment
 * @property int $created_at
 *
 * @property User $user
 */
class HistoryBalance extends ActiveRecord implements UserRelationInterface
{
    public static function find(): HistoryBalanceQuery
    {
        return new HistoryBalanceQuery(static::class);
    }

    public function behaviors()
    {
        return array_merge_recursive(parent::behaviors(), [
            TimestampBehavior::class => [
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
        return 'history_balance';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'created_at'], 'integer'],
            [['balance', 'credit', 'balance_up', 'credit_up'], 'number'],
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
            'balance' => Yii::t('app', 'Balance'),
            'credit' => Yii::t('app', 'Credit'),
            'balance_up' => Yii::t('app', 'Balance Up'),
            'credit_up' => Yii::t('app', 'Credit Up'),
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
