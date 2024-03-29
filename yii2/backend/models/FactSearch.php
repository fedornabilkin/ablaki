<?php

namespace backend\models;

use common\models\Fact as FactModel;
use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * Fact represents the model behind the search form of `common\models\Fact`.
 */
class FactSearch extends FactModel
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['hide'], 'integer'],
            [['title', 'type'], 'safe'],
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
        $query = FactModel::find();

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params);

        if (!$this->validate()) {
            // uncomment the following line if you do not want to return any records when validation fails
            // $query->where('0=1');
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'hide' => $this->hide,
            'type' => $this->type,
        ]);

        $query->andFilterWhere(['like', 'type', $this->type]);

        return $dataProvider;
    }
}
