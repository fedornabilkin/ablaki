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
 * This is the model class for table "credit_transfer".
 *
 * @property int $id
 * @property int $user_id
 * @property int $user_buyer
 * @property double $amount
 * @property string $password
 * @property int $updated_at
 * @property int $created_at
 *
 * @property User $userBuyer
 * @property User $user
 */
class CreditTransfer extends ActiveRecord implements UserRelationInterface, BuyerRelationInterface, HistorySaveInterface
{
    use ModelQueryTrait;
    use HistoryTypeTrait;

    public $historyType = 'transfer';

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'credit_transfer';
    }

    public function behaviors()
    {
        return array_merge_recursive(parent::behaviors(), [
            TimestampBehavior::class => [
                'class' => TimestampBehavior::class,
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'user_buyer', 'updated_at', 'created_at'], 'integer'],
            [['amount'], 'required'],
            [['amount'], 'number'],
            [['password'], 'string', 'max' => 60],
            [['user_buyer'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
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
            'user_buyer' => Yii::t('app', 'User Buyer'),
            'amount' => Yii::t('app', 'Amount'),
            'password' => Yii::t('app', 'Password'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_at' => Yii::t('app', 'Created At'),
        ];
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
}
