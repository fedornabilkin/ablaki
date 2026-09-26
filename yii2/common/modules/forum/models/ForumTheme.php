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

    public static function find(): ActiveQuery { return new ForumThemeQuery(static::class); }

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
                'updatedAtAttribute' => false,
            ],
            BlameableBehavior::class => [
                'class' => BlameableBehavior::class,
                'createdByAttribute' => 'user_id',
                'updatedByAttribute' => false,
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['is_private'], 'default', 'value' => 0, 'isEmpty' => static function ($value) { return $value === null; }],
            [['is_private'], 'in', 'range' => [0, 1, '0', '1'], 'strict' => true, 'skipOnEmpty' => false],
            [['user_id', 'view', 'last_post', 'created_at'], 'default', 'value' => null],
            [['user_id', 'view', 'last_post', 'created_at'], 'integer'],
            [['title'], 'filter', 'filter' => static function ($value) { return is_string($value) ? preg_replace('/^[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+$/u', '', $value) : $value; }, 'skipOnArray' => true],
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
            'is_private' => 'Только для участников',
            'view' => Yii::t('forum', 'View'),
            'last_post' => Yii::t('forum', 'Last Post'),
            'created_at' => Yii::t('forum', 'Created At'),
        ];
    }

    public function fields(): array
    {
        $fields = parent::fields();
        $fields['is_private'] = static function ($model) { return (bool)$model->is_private; };

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
