<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Voluntary demolition of an empty purchased building; never a forced recovery or a refund. */
class WorldDemolition
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function node(int $user, int $id, string $type): array
    {
        $this->flags->requireFlag('world_read'); $this->flags->requireFlag('storage_v2');
        $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($id);
        if ($node['type'] !== $type || !$node['permissions']['storage']) throw new GameError('DEMOLITION_OWNER_REQUIRED', 'Откройте собственную постройку или площадку.', 403);
        return $node;
    }
    private function context(int $user, int $id): array
    {
        $node = $this->node($user, $id, 'BUILDING'); $reasons = [];
        $deny = static function (string $code, string $message) use (&$reasons): void { $reasons[] = compact('code', 'message'); };
        $purchase = (new Query())->from('world_premises_purchase')->where(['building_id' => $id, 'user_id' => $user])->one($this->db);
        $building = (new Query())->from('world_building')->where(['node_id' => $id])->one($this->db);
        $rooms = (new Query())->select('n.*')->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.descendant_id]]=[[n.id]]')
            ->where(['c.ancestor_id' => $id])->andWhere(['>', 'c.distance', 0])->orderBy(['n.id' => SORT_ASC])->limit(101)->all($this->db);
        $ids = array_merge([$id], array_map('intval', array_column($rooms, 'id')));
        if ((new Query())->from('npc')->where(['home_node_id' => $ids])->andWhere(['not', ['status' => 'released']])->exists($this->db)
            || (new Query())->from('world_job_position')->where(['building_id' => $ids])->exists($this->db)
            || (new Query())->from('production_order')->where(['node_id' => $ids, 'closed_at' => null])->exists($this->db)
            || (new Query())->from('world_capacity_place')->where(['node_id' => $ids])->exists($this->db)) $deny('DOMAIN_DEPENDENCIES', 'В постройке есть NPC, рабочие места, производство или дополнительные права. Требуется отдельное завершение этих связей.');
        if (!$purchase || !$building || (int)$purchase['plot_id'] !== (int)$node['parent_id'] || count($rooms) !== 1 || (int)$rooms[0]['id'] !== (int)$purchase['room_id']) $deny('PURCHASE_REQUIRED', 'Поддерживается купленная постройка с исходной площадкой и одной комнатой.');
        foreach ($rooms as $room) if ($room['node_type'] !== 'ROOM' || (int)$room['owner_user_id'] !== $user || (int)$room['parent_id'] !== $id || $room['status'] !== 'active') { $deny('STRUCTURE_UNSUPPORTED', 'Сначала требуется сверка структуры, состояния или владельцев помещений.'); break; }
        if (!$building || !in_array($building['operational_status'], ['active', 'paused', 'damaged', 'destroyed'], true) || $building['active_project_id'] !== null) $deny('BUILDING_NOT_READY', 'Снос недоступен для текущего состояния постройки. Незавершённую стройку отмените отдельно.');
        if ((new Query())->from('world_building_demolition')->where(['building_id' => $id])->exists($this->db)) $deny('ALREADY_DEMOLISHED', 'Снос уже зарегистрирован.');
        if ((new Query())->from('world_shelter_deployment')->where(['node_id' => $ids])->exists($this->db)) $deny('SHELTER', 'Шалаш нужно складывать через действия с укрытием.');
        $plot = $purchase ? (new Query())->from('world_node')->where(['id' => $purchase['plot_id'], 'node_type' => 'PLOT', 'owner_user_id' => $user, 'status' => 'active'])->one($this->db) : null;
        if (!$plot || (int)$plot['root_id'] !== $node['root_id']) $deny('PLOT_UNAVAILABLE', 'Исходная площадка недоступна.');
        if ((new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $id])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) $deny('ANCESTOR_UNAVAILABLE', 'Постройка или её родитель недоступны.');
        $storages = (new Query())->from('craft_storage')->where(['node_id' => $ids])->orderBy(['id' => SORT_ASC])->limit(101)->all($this->db);
        if (count($storages) > 100) $deny('STORAGE_LIMIT', 'Слишком много связанных хранилищ для одного сноса.');
        foreach ($storages as $storage) if ((int)$storage['owner_user_id'] !== $user || !in_array($storage['kind'], ['placement', 'stockpile'], true) || !in_array($storage['status'], ['active', 'retired'], true)) { $deny('STORAGE_UNSUPPORTED', 'Есть хранилище, которое нельзя закрыть обычным сносом.'); break; }
        $storageIds = array_map('intval', array_column($storages, 'id'));
        $spatialStorage = (new Query())->select('id')->from('craft_storage')->where(['node_id' => $ids]);
        $inventory = (new Query())->select('id')->from('craft_inventory')->where(['storage_id' => $spatialStorage]);
        if ((new Query())->from('craft_inventory')->where(['storage_id' => $spatialStorage])->andWhere(['>', 'item_quantity', 0])->exists($this->db)) $deny('ITEMS_PRESENT', 'Заберите все вещи, станции и сундуки из помещений. Содержимое сундуков также сохраняйте обычным переносом.');
        if ((new Query())->from('craft_equipment_instance')->where(['inventory_id' => $inventory, 'status' => 'active'])->exists($this->db)) $deny('EQUIPMENT_PRESENT', 'В постройке остались экземпляры оборудования.');
        if ((new Query())->from('craft_storage')->where(['container_inventory_id' => $inventory, 'status' => 'active'])->exists($this->db)) $deny('CONTAINERS_PRESENT', 'В постройке осталось связанное хранилище сундука.');
        if ((new Query())->from(['l' => 'world_housing_interval'])->innerJoin(['p' => 'world_housing_place'], '[[p.id]]=[[l.place_id]]')->where(['p.room_id' => $ids, 'l.ended_at' => null])->exists($this->db)) $deny('LODGING_PRESENT', 'Отмените назначение ночлега перед сносом.');
        if ((new Query())->from('world_construction')->where(['node_id' => $ids, 'status' => ['constructing', 'paused']])->exists($this->db)) $deny('CONSTRUCTION_PRESENT', 'Сначала завершите или отмените активную стройку.');
        if ((new Query())->from('economy_purchase_order')->where(['node_id' => $ids, 'closed_at' => null])->exists($this->db)) $deny('ORDERS_PRESENT', 'Сначала закройте действующие заказы.');
        $subjects = (new Query())->select('id')->from('economy_subject')->where(['node_id' => $ids]);
        $accountsQuery = (new Query())->from('economy_account')->where(['subject_id' => $subjects]);
        $accounts = (clone $accountsQuery)->orderBy(['id' => SORT_ASC])->limit(201)->all($this->db);
        if (count($accounts) > 200) $deny('ACCOUNT_LIMIT', 'Слишком много связанных счетов для одного сноса.');
        if ((clone $accountsQuery)->andWhere(['or', ['<>', 'amount', 0], ['<>', 'reserved', 0]])->exists($this->db)) $deny('MONEY_PRESENT', 'Бюджеты и казны здания и комнаты должны быть пустыми, без резервов. Деньги при сносе не удаляются и не возвращаются автоматически.');
        $accountIds = (clone $accountsQuery)->select('id');
        if ((new Query())->from('economy_obligation')->where(['or', ['budget_account_id' => $accountIds], ['recipient_account_id' => $accountIds]])->andWhere(['<>', 'status', 'paid'])->exists($this->db)) $deny('OBLIGATIONS_PRESENT', 'Есть неоплаченные исходящие или входящие обязательства.');
        if ((new Query())->from('economy_budget_reservation')->where(['account_id' => $accountIds, 'released_at' => null])->exists($this->db)
            || (new Query())->from('economy_spending_commitment')->where(['account_id' => $accountIds, 'closed_at' => null])->exists($this->db)) $deny('RESERVATIONS_PRESENT', 'Сначала освободите резервы и завершите расходные обязательства.');
        if ((new Query())->from('economy_funding_lot')->where(['budget_account_id' => $accountIds])->andWhere(['<>', 'remaining_amount', 0])->exists($this->db)
            || (new Query())->from('economy_treasury_receipt')->where(['account_id' => $accountIds, 'closed_at' => null])->exists($this->db)) $deny('FUNDS_UNSETTLED', 'Есть незавершённые вклады или поступления казны.');
        if ((new Query())->from(['h' => 'economy_parent_history'])->innerJoin(['r' => 'economy_parent_rule'], '[[r.history_id]]=[[h.id]]')->where(['h.parent_subject_id' => $subjects])->andWhere(['not in', 'h.subject_id', $subjects])->exists($this->db)) $deny('FINANCIAL_CHILDREN', 'Другие объекты перечисляют средства в эту постройку. Сначала требуется переоформить финансовые связи.');
        return compact('node', 'purchase', 'building', 'rooms', 'ids', 'plot', 'storages', 'storageIds', 'accounts', 'reasons');
    }
    public function state(int $user, int $id): array
    {
        $c = $this->context($user, $id);
        return ['node_id' => $id, 'name' => $c['node']['name'], 'plot_id' => $c['plot'] ? (int)$c['plot']['id'] : null,
            'area' => $c['purchase'] ? (int)$c['purchase']['area'] : 0, 'available' => !$c['reasons'], 'reasons' => $c['reasons'],
            'writable' => $this->flags->capabilities()['world_write'], 'price' => '0.0000', 'refund' => '0.0000', 'server_time' => time()];
    }
    private function prepare(int $user, array $input): array
    {
        $c = $this->context($user, $input['node_id']);
        if ($c['reasons']) throw new GameError('DEMOLITION_BLOCKED', 'Сначала устраните препятствия для сноса.', 409, ['reasons' => $c['reasons']]);
        $revisions = ['node:' . $input['node_id'] => $c['node']['revision'], 'node:' . $c['plot']['id'] => (int)$c['plot']['revision']];
        foreach ($c['rooms'] as $room) $revisions['node:' . $room['id']] = (int)$room['revision'];
        foreach ($c['storages'] as $storage) $revisions['storage:' . $storage['id']] = (int)$storage['revision'];
        foreach ($c['accounts'] as $account) $revisions['account:' . $account['id']] = (int)$account['revision'];
        return ['context' => $c, 'revisions' => $revisions, 'terms' => $input + ['name' => $c['node']['name'], 'purchase_id' => (int)$c['purchase']['id'],
            'plot_id' => (int)$c['plot']['id'], 'area' => (int)$c['purchase']['area'], 'room_ids' => array_map('intval', array_column($c['rooms'], 'id')),
            'storage_ids' => $c['storageIds'], 'account_ids' => array_map('intval', array_column($c['accounts'], 'id')), 'price' => '0.0000', 'refund' => '0.0000', 'salvage' => [], 'condition' => (int)$c['building']['condition'], 'status_before' => $c['building']['operational_status']]];
    }
    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.building.demolish', $input, function () use ($user, $input) { return $this->prepare($user, $input); });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.building.demolish', $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $bus) {
            $p = $this->prepare($user, $payload); $c = $p['context'];
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('DEMOLITION_CHANGED', 'Условия сноса изменились. Повторите расчёт.');
            $now = time();
            $this->db->createCommand()->insert('world_building_demolition', ['purchase_id' => $terms['purchase_id'], 'building_id' => $payload['node_id'], 'plot_id' => $terms['plot_id'], 'user_id' => $user, 'area' => $terms['area'], 'name' => $terms['name'], 'operation_id' => $operation, 'terms_json' => CanonicalJson::encode($terms), 'created_at' => $now])->execute();
            if ($this->db->createCommand()->update('world_node', ['status' => 'archived', 'revision' => new Expression('[[revision]]+1'), 'updated_at' => $now], ['id' => $c['ids'], 'status' => 'active', 'owner_user_id' => $user])->execute() !== count($c['ids'])) throw new \RuntimeException('Demolition archive failed.');
            if ($this->db->createCommand()->update('world_building', ['operational_status' => 'archived'], ['node_id' => $payload['node_id'], 'operational_status' => $terms['status_before']])->execute() !== 1) throw new \RuntimeException('Demolition building update failed.');
            if ($c['storageIds']) {
                if ($this->db->createCommand()->update('craft_storage', ['status' => 'retired', 'capacity' => 0, 'revision' => new Expression('[[revision]]+1')], ['id' => $c['storageIds']])->execute() !== count($c['storageIds'])) throw new \RuntimeException('Demolition storage retirement failed.');
                $this->db->createCommand()->update('world_slot', ['status' => 'retired'], ['storage_id' => $c['storageIds']])->execute();
            }
            $ancestors = array_map('intval', (new Query())->select('ancestor_id')->from('world_node_closure')->where(['descendant_id' => $payload['node_id']])->andWhere(['>', 'distance', 0])->column($this->db));
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => $now], ['id' => $ancestors])->execute();
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
            $result = ['changed_node_ids' => array_values(array_unique(array_merge($c['ids'], $ancestors))), 'changed_storage_ids' => $c['storageIds'], 'return_node_id' => $terms['plot_id']];
            (new WorldTree($this->db))->audit($user, 'world.building.demolish', 'Добровольный снос пустой постройки без возврата', $terms, ['id' => $payload['node_id'], 'status' => 'archived', 'released_area' => $terms['area']], $operation);
            $bus->emit($operation, $user, 'world.building.demolished', $result); return $result;
        });
    }
    public function history(int $user, int $plot, int $page, string $search): array
    {
        $this->node($user, $plot, 'PLOT');
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_FILTER', 'Некорректные параметры истории сноса.', 422);
        $query = (new Query())->from(['d' => 'world_building_demolition'])->where(['d.plot_id' => $plot, 'd.user_id' => $user]);
        if ($search !== '') $query->andWhere(['like', 'd.name', $search]);
        $total = (int)(clone $query)->count('*', $this->db); $items = [];
        foreach ($query->orderBy(['d.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) $items[] = ['id' => (int)$row['id'], 'building_id' => (int)$row['building_id'], 'name' => $row['name'], 'area' => (int)$row['area'], 'created_at' => (int)$row['created_at'], 'refund' => '0.0000'];
        return ['node_id' => $plot, 'items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20]];
    }
}
