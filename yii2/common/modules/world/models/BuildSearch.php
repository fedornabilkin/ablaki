<?php
namespace common\modules\world\models;

class BuildSearch extends \yii\base\Model
{
    public $q; public $parent_id; public $node_id; public $status;
    public function rules(): array
    {
        return [['q', 'string', 'max' => 120], [['parent_id', 'node_id'], 'integer', 'min' => 1],
            ['status', 'in', 'range' => ['building', 'completed', 'cancelled']]];
    }
    public function search(int $user, array $params): \yii\data\ActiveDataProvider
    {
        $query = Build::find()->where(['or', ['owner_user_id' => $user], ['and', ['status' => 'building'], ['>', 'labor_budget', 0]]])
            ->andWhere(['node_id' => Node::visibleTo($user)->select('id')]);
        $provider = new \yii\data\ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 20],
            'sort' => ['defaultOrder' => ['id' => SORT_DESC], 'attributes' => ['id', 'created_at', 'required_seconds']]]);
        $this->load($params, '');
        if (!$this->validate()) throw new \common\modules\world\support\GameError('INVALID_FILTER', 'Проверьте фильтры строительства.', 422, $this->errors);
        $query->andFilterWhere(['parent_id' => $this->parent_id, 'node_id' => $this->node_id, 'status' => $this->status]);
        if ($this->q !== null && $this->q !== '') $query->andWhere(['node_id' => Node::find()->select('id')->where(['like', 'name', $this->q])]);
        return $provider;
    }
}
