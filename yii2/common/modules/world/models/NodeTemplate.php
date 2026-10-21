<?php
namespace common\modules\world\models;

use yii\helpers\Json;

class NodeTemplate extends LockedRecord
{
    public $parentIds;
    public $worldBuildSeconds;
    public static function tableName() { return 'world_template'; }
    public function beforeValidate()
    {
        if (!parent::beforeValidate()) return false;
        if ((is_int($this->worldBuildSeconds) || is_string($this->worldBuildSeconds)) && ctype_digit((string)$this->worldBuildSeconds)
            && (float)$this->worldBuildSeconds >= 1 && (float)$this->worldBuildSeconds <= 52254720000)
            $this->build_seconds = WorldTime::realSeconds((int)$this->worldBuildSeconds, WorldTime::rate());
        if ($this->parentIds === '') $this->parentIds = [];
        if ($this->parentIds !== null) {
            if (!is_array($this->parentIds)) { $this->addError('parentIds', 'Выберите родителей из списка.'); return true; }
            foreach ($this->parentIds as $id) if ((!is_int($id) && !is_string($id)) || !ctype_digit((string)$id) || (int)$id < 1 || (float)$id > 2147483647) {
                $this->addError('parentIds', 'Некорректный родитель.'); return true;
            }
        }
        $ids = $this->parentIds === null ? $this->getAllowedParents()->select('id')->column() : (array)$this->parentIds;
        if ($this->kind === 'WORLD') { $this->hierarchy_level = 1; return true; }
        $levels = self::find()->select('hierarchy_level')->where(['id' => $ids])->distinct()->column();
        if (count($levels) === 1) $this->hierarchy_level = (int)$levels[0] + 1;
        elseif ($ids) $this->addError('parentIds', 'Все разрешённые родители должны быть одного уровня.');
        return true;
    }
    public function rules(): array
    {
        return [
            [['code', 'kind', 'name', 'hierarchy_level'], 'required'],
            ['code', 'match', 'pattern' => '/^[a-z][a-z0-9-]{0,79}$/D'],
            ['code', 'unique'], ['name', 'string', 'max' => 120],
            ['kind', 'in', 'range' => ['WORLD', 'REGION', 'SETTLEMENT', 'PLOT', 'BUILDING', 'ROOM', 'BED', 'CHEST', 'PLACE']],
            ['hierarchy_level', 'integer', 'min' => 1, 'max' => 7],
            ['build_seconds', 'integer', 'min' => 1, 'max' => 604800],
            ['worldBuildSeconds', 'integer', 'min' => 1, 'max' => 52254720000],
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
            'build_seconds' => 'Трудоёмкость, реальных секунд', 'worldBuildSeconds' => 'Трудоёмкость, секунд мира', 'materials_json' => 'Материалы (код: количество)',
            'defaults_json' => 'Настройки', 'enabled' => 'Доступен', 'player_buildable' => 'Строится игроками', 'parentIds' => 'Разрешённые родители'];
    }
    public function validateJson($attribute): void
    {
        try {
            $data = Json::decode($this->$attribute);
            if (!is_array($data)) throw new \InvalidArgumentException();
            if ($attribute === 'materials_json') foreach ($data as $code => $quantity) {
                if (!is_string($code) || !is_int($quantity) || $quantity < 1 || $quantity > 10000
                    || !modules\ItemLookup::exists($code, 'material')) throw new \InvalidArgumentException();
            }
            if ($attribute === 'defaults_json') {
                if (isset($data['description']) && (!is_string($data['description']) || mb_strlen($data['description']) > 5000)) throw new \InvalidArgumentException();
                foreach (['population', 'plot_limit', 'level', 'condition', 'max_condition', 'area', 'fertility', 'allow_building', 'unlocked'] as $field)
                    if (isset($data[$field]) && (!is_int($data[$field]) || $data[$field] < 0 || $data[$field] > 1000000)) throw new \InvalidArgumentException();
                foreach (['climate', 'settlement_kind', 'building_kind', 'exposure_class', 'plot_kind'] as $field)
                    if (isset($data[$field]) && (!is_string($data[$field]) || strlen($data[$field]) > 32)) throw new \InvalidArgumentException();
                foreach (['build_tools' => 'tool', 'build_stations' => 'station'] as $field => $kind) if (isset($data[$field])) {
                    if (!is_array($data[$field])) throw new \InvalidArgumentException();
                    foreach ($data[$field] as $code => $quantity) if (!is_string($code) || !is_int($quantity) || $quantity < 1 || $quantity > 100
                        || !modules\ItemLookup::exists($code, $kind)) throw new \InvalidArgumentException();
                }
                foreach (['map_width', 'map_height'] as $field) if (isset($data[$field]) && (!is_int($data[$field]) || $data[$field] < 1 || $data[$field] > 1000)) throw new \InvalidArgumentException();
                if (isset($data['storage_capacity']) && (!is_int($data['storage_capacity']) || $data['storage_capacity'] < 1 || $data['storage_capacity'] > 100)) throw new \InvalidArgumentException();
                if (isset($data['allow_sales']) && !is_bool($data['allow_sales'])) throw new \InvalidArgumentException();
            }
        } catch (\Throwable $e) { $this->addError($attribute, 'Укажите корректный JSON; материалы должны существовать в каталоге.'); }
    }
    public function validateHierarchy(): void
    {
        if ($this->hasErrors('parentIds') || !is_string($this->kind)) return;
        if (!$this->isNewRecord && $this->isAttributeChanged('code')) $this->addError('code', 'Постоянный код шаблона нельзя менять.');
        if (($this->kind === 'WORLD') !== ((int)$this->hierarchy_level === 1)) $this->addError('hierarchy_level', 'Корень дерева должен быть миром; остальные объекты размещаются внутри родителя.');
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
        if ($this->player_buildable) return (new domain\CommonNodePolicy())->permitsChild($parent->getAttributes());
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
        return array_merge(parent::fields(), ['description' => function () { return $this->defaults()['description'] ?? ''; },
            'economy' => function () { return domain\EconomyConstruction::terms($this); }, 'world_time_rate' => function () { return WorldTime::rate(); },
            'world_build_seconds' => function () { return (int)$this->build_seconds * WorldTime::rate(); }, 'material_summary' => function () {
            $defaults = $this->defaults();
            $requirements = $this->materials() + ($defaults['build_tools'] ?? []) + ($defaults['build_stations'] ?? []);
            $items = \common\modules\world\modules\craft\models\CraftItem::find()->where(['code' => array_keys($requirements)])->indexBy('code')->all();
            $parts = []; foreach ($requirements as $code => $quantity) $parts[] = ($items[$code]->name ?? $code) . ': ' . $quantity;
            return implode(', ', $parts);
        }]);
    }
}
