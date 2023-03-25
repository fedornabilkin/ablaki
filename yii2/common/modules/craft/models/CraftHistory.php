<?php

namespace common\modules\craft\models;

use common\models\core\ModelQueryTrait;
use common\models\user\User;
use common\models\user\UserQuery;
use common\models\user\UserRelationInterface;
use Yii;
use yii\behaviors\TimestampBehavior;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "craft_history".
 *
 * @property int $id
 * @property int $user_id
 * @property int $item_id
 * @property int $recipe_id
 * @property int $created_at
 *
 * @property CraftItem $item
 * @property CraftRecipe $recipe
 * @property User $user
 */
class CraftHistory extends ActiveRecord implements UserRelationInterface
{
    use ModelQueryTrait;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'craft_history';
    }

    public function behaviors(): array
    {
        return array_merge_recursive(parent::behaviors(), [
            TimestampBehavior::class => [
                'class' => TimestampBehavior::class,
                'updatedAtAttribute' => 'created_at',
            ],
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['user_id', 'item_id', 'recipe_id'], 'required'],
            [['user_id', 'item_id', 'recipe_id', 'created_at'], 'default', 'value' => null],
            [['user_id', 'item_id', 'recipe_id', 'created_at'], 'integer'],
            [['item_id'], 'exist', 'skipOnError' => true, 'targetClass' => CraftItem::class, 'targetAttribute' => ['item_id' => 'id']],
            [['recipe_id'], 'exist', 'skipOnError' => true, 'targetClass' => CraftRecipe::class, 'targetAttribute' => ['recipe_id' => 'id']],
            [['user_id'], 'exist', 'skipOnError' => true, 'targetClass' => User::class, 'targetAttribute' => ['user_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('craft', 'ID'),
            'user_id' => Yii::t('craft', 'User ID'),
            'item_id' => Yii::t('craft', 'Item ID'),
            'recipe_id' => Yii::t('craft', 'Recipe ID'),
            'created_at' => Yii::t('craft', 'Created At'),
        ];
    }

    /**
     * Gets query for [[Item]].
     *
     * @return ActiveQuery|CraftItemQuery
     */
    public function getItem()
    {
        return $this->hasOne(CraftItem::class, ['id' => 'item_id']);
    }

    /**
     * Gets query for [[Recipe]].
     *
     * @return ActiveQuery|CraftRecipeQuery
     */
    public function getRecipe()
    {
        return $this->hasOne(CraftRecipe::class, ['id' => 'recipe_id']);
    }

    /**
     * Gets query for [[User]].
     *
     * @return ActiveQuery|UserQuery
     */
    public function getUser(): ActiveQuery
    {
        return $this->hasOne(User::class, ['id' => 'user_id']);
    }
}
