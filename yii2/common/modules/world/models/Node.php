<?php
namespace common\modules\world\models;

use common\modules\world\support\GameError;
use yii\behaviors\TimestampBehavior;

/** One physical table for all seven levels. Type details are optional columns of this record. */
class Node extends LockedRecord
{
    public const LEVELS = [1 => 'Мир', 2 => 'Регион', 3 => 'Поселение', 4 => 'Стоянка / усадьба',
        5 => 'Постройка / огород / шахта', 6 => 'Комната / грядка / сундук', 7 => 'Место / полка'];
    public static function tableName() { return 'world_node'; }
    public function behaviors(): array { return [TimestampBehavior::class]; }
    public function optimisticLock() { return 'revision'; }
    public function transactions() { return [self::SCENARIO_DEFAULT => self::OP_ALL]; }
    public function afterSave($insert, $changedAttributes)
    {
        parent::afterSave($insert, $changedAttributes);
        if (!$insert) return;
        $db = self::getDb();
        if (!$this->parent_id) { $this->root_id = $this->id; self::updateAll(['root_id' => $this->id], ['id' => $this->id]); }
        $db->createCommand()->insert('world_node_closure', ['ancestor_id' => $this->id, 'descendant_id' => $this->id, 'distance' => 0])->execute();
        if ($this->parent_id) foreach ((new \yii\db\Query())->from('world_node_closure')->where(['descendant_id' => $this->parent_id])->all($db) as $ancestor)
            $db->createCommand()->insert('world_node_closure', ['ancestor_id' => $ancestor['ancestor_id'], 'descendant_id' => $this->id, 'distance' => $ancestor['distance'] + 1])->execute();
    }
    public function rules(): array
    {
        return [
            [['name', 'code', 'slug', 'template_id'], 'required'],
            [['name', 'label'], 'string', 'max' => 120], ['name', 'filter', 'filter' => 'trim'],
            [['code', 'slug'], 'match', 'pattern' => '/^[a-z0-9][a-z0-9-]{0,79}$/D'],
            ['code', 'unique'], [['parent_id', 'slug'], 'unique', 'targetAttribute' => ['parent_id', 'slug']],
            [['parent_id', 'template_id', 'owner_user_id', 'position_x', 'position_y'], 'integer'],
            ['owner_user_id', 'exist', 'targetClass' => \common\models\user\User::class, 'targetAttribute' => 'id'],
            ['visibility', 'in', 'range' => ['public', 'private']],
            ['status', 'in', 'range' => ['planned', 'constructing', 'active', 'archived']],
            ['template_id', 'validatePlacement'], ['status', 'validateArchive'],
        ];
    }
    public function attributeLabels(): array
    {
        return ['name' => 'Название', 'code' => 'Код', 'slug' => 'Адрес', 'template_id' => 'Шаблон', 'parent_id' => 'Родитель',
            'owner_user_id' => 'Владелец', 'hierarchy_level' => 'Уровень', 'status' => 'Состояние', 'visibility' => 'Видимость',
            'position_x' => 'X', 'position_y' => 'Y', 'created_at' => 'Создан'];
    }
    public function getParent() { return $this->hasOne(self::class, ['id' => 'parent_id']); }
    public function getChildren() { return $this->hasMany(self::class, ['parent_id' => 'id']); }
    public function getTemplate() { return $this->hasOne(NodeTemplate::class, ['id' => 'template_id']); }
    public function getBuild() { return $this->hasOne(Build::class, ['node_id' => 'id']); }
    public function validatePlacement(): void
    {
        $template = NodeTemplate::findOne($this->template_id);
        $parent = $this->parent_id ? self::findOne($this->parent_id) : null;
        if (!$template || ($this->isNewRecord && !$template->enabled) || ($this->parent_id && !$parent) || !$template->permits($parent)) {
            $this->addError('parent_id', 'Шаблон нельзя построить в выбранном объекте.'); return;
        }
        if (!$this->isNewRecord && ($this->isAttributeChanged('code') || $this->isAttributeChanged('parent_id') || $this->isAttributeChanged('template_id') || $this->isAttributeChanged('owner_user_id')))
            $this->addError('parent_id', 'Для существующего объекта родитель, шаблон и владелец фиксированы.');
        if ($parent && (int)$parent->hierarchy_level >= 4 && (int)$parent->owner_user_id !== (int)$this->owner_user_id)
            $this->addError('owner_user_id', 'Объект должен принадлежать владельцу территории.');
        $this->hierarchy_level = (int)$template->hierarchy_level;
        $this->depth = $this->hierarchy_level - 1;
        $this->node_type = $template->kind;
        $this->root_id = $parent ? $parent->root_id : $this->id;
        if ($this->isNewRecord && $this->map_width === null) {
            NodeFactory::initialize($this, $template);
            if ($parent) {
                $position = (new domain\WorldTree(self::getDb()))->nextPosition((int)$parent->id);
                $this->position_x = $position['x']; $this->position_y = $position['y'];
            }
        }
        if ($this->isNewRecord && $this->hierarchy_level > 3 && $this->status === 'active')
            $this->addError('status', 'Сначала завершите строительство.');
        if (!$this->isNewRecord && $this->isAttributeChanged('status') && $this->status === 'active'
            && $this->hierarchy_level > 3 && (!$this->build || $this->build->status !== 'completed'))
            $this->addError('status', 'Сначала завершите строительство.');
    }
    public static function visibleTo(int $user)
    {
        $hidden = (new \yii\db\Query())->select('c.descendant_id')->from(['c' => 'world_node_closure'])
            ->innerJoin(['n' => 'world_node'], 'n.id=c.ancestor_id')->where(['or', ['n.status' => 'archived'],
                ['and', ['n.visibility' => 'private'], ['or', ['n.owner_user_id' => null], ['<>', 'n.owner_user_id', $user]]]]);
        return self::find()->where(['not in', 'id', $hidden])->andWhere(['<>', 'status', 'archived']);
    }
    public function validateArchive(): void
    {
        if ($this->status !== 'archived' || !$this->isAttributeChanged('status')) return;
        if (Registry::worldId() === (int)$this->id) { $this->addError('status', 'Нельзя архивировать действующий мир.'); return; }
        if ((!$this->build || $this->build->status !== 'cancelled') && NodeUsage::hasReferences((int)$this->id))
            $this->addError('status', 'Объект используется игрой. Сначала завершите связанные операции.');
        if ($this->getChildren()->andWhere(['<>', 'status', 'archived'])->exists()
            || ($this->build && $this->build->status === 'building') || NodeUsage::hasAssets((int)$this->id))
            $this->addError('status', 'Объект содержит постройки, имущество или средства. Сначала завершите связанные операции.');
    }
    public static function readable(int $id, int $user): self
    {
        $node = self::visibleTo($user)->andWhere(['id' => $id])->one();
        if (!$node) throw new GameError('NODE_NOT_FOUND', 'Объект не найден.', 404);
        return $node;
    }
    public function requireOwner(int $user): void
    {
        if ((int)$this->owner_user_id !== $user) throw new GameError('OWNER_REQUIRED', 'Требуются права владельца.', 403);
    }
    public function beforeDelete() { $this->addError('id', 'Используйте архив: объект связан с историей мира.'); return false; }
    public function fields(): array
    {
        return ['id', 'parent_id', 'root_id', 'template_id', 'hierarchy_level', 'node_type', 'name', 'status', 'visibility',
            'owner_user_id', 'building_kind', 'position_x', 'position_y', 'revision'];
    }
}
