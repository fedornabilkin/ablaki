<?php

namespace common\modules\craft\models;

use common\models\core\ModelQueryTrait;
use common\models\ModelNameInterface;
use Yii;
use yii\base\InvalidConfigException;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "craft_item".
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $description
 * @property int|null $crafting_time
 * @property int|null $rare
 * @property int $category_id
 * @property int|null $active
 *
 * @property CraftCategory $category
 * @property string $categoryName
 * @property CraftHistory[] $histories
 * @property CraftInventory[] $inventories
 * @property CraftRecipe $recipeResult
 * @property CraftRecipeItem[] $recipeItems
 * @property CraftRecipeTool[] $recipeTools
 * @property CraftRecipe[] $recipesSource
 * @property CraftRecipe[] $recipesTool
 */
class CraftItem extends ActiveRecord implements ModelNameInterface
{
    use ModelQueryTrait;

    /**
     * Виртульаное свойство для определения количества предметов при перемещении в инвентарь, рынок и обратно.
     * @var int
     */
    protected $quantity = 1;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'craft_item';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['category_id'], 'default', 'value' => 1],
            [['active'], 'default', 'value' => 0],
            [['crafting_time'], 'default', 'value' => 20],
            [['rare'], 'default', 'value' => 100],
            [['crafting_time', 'rare', 'category_id', 'active'], 'integer'],
            [['name', 'category_id'], 'required'],
            [['name'], 'string', 'max' => 50],
            [['description'], 'string', 'max' => 5000],
            [['name'], 'unique'],
            [['category_id'], 'exist', 'skipOnError' => true, 'targetClass' => CraftCategory::class, 'targetAttribute' => ['category_id' => 'id']],
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
            'crafting_time' => Yii::t('craft', 'Crafting Time'),
            'rare' => Yii::t('craft', 'Rare'),
            'category_id' => Yii::t('craft', 'Category ID'),
            'categoryName' => Yii::t('craft', 'Category'),
            'active' => Yii::t('craft', 'Active'),
        ];
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return int
     */
    public function getQuantity(): int
    {
        return $this->quantity;
    }

    /**
     * @param int $quantity
     */
    public function setQuantity(int $quantity): void
    {
        $this->quantity = $quantity;
    }

    /**
     * @return ActiveQuery
     */
    public function getCategory(): ActiveQuery
    {
        return $this->hasOne(CraftCategory::class, ['id' => 'category_id']);
    }

    public function getCategoryName(): string
    {
        return $this->category->name;
    }

    /**
     * @return ActiveQuery
     */
    public function getHistories(): ActiveQuery
    {
        return $this->hasMany(CraftHistory::class, ['item_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getInventories(): ActiveQuery
    {
        return $this->hasMany(CraftInventory::class, ['item_id' => 'id']);
    }

    /**
     * Рецепт, если предмет является его результатом
     *
     * @return ActiveQuery
     */
    public function getRecipeResult(): ActiveQuery
    {
        return $this->hasOne(CraftRecipe::class, ['item_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getRecipeItems(): ActiveQuery
    {
        return $this->hasMany(CraftRecipeItem::class, ['item_id' => 'id']);
    }

    /**
     * @return ActiveQuery
     */
    public function getRecipeTools(): ActiveQuery
    {
        return $this->hasMany(CraftRecipeTool::class, ['item_id' => 'id']);
    }

    /**
     * Рецепты, в которых предмет является ресурсом для создания другого предмета
     *
     * @return ActiveQuery
     *
     * @throws InvalidConfigException
     */
    public function getRecipesSource(): ActiveQuery
    {
        return $this->hasMany(CraftRecipe::class, ['id' => 'recipe_id'])
            ->viaTable('craft_recipe_item', ['item_id' => 'id']);
    }

    /**
     * Рецепты, в которых предмет является инструментом для создания другого предмета
     *
     * @return ActiveQuery
     *
     * @throws InvalidConfigException
     */
    public function getRecipesTool(): ActiveQuery
    {
        return $this->hasMany(CraftRecipe::class, ['id' => 'recipe_id'])
            ->viaTable('craft_recipe_tool', ['item_id' => 'id']);
    }
}
