<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\Node;
use common\modules\world\modules\economy\models\domain\{BudgetSpending, EconomyHierarchy};
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Earmarked part of a settlement budget. Other spending cannot consume it. */
final class StarterReserve
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function installed(): bool { return $this->db->schema->getTableSchema('world_starter_reserve') !== null; }
    public function terms(int $node): array
    {
        $policy = $this->installed() ? (new Query())->from('world_starter_reserve')->where(['node_id' => $node])->one($this->db) : null;
        $hold = $policy && $policy['commitment_id'] ? (new Query())->from('economy_spending_commitment')->where(['id' => $policy['commitment_id'], 'closed_at' => null])->one($this->db) : null;
        return ['payout_cr' => (string)($policy['payout_cr'] ?? EconomyRuntime::current($this->db)['profile']['starter']['credits_cr'] ?? '250.0000'),
            'reserve_cr' => $hold ? (string)$hold['remaining_amount'] : '0.0000', 'commitment_id' => $hold ? (int)$hold['id'] : null];
    }
    public function state(int $node): array
    {
        $terms = $this->terms($node); $pending = Money::parse('0'); $waiting = 0;
        foreach ((new Query())->from('world_starter_funding')->where(['source_node_id' => $node, 'status' => 'pending'])->all($this->db) as $row) { $pending = $pending->add(Money::parse((string)$row['amount'])); $waiting++; }
        $free = Money::parse($terms['reserve_cr'])->subtract($pending); if ($free->isNegative()) $free = Money::parse('0');
        $payout = Money::parse($terms['payout_cr']);
        $places = $payout->isZero() ? 0 : min(2147483647, intdiv((int)str_replace('.', '', $free->decimal()), (int)str_replace('.', '', $payout->decimal())));
        return ['node_id' => $node, 'amount_cr' => $payout->decimal(), 'reserve_cr' => Money::parse($terms['reserve_cr'])->decimal(), 'pending_cr' => $pending->decimal(), 'waiting' => $waiting, 'available_places' => $places, 'available' => $places > 0,
            'message' => $places > 0 ? 'Стартовые средства зарезервированы. Выплата поступит в бюджет вашей стоянки при заселении.' : 'Стартовых средств для нового поселенца сейчас нет. Выберите другое поселение или заселитесь в очередь без немедленной выплаты.'];
    }
    public function configure(int $user, int $node, string $payoutValue, string $additionValue, string $operation, bool $administrator = false): array
    {
        if (!$this->installed() || !$this->db->getTransaction()) throw new \LogicException('Starter reserve needs installed schema and a command transaction.');
        $place = Node::requireOne($node);
        if (!$administrator) $place->requireOwner($user);
        if ($place->status !== 'active' || (int)$place->hierarchy_level !== 3 || (int)$place->root_id !== EconomyRuntime::world($this->db) || !NodeAvailability::activePath($this->db, $node)) throw new GameError('SETTLEMENT_REQUIRED', 'Выберите действующее поселение текущего мира.', 422);
        try { $payout = Money::parse($payoutValue); $addition = Money::parse($additionValue); }
        catch (\InvalidArgumentException | \OverflowException $error) { throw new GameError('INVALID_STARTER_RESERVE', 'Укажите точные суммы, до четырёх знаков после точки.', 422); }
        if ($payout->isNegative() || $payout->isZero() || $addition->isNegative()) throw new GameError('INVALID_STARTER_RESERVE', 'Выплата должна быть положительной, пополнение резерва — неотрицательным.', 422);
        $hierarchy = new EconomyHierarchy($this->db); $account = $hierarchy->provision($node, $operation)['budget']; $spending = new BudgetSpending($this->db);
        if ($spending->available((int)$account['id'])->compare($addition) < 0) throw new GameError('INSUFFICIENT_BUDGET', 'В свободном бюджете недостаточно средств для стартового резерва.', 422);
        $terms = $this->terms($node); $hold = $terms['commitment_id'];
        if (!$addition->isZero()) {
            $amount = Money::parse($terms['reserve_cr'])->add($addition);
            if ($hold) $spending->release($hold);
            $hold = $spending->reserve((int)$account['id'], $amount, 'Стартовые средства новых поселенцев', $operation);
        }
        $row = (new Query())->from('world_starter_reserve')->where(['node_id' => $node])->one($this->db);
        $values = ['payout_cr' => $payout->decimal(), 'commitment_id' => $hold, 'operation_id' => $operation, 'updated_at' => time(), 'revision' => $row ? (int)$row['revision'] + 1 : 1];
        if ($row) { if ($this->db->createCommand()->update('world_starter_reserve', $values, ['id' => $row['id']])->execute() !== 1) throw new \RuntimeException('Starter reserve settings write failed.'); }
        else $this->db->createCommand()->insert('world_starter_reserve', ['node_id' => $node] + $values)->execute();
        $paid = (new StarterFunding($this->db))->settle($node, $operation);
        $accounts = $hierarchy->accounts($node);
        return ['changed_node_ids' => array_merge([$node], $paid), 'starter_reserve' => $this->state($node), 'available_budget_cr' => $spending->available((int)$accounts['budget']['id'])->decimal(),
            'message' => 'Стартовый резерв и размер выплаты сохранены.'];
    }
    public function availability(int $node, int $user): array
    {
        $place = Node::readable($node, $user); $cities = [];
        if ((int)$place->hierarchy_level === 3) $ids = [$node];
        else $ids = (new Query())->select('n.id')->from(['n' => 'world_node'])->innerJoin(['c' => 'world_node_closure'], 'c.descendant_id=n.id')->where(['c.ancestor_id' => $node, 'n.hierarchy_level' => 3, 'n.status' => 'active'])->orderBy('n.id')->column($this->db);
        foreach ($ids as $id) try { $city = Node::readable((int)$id, $user); if (NodeAvailability::activePath($this->db, (int)$id)) $cities[] = $this->state((int)$id) + ['name' => $city->name]; } catch (GameError $error) { if (!in_array($error->status, [403, 404], true)) throw $error; }
        if ((int)$place->hierarchy_level === 3) return $cities ? $cities[0] + ['settlements' => $cities] : [];
        $count = min(2147483647, array_sum(array_column($cities, 'available_places')));
        return ['node_id' => $node, 'amount_cr' => null, 'available' => $count > 0, 'available_places' => $count, 'settlements' => $cities,
            'message' => $count > 0 ? 'В поселениях региона есть места с обеспеченными стартовыми средствами.' : 'В поселениях региона сейчас нет обеспеченных мест. Стартовый резерв должен пополнить владелец бюджета.'];
    }
}
