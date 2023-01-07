<?php

namespace common\models;

use common\models\user\User;
use common\models\user\UserRelationInterface;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;


/**
 * This is the model class for table "todo".
 *
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property string $comment
 * @property int $status
 * @property int $updated_at
 * @property int $created_at
 */
class Todo extends ActiveRecord implements UserRelationInterface
{
    /**
     * {@inheritdoc}
     */
    public static function tableName(): string
    {
        return 'todo';
    }

    public function behaviors(): array
    {
        return array_merge_recursive(parent::behaviors(), [
            TimestampBehavior::class => [
                'class' => TimestampBehavior::class,
            ],
            BlameableBehavior::class => [
                'class' => BlameableBehavior::class,
                'createdByAttribute' => 'user_id',
                'updatedByAttribute' => 'user_id',
            ]
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'status', 'updated_at', 'created_at'], 'integer'],
            [['status'], 'default', 'value' => 0],
            [['title', 'comment'], 'required'],
            [['comment'], 'string'],
            [['title'], 'string', 'max' => 50],
            [['updated_at', 'created_at'], 'safe'],
        ];
    }

//    public function beforeSave($insert): bool
//    {
//        $parent = parent::beforeSave($insert);
//        if ($insert) {
//            $this->user_id = Yii::$app->user->id;
//            return $parent;
//        }
//        return $parent;
//    }

    /**
     * @return ActiveQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('app', 'ID'),
            'user_id' => Yii::t('app', 'User ID'),
            'title' => Yii::t('app', 'Title'),
            'comment' => Yii::t('app', 'Comment'),
            'status' => Yii::t('app', 'Status'),
            'updated_at' => Yii::t('app', 'Updated At'),
            'created_at' => Yii::t('app', 'Created At'),
        ];
    }
}
