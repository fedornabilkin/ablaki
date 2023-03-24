<?php

namespace common\modules\craft\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * CraftInventorySearch represents the model behind the search form of `common\modules\craft\models\CraftInventory`.
 */
class CraftInventorySearch extends CraftInventory
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['id', 'user_id', 'item_id', 'item_quantity', 'slot'], 'integer'],
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
        $query = CraftInventory::find()->with(['user', 'item']);

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
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'id' => $this->id,
            'user_id' => $this->user_id,
            'item_id' => $this->item_id,
            'item_quantity' => $this->item_quantity,
            'slot' => $this->slot,
        ]);

        return $dataProvider;
    }
}
