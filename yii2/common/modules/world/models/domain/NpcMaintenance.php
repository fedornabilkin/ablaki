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
        $this->refresh($policy, $operation);
    }
    public function refresh(array $policy, string $operation): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('NPC maintenance needs a locked world transaction.');
        $infrastructure = new NpcInfrastructure($this->db); $nodes = $infrastructure->nodes($policy);
        $public = (new Query())->from('world_node')->where(['root_id' => $policy['world_id'], 'owner_user_id' => $policy['bot_user_id'], 'status' => 'active', 'node_type' => ['WORLD', 'REGION', 'SETTLEMENT']])->all($this->db);
        foreach (array_merge($nodes, $public) as $node) {
            $this->priceStorage((int)$node['id'], $operation);
            $accounts = (new EconomyHierarchy($this->db))->accounts((int)$node['id']);
            if (!isset($accounts['treasury']) || Money::parse((string)$accounts['treasury']['amount'])->isZero()) continue;
            try {
                $ledger = new TreasuryLedger($this->db); $plan = $ledger->planCollection((int)$node['id'], $accounts, time());
                $receiptOperation = $this->operation((int)$policy['bot_user_id'], (int)$policy['world_id'], 'world.npc.collect');
                $ledger->collect((int)$node['id'], $accounts, $plan, $receiptOperation);
            } catch (GameError $error) {
                if (!in_array($error->reason, ['TREASURY_EMPTY', 'TREASURY_CATCHING_UP'], true)) throw $error;
            }
        }
        $shortfall = Money::parse('0');
        foreach ($nodes as $node) if (in_array(NpcInfrastructure::role($node), ['camp', 'source', 'workshop', 'equipment', 'warehouse'], true)) {
            $this->fund($node, $policy, $operation);
            $accounts = (new EconomyHierarchy($this->db))->accounts((int)$node['id']);
            $available = (new BudgetSpending($this->db))->available((int)$accounts['budget']['id']); $target = Money::parse($policy['target_budget_cr']);
            if ($available->compare($target) < 0) $shortfall = $shortfall->add($target->subtract($available));
        }
        $this->db->createCommand()->update('world_npc_policy', ['last_refill_at' => time(), 'last_shortfall_cr' => $shortfall->decimal()], ['id' => $policy['id']])->execute();
    }
    public function ensureBudget(Node $node, string $operation): void
    {
        $infrastructure = new NpcInfrastructure($this->db);
        if ($infrastructure->managed($node->getAttributes(), ['source'])) $this->fund($node->getAttributes(), $infrastructure->policy((int)$node->root_id), $operation);
    }
    private function fund(array $node, array $policy, string $operation): void
    {
        $db = $this->db; $spending = new BudgetSpending($db); $hierarchy = new EconomyHierarchy($db);
        $target = $hierarchy->provision((int)$node['id'], $operation)['budget'];
        $goal = Money::parse($policy['target_budget_cr']); $available = $spending->available((int)$target['id']);
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
            if ($role !== null && $role !== 'store') $free = $free->compare($goal) > 0 ? $free->subtract($goal) : Money::parse('0');
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
