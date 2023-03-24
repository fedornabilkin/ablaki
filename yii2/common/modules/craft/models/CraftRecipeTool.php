<?php

namespace common\modules\craft\models;

use common\models\core\ModelQueryTrait;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "craft_recipe_tool".
 *
 * @property int $id
 * @property int $recipe_id
 * @property int $item_id
 *
 * @property CraftItem $item
 * @property CraftRecipe $recipe
 */
class CraftRecipeTool extends ActiveRecord
{
    use ModelQueryTrait;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'craft_recipe_tool';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['recipe_id', 'item_id'], 'required'],
            [['recipe_id', 'item_id'], 'integer'],
            [['item_id', 'recipe_id'], 'unique', 'targetAttribute' => ['item_id', 'recipe_id']],
            [['item_id'], 'exist', 'skipOnError' => true, 'targetClass' => CraftItem::class, 'targetAttribute' => ['item_id' => 'id']],
            [['recipe_id'], 'exist', 'skipOnError' => true, 'targetClass' => CraftRecipe::class, 'targetAttribute' => ['recipe_id' => 'id']],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function attributeLabels()
    {
        return [
            'id' => Yii::t('craft', 'ID'),
            'recipe_id' => Yii::t('craft', 'Recipe ID'),
            'item_id' => Yii::t('craft', 'Item ID'),
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
}