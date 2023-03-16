<?php

namespace common\modules\forum\models;

use common\models\core\ModelQueryTrait;
use common\models\user\User;
use common\models\user\UserRelationInterface;
use Yii;
use yii\behaviors\BlameableBehavior;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "forum_theme".
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $title
 * @property int $view
 * @property int|null $last_post
 * @property int|null $created_at
 *
 * @property ForumComment[] $forumComments
 * @property User $user
 */
class ForumTheme extends ActiveRecord implements UserRelationInterface
{
    use ModelQueryTrait;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'forum_theme';
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
            [['user_id', 'view', 'last_post', 'created_at'], 'default', 'value' => null],
            [['user_id', 'view', 'last_post', 'created_at'], 'integer'],
            [['title'], 'required'],
            [['title'], 'string', 'max' => 250],
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
            'title' => Yii::t('forum', 'Title'),
            'view' => Yii::t('forum', 'View'),
            'last_post' => Yii::t('forum', 'Last Post'),
            'created_at' => Yii::t('forum', 'Created At'),
        ];
    }

    public function fields(): array
    {
        $fields = parent::fields();

        $fields['title'] = static function ($model) {
            return trim($model->title);
        };

        return $fields;
    }

    /**
     * Gets query for [[ForumComments]].
     *
     * @return ActiveQuery
     */
    public function getForumComments(): ActiveQuery
    {
        return $this->hasMany(ForumComment::class, ['theme_id' => 'id']);
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
