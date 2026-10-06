<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\StorageAccessPolicy;
use common\modules\world\modules\economy\models\domain\BudgetFunding;
use common\modules\world\modules\economy\models\domain\BudgetSpending;
use common\modules\world\modules\economy\models\domain\EconomyHierarchy;
use common\modules\world\modules\economy\models\domain\TreasuryLedger;
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

class WorldWarehouse
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function context(int $user, int $node): array
    {
        $capabilities = $this->flags->requireFlags(['world_read', 'storage_v2']);
        $place = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->record($node);
        if ((int)$place['owner_user_id'] !== $user) throw new GameError('STORAGE_FORBIDDEN', 'Склад доступен владельцу.', 403);
        $place['id'] = (int)$place['id']; $place['revision'] = (int)$place['revision'];
        $storage = (new Query())->from('craft_storage')->where(['identity_key' => 'stockpile:building:' . $node, 'status' => 'active'])->one($this->db);
        if (!$storage) throw new GameError('WAREHOUSE_NOT_FOUND', 'У здания нет склада.', 404);
        $storage = (new StorageAccessPolicy($this->db))->storage($user, (int)$storage['id'], true);
        $policy = (new Query())->from('world_warehouse_policy')->where(['storage_id' => $storage['id']])->one($this->db);
        if (!$policy || (int)$storage['capacity'] < (int)$policy['initial_capacity']) throw new GameError('WAREHOUSE_UNAVAILABLE', 'Параметры склада требуют сверки.');
        $finance = (new EconomyHierarchy($this->db))->financialNode($node);
        $recipient = (new Query())->select('n.id')->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], '[[c.ancestor_id]]=[[n.id]]')->where(['c.descendant_id' => $node, 'n.node_type' => 'SETTLEMENT'])->scalar($this->db);
        if (!$recipient) throw new GameError('WAREHOUSE_UNAVAILABLE', 'Склад должен находиться в поселении.');
        return compact('place', 'storage', 'policy', 'finance', 'recipient', 'capabilities');
    }
    public function state(int $user, int $node): array
    {
        return $this->present($this->context($user, $node));
    }
    private function present(array $c): array
    {
        $node = $c['place']['id']; $storage = $c['storage']; $policy = $c['policy'];
        $price = Money::parse($policy['base_price'])->multiply((int)$storage['capacity'] - (int)$policy['initial_capacity'] + 1);
        $used = (int)(new Query())->from('craft_inventory')->where(['storage_id' => $storage['id']])->andWhere(['>', 'item_quantity', 0])->count('*', $this->db);
        return ['node_id' => $node, 'storage_id' => (int)$storage['id'], 'capacity' => (int)$storage['capacity'], 'used' => $used,
            'limit' => (int)$policy['max_capacity'], 'next_price' => (int)$storage['capacity'] < (int)$policy['max_capacity'] ? $price->decimal() : null,
            'finance_node_id' => $c['finance'], 'writable' => $c['capabilities']['world_write'], 'server_time' => time()];
    }
    private function prepare(int $user, array $input): array
    {
        $c = $this->context($user, $input['node_id']); $state = $this->present($c);
        if ($state['next_price'] === null) throw new GameError('WAREHOUSE_FULLY_EXPANDED', 'Склад расширен до предела.');
        $price = Money::parse($state['next_price']); $hierarchy = new EconomyHierarchy($this->db);
        $accounts = $hierarchy->accounts($c['finance']); $budget = $accounts['budget'] ?? null;
        $available = $budget ? (new BudgetSpending($this->db))->available((int)$budget['id']) : Money::parse('0');
        $gap = $price->compare($available) > 0 ? $price->subtract($available) : Money::parse('0');
        if (!$gap->isZero() && !$input['top_up']) throw new GameError('INSUFFICIENT_BUDGET', 'Пополните бюджет родительского объекта или оплатите разницу с личного баланса.');
        if (!$gap->isZero()) {
            $credit = (new Query())->select('credit')->from('persone')->where(['user_id' => $user])->scalar($this->db);
            if ($credit === false || Money::parse((string)$credit)->compare($gap) < 0) throw new GameError('INSUFFICIENT_CREDIT', 'На балансе недостаточно кредитов.');
        }
        (new TreasuryLedger($this->db))->published((int)$c['recipient']);
        $revisions = ['node:' . $input['node_id'] => $c['place']['revision'], 'storage:' . $c['storage']['id'] => (int)$c['storage']['revision']];
        if ($budget) $revisions['account:' . $budget['id']] = (int)$budget['revision'];
        return $c + ['revisions' => $revisions, 'terms' => ['node_id' => $input['node_id'], 'storage_id' => (int)$c['storage']['id'], 'finance_node_id' => $c['finance'],
            'capacity_after' => $state['capacity'] + 1, 'price' => $price->decimal(), 'personal_charge' => $gap->decimal(), 'recipient_node_id' => (int)$c['recipient'], 'policy_revision' => (int)$c['policy']['revision']]];
    }
    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.warehouse.expand', $input, function () use ($user, $input) { return $this->prepare($user, $input); });
    }
    public function execute(int $user, array $input, string $key, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.warehouse.expand', $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $bus) {
            $p = $this->prepare($user, $payload);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('WAREHOUSE_CHANGED', 'Условия расширения изменились. Повторите расчёт.');
            $gap = Money::parse($terms['personal_charge']); $price = Money::parse($terms['price']);
            if (!$gap->isZero()) (new BudgetFunding($this->db))->contribute($user, $terms['finance_node_id'], $gap, 'Расширение склада', $operation);
            $accounts = (new EconomyHierarchy($this->db))->provision($terms['finance_node_id'], $operation); $spending = new BudgetSpending($this->db);
            $hold = $spending->reserve((int)$accounts['budget']['id'], $price, 'Расширение склада', $operation);
            $spending->pay($hold, $terms['recipient_node_id'], $price, $operation, 'warehouse_expansion');
            if ($this->db->createCommand()->update('craft_storage', ['capacity' => $terms['capacity_after'], 'revision' => new Expression('[[revision]]+1')], ['id' => $terms['storage_id'], 'revision' => $p['storage']['revision']])->execute() !== 1) throw new GameError('WAREHOUSE_CHANGED', 'Вместимость склада изменилась.');
            $result = ['changed_node_ids' => array_values(array_unique([$payload['node_id'], $terms['finance_node_id'], $terms['recipient_node_id']])), 'changed_storage_ids' => [$terms['storage_id']], 'amount' => $terms['price'], 'currency' => 'Cr'];
            $bus->emit($operation, $user, 'world.warehouse.expanded', $result); return $result;
        });
    }
}
