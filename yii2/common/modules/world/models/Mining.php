<?php
namespace common\modules\world\models;

use common\modules\world\modules\craft\models\domain\CanonicalInventory;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\support\GameError;
use yii\db\Query;

/** Manual resource extraction uses the same server heartbeat limit as construction. */
final class Mining
{
    private static function context(int $nodeId, int $user): array
    {
        $node = Node::readable($nodeId, $user); $settings = json_decode($node->settings_json, true) ?: [];
        if ($node->status !== 'active' || !$node->owner_user_id || ($settings['production_enabled'] ?? false) !== true)
            throw new GameError('MINING_UNAVAILABLE', 'Добыча в этом объекте недоступна.', 422);
        foreach (Node::find()->where(['parent_id' => $nodeId, 'status' => 'active'])->orderBy('id')->all() as $child) {
            $config = (json_decode($child->settings_json, true) ?: [])['extraction'] ?? null;
            if (is_array($config) && is_string($config['item_code'] ?? null) && is_int($config['seconds_per_unit'] ?? null) && $config['seconds_per_unit'] >= 1 && $config['seconds_per_unit'] <= 86400) return [$node, $config];
        }
        return [$node, null];
    }
    public static function state(int $nodeId, int $user): array
    {
        [$node, $config] = self::context($nodeId, $user); $db = Node::getDb(); $now = time();
        $state = (new Query())->from('world_mining_state')->where(['node_id' => $nodeId])->one($db);
        $workers = (new Query())->from('world_mining_worker')->where(['node_id' => $nodeId])->andWhere(['>', 'last_seen_at', $now - 60])->all($db);
        return ['node_id' => $nodeId, 'ready' => $config !== null, 'working' => in_array($user, array_map('intval', array_column($workers, 'user_id')), true),
            'workers' => count($workers), 'produced_quantity' => (int)($state['produced_quantity'] ?? 0), 'pending_quantity' => $state ? max(0, intdiv((int)$state['worked_seconds'], (int)$state['seconds_per_unit']) - (int)$state['produced_quantity']) : 0,
            'seconds_per_unit' => (int)($state['seconds_per_unit'] ?? $config['seconds_per_unit'] ?? 60), 'server_time' => $now];
    }
    public static function start(int $nodeId, int $user, string $operation): array
    {
        [$node, $config] = self::context($nodeId, $user); $db = Node::getDb();
        if (!$config) throw new GameError('EXTRACTOR_REQUIRED', 'Сначала постройте буровую установку внутри шахты.', 422);
        if ((new Query())->from('world_builder')->where(['user_id' => $user])->exists($db)) throw new GameError('BUILDER_BUSY', 'Приостановите строительство перед добычей.', 409);
        $worker = (new Query())->from('world_mining_worker')->where(['user_id' => $user])->one($db);
        if ($worker && (int)$worker['node_id'] !== $nodeId) throw new GameError('MINER_BUSY', 'Сначала приостановите добычу на другой шахте.', 409);
        if (!(new Query())->from('world_mining_state')->where(['node_id' => $nodeId])->exists($db)) {
            $item = (new Query())->from('craft_item')->where(['code' => $config['item_code'], 'active' => 1, 'kind' => 'material'])->one($db);
            if (!$item) throw new GameError('RESOURCE_UNAVAILABLE', 'Ресурс добычи не настроен.', 503);
            $db->createCommand()->insert('world_mining_state', ['node_id' => $nodeId, 'item_id' => $item['id'], 'seconds_per_unit' => $config['seconds_per_unit'], 'worked_seconds' => 0, 'produced_quantity' => 0])->execute();
        }
        self::checkpoint($nodeId, $operation);
        $worker = (new Query())->from('world_mining_worker')->where(['user_id' => $user])->one($db);
        if (!$worker) $db->createCommand()->insert('world_mining_worker', ['user_id' => $user, 'node_id' => $nodeId, 'accounted_at' => time(), 'last_seen_at' => time()])->execute();
        else $db->createCommand()->update('world_mining_worker', ['accounted_at' => time(), 'last_seen_at' => time()], ['user_id' => $user])->execute();
        return self::state($nodeId, $user);
    }
    public static function heartbeat(int $nodeId, int $user, string $operation, bool $stop = false): array
    {
        self::context($nodeId, $user); $db = Node::getDb();
        $worker = (new Query())->from('world_mining_worker')->where(['user_id' => $user, 'node_id' => $nodeId])->one($db);
        if (!$worker) throw new GameError('WORK_NOT_STARTED', 'Сначала начните добычу.', 409);
        self::checkpoint($nodeId, $operation);
        if ($stop) $db->createCommand()->delete('world_mining_worker', ['user_id' => $user, 'node_id' => $nodeId])->execute();
        else $db->createCommand()->update('world_mining_worker', ['last_seen_at' => time(), 'accounted_at' => time()], ['user_id' => $user, 'node_id' => $nodeId])->execute();
        return self::state($nodeId, $user);
    }
    public static function checkpoint(int $nodeId, string $operation): void
    {
        $db = Node::getDb(); if (!$db->getTransaction()) throw new \LogicException('Mining requires a command transaction.');
        $node = Node::requireOne($nodeId);
        try { [, $config] = self::context($nodeId, (int)$node->owner_user_id); }
        catch (GameError $e) { $db->createCommand()->delete('world_mining_worker', ['node_id' => $nodeId])->execute(); return; }
        if (!$config) { $db->createCommand()->delete('world_mining_worker', ['node_id' => $nodeId])->execute(); return; }
        $state = (new Query())->from('world_mining_state')->where(['node_id' => $nodeId])->one($db); if (!$state) return;
        $total = (int)$state['worked_seconds']; $now = time();
        foreach ((new Query())->from('world_mining_worker')->where(['node_id' => $nodeId])->all($db) as $worker) {
            $end = min($now, (int)$worker['last_seen_at'] + 60); $total += max(0, $end - (int)$worker['accounted_at']);
            $db->createCommand()->update('world_mining_worker', ['accounted_at' => max((int)$worker['accounted_at'], $end)], ['user_id' => $worker['user_id']])->execute();
        }
        $quantity = min(10000, max(0, intdiv($total, (int)$state['seconds_per_unit']) - (int)$state['produced_quantity'])); $produced = (int)$state['produced_quantity'];
        if ($quantity) {
            $store = new CraftStorage($db); $store->operationId = $operation; $inventory = new CanonicalInventory($store);
            $target = (new Query())->select('s.*')->from(['s' => 'craft_storage'])->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
                ->where(['n.parent_id' => $nodeId, 'n.status' => 'active', 's.kind' => 'stockpile', 's.status' => 'active', 's.owner_user_id' => $node->owner_user_id])->orderBy('s.id')->one($db) ?: $inventory->backpack((int)$node->owner_user_id);
            $item = (new Query())->from('craft_item')->where(['id' => $state['item_id'], 'active' => 1, 'kind' => 'material'])->one($db);
            if (!$item) throw new GameError('RESOURCE_UNAVAILABLE', 'Ресурс добычи отключён.', 503);
            if ($target) {
                $plan = $inventory->planCraft([], $target, [], [], $item, $quantity);
                if ($plan['output_fits']) { $inventory->applyCraft((int)$node->owner_user_id, $plan, $target, [], $item, 'mining'); $produced += $quantity; }
            }
        }
        $db->createCommand()->update('world_mining_state', ['worked_seconds' => $total, 'produced_quantity' => $produced], ['node_id' => $nodeId])->execute();
        $db->createCommand()->delete('world_mining_worker', ['and', ['node_id' => $nodeId], ['<=', 'last_seen_at', $now - 60]])->execute();
    }
    public static function discover(): void
    {
        $db = Node::getDb(); if (!$db->schema->getTableSchema('world_mining_worker')) return;
        $ids = (new Query())->select('node_id')->from('world_mining_worker')->distinct()->limit(100)->column($db);
        foreach ($ids as $id) {
            $node = Node::requireOne($id);
            Command::run((int)$node->owner_user_id, 'mining-tick-' . $id . '-' . time(), 'world.mining.tick', ['mining_node_id' => (int)$id], function ($operation) use ($id) { self::checkpoint((int)$id, $operation); return ['node_id' => (int)$id]; });
        }
    }
}
