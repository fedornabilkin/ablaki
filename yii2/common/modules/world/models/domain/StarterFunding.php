<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\models\domain\{BudgetDisbursement, BudgetSpending, EconomyHierarchy};
use common\modules\world\modules\economy\value\Money;
use yii\db\Connection;
use yii\db\Query;

/** One lifetime starter obligation per member/world, paid in source FIFO order. */
final class StarterFunding
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function enabled(): bool { return $this->db->schema->getTableSchema('world_starter_funding') !== null && (EconomyRuntime::current($this->db)['profile']['starter']['funding'] ?? null) === 'parent_budget'; }
    public function preview(int $parent): ?array
    {
        if (!$this->enabled()) return null;
        $reserve = new StarterReserve($this->db);
        if ($reserve->installed()) return $reserve->state($parent);
        $amount = Money::parse(EconomyRuntime::current($this->db)['profile']['starter']['credits_cr']);
        $accounts = (new EconomyHierarchy($this->db))->accounts($parent);
        $waiting = (new Query())->from('world_starter_funding')->where(['source_node_id' => $parent, 'status' => 'pending'])->orderBy('id')->all($this->db);
        $needed = $amount; foreach ($waiting as $row) $needed = $needed->add(Money::parse((string)$row['amount']));
        $ready = isset($accounts['budget']) && (new BudgetSpending($this->db))->available((int)$accounts['budget']['id'])->compare($needed) >= 0;
        return ['amount_cr' => $amount->decimal(), 'available' => $ready, 'waiting' => count($waiting),
            'message' => $ready ? 'Стартовые средства поступят из бюджета родительского поселения в бюджет стоянки.' : 'В бюджете этого поселения пока недостаточно средств для старта стоянки. Можно выбрать другое поселение или вступить и ждать финансирования в очереди.'];
    }
    public function claim(int $user, int $parent, int $site, string $operation): ?array
    {
        if (!$this->enabled()) return null;
        if (!$this->db->getTransaction()) throw new \LogicException('Starter funding requires the start transaction.');
        $where = ['world_id' => EconomyRuntime::world($this->db), 'user_id' => $user];
        if (!(new Query())->from('world_starter_funding')->where($where)->exists($this->db)) {
            $this->db->createCommand()->insert('world_starter_funding', $where + ['source_node_id' => $parent, 'destination_node_id' => $site,
                'amount' => (new StarterReserve($this->db))->terms($parent)['payout_cr'], 'status' => 'pending', 'operation_id' => $operation, 'created_at' => time()])->execute();
        }
        $this->settle($parent, $operation);
        return $this->forUser($user);
    }
    public function forUser(int $user): ?array
    {
        if (!$this->enabled()) return null;
        $row = (new Query())->from('world_starter_funding')->where(['world_id' => EconomyRuntime::world($this->db), 'user_id' => $user])->one($this->db);
        return $row ? ['status' => $row['status'], 'amount_cr' => Money::parse((string)$row['amount'])->decimal(), 'source_node_id' => (int)$row['source_node_id'], 'destination_node_id' => (int)$row['destination_node_id'], 'paid_at' => $row['paid_at'] === null ? null : (int)$row['paid_at']] : null;
    }
    public function settle(int $parent, string $operation): array
    {
        if (!$this->enabled()) return [];
        if (!$this->db->getTransaction()) throw new \LogicException('Starter queue requires the funding transaction.');
        $db = $this->db; $hierarchy = new EconomyHierarchy($db); $source = $hierarchy->accounts($parent); $paid = [];
        if (!isset($source['budget'])) return [];
        $reserve = new StarterReserve($db); $earmarked = $reserve->installed();
        foreach ((new Query())->from('world_starter_funding')->where(['source_node_id' => $parent, 'status' => 'pending'])->orderBy('id')->limit(1000)->all($db) as $row) {
            $target = (new Query())->from('world_node')->where(['id' => $row['destination_node_id']])->one($db);
            if (!$target || in_array($target['status'], ['archived', 'destroyed'], true)) { $db->createCommand()->update('world_starter_funding', ['status' => 'cancelled'], ['id' => $row['id'], 'status' => 'pending'])->execute(); continue; }
            $amount = Money::parse((string)$row['amount']);
            $terms = $reserve->terms($parent);
            if ($earmarked ? Money::parse($terms['reserve_cr'])->compare($amount) < 0 : (new BudgetSpending($db))->available((int)$source['budget']['id'])->compare($amount) < 0) break;
            $destination = $hierarchy->provision((int)$row['destination_node_id'], $operation);
            $transfer = (new BudgetDisbursement($db))->toBudget((int)$source['budget']['id'], (int)$destination['budget']['id'], $amount, $operation, 'starter-' . $row['id'], 'Старт стоянки #' . $row['destination_node_id'], $earmarked ? $terms['commitment_id'] : null);
            if ($db->createCommand()->update('world_starter_funding', ['status' => 'paid', 'transfer_id' => $transfer, 'paid_at' => time()], ['id' => $row['id'], 'status' => 'pending'])->execute() !== 1) throw new \RuntimeException('Starter obligation changed.');
            $paid[] = (int)$row['destination_node_id'];
        }
        return $paid;
    }
    public function cancel(int $site, string $operation): void
    {
        if (!$this->enabled()) return;
        $db = $this->db; $row = (new Query())->from('world_starter_funding')->where(['destination_node_id' => $site])->one($db);
        if (!$row || !in_array($row['status'], ['pending', 'paid'], true)) return;
        if ($row['status'] === 'paid') {
            $hierarchy = new EconomyHierarchy($db); $source = $hierarchy->accounts($site); $parent = $hierarchy->accounts((int)$row['source_node_id']);
            $available = (new BudgetSpending($db))->available((int)$source['budget']['id']); $original = Money::parse((string)$row['amount']);
            $return = $available->compare($original) > 0 ? $original : $available;
            if (!$return->isZero()) (new BudgetDisbursement($db))->toBudget((int)$source['budget']['id'], (int)$parent['budget']['id'], $return, $operation, 'starter-return-' . $row['id'], 'Возврат старта отменённой стоянки');
            $reserve = new StarterReserve($db);
            if (!$return->isZero() && $reserve->installed()) $reserve->configure((int)$row['user_id'], (int)$row['source_node_id'], $reserve->terms((int)$row['source_node_id'])['payout_cr'], $return->decimal(), $operation, true);
        }
        $db->createCommand()->update('world_starter_funding', ['status' => 'cancelled'], ['id' => $row['id']])->execute();
        $this->settle((int)$row['source_node_id'], $operation);
    }
}
