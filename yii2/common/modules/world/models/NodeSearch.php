<?php
namespace common\modules\world\models;

use yii\data\ActiveDataProvider;

class NodeSearch extends \yii\base\Model
{
    public $q;
    public $parent_id;
    public $template_id;
    public $hierarchy_level;
    public $status;
    public $owner_user_id;
    public function rules(): array
    {
        return [['q', 'string', 'max' => 120], [['parent_id', 'template_id', 'hierarchy_level', 'owner_user_id'], 'integer', 'min' => 1],
            ['status', 'in', 'range' => ['planned', 'constructing', 'active', 'archived']]];
    }
    public function search(array $params, ?int $user = null): ActiveDataProvider
    {
        $query = $user === null ? Node::find() : Node::visibleTo($user);
        $provider = new ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 20], 'sort' => [
            'defaultOrder' => ['id' => SORT_DESC], 'attributes' => ['id', 'name', 'hierarchy_level', 'status', 'created_at', 'parent_id', 'owner_user_id']]]);
        $this->load($params, isset($params[$this->formName()]) ? $this->formName() : '');
        if (!$this->validate()) { $query->andWhere('0=1'); return $provider; }
        $query->andFilterWhere(['parent_id' => $this->parent_id, 'template_id' => $this->template_id, 'hierarchy_level' => $this->hierarchy_level,
            'owner_user_id' => $this->owner_user_id, 'status' => $this->status])->andFilterWhere(['like', 'name', $this->q]);
        return $provider;
    }
}
