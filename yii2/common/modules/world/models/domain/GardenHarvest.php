<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\CanonicalInventory;
use common\modules\world\modules\craft\models\domain\CraftStorage;
use common\modules\world\modules\craft\models\domain\StorageAccessPolicy;
use common\modules\world\modules\economy\models\domain\FinanceReadSnapshot;
use common\modules\world\modules\economy\models\domain\TreasuryLedger;
use common\modules\world\modules\economy\models\domain\WalletSchema;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Explicit mini-storage with batch freshness; retains the pre-conversion harvest API. */
class GardenHarvest
{
    public const FRESH_SECONDS = 1209600;
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    public static function provision(Connection $db, int $garden, int $owner): array
    {
        // Only the pre-conversion schema retains its implicit five-slot warehouses.
        if (!$db->schema->getTableSchema('world_template')->getColumn('name')) {
            $where = ['identity_key' => 'harvest:garden:' . $garden];
            $legacy = (new Query())->from('craft_storage')->where($where)->one($db);
            if (!$legacy) {
                if (!$db->getTransaction()) throw new \LogicException('Harvest provisioning requires a transaction.');
                $db->createCommand()->insert('craft_storage', $where + ['kind' => 'harvest', 'node_id' => $garden, 'owner_user_id' => $owner, 'capacity' => 5])->execute();
                $legacy = (new Query())->from('craft_storage')->where($where)->one($db);
            }
            if ((int)$legacy['owner_user_id'] !== $owner || $legacy['kind'] !== 'harvest' || $legacy['status'] !== 'active') throw new GameError('HARVEST_STORAGE_UNAVAILABLE', 'Склад недоступен.');
            return $legacy;
        }
        $storage = (new Query())->select('s.*')->from(['s' => 'craft_storage'])->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
            ->where(['n.parent_id' => $garden, 'n.node_type' => 'CHEST', 'n.status' => 'active', 's.kind' => 'stockpile', 's.owner_user_id' => $owner, 's.status' => 'active'])->orderBy(['s.id' => SORT_ASC])->one($db);
        if ($storage) $storage['preserve_batches'] = true;
        else $storage = (new CanonicalInventory(new CraftStorage($db)))->backpack($owner);
        if (!$storage) throw new GameError('BACKPACK_UNAVAILABLE', 'Оборудуйте мини-склад или освободите рюкзак.');
        $storage = (new StorageAccessPolicy($db))->storage($owner, (int)$storage['id'], true) + $storage;
        return $storage;
    }
    private function context(int $garden, int $user): array
    {
        $this->flags->requireFlags(['world_read', 'storage_v2']);
        (new WorldQuery($this->db, new WorldAccessPolicy($user)))->record($garden);
        $legacy = !$this->db->schema->getTableSchema('world_template')->getColumn('name');
        $node = $this->one('world_node', ['id' => $garden, 'status' => 'active'] + ($legacy ? ['node_type' => 'PLOT'] : []));
        if ($legacy && !$this->one('world_plot', ['node_id' => $garden, 'plot_kind' => 'garden'])) $node = null;
        if (!$node || !$node['owner_user_id'] || (new Query())->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $garden])->andWhere(['<>', 'n.status', 'active'])->exists($this->db)) throw new GameError('STORAGE_UNAVAILABLE', 'Мини-склад недоступен.', 404);
        $storage = $this->one('craft_storage', ['node_id' => $garden, 'kind' => $legacy ? 'harvest' : 'stockpile', 'owner_user_id' => $node['owner_user_id'], 'status' => 'active']);
        if (!$storage) throw new GameError('HARVEST_STORAGE_UNAVAILABLE', 'Мини-склад ещё не построен.', 503);
        if ($legacy) $node['settings_json'] = '{"allow_sales":true}';
        return compact('node', 'storage');
    }
    public static function spoiled(array $lot, int $quantity, int $now): int
    {
        return $lot['spoiled_at'] === null && $now >= (int)$lot['harvested_at'] + self::FRESH_SECONDS ? intdiv($quantity * 30, 100) : 0;
    }
    public function state(int $user, int $garden): array
    {
        return (new FinanceReadSnapshot($this->db))->run(function () use ($user, $garden) {
            $c = $this->context($garden, $user); $owner = (int)$c['node']['owner_user_id'] === $user; $now = time(); $items = [];
            $npc = (new NpcInfrastructure($this->db))->managed($c['node'], ['store', 'warehouse']);
            $rows = (new Query())->select(['i.*', 'l.harvested_at', 'l.spoiled_at', 'l.price', 'name' => 'd.name', 'icon' => 'd.icon'])->from(['i' => 'craft_inventory'])
                ->leftJoin(['l' => 'world_harvest_lot'], '[[l.inventory_id]]=[[i.id]]')->innerJoin(['d' => 'craft_item'], '[[d.id]]=[[i.item_id]]')
                ->where(['i.storage_id' => $c['storage']['id']])->andWhere(['>', 'i.item_quantity', 0])->orderBy(['i.slot' => SORT_ASC])->all($this->db);
            foreach ($rows as $row) {
                if ($row['harvested_at'] === null) { $row['harvested_at'] = 2147483647 - self::FRESH_SECONDS; $row['spoiled_at'] = $now; }
                $lost = self::spoiled($row, (int)$row['item_quantity'], $now);
                $items[] = ['inventory_id' => (int)$row['id'], 'position' => (int)$row['slot'], 'name' => $row['name'], 'icon' => $row['icon'], 'quantity' => (int)$row['item_quantity'] - $lost,
                    'harvested_at' => (int)$row['harvested_at'], 'fresh_until' => $npc ? 2147483647 : (int)$row['harvested_at'] + self::FRESH_SECONDS, 'spoiled' => !$npc && ($row['spoiled_at'] !== null || $now >= (int)$row['harvested_at'] + self::FRESH_SECONDS),
                    'price' => $row['price'] === null ? null : Money::parse((string)$row['price'])->decimal()];
            }
            $settings = json_decode($c['node']['settings_json'] ?? '{}', true);
            return ['node_id' => $garden, 'name' => $c['node']['name'], 'capacity' => (int)$c['storage']['capacity'], 'slot_limit' => MiniStorage::slotLimit($this->db, $c['storage']), 'npc_supply' => $npc, 'allow_sales' => ($settings['allow_sales'] ?? false) === true, 'owned_by_me' => $owner, 'items' => $items, 'destinations' => $owner ? $this->destinations($user, $garden) : [], 'writable' => $this->flags->capabilities()['world_write'], 'server_time' => $now];
        });
    }
    private function destinations(int $user, int $garden): array
    {
        $site = (new Query())->select('n.id')->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')
            ->where(['c.descendant_id' => $garden, 'n.owner_user_id' => $user, 'n.node_type' => 'PLOT', 'n.status' => 'active'])
            ->andWhere(['>', 'c.distance', 0])->orderBy(['c.distance' => SORT_ASC])->scalar($this->db);
        $headers = (new \common\modules\world\modules\craft\models\domain\WorldStorage($this->db, $this->flags))->list($user, $site ? (int)$site : $garden)['items'];
        $items = [];
        foreach ($headers as $header) {
            if (!in_array($header['kind'], ['backpack', 'chest', 'stockpile'], true)) continue;
            try { (new StorageAccessPolicy($this->db))->storage($user, $header['id'], true); }
            catch (\yii\web\HttpException $error) { continue; } catch (GameError $error) { continue; }
            $items[] = ['id' => $header['id'], 'name' => $header['name'], 'kind' => $header['kind']];
        }
        return $items;
    }
    public function input(array $body, string $action): array
    {
        if (!in_array($action, ['price', 'buy', 'withdraw'], true) || !is_int($body['inventory_id'] ?? null) || $body['inventory_id'] < 1) throw new GameError('INVALID_HARVEST', 'Выберите ячейку урожая.', 422);
        $input = ['inventory_id' => $body['inventory_id']];
        if ($action === 'price') {
            $input['price'] = $body['price'] ?? null;
            if ($input['price'] !== null) {
                try { if (!is_string($input['price'])) throw new \InvalidArgumentException(); $price = Money::parse($input['price']); }
                catch (\Exception $e) { throw new GameError('INVALID_PRICE', 'Укажите положительную цену.', 422); }
                if ($price->isNegative() || $price->isZero()) throw new GameError('INVALID_PRICE', 'Укажите положительную цену.', 422);
                $input['price'] = $price->decimal();
            }
        } else {
            if (!is_int($body['quantity'] ?? null) || $body['quantity'] < 1 || $body['quantity'] > 10000) throw new GameError('INVALID_QUANTITY', 'Выберите количество урожая.', 422);
            $input['quantity'] = $body['quantity'];
            if ($action === 'withdraw' && isset($body['destination_storage_id'])) {
                if (!is_int($body['destination_storage_id']) || $body['destination_storage_id'] < 1) throw new GameError('INVALID_HARVEST', 'Выберите хранилище назначения.', 422);
                $input['destination_storage_id'] = $body['destination_storage_id'];
            }
        }
        return $input;
    }
    private function prepare(int $user, int $garden, string $action, array $input): array
    {
        $c = $this->context($garden, $user); $owner = (int)$c['node']['owner_user_id'];
        if (($action === 'buy') === ($owner === $user)) throw new GameError('HARVEST_FORBIDDEN', 'Покупка доступна другому игроку; ценой и выдачей управляет владелец.', 403);
        $row = $this->one('craft_inventory', ['id' => $input['inventory_id'], 'storage_id' => $c['storage']['id'], 'user_id' => $owner]);
        $lot = $this->one('world_harvest_lot', ['inventory_id' => $input['inventory_id']]);
        if (!$row || (int)$row['item_quantity'] < 1) throw new GameError('HARVEST_UNAVAILABLE', 'Продукцию уже забрали.', 409);
        $definition = $this->one('craft_item', ['id' => $row['item_id'], 'active' => 1]);
        if (!$definition || !in_array($definition['kind'], ['material', 'product'], true) || ($definition['storage_kind'] ?? 'none') !== 'none') throw new GameError('PRODUCT_NOT_FOR_SALE', 'Здесь продаются обычные материалы и продукция.', 422);
        $settings = json_decode($c['node']['settings_json'] ?? '{}', true);
        if ($action !== 'withdraw' && ($settings['allow_sales'] ?? false) !== true) throw new GameError('SALES_DISABLED', 'Продажа запрещена настройками объекта.', 403);
        $lot = $lot ?: ['inventory_id' => $row['id'], 'harvested_at' => 2147483647 - self::FRESH_SECONDS, 'spoiled_at' => time(), 'price' => null];
        $loss = self::spoiled($lot, (int)$row['item_quantity'], time()); $available = (int)$row['item_quantity'] - $loss;
        $terms = ['node_id' => $garden, 'inventory_id' => $input['inventory_id'], 'available' => $available, 'loss' => $loss, 'price' => $action === 'price' ? $input['price'] : ($lot['price'] === null ? null : Money::parse((string)$lot['price'])->decimal())];
        $revisions = ['node:' . $garden => (int)$c['node']['revision'], 'storage:' . $c['storage']['id'] => (int)$c['storage']['revision'], 'inventory:' . $row['id'] => (int)$row['revision']];
        $p = $c + compact('terms', 'revisions', 'row', 'lot', 'owner');
        if ($action === 'price') return $p;
        if ($input['quantity'] > $available) throw new GameError('HARVEST_QUANTITY_CHANGED', 'На складе осталось меньше урожая.', 409);
        $store = new CraftStorage($this->db); $inventory = new CanonicalInventory($store); $backpack = $inventory->backpack($user);
        if (!$backpack) throw new GameError('BACKPACK_UNAVAILABLE', 'Рюкзак ещё не подготовлен.');
        $target = (new StorageAccessPolicy($this->db))->storage($user, (int)$backpack['id'], true);
        if ($action === 'withdraw' && isset($input['destination_storage_id'])) {
            if (!in_array($input['destination_storage_id'], array_column($this->destinations($user, $garden), 'id'), true))
                throw new GameError('HARVEST_DESTINATION_UNAVAILABLE', 'Выберите свой рюкзак, сундук или склад на территории огорода.', 403);
            $target = (new StorageAccessPolicy($this->db))->storage($user, $input['destination_storage_id'], true);
        }
        $item = $this->one('craft_item', ['id' => $row['item_id'], 'active' => 1]);
        if (!$item) throw new GameError('CROP_UNAVAILABLE', 'Этот урожай недоступен.');
        $plan = $inventory->planCraft([], $target, [], [], $item, $input['quantity']);
        $p['terms'] += ['quantity' => $input['quantity'], 'name' => $item['name'], 'fits' => $plan['output_fits'], 'destination_storage_id' => (int)$target['id'], 'total' => '0.0000'];
        $p['revisions']['storage:' . $target['id']] = (int)$target['revision'];
        $p['revisions']['catalog'] = (int)$this->one('craft_meta', ['id' => 1])['revision'];
        if ($action === 'buy') {
            WalletSchema::requireReady($this->db);
            if ($lot['price'] === null) throw new GameError('HARVEST_NOT_FOR_SALE', 'Владелец ещё не выставил цену.');
            try { $total = Money::parse((string)$lot['price'])->multiply($input['quantity']); }
            catch (\OverflowException $e) { throw new GameError('INVALID_PRICE', 'Общая стоимость превышает допустимую сумму.', 422); }
            $wallet = $this->one('persone', ['user_id' => $user]);
            if (!$wallet || Money::parse((string)$wallet['credit'])->compare($total) < 0) throw new GameError('INSUFFICIENT_CREDIT', 'Не хватает кредитов.');
            (new TreasuryLedger($this->db))->published($garden);
            $p['terms']['total'] = $total->decimal();
        }
        return $p + compact('store', 'target', 'item', 'plan');
    }
    public function preview(int $user, int $garden, string $action, array $input): array
    {
        $input = ['node_id' => $garden] + $this->input($input, $action);
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.harvest.' . $action, $input, function () use ($user, $garden, $action, $input) { return $this->prepare($user, $garden, $action, $input); });
    }
    public function execute(int $user, int $garden, string $action, array $input, string $key, string $quote, array $revisions): array
    {
        $input = ['node_id' => $garden] + $this->input($input, $action); $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.harvest.' . $action, $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $garden, $action, $bus) {
            $p = $this->prepare($user, $garden, $action, $payload);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('HARVEST_CHANGED', 'Количество или цена изменились. Повторите действие.');
            $store = new CraftStorage($this->db); $store->operationId = $operation; $inventory = new CanonicalInventory($store);
            if ($terms['loss']) $inventory->consumeHarvest($p['owner'], $payload['inventory_id'], $terms['loss'], 'harvest.spoiled');
            if ($p['lot']['spoiled_at'] === null && time() >= (int)$p['lot']['harvested_at'] + self::FRESH_SECONDS)
                $this->db->createCommand()->update('world_harvest_lot', ['spoiled_at' => time()], ['inventory_id' => $payload['inventory_id'], 'spoiled_at' => null])->execute();
            $changed = [(int)$p['storage']['id']];
            if ($action === 'price') {
                if (!$this->one('world_harvest_lot', ['inventory_id' => $payload['inventory_id']])) $this->db->createCommand()->insert('world_harvest_lot', ['inventory_id' => $payload['inventory_id'], 'harvested_at' => $p['lot']['harvested_at'], 'spoiled_at' => $p['lot']['spoiled_at'], 'operation_id' => $operation, 'price' => $payload['price']])->execute();
                else $this->db->createCommand()->update('world_harvest_lot', ['price' => $payload['price']], ['inventory_id' => $payload['inventory_id']])->execute();
            }
            else {
                if (!$p['plan']['output_fits']) throw new GameError('BACKPACK_FULL', 'Освободите место в выбранном хранилище.');
                if ($action === 'buy') {
                    $profile = EconomyRuntime::current($this->db);
                    if ($profile && $payload['quantity'] > $profile['profile']['limits']['sales_daily']['same_item_per_operation']) throw new GameError('SALE_QUANTITY_LIMIT', 'Превышен предел одинаковых предметов в одной продаже.', 422);
                    $seller = $this->one('world_node', ['id' => $garden]);
                    $npc = (new NpcInfrastructure($this->db))->managed($seller, ['store', 'warehouse']);
                    if (!$npc) EconomyQuota::consume($this->db, $p['owner'], 'sales_daily', 1, $operation, $terms['total']);
                    (new TreasuryLedger($this->db))->receivePersonalPayment($user, $garden, Money::parse($terms['total']), $operation, ($npc ? 'Покупка сырья: ' : 'Покупка урожая: ') . $terms['name']);
                }
                $inventory->consumeHarvest($p['owner'], $payload['inventory_id'], $payload['quantity'], $action === 'buy' ? 'harvest.sold' : 'harvest.withdrawn');
                $inventory->applyCraft($user, $p['plan'], $p['target'], [], $p['item'], 'harvest.' . $action);
                $changed[] = (int)$p['target']['id'];
            }
            if ($this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1')], ['id' => $garden])->execute() !== 1) throw new \RuntimeException('Garden revision failed.');
            $result = ['changed_node_ids' => [$garden], 'changed_storage_ids' => $changed];
            $bus->emit($operation, $user, 'world.harvest.' . $action, $result); return $result;
        });
    }
    public function recordHarvest(array $storage, array $plan, string $operation): void
    {
        if ($storage['kind'] === 'backpack') return;
        foreach ($plan['grant'] as $grant) {
            $row = $this->one('craft_inventory', ['storage_id' => $storage['id'], 'slot' => $grant['position']]);
            if (!$row || $grant['inventory_id'] !== null) throw new \LogicException('Harvest batches must not merge.');
            $this->db->createCommand()->insert('world_harvest_lot', ['inventory_id' => $row['id'], 'harvested_at' => time(), 'operation_id' => $operation])->execute();
        }
    }
}
