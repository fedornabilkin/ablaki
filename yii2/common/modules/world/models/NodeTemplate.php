<?php
namespace common\modules\world\models;

use yii\helpers\Json;

class NodeTemplate extends LockedRecord
{
    public $parentIds;
    public static function tableName() { return 'world_template'; }
    public function rules(): array
    {
        return [
            [['code', 'kind', 'name', 'hierarchy_level'], 'required'],
            ['code', 'match', 'pattern' => '/^[a-z][a-z0-9-]{0,79}$/D'],
            ['code', 'unique'], ['name', 'string', 'max' => 120],
            ['kind', 'in', 'range' => ['WORLD', 'REGION', 'SETTLEMENT', 'PLOT', 'BUILDING', 'ROOM', 'BED', 'CHEST', 'PLACE']],
            ['hierarchy_level', 'integer', 'min' => 1, 'max' => 7],
            ['build_seconds', 'integer', 'min' => 1, 'max' => 604800],
            [['enabled', 'player_buildable'], 'boolean'],
            [['materials_json', 'defaults_json'], 'default', 'value' => '{}'],
            [['materials_json', 'defaults_json'], 'validateJson'],
            ['parentIds', 'each', 'rule' => ['integer', 'min' => 1]],
            ['hierarchy_level', 'validateHierarchy'],
        ];
    }
    public function attributeLabels(): array
    {
        return ['code' => 'Код', 'name' => 'Название', 'kind' => 'Тип', 'hierarchy_level' => 'Уровень (1–7)',
            'build_seconds' => 'Трудоёмкость, секунд', 'materials_json' => 'Материалы (код: количество)',
            'defaults_json' => 'Настройки', 'enabled' => 'Доступен', 'player_buildable' => 'Строится игроками', 'parentIds' => 'Разрешённые родители'];
    }
    public function validateJson($attribute): void
    {
        try {
            $data = Json::decode($this->$attribute);
            if (!is_array($data)) throw new \InvalidArgumentException();
            if ($attribute === 'materials_json') foreach ($data as $code => $quantity) {
                if (!is_string($code) || !is_int($quantity) || $quantity < 1 || $quantity > 10000
                    || !modules\ItemLookup::exists($code)) throw new \InvalidArgumentException();
            }
            if ($attribute === 'defaults_json') {
                foreach (['population', 'plot_limit', 'level', 'condition', 'max_condition', 'area', 'fertility', 'allow_building', 'unlocked'] as $field)
                    if (isset($data[$field]) && (!is_int($data[$field]) || $data[$field] < 0 || $data[$field] > 1000000)) throw new \InvalidArgumentException();
                foreach (['climate', 'settlement_kind', 'building_kind', 'exposure_class', 'plot_kind'] as $field)
                    if (isset($data[$field]) && (!is_string($data[$field]) || strlen($data[$field]) > 32)) throw new \InvalidArgumentException();
            }
        } catch (\Throwable $e) { $this->addError($attribute, 'Укажите корректный JSON; материалы должны существовать в каталоге.'); }
    }
    public function validateHierarchy(): void
    {
        $levels = ['WORLD' => [1], 'REGION' => [2], 'SETTLEMENT' => [3], 'PLOT' => [4, 5], 'BUILDING' => [5], 'ROOM' => [6], 'BED' => [6], 'CHEST' => [6], 'PLACE' => [7]];
        if (!$this->isNewRecord && $this->isAttributeChanged('code')) $this->addError('code', 'Постоянный код шаблона нельзя менять.');
        if (!in_array((int)$this->hierarchy_level, $levels[$this->kind] ?? [], true)) $this->addError('hierarchy_level', 'Уровень не соответствует типу объекта.');
        if ((int)$this->hierarchy_level <= 3 && $this->player_buildable) $this->addError('player_buildable', 'Географию создаёт администратор.');
        if (!$this->isNewRecord && ($this->isAttributeChanged('hierarchy_level') || $this->isAttributeChanged('kind'))
            && Node::find()->where(['template_id' => $this->id])->exists()) $this->addError('hierarchy_level', 'Шаблон уже используется. Создайте новый шаблон.');
        $ids = $this->parentIds === null ? $this->getAllowedParents()->select('id')->column() : (array)$this->parentIds;
        if ((int)$this->hierarchy_level === 1 && $ids) $this->addError('parentIds', 'У мира нет родителя.');
        if ((int)$this->hierarchy_level > 1 && !$ids) $this->addError('parentIds', 'Выберите допустимого родителя.');
        foreach ($ids as $id) {
            $parent = self::findOne($id);
            if (!$parent || (int)$parent->hierarchy_level + 1 !== (int)$this->hierarchy_level) $this->addError('parentIds', 'Родитель должен быть на предыдущем уровне.');
        }
        if (!$this->isNewRecord && $this->parentIds !== null) {
            $used = Node::find()->alias('n')->innerJoin(['p' => 'world_node'], 'p.id=n.parent_id')
                ->where(['n.template_id' => $this->id])->select('p.template_id')->distinct()->column();
            if (array_diff($used, $ids)) $this->addError('parentIds', 'Эта связь используется существующими объектами.');
        }
    }
    public function getAllowedParents()
    {
        return $this->hasMany(self::class, ['id' => 'parent_template_id'])->viaTable('world_template_parent', ['template_id' => 'id']);
    }
    public function permits(?Node $parent): bool
    {
        if (!$parent) return (int)$this->hierarchy_level === 1;
        return $parent->status === 'active' && (int)$parent->hierarchy_level + 1 === (int)$this->hierarchy_level
            && $this->getAllowedParents()->andWhere(['id' => $parent->template_id])->exists();
    }
    public function transactions() { return [self::SCENARIO_DEFAULT => self::OP_ALL]; }
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        if ($this->parentIds === null) return;
        self::getDb()->createCommand()->delete('world_template_parent', ['template_id' => $this->id])->execute();
        foreach (array_unique((array)$this->parentIds) as $id) self::getDb()->createCommand()->insert('world_template_parent', ['template_id' => $this->id, 'parent_template_id' => $id])->execute();
    }
    public function beforeDelete() { $this->addError('id', 'Отключите шаблон вместо удаления.'); return false; }
    public function materials(): array { return Json::decode($this->materials_json); }
    public function defaults(): array { return Json::decode($this->defaults_json); }
    public function fields(): array
    {
        return array_merge(parent::fields(), ['material_summary' => function () {
            $items = \common\modules\world\modules\craft\models\CraftItem::find()->where(['code' => array_keys($this->materials())])->indexBy('code')->all();
            $parts = []; foreach ($this->materials() as $code => $quantity) $parts[] = ($items[$code]->name ?? $code) . ': ' . $quantity;
            return implode(', ', $parts);
        }]);
    }
}
