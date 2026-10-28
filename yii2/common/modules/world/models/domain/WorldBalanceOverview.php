<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\economy\models\domain\FinanceReadSnapshot;
use common\modules\world\modules\economy\value\Money;
use yii\db\Connection;
use yii\db\Query;

/** Administrative snapshot: reserves are included in balances, never added twice. */
final class WorldBalanceOverview
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private static function sum(array $rows, string $field): Money
    {
        $result = Money::parse('0');
        foreach ($rows as $row) $result = $result->add(Money::parse((string)$row[$field]));
        return $result;
    }
    public function report(int $participants = 2): array
    {
        if ($participants < 1 || $participants > 1000) throw new \InvalidArgumentException('Choose 1–1000 participants.');
        return (new FinanceReadSnapshot($this->db))->run(function () use ($participants) {
            $db = $this->db; $world = EconomyRuntime::world($db);
            $revision = EconomyRuntime::current($db); $profile = $revision ? $revision['profile'] : EconomyProfile::load();
            $users = (new Query())->select(['u.id', 'u.username', 'p.credit'])->from(['u' => 'user'])->innerJoin(['p' => 'persone'], 'p.user_id=u.id')->orderBy('u.id')->all($db);
            $accounts = (new Query())->select(['a.*', 'node_id' => 'n.id', 'node_name' => 'n.name', 'n.hierarchy_level', 'n.owner_user_id'])->from(['a' => 'economy_account'])
                ->innerJoin(['s' => 'economy_subject'], 's.id=a.subject_id')->innerJoin(['n' => 'world_node'], 'n.id=s.node_id')->where(['n.root_id' => $world])->orderBy(['n.id' => SORT_ASC, 'a.role' => SORT_ASC])->all($db);
            $roles = []; $freeSettlement = Money::parse('0');
            foreach ($accounts as &$account) {
                $amount = Money::parse((string)$account['amount']); $reserved = Money::parse((string)$account['reserved']);
                $account['available_cr'] = $amount->subtract($reserved)->decimal();
                $role = $account['role'];
                if (!isset($roles[$role])) $roles[$role] = ['amount' => Money::parse('0'), 'reserved' => Money::parse('0')];
                $roles[$role]['amount'] = $roles[$role]['amount']->add($amount); $roles[$role]['reserved'] = $roles[$role]['reserved']->add($reserved);
                if ($role === 'budget' && (int)$account['hierarchy_level'] === 3) $freeSettlement = $freeSettlement->add($amount->subtract($reserved));
            }
            unset($account);
            foreach ($roles as &$role) { $role['available_cr'] = $role['amount']->subtract($role['reserved'])->decimal(); $role['amount_cr'] = $role['amount']->decimal(); $role['reserved_cr'] = $role['reserved']->decimal(); unset($role['amount'], $role['reserved']); } unset($role);
            $orderDetails = []; $ordersPerUser = Money::parse('0');
            foreach (EconomyScenario::load()['orders'] as $code => $order) {
                $cost = Money::parse($order['unit_price_cr'])->multiply((int)$order['quantity']); $ordersPerUser = $ordersPerUser->add($cost);
                $orderDetails[] = ['code' => $code, 'quantity_per_user' => $order['quantity'], 'quantity' => $order['quantity'] * $participants, 'unit_price_cr' => $order['unit_price_cr'], 'total_cr' => $cost->multiply($participants)->decimal()];
            }
            $goalsPerUser = Money::parse('0'); foreach ($profile['goals'] as $goal) $goalsPerUser = $goalsPerUser->add(Money::parse($goal['credits_cr']));
            $starter = Money::parse($profile['starter']['credits_cr'])->multiply($participants);
            $orders = $ordersPerUser->multiply($participants); $goals = $goalsPerUser->multiply($participants); $requiredBudget = $orders->add($goals);
            $parentFunding = ($profile['starter']['funding'] ?? '') === 'parent_budget';
            if ($parentFunding) $requiredBudget = $requiredBudget->add($starter);
            $starterRows = $db->schema->getTableSchema('world_starter_funding') ? (new Query())->from('world_starter_funding')->where(['world_id' => $world])->orderBy('id')->all($db) : [];
            $paidStarter = self::sum(array_filter($starterRows, static function ($r) { return $r['status'] === 'paid'; }), 'amount');
            $pendingStarter = self::sum(array_filter($starterRows, static function ($r) { return $r['status'] === 'pending'; }), 'amount');
            $orderHolds = (new Query())->select('c.remaining_amount')->from(['o' => 'economy_purchase_order'])->innerJoin(['c' => 'economy_spending_commitment'], 'c.id=o.commitment_id')
                ->innerJoin(['n' => 'world_node'], 'n.id=o.node_id')->where(['n.root_id' => $world, 'o.status' => 'open', 'c.closed_at' => null])->andWhere(['>', 'o.expires_at', time()])->all($db);
            $goalHolds = (new Query())->select('c.remaining_amount')->from(['g' => 'world_goal_pool'])->innerJoin(['c' => 'economy_spending_commitment'], 'c.id=g.commitment_id')
                ->where(['g.world_id' => $world, 'c.closed_at' => null])->andWhere(['>', 'g.remaining_claims', 0])->all($db);
            $fundedOrders = self::sum($orderHolds, 'remaining_amount'); $fundedGoals = self::sum($goalHolds, 'remaining_amount');
            $orderPayments = (new Query())->select('t.amount')->from(['f' => 'economy_order_fulfillment'])->innerJoin(['o' => 'economy_purchase_order'], 'o.id=f.order_id')
                ->innerJoin(['n' => 'world_node'], 'n.id=o.node_id')->innerJoin(['t' => 'economy_transfer'], 't.id=f.transfer_id')->where(['n.root_id' => $world])->all($db);
            $goalPayments = (new Query())->select('earned_cr')->from('world_goal_claim')->where(['world_id' => $world])->all($db);
            $paidOrders = self::sum($orderPayments, 'amount'); $paidGoals = self::sum($goalPayments, 'earned_cr');
            $remainingBudget = $requiredBudget->subtract($paidOrders)->subtract($paidGoals);
            if ($parentFunding) $remainingBudget = $remainingBudget->subtract($paidStarter);
            if ($remainingBudget->isNegative()) $remainingBudget = Money::parse('0');
            $settlements = []; $fundedStarter = Money::parse('0');
            if ((new StarterReserve($db))->installed()) foreach ((new Query())->from('world_node')->where(['root_id' => $world, 'hierarchy_level' => 3, 'status' => 'active'])->orderBy('id')->all($db) as $city) {
                $state = (new StarterReserve($db))->state((int)$city['id']);
                $fundedStarter = $fundedStarter->add(Money::parse($state['reserve_cr']));
                $budget = null; foreach ($accounts as $account) if ((int)$account['node_id'] === (int)$city['id'] && $account['role'] === 'budget') { $budget = $account; break; }
                $settlements[] = $state + ['name' => $city['name'], 'available_budget_cr' => $budget['available_cr'] ?? '0.0000'];
            }
            $shortfall = $remainingBudget->subtract($fundedOrders)->subtract($fundedGoals)->subtract($fundedStarter)->subtract($freeSettlement);
            $open = ['or', ['user_buyer' => null], ['user_buyer' => 0]];
            $transit = $db->schema->getTableSchema('credit_transfer') ? (new Query())->select('amount')->from('credit_transfer')->where($open)->all($db) : [];
            $exchange = $db->schema->getTableSchema('credit_exchange') ? (new Query())->select('credit')->from('credit_exchange')->where($open)->andWhere(['in', 'type', ['buy', 'buy ']])->all($db) : [];
            $wallets = self::sum($users, 'credit'); $inTransit = self::sum($transit, 'amount'); $exchangeReserve = self::sum($exchange, 'credit'); $accountTotal = self::sum($accounts, 'amount');
            return ['world_id' => $world, 'participants' => $participants, 'profile_version' => $revision['version'] ?? null, 'users' => $users, 'accounts' => $accounts, 'roles' => $roles,
                'starter_settlements' => $settlements, 'funded_starter_cr' => $fundedStarter->decimal(),
                'wallets_cr' => $wallets->decimal(), 'accounts_cr' => $accountTotal->decimal(), 'transfer_reserve_cr' => $inTransit->decimal(), 'exchange_reserve_cr' => $exchangeReserve->decimal(),
                'total_cr' => $wallets->add($accountTotal)->add($inTransit)->add($exchangeReserve)->decimal(),
                'starter_per_user_cr' => $profile['starter']['credits_cr'], 'starter_total_cr' => $starter->decimal(), 'orders_per_user_cr' => $ordersPerUser->decimal(), 'orders_total_cr' => $orders->decimal(),
                'goals_per_user_cr' => $goalsPerUser->decimal(), 'goals_total_cr' => $goals->decimal(), 'required_budget_cr' => $requiredBudget->decimal(), 'required_total_cr' => ($parentFunding ? $requiredBudget : $starter->add($requiredBudget))->decimal(),
                'starter_funding_mode' => $parentFunding ? 'parent_budget' : 'personal_transfer', 'paid_starter_cr' => $paidStarter->decimal(), 'pending_starter_cr' => $pendingStarter->decimal(), 'starter_obligations' => $starterRows,
                'free_settlement_cr' => $freeSettlement->decimal(), 'funded_orders_cr' => $fundedOrders->decimal(), 'funded_goals_cr' => $fundedGoals->decimal(),
                'paid_orders_cr' => $paidOrders->decimal(), 'paid_goals_cr' => $paidGoals->decimal(), 'remaining_budget_cr' => $remainingBudget->decimal(),
                'budget_shortfall_cr' => $shortfall->isNegative() ? '0.0000' : $shortfall->decimal(), 'order_details' => $orderDetails, 'generated_at' => time()];
        });
    }
}
