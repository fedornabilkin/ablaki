<?php

namespace backend\models;

use common\models\Commission;
use yii\base\Model;
use yii\data\ActiveDataProvider;

class CommissionSearch extends Commission
{
    /**
     * {@inheritdoc}
     */
    public function rules()
    {
        return [
            [['created_at'], 'integer'],
            [['type'], 'string'],
            [['amount'], 'double'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function scenarios()
    {
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
        $query = Commission::find()
            ->orderBy(['id' => SORT_DESC,]);

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params);

        if (!$this->validate()) {
            return $dataProvider;
        }

        // grid filtering conditions
        $query->andFilterWhere([
            'type' => $this->type,
            'amount' => $this->amount,
            'created_at' => $this->created_at,
        ]);

        return $dataProvider;
    }
}
