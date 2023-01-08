<?php

namespace common\modules\exchange\models;

use common\models\core\ModelQueryTrait;
use common\models\history\HistorySaveInterface;
use common\models\history\HistoryTypeTrait;
use common\models\user\BuyerRelationInterface;
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
 * @property User $userBuyer
 * @property User $user
 */
class CreditExchange extends ActiveRecord implements UserRelationInterface, BuyerRelationInterface, HistorySaveInterface
{
    use ModelQueryTrait;
    use HistoryTypeTrait;

    public const EX_TYPE_SELL = 'sell';
    public const EX_TYPE_BUY = 'buy';

    public const EX_TYPE_AVAILABLE = [
        self::EX_TYPE_SELL => self::EX_TYPE_SELL,
        self::EX_TYPE_BUY => self::EX_TYPE_BUY,
    ];

    protected $historyType = 'exchange';

    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return 'credit_exchange';
    }

    public static function balanceFieldName(): string
    {
        return 'amount';
    }

    public static function creditFieldName(): string
    {
        return 'credit';
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
     * @return bool
     */
    public function isSell(): bool
    {
        return trim($this->type) === self::EX_TYPE_SELL;
    }

    /**
     * @return bool
     */
    public function isBuy(): bool
    {
        return trim($this->type) === self::EX_TYPE_BUY;
    }

    /**
     * @return ActiveQuery
     */
    public function getUserBuyer(): ActiveQuery
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

    /**
     * @return string[]
     */
    public function availableTypes(): array
    {
        return self::EX_TYPE_AVAILABLE;
    }
}
