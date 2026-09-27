<?php
namespace common\modules\world\service;

use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** A permanent sleeping place in a paid house, separate from equipment capacity. */
class WorldHousing
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    public function initialize(int $room, int $plot, int $purchase, array $config, string $operation): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Housing requires a purchase transaction.');
        if (($config['kind'] ?? '') !== 'house') return;
        if (($config['lodging_places'] ?? null) !== 1 || $config['exposure_class'] !== 'indoor') throw new \LogicException('Invalid housing template.');
        $this->db->createCommand()->insert('world_housing_place', ['room_id' => $room, 'plot_id' => $plot, 'purchase_id' => $purchase, 'operation_id' => $operation, 'created_at' => time()])->execute();
    }
    private function context(int $user, int $node): array
    {
        $this->flags->requireFlag('world_read');
        $room = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($node);
        if ($room['type'] !== 'ROOM' || !$room['permissions']['storage']) throw new GameError('HOUSING_OWNER_REQUIRED', 'Откройте комнату собственного дома.', 403);
        $place = $this->one('world_housing_place', ['room_id' => $node]);
        $actor = $this->one('game_actor', ['user_id' => $user, 'kind' => 'player']);
        $current = $actor ? (new LodgingAssignments($this->db))->current((int)$actor['id']) : null;
        $active = false; $lodging = null;
        if ($place) {
            $purchase = $this->one('world_premises_purchase', ['id' => $place['purchase_id'], 'room_id' => $node, 'plot_id' => $place['plot_id'], 'user_id' => $user]);
            $membership = $this->one('world_membership', ['user_id' => $user, 'starter_site_id' => $place['plot_id'], 'world_id' => $room['root_id']]);
            if (!$purchase || !$membership || !(new Query())->from('world_node_closure')->where(['ancestor_id' => $place['plot_id'], 'descendant_id' => $node])->exists($this->db)) throw new GameError('HOUSING_STATE_INVALID', 'Принадлежность жилья требует сверки.');
            $active = ((array)$room['details'])['exposure_class'] === 'indoor'
                && !(new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $node])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)
                && !(new Query())->from(['b' => 'world_building'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[b.node_id]]')->where(['c.descendant_id' => $node])->andWhere(['or', ['<>', 'b.operational_status', 'active'], ['<', 'b.condition', 1]])->exists($this->db);
            $lodging = $this->one('world_housing_interval', ['active_place_id' => $place['id']]);
            if ($lodging && (!$actor || (int)$lodging['actor_id'] !== (int)$actor['id'])) throw new GameError('HOUSING_STATE_INVALID', 'Назначение жильца требует сверки.');
        }
        return compact('room', 'place', 'actor', 'current', 'active', 'lodging');
    }
    public function state(int $user, int $node): array
    {
        $c = $this->context($user, $node); $flags = $this->flags->capabilities();
        return ['node_id' => $node, 'supported' => (bool)$c['place'], 'active' => $c['active'],
            'writable' => (bool)$c['place'] && $flags['world_write'] && $flags['storage_v2'],
            'place' => $c['place'] ? ['id' => (int)$c['place']['id'], 'plot_id' => (int)$c['place']['plot_id'], 'created_at' => (int)$c['place']['created_at']] : null,
            'lodging' => $c['lodging'] ? ['id' => (int)$c['lodging']['id'], 'assigned_at' => (int)$c['lodging']['started_at'], 'protects_now' => $c['active']] : null,
            'current_assignment' => $c['current'], 'night_resolution_enabled' => (new WorldNights($this->db, $this->flags))->policy($c['room']['root_id']) !== null, 'server_time' => time()];
    }
    private function prepare(int $user, array $input, string $action): array
    {
        $this->flags->requireFlag('storage_v2'); $c = $this->context($user, $input['node_id']);
        if (!in_array($action, ['lodge', 'leave'], true)) throw new GameError('INVALID_HOUSING_ACTION', 'Некорректное действие с жильём.', 422);
        if (!$c['place'] || !$c['actor']) throw new GameError('HOUSING_REQUIRED', 'В этой комнате нет постоянного спального места.');
        if ($action === 'lodge' && !$c['active']) throw new GameError('HOUSING_UNAVAILABLE', 'Дом сейчас не защищает от непогоды.');
        if ($action === 'lodge' && $c['current']) throw new GameError('LODGING_ALREADY_ASSIGNED', 'Сначала отмените текущее назначение ночлега.');
        if ($action === 'leave' && !$c['lodging']) throw new GameError('LODGING_NOT_ASSIGNED', 'Ночлег уже отменён.');
        $terms = $input + ['place_id' => (int)$c['place']['id'], 'plot_id' => (int)$c['place']['plot_id'], 'actor_id' => (int)$c['actor']['id'],
            'lodging_id' => $c['lodging'] ? (int)$c['lodging']['id'] : null, 'price' => '0.0000'];
        return $c + ['terms' => $terms, 'revisions' => ['node:' . $input['node_id'] => $c['room']['revision']]];
    }
    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.housing.' . $action, $input, function () use ($user, $input, $action) { return $this->prepare($user, $input, $action); });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions, string $action): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.housing.' . $action, $input, $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $action, $bus) {
            $p = $this->prepare($user, $payload, $action);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('HOUSING_CHANGED', 'Назначение изменилось. Повторите расчёт.');
            $now = time();
            if ($action === 'lodge') {
                $now = max($now, (int)$p['place']['created_at']);
                $this->db->createCommand()->insert('world_housing_interval', ['place_id' => $terms['place_id'], 'active_place_id' => $terms['place_id'], 'actor_id' => $terms['actor_id'], 'active_actor_id' => $terms['actor_id'], 'started_at' => $now, 'operation_id' => $operation])->execute();
            } else {
                $now = max($now, (int)$p['lodging']['started_at']);
                if ($this->db->createCommand()->update('world_housing_interval', ['ended_at' => $now, 'active_place_id' => null, 'active_actor_id' => null], ['id' => $terms['lodging_id'], 'ended_at' => null])->execute() !== 1) throw new \RuntimeException('Housing release failed.');
            }
            $ids = array_map('intval', (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $payload['node_id']])->column($this->db));
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => $now], ['id' => $ids])->execute();
            $result = ['changed_node_ids' => $ids, 'changed_storage_ids' => []];
            (new WorldTree($this->db))->audit($user, 'world.housing.' . $action, 'Назначение ночлега в доме', $terms, $result + ['id' => $payload['node_id']], $operation);
            $bus->emit($operation, $user, 'world.housing.' . $action, $result);
            return $result;
        });
    }
    /** Saved assignments are the protection evidence; current state never rewrites past nights. */
    public function coverage(int $actor, int $plot, int $start, int $end): ?array
    {
        $rows = (new Query())->select(['l.*'])->from(['l' => 'world_housing_interval'])->innerJoin(['p' => 'world_housing_place'], '[[p.id]]=[[l.place_id]]')
            ->where(['l.actor_id' => $actor, 'p.plot_id' => $plot])->andWhere(['<=', 'p.created_at', $start])
            ->andWhere(['<', 'l.started_at', $end])->andWhere(['or', ['l.ended_at' => null], ['>', 'l.ended_at', $start]])
            ->orderBy(['l.place_id' => SORT_ASC, 'l.started_at' => SORT_ASC, 'l.id' => SORT_ASC]);
        $place = null; $until = $start; $evidence = [];
        foreach ($rows->each(100, $this->db) as $row) {
            if ($place !== (int)$row['place_id']) { $place = (int)$row['place_id']; $until = $start; $evidence = []; }
            $from = max($start, (int)$row['started_at']); $to = min($end, $row['ended_at'] === null ? $end : (int)$row['ended_at']);
            if ($to <= $from || $from > $until) continue;
            $until = max($until, $to); $evidence[] = (int)$row['id'];
            if ($until >= $end) return ['protected' => true, 'deployment_id' => null, 'housing_place_id' => $place, 'interval_ids' => $evidence, 'protection_ids' => []];
        }
        return null;
    }
}
