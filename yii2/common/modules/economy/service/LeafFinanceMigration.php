<?php
namespace common\modules\economy\service;

use common\modules\economy\value\Money;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Retire leaf accounts without losing balances, open reserves or immutable ledger history. */
class LeafFinanceMigration
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }

    public function run(): void
    {
        $nodes = (new Query())->select('s.node_id')->from(['s' => 'economy_subject'])
            ->innerJoin(['n' => 'world_node'], '[[n.id]]=[[s.node_id]]')->leftJoin(['b' => 'world_building'], '[[b.node_id]]=[[n.id]]')
            ->where(['or', ['n.node_type' => ['ROOM', 'BED']], ['b.building_kind' => 'warehouse']])->column($this->db);
        foreach ($nodes as $node) $this->db->transaction(function () use ($node) {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $operation = md5('world.leaf-finance.v1:' . $node);
            if ((new Query())->from('game_operation')->where(['id' => $operation])->exists($this->db)) return;
            $hierarchy = new EconomyHierarchy($this->db); $parent = $hierarchy->financialNode((int)$node);
            $old = (new Query())->select('a.*')->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], '[[s.id]]=[[a.subject_id]]')
                ->where(['s.node_id' => $node, 'a.currency' => 'Cr', 'a.role' => ['budget', 'treasury']])->all($this->db);
            if (!$old) return;
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => null, 'type' => 'world.finance.inherit', 'created_at' => time()])->execute();
            $accounts = $hierarchy->accounts($parent);
            if (!isset($accounts['budget'], $accounts['treasury'])) $accounts = $hierarchy->provision($parent, $operation);
            foreach ($old as $source) {
                $target = $locks->row('economy_account', ['id' => $accounts[$source['role']]['id']]);
                $amount = Money::parse((string)$source['amount']); $reserved = Money::parse((string)$source['reserved']);
                if ($amount->isNegative() || $reserved->isNegative() || $reserved->compare($amount) > 0) throw new \RuntimeException('Invalid legacy leaf account: ' . $source['id']);
                $after = Money::parse((string)$target['amount'])->add($amount);
                $reserveAfter = Money::parse((string)$target['reserved'])->add($reserved);
                $this->db->createCommand()->update('economy_account', ['amount' => '0.0000', 'reserved' => '0.0000', 'revision' => new Expression('[[revision]]+1')], ['id' => $source['id']])->execute();
                $this->db->createCommand()->update('economy_account', ['amount' => $after->decimal(), 'reserved' => $reserveAfter->decimal(), 'revision' => new Expression('[[revision]]+1')], ['id' => $target['id']])->execute();
                if (!$amount->isZero()) $this->db->createCommand()->insert('economy_transfer', [
                    'operation_id' => $operation, 'line_code' => $source['role'], 'kind' => 'leaf_finance_inheritance',
                    'source_account_id' => $source['id'], 'destination_account_id' => $target['id'], 'source_user_id' => null,
                    'amount' => $amount->decimal(), 'source_after' => '0.0000', 'destination_after' => $after->decimal(),
                    'purpose' => 'Перенос финансов дочернего объекта #' . $node . ' в объект #' . $parent, 'created_at' => time(),
                ])->execute();
                $this->rebind((int)$source['id'], (int)$target['id']);
            }
            $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => [(int)$node, $parent]])->execute();
        });
    }

    private function rebind(int $source, int $target): void
    {
        // Mutable settlement state follows the funds. Completed records and transfers retain their original accounts.
        $references = [
            ['economy_funding_lot', 'budget_account_id', ['>', 'remaining_amount', 0]],
            ['economy_spending_commitment', 'account_id', ['closed_at' => null]],
            ['economy_treasury_receipt', 'account_id', ['closed_at' => null]],
            ['economy_obligation', 'budget_account_id', ['status' => 'pending']],
            ['economy_obligation', 'recipient_account_id', ['status' => 'pending']],
            ['economy_budget_reservation', 'account_id', ['released_at' => null]],
            ['economy_contract', 'payer_account_id', ['not in', 'status', ['completed', 'cancelled', 'expired']]],
            ['economy_contract', 'payee_account_id', ['not in', 'status', ['completed', 'cancelled', 'expired']]],
            ['economy_debt', 'debtor_account_id', ['not in', 'status', ['paid', 'cancelled']]],
            ['economy_debt', 'creditor_account_id', ['not in', 'status', ['paid', 'cancelled']]],
            ['npc_wage_accrual', 'budget_account_id', ['not in', 'status', ['paid', 'cancelled']]],
            ['npc_wage_accrual', 'recipient_account_id', ['not in', 'status', ['paid', 'cancelled']]],
            ['quest_template_revision', 'reward_budget_account_id', ['status' => 'published']],
        ];
        foreach ($references as list($table, $column, $where)) {
            $schema = $this->db->schema->getTableSchema($table);
            if ($schema && isset($schema->columns[$column])) $this->db->createCommand()->update($table, [$column => $target], ['and', [$column => $source], $where])->execute();
        }
    }
}
