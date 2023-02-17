<?php

namespace common\modules\forum\models;

use common\models\user\User;
use common\models\user\UserRelationInterface;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "forum_comment".
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $theme_id
 * @property string|null $comment
 * @property int|null $active
 * @property int|null $created_at
 *
 * @property ForumTheme $theme
 * @property User $user
 */
class ForumComment extends ActiveRecord implements UserRelationInterface
{
    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'forum_comment';
    }

    public function behaviors(): array
    {
        return array_merge_recursive(parent::behaviors(), [
            TimestampBehavior::class => [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => 'created_at',
            ],
            BlameableBehavior::class => [
                'class' => BlameableBehavior::class,
                'createdByAttribute' => 'user_id',
                'updatedByAttribute' => 'user_id',
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'theme_id', 'active', 'created_at'], 'default', 'value' => null],
            [['user_id', 'theme_id', 'active', 'created_at'], 'integer'],
            [['comment'], 'required'],
            [['comment'], 'string', 'max' => 3000],
            [['theme_id'], 'exist', 'skipOnError' => true, 'targetClass' => ForumTheme::class, 'targetAttribute' => ['theme_id' => 'id']],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('forum', 'ID'),
            'user_id' => Yii::t('forum', 'User ID'),
            'theme_id' => Yii::t('forum', 'Theme ID'),
            'comment' => Yii::t('forum', 'Comment'),
            'active' => Yii::t('forum', 'Active'),
            'created_at' => Yii::t('forum', 'Created At'),
        ];
    }

    /**
     * Gets query for [[Theme]].
     *
     * @return ActiveQuery
     */
    public function getTheme(): ActiveQuery
    {
        return $this->hasOne(ForumTheme::class, ['id' => 'theme_id']);
    }

    /**
     * Gets query for [[User]].
     *
     * @return ActiveQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
