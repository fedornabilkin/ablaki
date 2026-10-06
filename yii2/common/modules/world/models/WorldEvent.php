<?php
namespace common\modules\world\models;

class WorldEvent extends LockedRecord
{
    public static function tableName() { return 'world_event'; }
    public function optimisticLock() { return 'revision'; }
    public function rules(): array
    {
        return [[['name', 'scope_node_id', 'starts_at', 'status'], 'required'], ['name', 'string', 'max' => 120],
            ['description', 'string', 'max' => 10000], [['scope_node_id', 'starts_at', 'ends_at'], 'integer', 'min' => 1],
            ['status', 'in', 'range' => ['draft', 'active', 'closed']], ['scope_node_id', 'validateScope'],
            ['ends_at', 'compare', 'compareAttribute' => 'starts_at', 'operator' => '>', 'type' => 'number']];
    }
    public function validateScope(): void
    {
        if (!Node::find()->where(['id' => $this->scope_node_id, 'status' => 'active'])->andWhere(['<=', 'hierarchy_level', 6])->exists())
            $this->addError('scope_node_id', 'Выберите действующий объект от мира до комнаты или грядки.');
    }
    public function beforeSave($insert)
    {
        if ($insert) { $this->seed = bin2hex(random_bytes(16)); $this->rules_json = '{}'; $this->revision = 1; }
        return parent::beforeSave($insert);
    }
    public function attributeLabels(): array
    {
        return ['name' => 'Название', 'scope_node_id' => 'Область события', 'description' => 'Описание', 'starts_at' => 'Начало (Unix)', 'ends_at' => 'Конец (Unix)', 'status' => 'Состояние'];
    }
    public static function atNode(int $id, int $user)
    {
        Node::readable($id, $user);
        $ancestors = (new \yii\db\Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $id]);
        return self::find()->where(['scope_node_id' => $ancestors, 'status' => 'active'])->andWhere(['<=', 'starts_at', time()])
            ->andWhere(['or', ['ends_at' => null], ['>', 'ends_at', time()]]);
    }
    public function fields(): array { return ['id', 'name', 'scope_node_id', 'description', 'starts_at', 'ends_at', 'status']; }
    public function beforeDelete() { $this->status = 'closed'; $this->save(); return false; }
}
