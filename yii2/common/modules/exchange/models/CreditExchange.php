<?php

namespace common\modules\exchange\models;

use common\models\history\HistorySaveInterface;
use common\models\history\HistoryTypeTrait;
use common\models\user\User;
use common\models\user\UserRelationInterface;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * @property int $id
 * @property int $user_id
 * @property int $user_buyer
 * @property double $credit
 * @property double $amount
 * @property string $type
 * @property int $updated_at
 * @property int $created_at
 *
 * @property User $userClient
 * @property User $user
 */
class CreditExchange extends ActiveRecord implements UserRelationInterface, HistorySaveInterface
{
    use HistoryTypeTrait;

    public const EX_TYPE_SELL = 'sell';
    public const EX_TYPE_BUY = 'buy';

    public const EX_TYPE_AVAILABLE = [
        self::EX_TYPE_SELL => self::EX_TYPE_SELL,
        self::EX_TYPE_BUY => self::EX_TYPE_BUY,
    ];

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'credit_exchange';
    }

    public static function find(): ActiveQuery
    {
        return new CreditExchangeQuery(static::class);
    }

    public function behaviors(): array
    {
        return array_merge(parent::behaviors(), [
            TimestampBehavior::class => [
                'class' => TimestampBehavior::class,
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['user_id', 'user_buyer', 'updated_at', 'created_at'], 'integer'],
            [['type', 'credit', 'amount'], 'required'],
            [['credit', 'amount'], 'number'],
            [['credit'], 'number', 'min' => 1],
            [['amount'], 'number', 'min' => 0.01],
//            [['type'], 'string', 'max' => 50],
            ['type', 'in', 'range' => $this->getAvailableTypes()],
            [['user_buyer'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_buyer' => 'id']],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels(): array
    {
        return [
            'id' => Yii::t('exchange', 'ID'),
            'user_id' => Yii::t('exchange', 'User ID'),
            'user_buyer' => Yii::t('exchange', 'User Buyer'),
            'credit' => Yii::t('exchange', 'Credit'),
            'amount' => Yii::t('exchange', 'Amount'),
            'type' => Yii::t('exchange', 'Type'),
            'updated_at' => Yii::t('exchange', 'Updated At'),
            'created_at' => Yii::t('exchange', 'Created At'),
        ];
    }

    /**
     * @return ActiveQuery
     */
    public function getUserClient(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_buyer']);
    }

    /**
     * @return ActiveQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    public function getAvailableTypes(): array
    {
        return self::EX_TYPE_AVAILABLE;
    }
}