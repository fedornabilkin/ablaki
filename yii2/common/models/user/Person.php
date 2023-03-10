<?php

namespace common\models\user;

use common\models\core\ModelQueryTrait;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "persone".
 *
 * @property int $id
 * @property int $user_id
 * @property double $balance
 * @property double $balance_in
 * @property double $balance_out
 * @property double $credit
 * @property int $refovod
 * @property double $rating
 * @property string $referrer
 * @property int $bonus_count
 * @property int $autoriz
 * @property int $last_cleaning_at
 *
 * @property User $user
 * @property User $refovodUser
 */
class Person extends ActiveRecord implements UserRelationInterface
{
    use ModelQueryTrait;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'persone';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'refovod'], 'integer'],
            [['balance', 'credit', 'rating'], 'number'],
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
            'refovod' => Yii::t('app', 'Refovod'),
            'rating' => Yii::t('app', 'Rating'),
        ];
    }

    public function setLastCleaning(): self
    {
        $this->last_cleaning_at = time();
        return $this;
    }

    /**
     * @return ActiveQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id'])->inverseOf('person');
    }

    /**
     * @return ActiveQuery
     */
    public function getRefovodUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'refovod']);
    }
}
