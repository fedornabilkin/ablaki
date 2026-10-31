<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use common\modules\world\modules\craft\models\domain\CanonicalInventory;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\EquipmentExposure;
use common\modules\world\modules\craft\models\domain\StationResolver;
use common\modules\world\modules\craft\models\domain\StorageAccessPolicy;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\GameError;
use yii\db\Query;

/** One accepted source batch uses Mining's worker guard and sixty-second heartbeat. */
final class WorldSources
{
    public static function handles(Node $node): bool
    {
        $db = Node::getDb();
        if (!EconomyRuntime::current($db)) return false;
        $legacy = (new Query())->from('world_mining_worker')->where(['node_id' => $node->id, 'source_work_id' => null])->exists($db);
        $legacy = $legacy || (new Query())->from('world_mining_state')->where(['node_id' => $node->id])
            ->andWhere('worked_seconds > produced_quantity * seconds_per_unit')->exists($db);
        return !$legacy && ((bool)self::options($node) || (new Query())->from('world_source_work')->where(['node_id' => $node->id])->andWhere(['not', ['active_key' => null]])->exists($db));
    }
    public static function options(Node $node): array
    {
        $revision = EconomyRuntime::current(Node::getDb()); $result = [];
        if (!$revision || !$node->template) return [];
        $bonus = DrillingRig::bonus($node, $revision['profile']);
        foreach ($revision['profile']['actions'] as $code => $row) if ($row['quota'] === 'extraction_daily' && $row['node'] === $node->template->code) $result[$code] = DrillingRig::apply($row, $bonus, $revision['profile']);
        return $result;
    }
    private static function context(int $node, int $user): Node
    {
        $place = Node::readable($node, $user);
        if ((int)$place->owner_user_id !== $user && !HiredExtraction::supports($place)) $place->requireOwner($user);
        if ((int)$place->root_id !== EconomyRuntime::world(Node::getDb()) || $place->status !== 'active' || (new Query())->from(['c' => 'world_node_closure'])->innerJoin(['n' => 'world_node'], 'n.id=c.ancestor_id')
            ->where(['c.descendant_id' => $node])->andWhere(['<>', 'n.status', 'active'])->exists(Node::getDb())) throw new GameError('SOURCE_UNAVAILABLE', 'Источник временно недоступен.', 409);
        return $place;
    }
    private static function work(int $node, int $user): ?array
    {
        return (new Query())->from('world_source_work')->where(['active_key' => $user . ':' . $node])->one(Node::getDb()) ?: null;
    }
    private static function dailyUsed(int $user, string $action, int $period): int
    {
        $db = Node::getDb();
        return (int)(new Query())->from(['w' => 'world_source_work'])->innerJoin(['n' => 'world_node'], 'n.id=w.node_id')
            ->where(['w.user_id' => $user, 'w.action_code' => $action, 'w.status' => 'completed', 'n.root_id' => EconomyRuntime::world($db)])
            ->andWhere(['>=', 'w.completed_at', $period])->count('*', $db);
    }
    public static function state(int $node, int $user): array
    {
        $place = self::context($node, $user); $options = self::options($place); $db = Node::getDb();
        $work = self::work($node, $user); $worker = (new Query())->from('world_mining_worker')->where(['node_id' => $node, 'user_id' => $user])->one($db);
        if ($work) $options[$work['action_code']] = json_decode($work['definition_json'], true);
        $codes = [];
        foreach ($options as $row) $codes = array_merge($codes, array_keys($row['output']), $row['tools']);
        $items = (new Query())->from('craft_item')->where(['code' => array_unique($codes)])->indexBy('code')->all($db);
        $resolver = new StationResolver(new CraftStorage($db)); $toolAvailability = [];
        $missing = static function (array $definition) use ($place, $user, &$items, $resolver, &$toolAvailability): ?string {
            try { DrillingRig::requireAccepted($place, $definition); } catch (GameError $error) { return $error->getMessage(); }
            $names = [];
            foreach ($definition['tools'] as $code) {
                if (!array_key_exists($code, $toolAvailability)) $toolAvailability[$code] = isset($items[$code]) && (int)$items[$code]['active'] === 1 && $resolver->select($user, (int)$items[$code]['id']) !== null;
                if (!$toolAvailability[$code]) $names[] = $items[$code]['name'] ?? $code;
            }
            return $names ? 'Нужен исправный инструмент в рюкзаке: ' . implode(', ', $names) . '.' : null;
        };
        $choices = []; foreach ($options as $code => $row) {
            $output = []; foreach ($row['output'] as $item => $quantity) $output[] = ['code' => $item, 'name' => $items[$item]['name'] ?? $item, 'quantity' => $quantity];
            $toolNames = []; foreach ($row['tools'] as $tool) $toolNames[] = $items[$tool]['name'] ?? $tool;
            $availableAt = 0;
            if ($row['cooldown_seconds']) $availableAt = (int)(new Query())->select('completed_at')->from('world_source_work')
                ->where(['user_id' => $user, 'node_id' => $node, 'action_code' => $code, 'status' => 'completed'])->orderBy(['id' => SORT_DESC])->scalar($db) + $row['cooldown_seconds'];
            if (isset($row['daily_limit'])) {
                $period = \common\modules\world\models\WorldTime::dayWindow($db, time());
                if (self::dailyUsed($user, $code, $period['start']) >= $row['daily_limit']) $availableAt = max($availableAt, $period['end']);
            }
            $reason = $missing($row);
            if ((int)$place->owner_user_id !== $user && !$reason) try { HiredExtraction::context($place, $user, $work !== null, $work ? null : $row); } catch (GameError $error) { $reason = $error->getMessage(); }
            $choices[] = ['code' => $code, 'work_seconds' => $row['work_seconds'], 'experience' => $row['experience'], 'cooldown_seconds' => $row['cooldown_seconds'], 'available_at' => max(time(), $availableAt), 'output' => $output, 'tools' => $row['tools'], 'tool_names' => $toolNames, 'unavailable_reason' => $reason, 'daily_limit' => $row['daily_limit'] ?? null]
                + (isset($row['drilling']) ? ['drilling' => $row['drilling']] : []);
        }
        $definition = $work ? json_decode($work['definition_json'], true) : null;
        // An accepted batch retains its published tools even after a profile update.
        if ($definition) foreach ($definition['tools'] as $code) if (!isset($items[$code])) {
            $items[$code] = (new Query())->from('craft_item')->where(['code' => $code])->one($db) ?: null;
        }
        return ['node_id' => $node, 'ready' => (bool)$options, 'working' => $worker !== false && $worker !== null,
            'workers' => $worker ? 1 : 0, 'produced_quantity' => (int)(new Query())->from('world_source_work')->where(['user_id' => $user, 'node_id' => $node, 'status' => 'completed'])->count('*', $db),
            'pending_quantity' => $work && $work['status'] === 'pending' ? 1 : 0, 'seconds_per_unit' => $definition ? $definition['work_seconds'] : ($choices[0]['work_seconds'] ?? 1),
            'worked_seconds' => $work ? (int)$work['worked_seconds'] : 0, 'action_code' => $work ? $work['action_code'] : null,
            'work_id' => $work ? (int)$work['id'] : null, 'required_seconds' => $definition ? (int)$definition['work_seconds'] : 0,
            'live_work' => $worker ? [['accounted_at' => (int)$worker['accounted_at'], 'last_seen_at' => (int)$worker['last_seen_at']]] : [],
            'status' => $work ? $work['status'] : 'idle', 'output_storage_id' => $work ? (int)$work['target_storage_id'] : null, 'output_destination' => $definition['output_destination'] ?? (isset($definition['employment']) ? 'mini_storage' : null), 'output_storages' => ExtractionStorage::choices($place, $user), 'actions' => $choices, 'blocked_code' => $work ? $work['blocked_code'] : null,
            'blocked_message' => $work ? $work['blocked_message'] : null, 'unavailable_reason' => $definition ? $missing($definition) : null,
            'employment' => HiredExtraction::supports($place) ? HiredExtraction::state($place, $user, $work) : null,
            'quota' => EconomyQuota::state($db, $user, 'extraction_daily'), 'server_time' => time()];
    }
    private static function tools(int $user, array $definition): array
    {
        $db = Node::getDb(); $store = new CraftStorage($db); $units = [];
        foreach ($definition['tools'] as $code) {
            $item = (new Query())->from('craft_item')->where(['code' => $code, 'active' => 1])->one($db);
            $unit = $item ? (new StationResolver($store))->select($user, (int)$item['id']) : null;
            if (!$unit) throw new GameError('SOURCE_TOOL_REQUIRED', 'Нужен исправный инструмент в рюкзаке: ' . $code, 409);
            $units[] = $unit;
        }
        return $units;
    }
    public static function start(int $node, int $user, string $operation, ?string $action, ?int $target, ?string $destination = null): array
    {
        $db = Node::getDb(); $place = self::context($node, $user); $options = self::options($place);
        $work = self::work($node, $user);
        $action = $action ?? ($work['action_code'] ?? array_keys($options)[0] ?? '');
        if ((new Query())->from('world_builder')->where(['user_id' => $user])->exists($db)) throw new GameError('BUILDER_BUSY', 'Приостановите строительство перед добычей.', 409);
        $worker = (new Query())->from('world_mining_worker')->where(['user_id' => $user])->one($db);
        if ($worker && (int)$worker['node_id'] !== $node) throw new GameError('MINER_BUSY', 'Приостановите другую добычу.', 409);
        if (!isset($options[$action]) && (!$work || $work['action_code'] !== $action)) throw new GameError('SOURCE_ACTION_UNAVAILABLE', 'Выберите доступное действие источника.', 422);
        if ($work && $work['action_code'] !== $action) throw new GameError('SOURCE_BATCH_PENDING', 'Сначала завершите принятую партию выбранного ресурса.', 409);
        if (!$work) {
            $definition = $options[$action]; self::tools($user, $definition);
            $quota = EconomyQuota::state($db, $user, 'extraction_daily');
            if (!$quota['remaining']) throw new GameError('ECONOMY_QUOTA_EXHAUSTED', 'Дневной лимит добычи исчерпан.', 409, $quota);
            $last = (new Query())->from('world_source_work')->where(['user_id' => $user, 'node_id' => $node, 'action_code' => $action, 'status' => 'completed'])->orderBy(['id' => SORT_DESC])->one($db);
            if ($last && (int)$last['completed_at'] + $definition['cooldown_seconds'] > time()) throw new GameError('SOURCE_COOLDOWN', 'Источник восстанавливается.', 409, ['available_at' => (int)$last['completed_at'] + $definition['cooldown_seconds']]);
            $period = \common\modules\world\models\WorldTime::dayWindow($db, time());
            $used = self::dailyUsed($user, $action, $period['start']);
            if ($used >= ($definition['daily_limit'] ?? 10000)) throw new GameError('SOURCE_DAILY_LIMIT', 'Сегодня этот ресурс уже получен.', 409, ['available_at' => $period['end']]);
            if ((int)$place->owner_user_id !== $user) {
                $definition = HiredExtraction::accept($place, $user, $definition, $operation);
                $target = (int)$definition['employment']['storage_id'];
            } else {
                if ($destination === 'mini_storage') {
                    $target = (int)ExtractionStorage::nearest($place, $definition)['id']; $definition['output_destination'] = 'mini_storage';
                } else $target = ExtractionStorage::ownerTarget($place, $user, $destination === 'backpack' ? null : $target);
            }
            $revision = EconomyRuntime::current($db);
            $db->createCommand()->insert('world_source_work', ['user_id' => $user, 'node_id' => $node, 'active_key' => $user . ':' . $node,
                'action_code' => $action, 'definition_json' => CanonicalJson::encode($definition), 'profile_id' => $revision['id'],
                'target_storage_id' => $target, 'worked_seconds' => 0, 'status' => 'paused', 'created_at' => time()])->execute();
            $work = self::work($node, $user);
        }
        if ($work) DrillingRig::requireAccepted($place, json_decode($work['definition_json'], true));
        if ((int)$place->owner_user_id !== $user && $work) HiredExtraction::target($place, $user, json_decode($work['definition_json'], true));
        if (!$worker) $db->createCommand()->insert('world_mining_worker', ['user_id' => $user, 'node_id' => $node, 'source_work_id' => $work['id'], 'accounted_at' => time(), 'last_seen_at' => time()])->execute();
        $db->createCommand()->update('world_source_work', ['status' => 'working'], ['id' => $work['id']])->execute();
        return self::state($node, $user);
    }
    public static function heartbeat(int $node, int $user, string $operation, bool $stop): array
    {
        self::context($node, $user); self::checkpoint($node, $operation);
        $db = Node::getDb();
        if ($stop) {
            $db->createCommand()->delete('world_mining_worker', ['node_id' => $node, 'user_id' => $user])->execute();
            $work = self::work($node, $user);
            if ($work && $work['status'] === 'working') $db->createCommand()->update('world_source_work', ['status' => 'paused'], ['id' => $work['id']])->execute();
        } else $db->createCommand()->update('world_mining_worker', ['last_seen_at' => time(), 'accounted_at' => time()], ['node_id' => $node, 'user_id' => $user])->execute();
        return self::state($node, $user);
    }
    public static function checkpoint(int $node, string $operation): void
    {
        $db = Node::getDb(); if (!$db->getTransaction()) throw new \LogicException('Source checkpoint requires Mining command locks.');
        $rows = (new Query())->from('world_source_work')->where(['node_id' => $node])->andWhere(['not', ['active_key' => null]])->all($db);
        foreach ($rows as $work) {
            $user = (int)$work['user_id']; $definition = json_decode($work['definition_json'], true); $total = (int)$work['worked_seconds'];
            $worker = (new Query())->from('world_mining_worker')->where(['source_work_id' => $work['id']])->one($db);
            if ($worker) $total += max(0, min(time(), (int)$worker['last_seen_at'] + 60) - (int)$worker['accounted_at']);
            $total = min($total, $definition['work_seconds']);
            $status = $total >= $definition['work_seconds'] ? 'pending' : ($worker && (int)$worker['last_seen_at'] + 60 > time() ? 'working' : 'paused');
            $db->createCommand()->update('world_source_work', ['worked_seconds' => $total, 'status' => $status], ['id' => $work['id']])->execute();
            if ($worker) $db->createCommand()->update('world_mining_worker', ['accounted_at' => time()], ['user_id' => $user])->execute();
            if ($status === 'pending') {
                // Persist earned work when delivery is temporarily blocked, without consuming quota/wear.
                $transaction = $db->beginTransaction();
                try {
                    // A single checkpoint can deliver batches for several people. Journals and
                    // quota receipts need an operation per batch, independent of its caller.
                    $delivery = bin2hex(random_bytes(16));
                    $db->createCommand()->insert('game_operation', [
                        'id' => $delivery, 'user_id' => $user, 'type' => 'world.source.complete', 'created_at' => time(),
                    ] + EconomyRuntime::operationContext($db, ['node_id' => $node]))->execute();
                    $place = self::context($node, $user); DrillingRig::requireAccepted($place, $definition); $units = self::tools($user, $definition);
                    $period = \common\modules\world\models\WorldTime::dayWindow($db, time());
                    $used = self::dailyUsed($user, $work['action_code'], $period['start']);
                    if ($used >= ($definition['daily_limit'] ?? 10000)) throw new GameError('SOURCE_DAILY_LIMIT', 'Дневной предел этого источника исчерпан.', 409);
                    $store = new CraftStorage($db); $store->operationId = $delivery; $inventory = new CanonicalInventory($store);
                    if (isset($definition['employment'])) (new NpcMaintenance($db))->ensureOutputStorage($place, $definition, $delivery);
                    $target = isset($definition['employment']) ? HiredExtraction::target($place, $user, $definition)
                        : (($definition['output_destination'] ?? null) === 'mini_storage' ? ExtractionStorage::nearest($place, $definition) : (new StorageAccessPolicy($db))->storage($user, (int)$work['target_storage_id'], true));
                    foreach ($definition['output'] as $code => $quantity) {
                        $item = (new Query())->from('craft_item')->where(['code' => $code, 'active' => 1])->one($db);
                        if (!$item) throw new GameError('SOURCE_OUTPUT_UNAVAILABLE', 'Ресурс временно отключён.', 409);
                        $plan = $inventory->planCraft([], $target, [], [], $item, $quantity);
                        if (!$plan['output_fits']) throw new GameError('SOURCE_STORAGE_FULL', 'Освободите место для добытого ресурса.', 409);
                        $inventory->applyCraft(isset($definition['employment']) ? (int)$definition['employment']['owner_user_id'] : $user, $plan, $target, [], $item, 'source:' . $work['action_code']);
                    }
                    HiredExtraction::pay($user, (int)$work['id'], $definition, $delivery);
                    $npc = new NpcMaintenance($db);
                    if ($target['node_id'] !== null) $npc->priceStorage((int)$target['node_id'], $delivery);
                    $npc->ensureBudget($place, $delivery);
                    EconomyRuntime::fact($db, $user, 'source:' . $work['action_code'], array_sum($definition['output']), $delivery);
                    EconomyQuota::consume($db, $user, 'extraction_daily', 1, $delivery);
                    foreach ($units as $unit) (new EquipmentExposure($db))->use($unit['instance_id'], 1, 1, false);
                    $xp = (int)$definition['experience'];
                    if ($xp > 0) {
                        $where = ['user_id' => $user, 'category_id' => $item['category_id']];
                        $skill = (new Query())->from('craft_skill')->where($where)->one($db);
                        if ($skill) $db->createCommand()->update('craft_skill', ['experience' => min(9900, (int)$skill['experience'] + $xp)], $where)->execute();
                        else $db->createCommand()->insert('craft_skill', $where + ['experience' => min(9900, $xp)])->execute();
                    }
                    $db->createCommand()->insert('craft_event', ['user_id' => $user, 'action' => 'source', 'item_id' => $item['id'], 'quantity' => array_sum($definition['output']), 'credit_change' => 0, 'created_at' => time()])->execute();
                    $db->createCommand()->update('world_source_work', ['status' => 'completed', 'target_storage_id' => (int)$target['id'], 'active_key' => null, 'blocked_code' => null, 'blocked_message' => null, 'completed_at' => time(), 'operation_id' => $delivery], ['id' => $work['id']])->execute();
                    $transaction->commit();
                } catch (GameError $error) {
                    $transaction->rollBack();
                    $db->createCommand()->update('world_source_work', ['blocked_code' => $error->reason, 'blocked_message' => $error->getMessage()], ['id' => $work['id']])->execute();
                } catch (\yii\web\HttpException $error) {
                    $transaction->rollBack();
                    $db->createCommand()->update('world_source_work', ['blocked_code' => 'SOURCE_DELIVERY_BLOCKED', 'blocked_message' => 'Проверьте место, ресурсы и доступ к хранилищу.'], ['id' => $work['id']])->execute();
                } catch (\Throwable $error) {
                    $transaction->rollBack();
                    throw $error;
                }
            }
            if ($status !== 'working') $db->createCommand()->delete('world_mining_worker', ['source_work_id' => $work['id']])->execute();
        }
    }
}
