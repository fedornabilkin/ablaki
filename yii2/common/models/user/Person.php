<?php

namespace common\models\user;

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
 *
 * @property User $user
 */
class Person extends ActiveRecord
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'persone';
    }

    public function fields()
    {
        $f = [
            'refovod',
            'rating',
        ];

        if (!Yii::$app->user->getIsGuest()) {
            $f[] = 'balance';
            $f[] = 'credit';
        }

        return $f;
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

    /**
     * @return ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id'])->inverseOf('person');
    }

    /**
     * @return ActiveQuery
     */
    public function getRefovodUser()
    {
        return $this->hasOne(User::class, ['id' => 'refovod']);
    }
}
