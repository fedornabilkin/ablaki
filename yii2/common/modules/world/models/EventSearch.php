<?php
namespace common\modules\world\models;

class EventSearch extends \yii\base\Model
{
    public $q;
    public $scope_node_id;
    public $status;
    public function rules(): array { return [['q', 'string', 'max' => 120], ['scope_node_id', 'integer', 'min' => 1], ['status', 'in', 'range' => ['draft', 'active', 'closed']]]; }
    public function search(array $params, ?int $node = null, ?int $user = null): \yii\data\ActiveDataProvider
    {
        $query = $node === null ? WorldEvent::find() : WorldEvent::atNode($node, $user);
        $provider = new \yii\data\ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 20], 'sort' => ['defaultOrder' => ['id' => SORT_DESC], 'attributes' => ['id', 'name', 'starts_at', 'ends_at', 'status']]]);
        $this->load($params, isset($params[$this->formName()]) ? $this->formName() : '');
        if (!$this->validate()) { $query->andWhere('0=1'); return $provider; }
        $query->andFilterWhere(['scope_node_id' => $this->scope_node_id, 'status' => $this->status])->andFilterWhere(['like', 'name', $this->q]);
        return $provider;
    }
}
