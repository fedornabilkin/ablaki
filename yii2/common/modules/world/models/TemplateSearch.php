<?php
namespace common\modules\world\models;

class TemplateSearch extends \yii\base\Model
{
    public $q;
    public $hierarchy_level;
    public $enabled;
    public function rules(): array { return [['q', 'string', 'max' => 120], ['hierarchy_level', 'integer', 'min' => 1, 'max' => 7], ['enabled', 'boolean']]; }
    public function search(array $params, ?int $parentId = null): \yii\data\ActiveDataProvider
    {
        $query = NodeTemplate::find()->andWhere(['not', ['hierarchy_level' => null]]);
        $provider = new \yii\data\ActiveDataProvider(['query' => $query, 'pagination' => ['pageSize' => 30],
            'sort' => ['defaultOrder' => ['id' => SORT_DESC], 'attributes' => ['id', 'name', 'code', 'hierarchy_level', 'build_seconds']]]);
        $this->load($params, isset($params[$this->formName()]) ? $this->formName() : '');
        if (!$this->validate()) { $query->andWhere('0=1'); return $provider; }
        $query->andFilterWhere(['hierarchy_level' => $this->hierarchy_level, 'enabled' => $this->enabled])->andFilterWhere(['like', 'name', $this->q]);
        if ($parentId !== null) {
            $parent = Node::requireOne($parentId);
            $allowed = (new \yii\db\Query())->select('template_id')->from('world_template_parent')->where(['parent_template_id' => $parent->template_id]);
            $query->andWhere(['id' => $allowed, 'enabled' => 1, 'player_buildable' => 1]);
        }
        return $provider;
    }
    public static function options(): array { return \yii\helpers\ArrayHelper::map(NodeTemplate::find()->where(['enabled' => 1])->andWhere(['not', ['hierarchy_level' => null]])->orderBy(['hierarchy_level' => SORT_ASC, 'name' => SORT_ASC])->all(), 'id', 'name'); }
}
