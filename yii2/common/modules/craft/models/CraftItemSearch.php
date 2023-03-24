<?php

namespace common\modules\craft\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * CraftItemSearch represents the model behind the search form of `common\modules\craft\models\CraftItem`.
 */
class CraftItemSearch extends CraftItem
{
    public $categoryName;

    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'crafting_time', 'rare', 'category_id', 'active'], 'integer'],
            [['name', 'description', 'categoryName'], 'safe'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
        // bypass scenarios() implementation in the parent class
        return Model::scenarios();
    }

    /**
     * Creates data provider instance with search query applied
     *
     * @param array $params
     *
     * @return ActiveDataProvider
     */
    public function search($params)
    {
        $query = CraftItem::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
            'sort' => [
                'defaultOrder' => ['id' => SORT_DESC]
            ],
        ]);

        $this->load($params);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            $query->with('category');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'crafting_time' => $this->crafting_time,
            'rare' => $this->rare,
            'category_id' => $this->category_id,
            'active' => $this->active,
        ]);

        $query->andFilterWhere(['like', 'name', $this->name])
            ->andFilterWhere(['like', 'description', $this->description]);

        $query->joinWith(['category' => function ($q) {
            return $q->andFilterWhere(['like', 'craft_category.name', $this->categoryName]);
        }]);

        return $dataProvider;
    }
}
