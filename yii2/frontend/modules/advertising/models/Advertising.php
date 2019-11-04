<?php

namespace frontend\modules\advertising\models;

use common\models\AbstractModel;
use common\models\user\User;
use frontend\modules\advertising\behaviors\UserBalanceBehavior;
use Yii;
use yii\behaviors\TimestampBehavior;

/**
 * This is the model class for table "advertising".
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $description
 * @property int $advertisingrove
 * @property int $status
 * @property string $url
 * @property string $banner
 * @property string $position
 * @property string $hash
 * @property double $credit
 * @property string $type
 * @property int $clicks
 * @property int $views
 * @property string $comment
 * @property int $updated_at
 * @property int $created_at
 *
 * @property User $user
 */
class Advertising extends AbstractModel
{
    CONST ADV_APPROVE_NO = 0;
    CONST ADV_APPROVE_YES = 1;
    CONST ADV_STATUS_DEACTIVE = 0;
    CONST ADV_STATUS_ACTIVE = 1;

    CONST SCENARIO_PAYMENT = 'play';

    /**
     * @return array
     */
    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios[self::SCENARIO_PAYMENT] = ['credit'];
        return $scenarios;
    }

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'advertising';
    }

    public function behaviors()
    {
        return array_merge_recursive(parent::behaviors(), [
            'TimestampBehavior' => [
                'class' => TimestampBehavior::class,
            ],
            'UserBalanceBehavior' => [
                'class' => UserBalanceBehavior::class,
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'approve', 'status', 'clicks', 'views', 'updated_at', 'created_at'], 'integer'],
            [['credit'], 'number', 'min' => 1, 'on' => self::SCENARIO_PAYMENT],
            [['title'], 'string', 'max' => 30],
            [['description', 'url', 'banner'], 'string', 'max' => 255],
            [['position', 'type'], 'string', 'max' => 50],
            [['hash'], 'string', 'max' => 32],
            [['comment'], 'string', 'max' => 1000],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('advertising', 'ID'),
            'user_id' => Yii::t('advertising', 'User ID'),
            'title' => Yii::t('advertising', 'Title'),
            'description' => Yii::t('advertising', 'Description'),
            'approve' => Yii::t('advertising', 'Approve'),
            'status' => Yii::t('advertising', 'Status'),
            'url' => Yii::t('advertising', 'Url'),
            'banner' => Yii::t('advertising', 'Banner'),
            'position' => Yii::t('advertising', 'Position'),
            'hash' => Yii::t('advertising', 'Hash'),
            'credit' => Yii::t('advertising', 'Credit'),
            'type' => Yii::t('advertising', 'Type'),
            'clicks' => Yii::t('advertising', 'Clicks'),
            'views' => Yii::t('advertising', 'Views'),
            'comment' => Yii::t('advertising', 'Comment'),
            'updated_at' => Yii::t('advertising', 'Updated At'),
            'created_at' => Yii::t('advertising', 'Created At'),
        ];
    }

    public function beforeSave($insert)
    {
        if (!parent::beforeSave($insert)) {
            return false;
        }

        if($insert){
            $this->user_id = $this->personInstance->user->id;
        }
        return true;
    }

    /**
     * Перед удалением проверить состояние
     * @return bool
     */
    public function beforeDelete()
    {
        if($this->user_id != $this->personInstance->user->id or $this->status != 0)
        {
            return false;
        }
        return parent::beforeDelete();
    }

    /**
     * @return \yii\db\ActiveQuery
     */
    public function getUser()
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    protected function getChangeCredit()
    {
        return $this->credit;
    }
}
