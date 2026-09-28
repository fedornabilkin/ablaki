<?php
namespace common\modules\world\service;

use common\modules\craft\service\CanonicalInventory;
use common\modules\craft\service\CraftStorage;
use common\modules\craft\service\EquipmentInstances;
use common\modules\craft\service\StorageAccessPolicy;
use common\modules\economy\service\FinanceReadSnapshot;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Growth is paused at a missed watering deadline. GET only projects state. */
class WorldCultivation
{
    private $db;
    private $flags;
    private $access;
    public function __construct(Connection $db, WorldFlags $flags, WorldAccessPolicy $access) { $this->db = $db; $this->flags = $flags; $this->access = $access; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    private function resource(int $id): array
    {
        $item = $this->one('craft_item', ['id' => $id, 'active' => 1]);
        if (!$item || ($item['storage_kind'] ?? 'none') === 'chest' || (new EquipmentInstances($this->db))->tracked($item) || (int)$item['stack_size'] < 1) throw new GameError('CROP_RESOURCE_UNAVAILABLE', 'Выберите действующий расходный предмет из каталога крафта.', 422);
        return $item;
    }
    public function publication(array $body): array
    {
        if (!is_string($body['code'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $body['code']) || !is_string($body['name'] ?? null) || trim($body['name']) === '' || mb_strlen($body['name'], 'UTF-8') > 120
            || !is_string($body['reason'] ?? null) || trim($body['reason']) === '' || mb_strlen($body['reason'], 'UTF-8') > 255) throw new GameError('INVALID_CROP', 'Укажите код, название культуры и причину публикации.', 422);
        $result = ['code' => $body['code'], 'name' => trim($body['name']), 'reason' => trim($body['reason'])];
        foreach (['seed_item_id', 'yield_item_id', 'seed_quantity', 'yield_quantity', 'grow_seconds', 'water_quantity', 'water_interval_seconds'] as $field) {
            $max = substr($field, -3) === '_id' ? 2147483647 : (substr($field, -8) === '_seconds' ? 31536000 : 10000);
            $min = in_array($field, ['water_quantity', 'water_interval_seconds'], true) ? 0 : 1;
            if (!is_int($body[$field] ?? null) || $body[$field] < $min || $body[$field] > $max) throw new GameError('INVALID_CROP', 'Некорректные ресурсы или длительность выращивания.', 422);
            $result[$field] = $body[$field];
        }
        $water = $body['water_item_id'] ?? null;
        if ($water !== null && (!is_int($water) || $water < 1 || $water > 2147483647)) throw new GameError('INVALID_CROP', 'Некорректный ресурс полива.', 422);
        if (($water === null && ($result['water_quantity'] || $result['water_interval_seconds'])) || ($water !== null && (!$result['water_quantity'] || !$result['water_interval_seconds']))) throw new GameError('INVALID_CROP', 'Для полива нужны ресурс, количество и интервал; либо отключите все три значения.', 422);
        $result['water_item_id'] = $water;
        return $result;
    }
    private function context(int $user, int $id): array
    {
        $this->flags->requireFlag('world_read'); $this->flags->requireFlag('storage_v2');
        $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($id);
        $bed = $this->one('world_bed', ['node_id' => $id]);
        if (!$bed || $node['type'] !== 'BED' || !$node['permissions']['storage'] || $node['status'] !== 'active') throw new GameError('BED_UNAVAILABLE', 'Выберите свою открытую грядку.', 403);
        if (!(new Query())->from('world_membership')->where(['user_id' => $user, 'world_id' => $node['root_id']])->exists($this->db)
            || (new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $id])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('BED_UNAVAILABLE', 'Территория грядки недоступна.');
        $entitlement = $this->one('world_expansion_entitlement', ['node_id' => $id]);
        if (!$entitlement) throw new GameError('BED_NOT_PURCHASED', 'Сначала откройте грядку.');
        $guard = $this->one('world_bed_active_cycle', ['bed_id' => $id]);
        $cycle = $guard ? $this->one('world_crop_cycle', ['id' => $guard['cycle_id'], 'owner_user_id' => $user, 'closed_at' => null]) : null;
        if ($guard && !$cycle) throw new GameError('CROP_STATE_INVALID', 'Цикл выращивания требует сверки.');
        return compact('node', 'bed', 'cycle');
    }
    public function crops(int $page, string $search): array
    {
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_FILTER', 'Некорректный фильтр культур.', 422);
        return (new FinanceReadSnapshot($this->db))->run(function () use ($page, $search) {
            $this->flags->requireFlag('world_read');
            $query = (new Query())->select(['r.*', 'c.code', 'c.name'])->from(['r' => 'world_crop_revision'])->innerJoin(['c' => 'world_crop'], '[[c.id]]=[[r.crop_id]]')->where(['r.status' => 'published']);
            if ($search !== '') $query->andWhere(['or', ['like', 'c.name', $search], ['like', 'c.code', $search]]);
            $total = (int)(clone $query)->count('*', $this->db); $items = [];
            foreach ($query->orderBy(['r.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) {
                $item = ['code' => $row['code'], 'name' => $row['name']];
                foreach (['id', 'crop_id', 'version', 'seed_item_id', 'yield_item_id', 'water_item_id', 'seed_quantity', 'yield_quantity', 'grow_seconds', 'water_quantity', 'water_interval_seconds'] as $key) $item[$key] = $row[$key] === null ? null : (int)$row[$key];
                $items[] = $item;
            }
            return ['items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20]];
        });
    }
    public function state(int $user, int $bed): array
    {
        return (new FinanceReadSnapshot($this->db))->run(function () use ($user, $bed) {
            $c = $this->context($user, $bed); $cycle = $c['cycle']; $result = null; $now = time();
            if ($cycle) {
                $thirsty = $cycle['water_due_at'] !== null && (int)$cycle['water_due_at'] < (int)$cycle['ready_at'] && (int)$cycle['water_due_at'] <= $now;
                $result = ['id' => (int)$cycle['id'], 'crop_revision_id' => (int)$cycle['crop_revision_id'], 'state' => $thirsty ? 'needs_water' : ($now >= (int)$cycle['ready_at'] ? 'ripe' : 'growing'), 'ready_at' => (int)$cycle['ready_at'], 'water_due_at' => $cycle['water_due_at'] === null ? null : (int)$cycle['water_due_at'], 'revision' => (int)$cycle['revision']];
            }
            return ['bed_id' => $bed, 'cycle' => $result, 'writable' => $this->flags->capabilities()['world_write'], 'server_time' => $now];
        });
    }
    private function prepare(int $user, string $action, array $input): array
    {
        $this->flags->requireFlag('world_read');
        $registry = $this->one('world_registry', ['id' => 1]); $revisions = ['registry' => (int)$registry['content_revision']];
        if ($action === 'publish') {
            $this->access->requireAdmin(); $normalized = $this->publication($input); $crop = $this->one('world_crop', ['code' => $input['code']]);
            foreach (['seed_item_id', 'yield_item_id', 'water_item_id'] as $field) if ($normalized[$field] !== null) $this->resource($normalized[$field]);
            $version = $crop ? (int)(new Query())->from('world_crop_revision')->where(['crop_id' => $crop['id']])->max('version', $this->db) + 1 : 1;
            return ['terms' => $normalized + ['version' => $version, 'existing_cycles_unchanged' => true], 'revisions' => $revisions];
        }
        $c = $this->context($user, $input['bed_id']); $cycle = $c['cycle'];
        $revisions['node:' . $input['bed_id']] = $c['node']['revision'];
        if ($action === 'sow') {
            if ($cycle) throw new GameError('BED_OCCUPIED', 'На грядке уже растёт культура.');
            $rules = $this->one('world_crop_revision', ['id' => $input['crop_revision_id'], 'status' => 'published']);
        } else {
            if (!$cycle) throw new GameError('CROP_NOT_FOUND', 'На грядке нет активного посева.', 404);
            $rules = $this->one('world_crop_revision', ['id' => $cycle['crop_revision_id']]);
        }
        if (!$rules) throw new GameError('CROP_UNAVAILABLE', 'Культура недоступна.', 404);
        $terms = ['bed_id' => $input['bed_id'], 'cycle_id' => $cycle ? (int)$cycle['id'] : null, 'cycle_revision' => $cycle ? (int)$cycle['revision'] : null, 'crop_revision_id' => (int)$rules['id'], 'grow_seconds' => (int)$rules['grow_seconds'], 'water_interval_seconds' => (int)$rules['water_interval_seconds'], 'cancel_refund' => false];
        if ($action === 'cancel') return $c + compact('rules', 'terms', 'revisions');
        $thirsty = $cycle && $cycle['water_due_at'] !== null && (int)$cycle['water_due_at'] < (int)$cycle['ready_at'] && (int)$cycle['water_due_at'] <= time();
        if ($action === 'water' && !$thirsty) throw new GameError('WATER_NOT_DUE', 'Полив пока не требуется.');
        if ($action === 'harvest' && ($thirsty || time() < (int)$cycle['ready_at'])) throw new GameError('CROP_NOT_RIPE', $thirsty ? 'Сначала полейте грядку.' : 'Урожай ещё не созрел.');
        $required = [];
        if ($action === 'sow') $required[(int)$rules['seed_item_id']] = (int)$rules['seed_quantity'];
        if (in_array($action, ['sow', 'water'], true) && $rules['water_item_id'] !== null) $required[(int)$rules['water_item_id']] = ($required[(int)$rules['water_item_id']] ?? 0) + (int)$rules['water_quantity'];
        $items = []; foreach ($required as $id => $quantity) $items[$id] = $this->resource($id);
        $output = $this->resource((int)$rules['yield_item_id']);
        $efficiency = $action === 'harvest' ? (new NightWorkEfficiency($this->db))->basisPoints($user) : 10000;
        $quantity = $action === 'harvest' ? max(1, intdiv((int)$rules['yield_quantity'] * $efficiency, 10000)) : 0;
        $store = new CraftStorage($this->db); $inventory = new CanonicalInventory($store); $backpack = $inventory->backpack($user);
        if (!$backpack) throw new GameError('BACKPACK_UNAVAILABLE', 'Рюкзак ещё не подготовлен.');
        $target = (new StorageAccessPolicy($this->db))->storage($user, (int)$backpack['id'], true);
        $plan = $inventory->planCraft([$target], $target, $required, $items, $output, $quantity);
        $revisions['storage:' . $target['id']] = (int)$target['revision']; $revisions['catalog'] = (int)$this->one('craft_meta', ['id' => 1])['revision'];
        $terms += ['work_efficiency_bps' => $efficiency, 'materials' => $plan['materials'], 'consume' => $plan['consume'], 'grant' => $plan['grant'], 'output' => ['item_id' => (int)$output['id'], 'quantity' => $quantity, 'storage_id' => (int)$target['id'], 'fits' => $plan['output_fits']]];
        return $c + compact('rules', 'terms', 'revisions', 'store', 'items', 'output', 'target', 'plan');
    }
    public function preview(int $user, string $action, array $input): array
    {
        if (!in_array($action, ['publish', 'sow', 'water', 'harvest', 'cancel'], true)) throw new GameError('INVALID_ACTION', 'Неизвестное действие.', 422);
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.crop.' . $action, $input, function () use ($user, $action, $input) { return $this->prepare($user, $action, $input); });
    }
    public function execute(int $user, string $action, array $input, string $key, string $quote, array $revisions): array
    {
        if (!in_array($action, ['publish', 'sow', 'water', 'harvest', 'cancel'], true)) throw new GameError('INVALID_ACTION', 'Неизвестное действие.', 422);
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.crop.' . $action, $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $action, $bus) {
            $p = $this->prepare($user, $action, $payload);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('CROP_CHANGED', 'Условия выращивания изменились. Повторите расчёт.');
            $now = time();
            if ($action === 'publish') {
                $crop = $this->one('world_crop', ['code' => $terms['code']]);
                if (!$crop) { $this->db->createCommand()->insert('world_crop', ['code' => $terms['code'], 'name' => $terms['name']])->execute(); $crop = ['id' => (int)$this->db->getLastInsertID()]; }
                else $this->db->createCommand()->update('world_crop', ['name' => $terms['name']], ['id' => $crop['id']])->execute();
                $this->db->createCommand()->update('world_crop_revision', ['status' => 'superseded'], ['crop_id' => $crop['id'], 'status' => 'published'])->execute();
                $values = array_intersect_key($terms, array_flip(['version', 'seed_item_id', 'yield_item_id', 'water_item_id', 'seed_quantity', 'yield_quantity', 'grow_seconds', 'water_quantity', 'water_interval_seconds']));
                $this->db->createCommand()->insert('world_crop_revision', $values + ['crop_id' => $crop['id'], 'status' => 'published', 'author_user_id' => $user, 'published_at' => $now, 'config_json' => CanonicalJson::encode(['reason' => $terms['reason'], 'operation_id' => $operation])])->execute();
                $result = ['crop_id' => (int)$crop['id'], 'crop_revision_id' => (int)$this->db->getLastInsertID(), 'changed_node_ids' => []];
                $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
            } else {
                if ($action !== 'cancel') { $p['store']->operationId = $operation; (new CanonicalInventory($p['store']))->applyCraft($user, $p['plan'], $p['target'], $p['items'], $p['output'], 'cultivation.' . $action); }
                if ($action === 'sow') {
                    $this->db->createCommand()->insert('world_crop_cycle', ['bed_id' => $payload['bed_id'], 'owner_user_id' => $user, 'crop_revision_id' => $p['rules']['id'], 'operation_id' => $operation, 'status' => 'growing', 'planted_at' => $now, 'ready_at' => $now + (int)$p['rules']['grow_seconds'], 'water_due_at' => $p['rules']['water_item_id'] === null ? null : $now + (int)$p['rules']['water_interval_seconds'], 'terms_json' => CanonicalJson::encode($terms)])->execute();
                    $cycleId = (int)$this->db->getLastInsertID();
                    $this->db->createCommand()->insert('world_bed_active_cycle', ['bed_id' => $payload['bed_id'], 'cycle_id' => $cycleId])->execute();
                } else {
                    $cycleId = (int)$p['cycle']['id'];
                    if ($action === 'water') $values = ['ready_at' => (int)$p['cycle']['ready_at'] + max(0, $now - (int)$p['cycle']['water_due_at']), 'water_due_at' => $now + (int)$p['rules']['water_interval_seconds']];
                    else {
                        $values = ['status' => $action === 'harvest' ? 'harvested' : 'cancelled', 'closed_at' => $now];
                        if ($this->db->createCommand()->delete('world_bed_active_cycle', ['bed_id' => $payload['bed_id'], 'cycle_id' => $cycleId])->execute() !== 1) throw new \RuntimeException('Crop guard changed.');
                        if ($action === 'harvest') $this->db->createCommand()->insert('world_harvest_result', ['cycle_id' => $cycleId, 'item_id' => $p['output']['id'], 'storage_id' => $p['target']['id'], 'operation_id' => $operation, 'quantity' => $terms['output']['quantity'], 'created_at' => $now])->execute();
                    }
                    if ($this->db->createCommand()->update('world_crop_cycle', $values + ['revision' => new Expression('[[revision]]+1')], ['id' => $cycleId, 'closed_at' => null, 'revision' => $p['cycle']['revision']])->execute() !== 1) throw new \RuntimeException('Crop cycle changed.');
                }
                $this->db->createCommand()->insert('world_crop_action', ['cycle_id' => $cycleId, 'operation_id' => $operation, 'kind' => $action, 'quantity' => $action === 'harvest' ? $terms['output']['quantity'] : 0, 'created_at' => $now])->execute();
                if ($this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1')], ['id' => $payload['bed_id']])->execute() !== 1) throw new \RuntimeException('Bed revision update failed.');
                $result = ['cycle_id' => $cycleId, 'changed_node_ids' => [$payload['bed_id']], 'changed_storage_ids' => $action === 'cancel' ? [] : [(int)$p['target']['id']]];
            }
            $bus->emit($operation, $user, $action === 'harvest' ? 'world.crop.harvested' : 'world.crop.' . $action, $result); return $result;
        });
    }
}
