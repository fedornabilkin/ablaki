<?php

namespace common\modules\craft\models;

use common\models\core\ModelQueryTrait;
use common\models\ModelNameInterface;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "craft_recipe".
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int $category_id
 * @property int $item_id
 * @property int|null $active
 *
 * @property CraftCategory $category
 * @property CraftHistory[] $histories
 * @property CraftRecipeItem[] $recipeItems
 * @property CraftRecipeTool[] $recipeTools
 * @property CraftItem $item
 * @property CraftItem[] $itemsSource
 * @property CraftItem[] $itemsTool
 */
class CraftRecipe extends ActiveRecord implements ModelNameInterface
{
    use ModelQueryTrait;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'craft_recipe';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['name', 'category_id', 'item_id'], 'required'],
            [['category_id', 'item_id'], 'default', 'value' => null],
            [['active'], 'default', 'value' => 0],
            [['category_id', 'item_id', 'active'], 'integer'],
            [['name'], 'string', 'max' => 50],
            [['description'], 'string', 'max' => 5000],
            [['item_id'], 'unique'],
            [['name'], 'unique'],
            [['category_id'], 'exist', 'skipOnError' => true, 'targetClass' => CraftCategory::class, 'targetAttribute' => ['category_id' => 'id']],
            [['item_id'], 'exist', 'skipOnError' => true, 'targetClass' => CraftItem::class, 'targetAttribute' => ['item_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('craft', 'ID'),
            'name' => Yii::t('craft', 'Name'),
            'description' => Yii::t('craft', 'Description'),
            'category_id' => Yii::t('craft', 'Category ID'),
            'item_id' => Yii::t('craft', 'Item ID'),
            'active' => Yii::t('craft', 'Active'),
        ];
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Gets query for [[Category]].
     *
     * @return ActiveQuery|CraftCategoryQuery
     */
    public function getCategory()
    {
        return $this->hasOne(CraftCategory::class, ['id' => 'category_id']);
    }

    /**
     * Gets query for [[CraftHistories]].
     *
     * @return ActiveQuery|CraftHistoryQuery
     */
    public function getHistories()
    {
        return $this->hasMany(CraftHistory::class, ['recipe_id' => 'id']);
    }

    /**
     * Gets query for [[CraftRecipeItems]].
     *
     * @return ActiveQuery|CraftRecipeItemQuery
     */
    public function getRecipeItems()
    {
        return $this->hasMany(CraftRecipeItem::class, ['recipe_id' => 'id']);
    }

    /**
     * Gets query for [[CraftRecipeTools]].
     *
     * @return ActiveQuery|CraftRecipeToolQuery
     */
    public function getRecipeTools()
    {
        return $this->hasMany(CraftRecipeTool::class, ['recipe_id' => 'id']);
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
     * Gets query for [[Items]].
     *
     * @return ActiveQuery|CraftItemQuery
     */
    public function getItemsSource()
    {
        return $this->hasMany(CraftItem::class, ['id' => 'item_id'])->viaTable('craft_recipe_item', ['recipe_id' => 'id']);
    }

    /**
     * Gets query for [[Items0]].
     *
     * @return ActiveQuery|CraftItemQuery
     */
    public function getItemsTool()
    {
        return $this->hasMany(CraftItem::class, ['id' => 'item_id'])->viaTable('craft_recipe_tool', ['recipe_id' => 'id']);
    }
}
