<?php
namespace common\modules\world\service;

use common\modules\craft\service\CanonicalInventory;
use common\modules\craft\service\CraftStorage;
use common\modules\economy\service\BudgetSpending;
use common\modules\economy\service\WalletSchema;
use common\modules\economy\value\Money;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use common\services\game\JobQueue;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

class WorldConstruction
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function inventory(string $operation): CanonicalInventory
    {
        $store = new CraftStorage($this->db); $store->operationId = $operation;
        return new CanonicalInventory($store);
    }
    private function update(string $table, array $values, array $where): void
    {
        if ($this->db->createCommand()->update($table, $values, $where)->execute() !== 1) throw new \RuntimeException('Construction state changed.');
    }
    private function node(int $user, int $id): array
    {
        $this->flags->requireFlag('world_read'); $this->flags->requireFlag('storage_v2');
        $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($id);
        if (!$node['permissions']['storage']) throw new GameError('CONSTRUCTION_FORBIDDEN', 'Нет доступа к строительству.', 403);
        return $node;
    }
    private function schedule(array $project): void
    {
        (new JobQueue($this->db))->enqueue('world.construction.finish', 'project:' . $project['id'] . ':revision:' . $project['revision'],
            ['project_id' => (int)$project['id'], 'revision' => (int)$project['revision']], (int)$project['owner_user_id'], (int)$project['finish_at']);
    }
    /** Called from the existing purchase command after the quote has been checked and budget reserved. */
    public function start(int $user, array $terms, int $hold, string $operation): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Construction requires the purchase transaction.');
        $tree = new WorldTree($this->db); $config = $terms['config']; $code = 'construction-' . $operation; $now = time();
        $plotId = (int)($terms['site_node_id'] ?? $terms['node_id']);
        $building = $tree->create(['code' => $code, 'slug' => $code, 'node_type' => 'BUILDING', 'name' => $config['name'], 'parent_id' => $plotId, 'owner_user_id' => $user, 'visibility' => 'private'], ['operational_status' => 'constructing', 'template_revision_id' => $terms['template_revision_id']]);
        $storage = $this->inventory($operation)->reserveConstruction($user, $terms['material_plan'], $operation);
        $project = ['node_id' => (int)$building['id'], 'owner_user_id' => $user, 'template_revision_id' => $terms['template_revision_id'], 'status' => 'constructing',
            'started_at' => $now, 'finish_at' => $now + $config['duration_seconds'], 'operation_id' => $operation, 'terms_json' => CanonicalJson::encode($terms), 'revision' => 1];
        $this->db->createCommand()->insert('world_construction', $project)->execute(); $project['id'] = (int)$this->db->getLastInsertID();
        $this->db->createCommand()->insert('world_construction_site', ['project_id' => $project['id'], 'plot_id' => $plotId, 'area' => $config['area'], 'commitment_id' => $hold, 'storage_id' => $storage])->execute();
        $this->update('world_building', ['active_project_id' => $project['id']], ['node_id' => $building['id']]);
        $this->schedule($project);
        (new CommandBus($this->db, $this->flags))->emit($operation, $user, 'world.construction.started', ['project_id' => $project['id'], 'node_id' => (int)$building['id'], 'plot_id' => $plotId, 'finish_at' => $project['finish_at']]);
        $tree->audit($user, 'world.construction.start', 'Начало строительства с резервом бюджета и материалов', [], ['id' => $building['id'], 'project_id' => $project['id'], 'terms' => $terms], $operation);
        return ['building_id' => (int)$building['id'], 'project_id' => $project['id'], 'finish_at' => $project['finish_at'],
            'changed_node_ids' => [$plotId, $terms['recipient_node_id'], (int)$building['id']],
            'changed_storage_ids' => array_values(array_unique(array_merge([$storage], array_column($terms['material_plan'], 'storage_id'))))];
    }
    public function listing(int $user, int $nodeId, int $page, string $q, string $status): array
    {
        $node = $this->node($user, $nodeId);
        if ($page < 1 || $page > 1000000 || mb_strlen($q, 'UTF-8') > 120 || !in_array($status, ['', 'constructing', 'paused', 'completed', 'cancelled'], true)) throw new GameError('INVALID_FILTER', 'Некорректные параметры списка.', 422);
        $query = (new Query())->select(['c.*', 's.plot_id', 'n.name', 'p.room_id', 'demolition_id' => 'd.id'])->from(['c' => 'world_construction'])
            ->innerJoin(['s' => 'world_construction_site'], '[[s.project_id]]=[[c.id]]')->innerJoin(['n' => 'world_node'], '[[n.id]]=[[c.node_id]]')
            ->leftJoin(['p' => 'world_premises_purchase'], '[[p.building_id]]=[[c.node_id]]')
            ->leftJoin(['d' => 'world_building_demolition'], '[[d.building_id]]=[[c.node_id]]')
            ->where(['c.owner_user_id' => $user])->andWhere(['or', ['s.plot_id' => $nodeId], ['c.node_id' => $nodeId]]);
        if ($q !== '') $query->andWhere(['like', 'n.name', $q]);
        if ($status !== '') $query->andWhere(['c.status' => $status]);
        $total = (int)(clone $query)->count('*', $this->db); $items = []; $now = time();
        foreach ($query->orderBy(['c.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) {
            $terms = json_decode($row['terms_json'], true, 512, JSON_THROW_ON_ERROR);
            $items[] = ['id' => (int)$row['id'], 'node_id' => (int)$row['node_id'], 'plot_id' => (int)$row['plot_id'], 'name' => $row['name'],
                'status' => $row['status'], 'demolished' => $row['demolition_id'] !== null, 'revision' => (int)$row['revision'], 'started_at' => (int)$row['started_at'], 'finish_at' => (int)$row['finish_at'],
                'remaining_seconds' => in_array($row['status'], ['completed', 'cancelled'], true) ? 0 : max(0, (int)$row['finish_at'] - ($row['paused_at'] === null ? $now : (int)$row['paused_at'])),
                'room_id' => $row['room_id'] === null ? null : (int)$row['room_id'], 'price' => $terms['config']['price'], 'materials' => $terms['config']['materials']];
        }
        return ['node_id' => $node['id'], 'items' => $items, 'writable' => $this->flags->capabilities()['world_write'], 'server_time' => $now,
            '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20]];
    }
    private function prepare(int $user, array $input, string $action): array
    {
        $node = $this->node($user, $input['node_id']);
        $project = (new Query())->from('world_construction')->where(['node_id' => $node['id'], 'owner_user_id' => $user])->one($this->db);
        if (!$project || !in_array($project['status'], ['constructing', 'paused'], true)) throw new GameError('CONSTRUCTION_CLOSED', 'Строительство уже завершено или отменено.');
        if (!in_array($action, ['pause', 'resume', 'cancel'], true)) throw new GameError('INVALID_CONSTRUCTION_ACTION', 'Неизвестное действие.', 422);
        if (($action === 'pause' && ($project['status'] !== 'constructing' || (int)$project['finish_at'] <= time())) || ($action === 'resume' && $project['status'] !== 'paused')) throw new GameError('CONSTRUCTION_CHANGED', 'Состояние стройки изменилось. Обновите страницу.');
        $contract = json_decode($project['terms_json'], true, 512, JSON_THROW_ON_ERROR);
        $site = (new Query())->from('world_construction_site')->where(['project_id' => $project['id']])->one($this->db);
        if (!$site || $node['status'] !== 'active' || (int)$node['parent_id'] !== (int)$site['plot_id']) throw new GameError('CONSTRUCTION_CHANGED', 'Площадка строительства недоступна.');
        $refund = $action === 'cancel' ? (new CanonicalInventory(new CraftStorage($this->db)))->constructionReturnPlan($user, (int)$site['storage_id']) : null;
        $revisions = ['node:' . $node['id'] => $node['revision']];
        if ($refund) $revisions['storage:' . $refund['target']['id']] = (int)$refund['target']['revision'];
        return ['project' => $project, 'site' => $site, 'revisions' => $revisions,
            'terms' => $input + ['project_id' => (int)$project['id'], 'project_revision' => (int)$project['revision'], 'action' => $action,
                'name' => $node['name'], 'price' => $contract['config']['price'], 'materials' => $contract['config']['materials'], 'finish_at' => (int)$project['finish_at'],
                'cancellation' => 'full_refund_before_completion', 'return_plan' => $refund ? $refund['grants'] : []]];
    }
    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.construction.' . $action, $input, function () use ($user, $input, $action) { return $this->prepare($user, $input, $action); });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions, string $action): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.construction.' . $action, $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $action, $bus) {
            $p = $this->prepare($user, $payload, $action);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('CONSTRUCTION_CHANGED', 'Состояние стройки изменилось. Повторите расчёт.');
            $project = $p['project']; $now = time(); $changed = []; $values = ['revision' => (int)$project['revision'] + 1];
            if ($action === 'pause') $values += ['status' => 'paused', 'paused_at' => $now];
            elseif ($action === 'resume') $values += ['status' => 'constructing', 'paused_at' => null, 'finish_at' => $now + max(1, (int)$project['finish_at'] - (int)$project['paused_at'])];
            else {
                (new BudgetSpending($this->db))->release((int)$p['site']['commitment_id']);
                $changed = $this->inventory($operation)->resolveConstruction($user, (int)$p['site']['storage_id'], true);
                $values += ['status' => 'cancelled'];
            }
            $this->update('world_construction', $values, ['id' => $project['id'], 'revision' => $project['revision']]);
            $this->update('world_building', ['operational_status' => $action === 'cancel' ? 'archived' : $values['status'], 'active_project_id' => $action === 'cancel' ? null : $project['id']], ['node_id' => $project['node_id']]);
            $this->update('world_node', ['status' => $action === 'cancel' ? 'archived' : 'active', 'revision' => new Expression('[[revision]]+1'), 'updated_at' => $now], ['id' => $project['node_id']]);
            $this->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => $now], ['id' => $p['site']['plot_id']]);
            if ($action === 'resume') $this->schedule(array_merge($project, $values));
            (new WorldTree($this->db))->audit($user, 'world.construction.' . $action, 'Управление строительством', ['project' => $project], ['id' => $project['node_id'], 'project' => array_merge($project, $values)], $operation);
            $result = ['changed_node_ids' => [(int)$project['node_id'], (int)$p['site']['plot_id']], 'changed_storage_ids' => $changed];
            if ($action === 'cancel') $result['return_node_id'] = (int)$p['site']['plot_id'];
            $bus->emit($operation, $user, 'world.construction.' . $action, $result); return $result;
        });
    }
    /** JobQueue already holds owner, registry and fenced job locks through this transaction. */
    public function finish(array $payload, array $job): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Construction completion requires a fenced job transaction.');
        $this->flags->requireFlag('world_write'); $this->flags->requireFlag('storage_v2'); WalletSchema::requireReady($this->db);
        $project = (new Query())->from('world_construction')->where(['id' => $payload['project_id'], 'owner_user_id' => $job['owner_user_id'], 'revision' => $payload['revision'], 'status' => 'constructing'])->one($this->db);
        if (!$project) return;
        if ((int)$project['finish_at'] > time()) throw new GameError('CONSTRUCTION_NOT_DUE', 'Время строительства ещё не истекло.');
        $user = (int)$project['owner_user_id']; $node = $this->node($user, (int)$project['node_id']);
        $site = (new Query())->from('world_construction_site')->where(['project_id' => $project['id']])->one($this->db);
        if (!$site || (int)$node['parent_id'] !== (int)$site['plot_id']) throw new GameError('CONSTRUCTION_CHANGED', 'Площадка строительства недоступна.');
        $terms = json_decode($project['terms_json'], true, 512, JSON_THROW_ON_ERROR); $operation = bin2hex(random_bytes(16));
        $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $user, 'type' => 'world.construction.finish', 'created_at' => time()])->execute();
        $transfer = (new BudgetSpending($this->db))->pay((int)$site['commitment_id'], $terms['recipient_node_id'], Money::parse($terms['config']['price']), $operation, 'premises_purchase');
        $changed = $this->inventory($operation)->resolveConstruction($user, (int)$site['storage_id'], false);
        $this->update('world_building', ['operational_status' => 'active', 'active_project_id' => null], ['node_id' => $project['node_id']]);
        $tree = new WorldTree($this->db);
        $result = (new PremisesDelivery($this->db, $this->flags))->deliver($user, $terms, $transfer, $operation, $tree->get((int)$project['node_id']));
        $result['changed_storage_ids'] = array_values(array_unique(array_merge($changed, $result['changed_storage_ids'])));
        $this->update('world_construction', ['status' => 'completed', 'revision' => (int)$project['revision'] + 1], ['id' => $project['id'], 'revision' => $project['revision']]);
        $tree->audit($user, 'world.construction.finish', 'Завершение строительства', ['project_id' => (int)$project['id']], ['id' => $project['node_id'], 'room_id' => $result['room_id']], $operation);
        (new CommandBus($this->db, $this->flags))->emit($operation, $user, 'world.construction.finished', $result);
    }
}
