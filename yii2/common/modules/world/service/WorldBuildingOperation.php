<?php
namespace common\modules\world\service;

use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Owner-controlled operation of an existing building; construction has its own lifecycle. */
class WorldBuildingOperation
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }

    public function context(int $user, int $id, bool $repair = false): array
    {
        $this->flags->requireFlag('world_read'); $this->flags->requireFlag('storage_v2');
        $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($id);
        if ($node['type'] !== 'BUILDING' || !$node['permissions']['storage']) throw new GameError('BUILDING_OWNER_REQUIRED', 'Откройте собственную постройку.', 403);
        $building = (new Query())->from('world_building')->where(['node_id' => $id])->one($this->db);
        if (!$building) throw new GameError('BUILDING_UNAVAILABLE', 'Постройка недоступна.');
        $reasons = [];
        if ((new Query())->from('world_shelter_deployment')->where(['node_id' => $id])->exists($this->db)) $reasons[] = 'Шалаш управляется через действия с укрытием.';
        if ($building['active_project_id'] !== null || (new Query())->from('world_construction')->where(['node_id' => $id, 'status' => ['constructing', 'paused']])->exists($this->db)) $reasons[] = 'Сначала завершите строительство. Пауза стройки доступна в разделе строительства.';
        if (!in_array($building['operational_status'], $repair ? ['active', 'paused', 'damaged'] : ['active', 'paused'], true)) $reasons[] = 'Действие недоступно для текущего состояния постройки.';
        if (!$repair && (int)$building['condition'] < 1) $reasons[] = 'Постройке требуется ремонт.';
        if ((new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $id])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) $reasons[] = 'Постройка или её родитель находится в архиве.';
        if ((new Query())->from(['b' => 'world_building'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[b.node_id]]')->where(['c.descendant_id' => $id])->andWhere(['<>', 'b.node_id', $id])->andWhere(['or', ['<>', 'b.operational_status', 'active'], ['<', 'b.condition', 1]])->exists($this->db)) $reasons[] = 'Родительская постройка недоступна для работы.';
        // This first lifecycle slice handles rooms, not nested estates, tenants or production posts.
        $rooms = (new Query())->select('n.*')->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.descendant_id]]=[[n.id]]')
            ->where(['c.ancestor_id' => $id])->andWhere(['>', 'c.distance', 0])->orderBy(['n.id' => SORT_ASC])->limit(101)->all($this->db);
        if (count($rooms) > 100) $reasons[] = 'Для постройки с более чем 100 комнатами требуется отдельное управление.';
        foreach ($rooms as $room) {
            if ($room['node_type'] !== 'ROOM' || (int)$room['parent_id'] !== $id || (int)$room['owner_user_id'] !== $user) {
                $reasons[] = 'Поддерживается постройка только с собственными комнатами, без вложенных участков и других построек.'; break;
            }
        }
        $ids = array_merge([$id], array_map('intval', array_column($rooms, 'id')));
        $lodgings = (new Query())->select(['l.id', 'l.actor_id', 'l.started_at', 'p.room_id', 'a.user_id'])->from(['l' => 'world_housing_interval'])
            ->innerJoin(['p' => 'world_housing_place'], '[[p.id]]=[[l.place_id]]')->innerJoin(['a' => 'game_actor'], '[[a.id]]=[[l.actor_id]]')
            ->where(['p.room_id' => $ids, 'l.ended_at' => null])->orderBy(['l.id' => SORT_ASC])->limit(101)->all($this->db);
        if (count($lodgings) > 100) $reasons[] = 'Назначения ночлега требуют сверки.';
        foreach ($lodgings as $lodging) if ((int)$lodging['user_id'] !== $user) { $reasons[] = 'В постройке есть ночлег другого владельца.'; break; }
        if (($building['operational_status'] !== 'active' || (int)$building['condition'] < 1) && $lodgings) $reasons[] = 'В недоступной постройке обнаружено активное назначение ночлега. Требуется сверка.';
        return compact('node', 'building', 'rooms', 'ids', 'lodgings', 'reasons');
    }

    public function state(int $user, int $id): array
    {
        $c = $this->context($user, $id); $building = $c['building'];
        return ['node_id' => $id, 'name' => $c['node']['name'], 'operational_status' => $building['operational_status'],
            'condition' => (int)$building['condition'], 'max_condition' => (int)$building['max_condition'],
            'writable' => $this->flags->capabilities()['world_write'], 'room_count' => count($c['rooms']), 'lodging_count' => count($c['lodgings']),
            'action' => $c['reasons'] ? null : ($building['operational_status'] === 'active' ? 'pause' : 'resume'), 'reasons' => $c['reasons'], 'server_time' => time()];
    }

    private function prepare(int $user, array $input, string $action): array
    {
        if (!in_array($action, ['pause', 'resume'], true)) throw new GameError('INVALID_BUILDING_ACTION', 'Неизвестное действие с постройкой.', 422);
        $c = $this->context($user, $input['node_id']);
        if ($c['reasons']) throw new GameError('BUILDING_UNAVAILABLE', implode(' ', $c['reasons']));
        $from = $action === 'pause' ? 'active' : 'paused'; $to = $action === 'pause' ? 'paused' : 'active';
        if ($c['building']['operational_status'] !== $from) throw new GameError('BUILDING_CHANGED', 'Состояние постройки изменилось. Обновите страницу.');
        $lodgings = array_map(static function (array $row): array { return ['id' => (int)$row['id'], 'room_id' => (int)$row['room_id'], 'actor_id' => (int)$row['actor_id'], 'started_at' => (int)$row['started_at']]; }, $c['lodgings']);
        $revisions = ['node:' . $input['node_id'] => $c['node']['revision']];
        foreach ($c['rooms'] as $room) $revisions['node:' . $room['id']] = (int)$room['revision'];
        return $c + ['revisions' => $revisions, 'terms' => $input + ['action' => $action, 'name' => $c['node']['name'], 'from' => $from, 'to' => $to,
            'condition' => (int)$c['building']['condition'], 'price' => '0.0000', 'room_ids' => array_map('intval', array_column($c['rooms'], 'id')), 'closed_lodgings' => $lodgings]];
    }

    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.building.' . $action, $input, function () use ($user, $input, $action) { return $this->prepare($user, $input, $action); });
    }

    public function execute(int $user, string $key, array $input, string $quote, array $revisions, string $action): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.building.' . $action, $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $action, $bus) {
            $p = $this->prepare($user, $payload, $action);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('BUILDING_CHANGED', 'Условия изменились. Повторите расчёт.');
            $now = time();
            foreach ($terms['closed_lodgings'] as $lodging) {
                if ($this->db->createCommand()->update('world_housing_interval', ['ended_at' => max($now, $lodging['started_at']), 'active_place_id' => null, 'active_actor_id' => null], ['id' => $lodging['id'], 'ended_at' => null])->execute() !== 1) throw new \RuntimeException('Building lodging release failed.');
            }
            if ($this->db->createCommand()->update('world_building', ['operational_status' => $terms['to']], ['node_id' => $payload['node_id'], 'operational_status' => $terms['from'], 'active_project_id' => null])->execute() !== 1) throw new \RuntimeException('Building transition failed.');
            $ancestors = array_map('intval', (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $payload['node_id']])->column($this->db));
            $ids = array_values(array_unique(array_merge($p['ids'], $ancestors)));
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => $now], ['id' => $ids])->execute();
            $storages = array_map('intval', (new Query())->select('id')->from('craft_storage')->where(['node_id' => $p['ids']])->column($this->db));
            if ($storages) $this->db->createCommand()->update('craft_storage', ['revision' => new Expression('[[revision]]+1')], ['id' => $storages])->execute();
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
            $result = ['changed_node_ids' => $ids, 'changed_storage_ids' => $storages];
            (new WorldTree($this->db))->audit($user, 'world.building.' . $action, 'Управление работой постройки', ['operational_status' => $terms['from']], ['id' => $payload['node_id'], 'operational_status' => $terms['to'], 'closed_lodgings' => $terms['closed_lodgings']], $operation);
            $bus->emit($operation, $user, 'world.building.' . $action, $result); return $result;
        });
    }
}
