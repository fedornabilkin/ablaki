<?php

namespace backend\models\history;

use common\models\history\HistoryBalance;
use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * HistoryBalanceSearch represents the model behind the search form of `common\models\HistoryBalance`.
 */
class HistoryBalanceSearch extends HistoryBalance
{
    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['user_id', 'created_at'], 'integer'],
            [['balance', 'credit', 'balance_up', 'credit_up'], 'number'],
            [['type', 'comment'], 'string'],
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
        $query = HistoryBalance::find()
            ->with('user')
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
            'user_id' => $this->user_id,
            'balance' => $this->balance,
            'credit' => $this->credit,
            'balance_up' => $this->balance_up,
            'credit_up' => $this->credit_up,
            'created_at' => $this->created_at,
            'type' => $this->type,
        ]);

        $query->andFilterWhere(['like', 'comment', $this->comment]);

        return $dataProvider;
    }
}
