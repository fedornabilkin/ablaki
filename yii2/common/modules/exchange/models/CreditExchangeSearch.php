<?php

namespace common\modules\exchange\models;

use yii\data\ActiveDataProvider;

/**
 * CreditExchangeSearch represents the model behind the search form of `common\modules\exchange\models\CreditExchange`.
 */
class CreditExchangeSearch extends CreditExchange
{
    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['user_id', 'user_buyer', 'updated_at', 'created_at'], 'integer'],
            [['credit', 'amount'], 'number'],
            ['type', 'safe'],
        ];
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
        $query = CreditExchange::find()
            ->with('user', 'userBuyer')
            ->orderBy(['id' => SORT_DESC]);

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
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user_buyer' => $this->user_buyer,
            'credit' => $this->credit,
            'amount' => $this->amount,
            'type' => $this->type,
            'updated_at' => $this->updated_at,
            'created_at' => $this->created_at,
        ]);

        return $dataProvider;
    }
}
