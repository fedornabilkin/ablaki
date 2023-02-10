<?php

namespace backend\models;

use common\models\user\Person;
use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * PersonSearch represents the model behind the search form of `common\models\user\Person`.
 */
class PersonSearch extends Person
{
    /**
     * {@inheritdoc}
     */
    public function rules(): array
    {
        return [
            [['id', 'user_id', 'refovod', 'bonus_count', 'autoriz'], 'integer'],
            [['balance', 'balance_in', 'balance_out', 'credit', 'rating'], 'number'],
            [['referrer'], 'safe'],
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
        $query = Person::find()
            ->with('user', 'refovodUser')
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
            'balance' => $this->balance,
            'balance_in' => $this->balance_in,
            'balance_out' => $this->balance_out,
            'credit' => $this->credit,
            'refovod' => $this->refovod,
            'rating' => $this->rating,
            'bonus_count' => $this->bonus_count,
            'autoriz' => $this->autoriz,
        ]);

        $query->andFilterWhere(['like', 'referrer', $this->referrer]);

        return $dataProvider;
    }
}
