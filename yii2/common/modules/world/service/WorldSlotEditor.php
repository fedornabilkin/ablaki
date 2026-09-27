<?php
namespace common\modules\world\service;

use common\modules\world\model\WorldSlotForm;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Empty administrative rooms only; paid capacities and occupied places retain their contracts. */
class WorldSlotEditor
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    public function state(int $node): array
    {
        $room = (new WorldNodeEditor($this->db, $this->flags))->snapshot($node);
        if (!in_array($room['node']['node_type'], ['ROOM', 'PLOT'], true)) throw new GameError('ROOM_REQUIRED', 'Выберите комнату или участок.', 422);
        $storage = (new Query())->from('craft_storage')->where(['node_id' => $node, 'kind' => 'placement'])->one($this->db) ?: null;
        $slots = $storage ? (new Query())->from('world_slot')->where(['storage_id' => $storage['id']])->indexBy('position')->orderBy(['position' => SORT_ASC])->all($this->db) : [];
        return compact('room', 'storage', 'slots');
    }
    private function prepare(array $input, string $action): array
    {
        $this->flags->requireFlag('storage_v2');
        $state = $this->state($input['node_id']); $node = $state['room']['node']; $storage = $state['storage']; $slots = $state['slots'];
        if ($node['node_type'] !== 'ROOM') throw new GameError('ROOM_REQUIRED', 'Редактор создаёт места в комнатах. Уличные места предоставляются вместе со стоянкой.', 422);
        if ((int)$node['revision'] !== $input['revision'] || (int)($storage['revision'] ?? 0) !== $input['storage_revision']) throw new GameError('REVISION_CHANGED', 'Комната или её места изменились. Откройте форму заново.');
        if ((new Query())->from(['n' => 'world_node'])->innerJoin(['p' => 'world_node_closure'], '[[p.ancestor_id]]=[[n.id]]')->where(['p.descendant_id' => $node['id']])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('ROOM_INACTIVE', 'Комната находится в архиве.');
        if ($storage && ((!in_array($storage['status'], ['active', 'retired'], true)) || ($storage['status'] === 'retired' && (int)$storage['capacity'] !== 0)
            || (int)$storage['owner_user_id'] !== (int)$node['owner_user_id'])) throw new GameError('STORAGE_MISMATCH', 'Хранилище комнаты требует восстановления.');
        $position = $action === 'create' ? count($slots) + 1 : $input['position'];
        $before = $slots[$position] ?? null;
        if ($action !== 'create' && !$before) throw new GameError('SLOT_NOT_FOUND', 'Место не найдено.', 404);
        if ($storage && (count($slots) !== (int)$storage['capacity'] || array_map('intval', array_keys($slots)) !== (count($slots) ? range(1, count($slots)) : []))) throw new GameError('PAID_CAPACITY', 'Вместимость содержит закрытые или несогласованные места. Используйте покупку расширения.');
        if ($storage && (new Query())->from('craft_inventory')->where(['storage_id' => $storage['id'], 'slot' => $position])->andWhere(['>', 'item_quantity', 0])->exists($this->db)) throw new GameError('SLOT_OCCUPIED', 'Перед изменением или удалением освободите место.');
        if ((new Query())->from('world_premises_purchase')->where(['room_id' => $node['id']])->exists($this->db)
            || (new Query())->from('world_expansion_policy')->where(['node_id' => $node['id']])->exists($this->db)
            || (new Query())->from('world_housing_place')->where(['room_id' => $node['id']])->exists($this->db)) throw new GameError('PAID_CAPACITY', 'Места купленной комнаты закреплены условиями покупки. Изменяйте предложение для будущих покупок, расширяйте комнату игровым действием.');
        if ($action === 'delete' && $position !== count($slots)) throw new GameError('SLOT_ORDER', 'Удалять можно последнее пустое место: номера остальных мест сохраняются.');
        $after = null;
        if ($action !== 'delete') {
            $form = new WorldSlotForm(); $form->populate($state, $action === 'create' ? 0 : $position);
            $form->setAttributes($input['slot'] + ['reason' => $input['reason']]);
            $compatibility = json_decode($input['slot']['compatibility_json'], true, 512, JSON_THROW_ON_ERROR);
            $form->item_codes = implode(', ', $compatibility['item_codes'] ?? []);
            if (!$form->validate()) throw new GameError('INVALID_SLOT', 'Проверьте параметры места.', 422);
            $after = $form->payload($position)['slot'];
            if ($after['exposure_class'] !== $state['room']['details']['exposure_class']) throw new GameError('EXPOSURE_MISMATCH', 'Защита места должна совпадать с защитой комнаты.', 422);
            $area = (int)$after['size'];
            foreach ($slots as $number => $slot) {
                if ((int)$number !== $position) $area += (int)$slot['size'];
                if ((int)$number !== $position && $slot['code'] === $after['code']) throw new GameError('SLOT_CODE_OCCUPIED', 'Код места уже занят.', 422);
            }
            if ($area > (int)$state['room']['details']['area'] || $position > 100) throw new GameError('ROOM_CAPACITY', 'Места должны помещаться в площади комнаты; максимум 100 мест.', 422);
            foreach ($compatibility['item_codes'] ?? [] as $code) {
                if (!(new Query())->from('craft_item')->where(['code' => $code, 'active' => 1])->exists($this->db)) throw new GameError('ITEM_NOT_FOUND', 'В каталоге нет действующего предмета с кодом ' . $code . '.', 422);
            }
        }
        $revisions = ['node:' . $node['id'] => (int)$node['revision']];
        if ($storage) $revisions['storage:' . $storage['id']] = (int)$storage['revision'];
        return ['terms' => ['node_id' => (int)$node['id'], 'room_name' => $node['name'], 'owner_user_id' => $node['owner_user_id'],
            'storage_id' => $storage ? (int)$storage['id'] : null, 'position' => $position, 'before' => $before, 'after' => $after,
            'capacity' => count($slots) + ($action === 'create' ? 1 : ($action === 'delete' ? -1 : 0))], 'revisions' => $revisions];
    }
    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.slot.' . $action, $input, function () use ($input, $action) { return $this->prepare($input, $action); });
    }
    public function execute(int $user, array $record): array
    {
        $action = $record['action']; $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $record['key'], 'world.slot.' . $action, $record['input'], $record['quote']['quote_id'], (array)$record['quote']['expected_revisions'],
            function (array $input, array $terms, string $operation) use ($user, $action, $bus) {
                $p = $this->prepare($input, $action);
                if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('SLOT_CHANGED', 'Место изменилось. Повторите предварительный просмотр.');
                $storage = $terms['storage_id'];
                if (!$storage) {
                    $this->db->createCommand()->insert('craft_storage', ['identity_key' => 'placement:node:' . $terms['node_id'], 'kind' => 'placement',
                        'owner_user_id' => $terms['owner_user_id'], 'node_id' => $terms['node_id'], 'capacity' => 0])->execute();
                    $storage = (int)$this->db->getLastInsertID();
                }
                $key = ['storage_id' => $storage, 'position' => $terms['position']];
                if ($action === 'delete') $this->db->createCommand()->delete('world_slot', $key)->execute();
                elseif ($action === 'create') $this->db->createCommand()->insert('world_slot', $key + $terms['after'] + ['status' => 'active'])->execute();
                else $this->db->createCommand()->update('world_slot', $terms['after'], $key)->execute();
                $this->db->createCommand()->update('craft_storage', ['capacity' => $terms['capacity'], 'status' => $terms['capacity'] ? 'active' : 'retired', 'revision' => new Expression('[[revision]]+1')], ['id' => $storage])->execute();
                $ancestors = (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $terms['node_id']])->column($this->db);
                $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $ancestors])->execute();
                (new WorldTree($this->db))->audit($user, 'world.slot.' . $action, $input['reason'], $terms['before'] ?: [], ['id' => $terms['node_id'], 'storage_id' => $storage, 'position' => $terms['position'], 'slot' => $terms['after']], $operation);
                $bus->emit($operation, $user, 'world.slot.' . $action, ['node_id' => $terms['node_id'], 'storage_id' => $storage, 'position' => $terms['position']]);
                return ['node_id' => $terms['node_id'], 'storage_id' => $storage, 'position' => $terms['position']];
            });
    }
}
