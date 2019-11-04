<?php

namespace common\modules\games\models;

use yii\base\Model;
use yii\data\ActiveDataProvider;

/**
 * OrelSearch represents the model behind the search form of `common\models\games\GameOrel`.
 */
class OrelSearch extends GameOrel
{
    public $status;

    /** {@inheritdoc} */
    public function rules()
    {
        return [
            [['id', 'user_id', 'user_gamer', 'type'], 'integer'],
//            [['kon'], 'number'],
            [['status'], 'safe'],
        ];
    }

    /** {@inheritdoc} */
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
        $userId =  is_object($this->personInstance) ? $this->personInstance->user->id : null;

        $query = self::find()
            ->with('user')
            ->with('userGamer')
        ;

        // add conditions that should always apply here

        $dataProvider = new ActiveDataProvider([
            'query' => $query,
        ]);

        $this->load($params);

//        if (!$this->validate()) {
//            // uncomment the following line if you do not want to return any records when validation fails
//            // $query->where('0=1');
//            return $dataProvider;
//        }

        $query->orderBy(['id' => SORT_ASC]);

        // grid filtering conditions
        if($this->status == 'last'){
            $query->andFilterWhere(['>', 'user_gamer',  0]);
            $query->orderBy(['updated_at' => SORT_DESC]);
        }
        if($this->status == 'open' && is_object($this->personInstance)){
            $query->andFilterWhere(['user_gamer' => 0]);
            $query->andFilterWhere(['!=', 'user_id', $userId]);
        }
        if($this->status == 'history' && is_object($this->personInstance)){
            // я играл или мои игры кто-то играл
            $query->andFilterWhere(['user_gamer' => $userId]);
            $query->orFilterWhere([
                'and', ['=', 'user_id', $userId], ['>', 'user_gamer', 0],
            ]);

            $query->orderBy(['updated_at' => SORT_DESC]);
        }

        $query->andFilterWhere(['kon' => $this->kon]);
        $query->andFilterWhere(['user_id' => $this->user_id]);
        $query->andFilterWhere(['user_gamer' => $this->user_gamer]);

        // grid filtering conditions
//        $query->andFilterWhere([
//            'id' => $this->id,
//            'user_id' => $this->user_id,
//            'user_gamer' => $this->user_gamer,
//            'kon' => $this->kon,
//            'type' => $this->type,
//            'hod' => $this->hod,
//            'updated_at' => $this->updated_at,
//            'created_at' => $this->created_at,
//        ]);

        return $dataProvider;
    }
}
