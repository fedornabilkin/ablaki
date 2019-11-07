<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.05.2018
 * Time: 17:27
 */

namespace common\modules\games\models;


use yii\base\Model;
use yii\data\ActiveDataProvider;

class SaperSearch extends GameSaper
{
    public $status;
    public $currentUser;
    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['user_id', 'user_gamer'], 'integer'],
            [['kon'], 'double'],
            [['status'], 'safe'],
        ];
    }

    /**
     * @inheritdoc
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
        $query = self::find()
            ->with('user')
            ->with('userGamer')
        ;

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

        $query->orderBy(['id' => SORT_ASC]);

        // grid filtering conditions
        if($this->status == 'open'){
            $query->andFilterWhere(['user_gamer' => 0, 'etap' => self::GAME_SAPER_ETAP_NEW]);
//            $query->orFilterWhere(['user_gamer' => $this->currentUser->id, 'etap']);
            $query->orFilterWhere([
                'and', ['=', 'user_gamer', $this->currentUser->id], [
                    'and',
                    ['!=', 'etap', self::GAME_SAPER_ETAP_WIN],
                    ['!=', 'etap', self::GAME_SAPER_ETAP_LOSE]
                ]
            ]);
            $query->orderBy(['user_gamer' => SORT_DESC, 'id' => SORT_ASC]);
        }
        if($this->status == 'last'){
            $query->andFilterWhere(['>', 'user_gamer',  0]);
            $query->andFilterWhere(['<>', 'etap', self::GAME_SAPER_ETAP_NEW]);
            $query->orderBy(['time_over_at' => SORT_DESC]);
        }
        if($this->status == 'history' && is_object($this->currentUser)){
            $query->andFilterWhere(['user_gamer' => $this->currentUser->id]);
            $query->orFilterWhere([
                'and', ['=', 'user_id', $this->currentUser->id], [
                    'or',
                    ['=', 'etap', self::GAME_SAPER_ETAP_WIN],
                    ['=', 'etap', self::GAME_SAPER_ETAP_LOSE]
                ]
            ]);
            $query->orderBy(['time_over_at' => SORT_DESC]);
        }

        $query->andFilterWhere(['kon' => $this->kon]);
        $query->andFilterWhere(['user_id' => $this->user_id]);
        $query->andFilterWhere(['user_gamer' => $this->user_gamer]);



        return $dataProvider;
    }
}