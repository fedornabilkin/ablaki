<?php

namespace common\modules\craft\models;

use common\models\core\ModelQueryTrait;
use common\models\ModelNameInterface;
use Yii;
use yii\db\ActiveQuery;
use yii\db\ActiveRecord;

/**
 * This is the model class for table "craft_category".
 *
 * @property int $id
 * @property string|null $name
 * @property string|null $description
 *
 * @property CraftItem[] $craftItems
 * @property CraftRecipe[] $craftRecipes
 */
class CraftCategory extends ActiveRecord implements ModelNameInterface
{
    use ModelQueryTrait;

    /**
     * {@inheritdoc}
     */
    public static function tableName()
    {
        return 'craft_category';
    }

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['name'], 'string', 'max' => 50],
            [['name'], 'required'],
            [['description'], 'string', 'max' => 5000],
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
        ];
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Gets query for [[CraftItems]].
     *
     * @return ActiveQuery|CraftItemQuery
     */
    public function getCraftItems()
    {
        return $this->hasMany(CraftItem::class, ['category_id' => 'id']);
    }

    /**
     * Gets query for [[CraftRecipes]].
     *
     * @return ActiveQuery|CraftRecipeQuery
     */
    public function getCraftRecipes()
    {
        return $this->hasMany(CraftRecipe::class, ['category_id' => 'id']);
    }
}
