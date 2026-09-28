<?php
namespace common\modules\economy\service;

use common\modules\craft\service\CanonicalInventory;
use common\modules\craft\service\CraftStorage;
use common\modules\craft\service\EquipmentInstances;
use common\modules\economy\value\Money;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldQuery;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use common\services\game\JobQueue;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Finite municipal demand. Goods are consumed by the settlement, credits come from its reserved budget. */
class StarterOrders
{
    private $db;
    private $flags;
    private $access;
    public function __construct(Connection $db, WorldFlags $flags, WorldAccessPolicy $access) { $this->db = $db; $this->flags = $flags; $this->access = $access; }
    private function settlement(int $user, int $id): array
    {
        $this->flags->requireFlag('world_read');
        $place = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($id);
        if ($place['type'] !== 'SETTLEMENT' || $place['status'] !== 'active' || $place['visibility'] !== 'public') throw new GameError('ORDER_SETTLEMENT_UNAVAILABLE', 'Выберите действующее публичное поселение.');
        return $place;
    }
    private function canManage(array $place): bool
    {
        $owner = (new Query())->select('owner_user_id')->from('world_node')->where(['id' => $place['id']])->scalar($this->db);
        return $place['permissions']['storage'] || ($owner === null && $this->access->isAdmin());
    }
    private function manager(array $place): void
    {
        if (!$this->canManage($place)) throw new GameError('ORDER_MANAGEMENT_FORBIDDEN', 'Заказы публикует владелец поселения. Системными поселениями управляет администрация.', 403);
    }
    private function site(int $user, array $settlement): ?array
    {
        return (new Query())->select('n.*')->from(['m' => 'world_membership'])->innerJoin(['n' => 'world_node'], '[[n.id]]=[[m.starter_site_id]]')
            ->where(['m.user_id' => $user, 'm.world_id' => $settlement['root_id'], 'n.parent_id' => $settlement['id'], 'n.status' => 'active', 'n.owner_user_id' => $user, 'n.node_type' => 'PLOT'])->one($this->db) ?: null;
    }
    private function item(int $id): array
    {
        $item = (new Query())->from('craft_item')->where(['id' => $id, 'active' => 1, 'kind' => 'material', 'storage_kind' => 'none'])->one($this->db);
        $crop = $this->db->schema->getTableSchema('world_crop_revision')
            && (new Query())->from('world_crop_revision')->where(['yield_item_id' => $id, 'status' => 'published'])->exists($this->db);
        if (!$item || ((int)$item['gather_quantity'] < 1 && !$crop) || (new EquipmentInstances($this->db))->tracked($item)) throw new GameError('ORDER_ITEM_UNAVAILABLE', 'Заказ принимает собираемое сырьё или урожай опубликованной культуры.', 422);
        return $item;
    }
    private function meta(int $total, int $page, int $size = 20): array { return ['totalCount' => $total, 'pageCount' => (int)ceil($total / $size), 'currentPage' => $page, 'perPage' => $size]; }
    public function listing(int $user, int $node, int $page, string $search, string $status): array
    {
        $place = $this->settlement($user, $node); $site = $this->site($user, $place);
        $ready = $this->flags->capabilities()['world_write'] && $this->flags->capabilities()['storage_v2'] && WalletSchema::ready($this->db);
        $canPublish = $ready && $this->canManage($place);
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120 || !in_array($status, ['open', 'all'], true)) throw new GameError('INVALID_ORDER_FILTER', 'Некорректные параметры списка.', 422);
        $query = (new Query())->select(['o.*', 'item_name' => 'i.name'])->from(['o' => 'economy_purchase_order'])->innerJoin(['i' => 'craft_item'], '[[i.id]]=[[o.item_id]]')->where(['o.node_id' => $node]);
        if ($status === 'open') $query->andWhere(['o.status' => 'open'])->andWhere(['>', 'o.expires_at', time()]);
        if ($search !== '') $query->andWhere(['or', ['like', 'i.name', $search], ['like', 'o.purpose', $search]]);
        $total = (int)(clone $query)->count('*', $this->db); $items = [];
        foreach ($query->orderBy(['o.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) {
            $used = (int)(new Query())->from('economy_order_fulfillment')->where(['order_id' => $row['id'], 'user_id' => $user])->sum('quantity', $this->db);
            $items[] = ['id' => (int)$row['id'], 'item_id' => (int)$row['item_id'], 'item_name' => trim($row['item_name']), 'purpose' => $row['purpose'], 'quantity' => (int)$row['quantity'],
                'remaining_quantity' => (int)$row['remaining_quantity'], 'my_remaining' => max(0, (int)$row['per_user_limit'] - $used), 'unit_price' => Money::parse((string)$row['unit_price'])->decimal(),
                'status' => $row['status'] === 'open' && (int)$row['expires_at'] <= time() ? 'expired' : $row['status'], 'can_cancel' => $canPublish && $row['status'] === 'open', 'expires_at' => (int)$row['expires_at']];
        }
        return ['node_id' => $node, 'items' => $items, '_meta' => $this->meta($total, $page), 'can_publish' => $canPublish,
            'can_deliver' => $ready && $site !== null, 'site_node_id' => $site ? (int)$site['id'] : null, 'server_time' => time()];
    }
    public function catalog(int $user, int $node, int $page, string $search): array
    {
        $this->settlement($user, $node);
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_ORDER_FILTER', 'Некорректные параметры списка.', 422);
        $query = (new Query())->from(['i' => 'craft_item'])->where(['i.active' => 1, 'i.kind' => 'material', 'i.storage_kind' => 'none'])
            ->andWhere(['not exists', (new Query())->from('craft_station')->where(new Expression('[[item_id]]=[[i.id]]'))])
            ->andWhere(['not exists', (new Query())->from('craft_recipe_tool')->where(new Expression('[[item_id]]=[[i.id]]'))]);
        if ($this->db->schema->getTableSchema('world_crop_revision')) $query->andWhere(['or', ['>', 'i.gather_quantity', 0],
            ['exists', (new Query())->from('world_crop_revision')->where(['status' => 'published'])->andWhere(new Expression('[[yield_item_id]]=[[i.id]]'))]]);
        else $query->andWhere(['>', 'i.gather_quantity', 0]);
        if ($search !== '') $query->andWhere(['like', 'i.name', $search]);
        $total = (int)(clone $query)->count('*', $this->db); $items = [];
        foreach ($query->orderBy(['i.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) $items[] = ['id' => (int)$row['id'], 'name' => trim($row['name'])];
        return ['items' => $items, '_meta' => $this->meta($total, $page)];
    }
    public function stock(int $user, int $node, int $item, int $page): array
    {
        $this->settlement($user, $node); $this->flags->requireFlag('storage_v2'); $this->item($item);
        if ($page < 1 || $page > 1000000) throw new GameError('INVALID_PAGINATION', 'Некорректная страница.', 422);
        $inventory = new CanonicalInventory(new CraftStorage($this->db)); $pack = $inventory->backpack($user); $items = []; $total = 0;
        if ($pack) {
            $storage = (new \common\modules\craft\service\StorageAccessPolicy($this->db))->storage($user, (int)$pack['id']);
            $query = (new Query())->from('craft_inventory')->where(['user_id' => $user, 'storage_id' => $pack['id'], 'item_id' => $item])->andWhere(['>', 'item_quantity', 0])->andWhere(['between', 'slot', 1, $storage['capacity']]);
            $total = (int)(clone $query)->count('*', $this->db);
            foreach ($query->orderBy(['id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) $items[] = ['id' => (int)$row['id'], 'position' => (int)$row['slot'], 'quantity' => (int)$row['item_quantity']];
        }
        return ['item_id' => $item, 'items' => $items, '_meta' => $this->meta($total, $page)];
    }
    private function order(int $node, int $id): array
    {
        $order = (new Query())->from('economy_purchase_order')->where(['id' => $id, 'node_id' => $node])->one($this->db);
        if (!$order) throw new GameError('ORDER_NOT_FOUND', 'Заказ не найден.', 404);
        if ($order['status'] !== 'open') throw new GameError('ORDER_CLOSED', 'Заказ уже закрыт.');
        return $order;
    }
    private function prepare(int $user, array $input, string $action): array
    {
        $place = $this->settlement($user, $input['node_id']); $this->flags->requireFlag('storage_v2'); WalletSchema::requireReady($this->db);
        $accounts = (new EconomyHierarchy($this->db))->accounts($place['id']);
        if (!isset($accounts['budget'])) throw new GameError('INSUFFICIENT_BUDGET', 'Сначала пополните бюджет поселения и опубликуйте финансовые правила.');
        $budget = $accounts['budget']; $revisions = ['node:' . $place['id'] => $place['revision'], 'account:' . $budget['id'] => (int)$budget['revision']];
        $terms = $input; $result = compact('place', 'budget');
        if ($action === 'publish') {
            $this->manager($place); $item = $this->item($input['item_id']);
            $policy = (new TreasuryLedger($this->db))->published($place['id']);
            if ($policy['rate_bps'] >= 10000) throw new GameError('STARTER_POLICY_ZERO_NET', 'Начальные условия должны оставлять игроку часть собранного дохода.', 422);
            $cost = Money::parse($input['unit_price'])->multiply($input['quantity']);
            if ($cost->isZero() || $cost->isNegative() || (new BudgetSpending($this->db))->available((int)$budget['id'])->compare($cost) < 0) throw new GameError('INSUFFICIENT_BUDGET', 'Недостаточно свободных средств бюджета для полной оплаты заказа.');
            $terms += ['reserved_cost' => $cost->decimal(), 'item_name' => trim($item['name']), 'starter_policy' => $policy, 'goods_consumed' => true];
        } else {
            $order = $this->order($place['id'], $input['order_id']); $result['order'] = $order; $terms['order_revision'] = (int)$order['revision'];
            if ($action === 'cancel') {
                $this->manager($place); $hold = (new BudgetSpending($this->db))->commitment((int)$order['commitment_id']);
                $terms['released_reserve'] = Money::parse((string)$hold['remaining_amount'])->decimal();
            } else {
                if ((int)$order['expires_at'] <= time()) throw new GameError('ORDER_EXPIRED', 'Срок заказа истёк.');
                $site = $this->site($user, $place);
                if (!$site) throw new GameError('STARTER_SITE_REQUIRED', 'Для сдачи заказа нужна собственная стартовая стоянка в этом поселении.');
                $incomeNode = (int)($input['income_node_id'] ?? $site['id']);
                if ($incomeNode !== (int)$site['id']) {
                    $bed = (new Query())->from(['b' => 'world_bed'])->innerJoin(['n' => 'world_node'], '[[n.id]]=[[b.node_id]]')
                        ->innerJoin(['p' => 'world_garden_purchase'], '[[p.node_id]]=[[b.garden_node_id]]')
                        ->innerJoin(['g' => 'world_node'], '[[g.id]]=[[p.node_id]] AND [[n.parent_id]]=[[g.id]]')
                        ->innerJoin(['m' => 'world_membership'], '[[m.id]]=[[p.membership_id]]')
                        ->where(['b.node_id' => $incomeNode, 'b.unlocked' => 1, 'n.status' => 'active', 'n.owner_user_id' => $user,
                            'g.status' => 'active', 'g.owner_user_id' => $user, 'g.parent_id' => $place['id'],
                            'm.user_id' => $user, 'm.starter_site_id' => $site['id']])->exists($this->db);
                    $crop = (new Query())->from('world_crop_revision')->where(['yield_item_id' => $order['item_id'], 'status' => ['published', 'superseded']])->exists($this->db);
                    if (!$bed || !$crop) throw new GameError('BED_INCOME_UNAVAILABLE', 'Доход от урожая можно направить только в казну своей открытой грядки.', 403);
                }
                $used = (int)(new Query())->from('economy_order_fulfillment')->where(['order_id' => $order['id'], 'user_id' => $user])->sum('quantity', $this->db);
                if ($input['quantity'] > (int)$order['remaining_quantity'] || $input['quantity'] > (int)$order['per_user_limit'] - $used) throw new GameError('ORDER_QUANTITY_CHANGED', 'Превышен остаток заказа или ваш лимит.');
                $selected = (new CanonicalInventory(new CraftStorage($this->db)))->inspectOrderDelivery($user, $input['inventory_id'], (int)$order['item_id'], $input['quantity']);
                $earning = Money::parse((string)$order['unit_price'])->multiply($input['quantity']);
                (new BudgetSpending($this->db))->allocation((int)$budget['id'], $earning);
                $hold = (new BudgetSpending($this->db))->commitment((int)$order['commitment_id']);
                if ((int)$hold['account_id'] !== (int)$budget['id'] || Money::parse((string)$hold['remaining_amount'])->compare($earning) < 0) throw new \RuntimeException('Order funding mismatch.');
                $policy = $incomeNode === (int)$site['id']
                    ? (new StarterIncomePolicy($this->db))->preview((int)$site['id'], $place['id'], (int)$order['starter_history_id'])
                    : (new TreasuryLedger($this->db))->published($incomeNode);
                $target = (new EconomyHierarchy($this->db))->accounts($incomeNode);
                Money::parse((string)($target['treasury']['amount'] ?? '0'))->add($earning);
                $revisions['node:' . $site['id']] = (int)$site['revision'];
                if ($incomeNode !== (int)$site['id']) $revisions['node:' . $incomeNode] = (int)(new Query())->select('revision')->from('world_node')->where(['id' => $incomeNode])->scalar($this->db);
                $revisions['inventory:' . $selected['row']['id']] = (int)$selected['row']['revision'];
                $revisions['storage:' . $selected['storage']['id']] = (int)$selected['storage']['revision'];
                $terms += ['item_id' => (int)$order['item_id'], 'site_node_id' => (int)$site['id'], 'income_node_id' => $incomeNode,
                    'earned' => $earning->decimal(), 'destination' => $incomeNode === (int)$site['id'] ? 'site_treasury' : 'bed_treasury', 'income_policy' => $policy, 'goods_consumed' => true];
                $result['site'] = $site;
            }
        }
        $meta = (new Query())->from('craft_meta')->where(['id' => 1])->one($this->db); $revisions['catalog'] = (int)$meta['revision'];
        return $result + compact('terms', 'revisions');
    }
    public function preview(int $user, array $input, string $action): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.orders.' . $action, $input, function () use ($user, $input, $action) {
            try { return $this->prepare($user, $input, $action); }
            catch (\OverflowException $e) { throw new GameError('ORDER_AMOUNT_LIMIT', 'Сумма заказа превышает допустимый предел.', 422); }
        });
    }
    public function execute(int $user, string $key, array $input, string $quote, array $revisions, string $action): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.orders.' . $action, $input, $quote, $revisions, function (array $payload, array $terms, string $operation) use ($user, $action, $bus) {
            try { $p = $this->prepare($user, $payload, $action); }
            catch (\OverflowException $e) { throw new GameError('ORDER_AMOUNT_LIMIT', 'Сумма превышает допустимый предел.'); }
            if (CanonicalJson::encode($terms) !== CanonicalJson::encode($p['terms'])) throw new GameError('ORDER_CHANGED', 'Условия заказа изменились. Повторите расчёт.');
            $changed = [$payload['node_id']]; $storages = []; $event = []; $spend = new BudgetSpending($this->db); $now = time();
            if ($action === 'publish') {
                $hold = $spend->reserve((int)$p['budget']['id'], Money::parse($terms['reserved_cost']), $payload['purpose'], $operation);
                $this->db->createCommand()->insert('economy_purchase_order', ['node_id' => $payload['node_id'], 'item_id' => $payload['item_id'], 'commitment_id' => $hold,
                    'starter_history_id' => $terms['starter_policy']['history_id'], 'quantity' => $payload['quantity'], 'remaining_quantity' => $payload['quantity'], 'per_user_limit' => $payload['per_user_limit'],
                    'unit_price' => $payload['unit_price'], 'purpose' => $payload['purpose'], 'status' => 'open', 'created_by' => $user, 'created_at' => $now, 'expires_at' => $now + $payload['lifetime_hours'] * 3600])->execute();
                $id = (int)$this->db->getLastInsertID();
                (new JobQueue($this->db))->enqueue('economy.order.expire', 'order:' . $id, ['order_id' => $id], null, $now + $payload['lifetime_hours'] * 3600);
            } elseif ($action === 'cancel') {
                $id = (int)$p['order']['id']; $this->close($p['order'], 'cancelled', $operation);
            } else {
                $order = $p['order']; $id = (int)$order['id']; $site = (int)$p['site']['id'];
                if ($terms['income_node_id'] === $site) (new StarterIncomePolicy($this->db))->initialize($site, $payload['node_id'], (int)$order['starter_history_id'], $operation, $id);
                $store = new CraftStorage($this->db); $store->operationId = $operation;
                $storages[] = (new CanonicalInventory($store))->deliverOrder($user, $payload['inventory_id'], (int)$order['item_id'], $payload['quantity']);
                $transfer = $spend->pay((int)$order['commitment_id'], $terms['income_node_id'], Money::parse($terms['earned']), $operation, $terms['destination'] === 'bed_treasury' ? 'crop_purchase' : 'order_payment');
                $left = (int)$order['remaining_quantity'] - $payload['quantity'];
                if ($this->db->createCommand()->update('economy_purchase_order', ['remaining_quantity' => $left, 'status' => $left ? 'open' : 'fulfilled', 'revision' => new Expression('[[revision]]+1'),
                    'closed_at' => $left ? null : $now, 'close_operation_id' => $left ? null : $operation], ['id' => $id, 'revision' => $order['revision'], 'status' => 'open'])->execute() !== 1) throw new \RuntimeException('Order update failed.');
                $this->db->createCommand()->insert('economy_order_fulfillment', ['order_id' => $id, 'user_id' => $user, 'site_node_id' => $site, 'inventory_id' => $payload['inventory_id'],
                    'quantity' => $payload['quantity'], 'transfer_id' => $transfer, 'operation_id' => $operation, 'created_at' => $now])->execute();
                $event = ['fulfillment_id' => (int)$this->db->getLastInsertID(), 'item_id' => (int)$order['item_id'], 'quantity' => $payload['quantity'], 'site_node_id' => $site, 'income_node_id' => $terms['income_node_id'], 'earned' => $terms['earned']];
                $changed[] = $site; $changed[] = $terms['income_node_id'];
            }
            $bus->emit($operation, $user, 'world.orders.' . $action, ['order_id' => $id, 'changed_node_ids' => $changed] + $event);
            return ['changed_node_ids' => $changed, 'changed_storage_ids' => $storages];
        });
    }
    private function close(array $order, string $status, string $operation): void
    {
        (new BudgetSpending($this->db))->release((int)$order['commitment_id']);
        if ($this->db->createCommand()->update('economy_purchase_order', ['status' => $status, 'closed_at' => time(), 'close_operation_id' => $operation, 'revision' => new Expression('[[revision]]+1')], ['id' => $order['id'], 'status' => 'open', 'revision' => $order['revision']])->execute() !== 1) throw new \RuntimeException('Order closure failed.');
    }
    public function expire(array $payload): void
    {
        if (!$this->db->getTransaction() || !is_int($payload['order_id'] ?? null)) throw new \LogicException('Invalid expiry job.');
        $order = (new Query())->from('economy_purchase_order')->where(['id' => $payload['order_id']])->one($this->db);
        if (!$order || $order['status'] !== 'open') return;
        if ((int)$order['expires_at'] > time()) throw new \RuntimeException('Order expiry job is early.');
        $operation = bin2hex(random_bytes(16));
        $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => null, 'type' => 'economy.order.expire', 'created_at' => time()])->execute();
        $this->close($order, 'expired', $operation);
        (new CommandBus($this->db, $this->flags))->emit($operation, null, 'economy.order.expire', ['order_id' => (int)$order['id'], 'node_id' => (int)$order['node_id']]);
    }
}
