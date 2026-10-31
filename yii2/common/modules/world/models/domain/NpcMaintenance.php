<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use common\modules\world\modules\economy\models\domain\{BudgetDisbursement, BudgetFunding, BudgetSpending, EconomyHierarchy, TreasuryLedger};
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\{GameError, JobQueue, Locks};
use yii\db\{Connection, Query};

/** Recycles existing public/NPC money. No credit or inventory issuance. */
final class NpcMaintenance
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function operation(int $bot, int $world, string $type): string
    {
        $operation = bin2hex(random_bytes(16));
        $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $bot, 'type' => $type, 'created_at' => time()] + EconomyRuntime::operationContext($this->db, ['node_id' => $world]))->execute();
        return $operation;
    }
    public function discover(): void
    {
        if (!$this->db->schema->getTableSchema('world_npc_policy')) return;
        foreach ((new Query())->from('world_npc_policy')->where(['enabled' => 1])->all($this->db) as $policy) {
            if ((int)$policy['last_refill_at'] + (int)$policy['refill_interval_seconds'] > time()) continue;
            $bucket = intdiv(time(), max(10, (int)$policy['refill_interval_seconds']));
            $this->db->transaction(function () use ($policy, $bucket) {
                (new Locks($this->db))->row('world_registry', ['id' => 1]);
                (new JobQueue($this->db))->enqueue('world.npc.maintain', 'npc-' . $policy['world_id'] . '-' . $bucket,
                    ['world_id' => (int)$policy['world_id'], 'bucket' => $bucket], (int)$policy['bot_user_id']);
            });
        }
    }
    public function perform(array $payload, array $job): void
    {
        $policy = (new NpcInfrastructure($this->db))->policy((int)$payload['world_id']);
        if (!$policy || (int)$job['owner_user_id'] !== (int)$policy['bot_user_id']) return;
        if ((int)$policy['last_refill_at'] + (int)$policy['refill_interval_seconds'] > time()) return;
        $operation = $this->operation((int)$policy['bot_user_id'], (int)$policy['world_id'], 'world.npc.maintain');
        $nodes = (new NpcInfrastructure($this->db))->nodes($policy);
        (new NpcStockLogistics($this->db))->consolidate($policy, $nodes, $operation);
        $this->refresh($policy, $operation);
        // Reads remain side-effect free: reopen full sources before the next visitor arrives.
        foreach ($nodes as $source) {
            if (NpcInfrastructure::role($source) !== 'source' || !NodeAvailability::activePath($this->db, (int)$source['id'])) continue;
            $place = Node::findOne((int)$source['id']);
            if (!$place || !ExtractionStorage::local($place)) continue;
            foreach (WorldSources::options($place) as $definition) $this->ensureOutputStorage($place, $definition, $operation);
        }
    }
    public function refresh(array $policy, string $operation): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('NPC maintenance needs a locked world transaction.');
        $infrastructure = new NpcInfrastructure($this->db); $nodes = $infrastructure->nodes($policy);
        $public = (new Query())->from('world_node')->where(['root_id' => $policy['world_id'], 'owner_user_id' => $policy['bot_user_id'], 'status' => 'active', 'node_type' => ['WORLD', 'REGION', 'SETTLEMENT']])->all($this->db);
        $circulation = array_merge($nodes, $public);
        // Collect and settle children first so their receipts reach the parent in this pass.
        usort($circulation, static fn($a, $b) => [$b['hierarchy_level'], $b['id']] <=> [$a['hierarchy_level'], $a['id']]);
        foreach ($circulation as $node) {
            $this->priceStorage((int)$node['id'], $operation);
            if (\common\modules\world\domain\NodeStructure::isLeaf($node)) continue;
            $accounts = (new EconomyHierarchy($this->db))->accounts((int)$node['id']);
            if (!isset($accounts['treasury'])) continue;
            try {
                $ledger = new TreasuryLedger($this->db); $plan = $ledger->planCollection((int)$node['id'], $accounts, time());
                $receiptOperation = $this->operation((int)$policy['bot_user_id'], (int)$policy['world_id'], 'world.npc.collect');
                $ledger->collect((int)$node['id'], $accounts, $plan, $receiptOperation);
            } catch (GameError $error) {
                if (!in_array($error->reason, ['TREASURY_EMPTY', 'TREASURY_CATCHING_UP'], true)) throw $error;
            }
            if (!empty($policy['settle_public_obligations'])) $this->settleObligations($node, $accounts, $policy);
        }
        // Keep one new batch funded at every source before filling larger buffers.
        foreach ($nodes as $node) if (NpcInfrastructure::role($node) === 'source') $this->fund($node, $policy, $operation, $this->minimumWage((int)$node['id'], $policy), true);
        usort($nodes, static fn($a, $b) => [NpcInfrastructure::role($a) !== 'source', $a['id']] <=> [NpcInfrastructure::role($b) !== 'source', $b['id']]);
        $shortfall = Money::parse('0');
        foreach ($nodes as $node) if (in_array(NpcInfrastructure::role($node), ['camp', 'source', 'workshop', 'equipment', 'warehouse'], true)) {
            if (\common\modules\world\domain\NodeStructure::isLeaf($node)) continue;
            $this->fund($node, $policy, $operation);
            $accounts = (new EconomyHierarchy($this->db))->accounts((int)$node['id']);
            $available = (new BudgetSpending($this->db))->available((int)$accounts['budget']['id']); $target = $this->targetBudget($node, $policy);
            if ($available->compare($target) < 0) $shortfall = $shortfall->add($target->subtract($available));
        }
        $this->db->createCommand()->update('world_npc_policy', ['last_refill_at' => time(), 'last_shortfall_cr' => $shortfall->decimal()], ['id' => $policy['id']])->execute();
    }
    private function settleObligations(array $node, array $accounts, array $policy): void
    {
        // Only actual invoices of the bot's public/managed nodes; other reservations stay intact.
        $invoices = (new Query())->select('id')->from('economy_obligation')
            ->where(['budget_account_id' => $accounts['budget']['id'], 'status' => 'pending'])->orderBy('id')->column($this->db);
        $ledger = new TreasuryLedger($this->db);
        foreach ($invoices as $invoice) {
            $plan = $ledger->planPayment((int)$node['id'], $accounts, (int)$invoice);
            $operation = $this->operation((int)$policy['bot_user_id'], (int)$policy['world_id'], 'world.npc.parent-payment');
            $ledger->pay($accounts, $plan, $operation);
        }
    }
    public function ensureBudget(Node $node, string $operation): void
    {
        $infrastructure = new NpcInfrastructure($this->db);
        if ($infrastructure->managed($node->getAttributes(), ['source'])) $this->fund($node->getAttributes(), $infrastructure->policy((int)$node->root_id), $operation, null, true);
    }
    /** A busy source must not wait for the periodic worker to receive already funded output. */
    public function ensureOutputStorage(Node $node, array $definition, string $operation): void
    {
        $infrastructure = new NpcInfrastructure($this->db);
        if (!$infrastructure->managed($node->getAttributes(), ['source']) || $this->outputFits($node, $definition)) return;
        if (!$this->db->getTransaction()) throw new \LogicException('NPC logistics requires a locked transaction.');
        $policy = $infrastructure->policy((int)$node->root_id); $nodes = $infrastructure->nodes($policy);
        $logistics = new NpcStockLogistics($this->db);
        $logistics->consolidate($policy, $nodes, $operation, (int)$node->id);
        if (!$this->outputFits($node, $definition)) {
            // Recycle public receipts, without touching accepted wage holds or private accounts.
            $this->refresh($policy, $operation);
            $warehouse = new WorldWarehouse($this->db, new WorldFlags($this->db, \Yii::$app->getModule('world')));
            foreach ($nodes as $target) {
                if (NpcInfrastructure::role($target) !== 'warehouse' || !NodeAvailability::activePath($this->db, (int)$target['id'])) continue;
                // Some batches contain several resources. Each slot is bought separately,
                // with a fresh price, until that batch fits or the policy/funds limit is hit.
                while (!$this->outputFits($node, $definition)) {
                    $state = $warehouse->state((int)$policy['bot_user_id'], (int)$target['id']);
                    if ($state['next_price'] === null) break;
                    $price = Money::parse($state['next_price']);
                    $purchase = $this->operation((int)$policy['bot_user_id'], (int)$policy['world_id'], 'world.npc.warehouse.expand');
                    $this->fund($target, $policy, $purchase, $price, true);
                    $account = (new EconomyHierarchy($this->db))->accounts($state['finance_node_id'])['budget'];
                    if ((new BudgetSpending($this->db))->available((int)$account['id'])->compare($price) < 0) break;
                    $warehouse->expandFunded((int)$policy['bot_user_id'], (int)$target['id'], $purchase);
                    $logistics->consolidate($policy, $nodes, $purchase, (int)$node->id);
                }
                if ($this->outputFits($node, $definition)) break;
            }
        }
        foreach ($nodes as $target) if (NpcInfrastructure::role($target) === 'warehouse') $this->priceStorage((int)$target['id'], $operation);
    }
    private function outputFits(Node $node, array $definition): bool
    {
        try { ExtractionStorage::nearest($node, $definition, true); return true; }
        catch (GameError $error) { if ($error->reason !== 'EMPLOYER_STORAGE_FULL') throw $error; return false; }
    }
    private function minimumWage(int $node, array $policy): Money
    {
        $place = Node::findOne($node);
        return Money::parse($place ? HiredExtraction::rates($place)['reward_cr'] : $policy['reward_cr']);
    }
    private function targetBudget(array $node, array $policy): Money
    {
        $target = Money::parse(NpcInfrastructure::role($node) === 'source' ? $policy['target_budget_cr'] : ($policy['idle_budget_cr'] ?? $policy['target_budget_cr']));
        $wage = NpcInfrastructure::role($node) === 'source' ? $this->minimumWage((int)$node['id'], $policy) : Money::parse('0');
        return $wage->compare($target) > 0 ? $wage : $target;
    }
    private function fund(array $node, array $policy, string $operation, ?Money $goal = null, bool $useBuffers = false): void
    {
        $db = $this->db; $spending = new BudgetSpending($db); $hierarchy = new EconomyHierarchy($db);
        $target = $hierarchy->provision((int)$node['id'], $operation)['budget'];
        $buffer = Money::parse($policy['target_budget_cr']); $goal = $goal ?? $this->targetBudget($node, $policy); $available = $spending->available((int)$target['id']);
        if ($available->compare($goal) >= 0) return;
        $needed = $goal->subtract($available);
        $sources = (new Query())->select(['a.*', 'n.node_type', 'n.root_id', 'n.owner_user_id', 'n.settings_json', 'n.id AS node_id'])->from(['a' => 'economy_account'])
            ->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')
            ->where(['n.root_id' => $policy['world_id'], 'n.owner_user_id' => $policy['bot_user_id'], 'n.status' => 'active', 'a.role' => 'budget'])
            ->andWhere(['<>', 'a.id', $target['id']])->orderBy('n.hierarchy_level,a.id')->all($db);
        foreach ($sources as $source) {
            $role = NpcInfrastructure::role($source);
            if (!in_array($source['node_type'], ['WORLD', 'REGION', 'SETTLEMENT'], true)
                && !(new NpcInfrastructure($db))->managed(array_replace($source, ['id' => $source['node_id']]))) continue;
            $free = $spending->available((int)$source['id']);
            if ($role !== null && $role !== 'store') {
                $minimum = $role === 'source' ? $this->minimumWage((int)$source['node_id'], $policy) : Money::parse('0');
                $roleBuffer = $role === 'source' ? $buffer : Money::parse($policy['idle_budget_cr'] ?? $policy['target_budget_cr']);
                $retained = $useBuffers || $minimum->compare($roleBuffer) > 0 ? $minimum : $roleBuffer;
                $free = $free->compare($retained) > 0 ? $free->subtract($retained) : Money::parse('0');
            }
            $amount = $free->compare($needed) < 0 ? $free : $needed;
            if ($amount->isZero()) continue;
            $line = (int)(new Query())->from('economy_transfer')->where(['operation_id' => $operation, 'kind' => 'npc_budget_refill'])->count('*', $db);
            (new BudgetDisbursement($db))->toBudget((int)$source['id'], (int)$target['id'], $amount, $operation, 'npc-refill-' . $line,
                'Пополнение бюджета NPC: ' . $node['name'], null, 'npc_budget_refill');
            $needed = $needed->subtract($amount); if ($needed->isZero()) break;
        }
        if (!$needed->isZero()) {
            $balance = (new Query())->select('credit')->from('persone')->where(['user_id' => $policy['bot_user_id']])->scalar($db);
            $wallet = $balance === false ? Money::parse('0') : Money::parse((string)$balance);
            $amount = $wallet->compare($needed) < 0 ? $wallet : $needed;
            if (!$amount->isZero()) (new BudgetFunding($db))->contribute((int)$policy['bot_user_id'], (int)$node['id'], $amount, 'Пополнение бюджета NPC', $operation);
        }
    }
    public function priceStorage(int $node, string $operation): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('NPC sale prices require a locked world transaction.');
        $place = (new Query())->from('world_node')->where(['id' => $node])->one($this->db);
        if (!$place || !(new NpcInfrastructure($this->db))->managed($place, ['store', 'warehouse'])) return;
        $profile = EconomyRuntime::current($this->db); if (!$profile) return;
        $rows = (new Query())->select(['i.id', 'd.code'])->from(['i' => 'craft_inventory'])->innerJoin(['s' => 'craft_storage'], 's.id=i.storage_id')->innerJoin(['d' => 'craft_item'], 'd.id=i.item_id')
            ->where(['s.node_id' => $node, 's.kind' => 'stockpile', 's.owner_user_id' => $place['owner_user_id'], 'd.active' => 1, 'd.kind' => 'material', 'd.storage_kind' => 'none'])
            ->andWhere(['>', 'i.item_quantity', 0])->all($this->db);
        foreach ($rows as $row) {
            $price = $profile['profile']['items'][$row['code']]['reference_price_cr'] ?? null;
            if ($price === null || Money::parse($price)->isZero()) continue;
            $old = (new Query())->from('world_harvest_lot')->where(['inventory_id' => $row['id']])->one($this->db);
            if (!$old) $this->db->createCommand()->insert('world_harvest_lot', ['inventory_id' => $row['id'], 'harvested_at' => time(), 'spoiled_at' => time(), 'price' => $price, 'operation_id' => $operation])->execute();
            elseif (Money::parse((string)($old['price'] ?? '0'))->compare(Money::parse($price)) !== 0 || $old['spoiled_at'] === null)
                $this->db->createCommand()->update('world_harvest_lot', ['price' => $price, 'spoiled_at' => time()], ['inventory_id' => $row['id']])->execute();
        }
    }
}
