<?php
namespace common\modules\economy\service;

use common\modules\economy\value\Money;
use common\modules\economy\value\ReportAmount;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldQuery;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** One owned object's lifetime flows. Never sums descendants or calls transfers new world revenue. */
class NodeFinanceReport
{
    private const PAYMENTS = ['order_payment', 'crop_purchase', 'premises_purchase', 'garden_purchase', 'garden_expansion', 'equipment_expansion', 'building_repair'];
    private const KINDS = ['personal_investment', 'budget_grant', 'treasury_collection', 'parent_payment', 'treasury_loss', 'order_payment', 'crop_purchase', 'premises_purchase', 'garden_purchase', 'garden_expansion', 'equipment_expansion', 'building_repair'];
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }

    private function totals(Query $base, int $budget, int $treasury): array
    {
        // IDs are integers obtained from owned accounts; no client SQL fragments enter expressions.
        $s = '[[source_account_id]]'; $d = '[[destination_account_id]]'; $k = '[[kind]]';
        $externalSource = "($s IS NULL OR $s NOT IN ($budget,$treasury))";
        $externalDestination = "$d NOT IN ($budget,$treasury)";
        $payments = $k . " IN ('" . implode("','", self::PAYMENTS) . "')";
        $investment = "($k='personal_investment' AND $s IS NULL AND [[source_user_id]] IS NOT NULL)";
        $conditions = [
            'treasury_received' => "$d=$treasury AND $externalSource",
            'treasury_parent_received' => "$d=$treasury AND $externalSource AND $k='parent_payment'",
            'treasury_payments_received' => "$d=$treasury AND $externalSource AND $payments",
            'treasury_other_received' => "$d=$treasury AND $externalSource AND $k<>'parent_payment' AND NOT ($payments)",
            'treasury_to_budget' => "$s=$treasury AND $d=$budget",
            'budget_to_treasury' => "$s=$budget AND $d=$treasury",
            'treasury_lost' => "$s=$treasury AND $externalDestination AND $k='treasury_loss'",
            'treasury_other_out' => "$s=$treasury AND $externalDestination AND $k<>'treasury_loss'",
            'budget_invested' => "$d=$budget AND $externalSource AND $investment",
            'budget_other_in' => "$d=$budget AND $externalSource AND NOT $investment",
            'budget_parent_paid' => "$s=$budget AND $externalDestination AND $k='parent_payment'",
            'budget_spent' => "$s=$budget AND $externalDestination AND $payments",
            'budget_other_out' => "$s=$budget AND $externalDestination AND $k<>'parent_payment' AND NOT ($payments)",
        ];
        $select = [];
        foreach ($conditions as $name => $condition) $select[$name] = new Expression("SUM(CASE WHEN $condition THEN [[amount]] ELSE 0 END)");
        foreach (['budget' => $budget, 'treasury' => $treasury] as $name => $id) $select[$name . '_ledger_balance'] = new Expression("SUM((CASE WHEN $d=$id THEN [[amount]] ELSE 0 END) - (CASE WHEN $s=$id THEN [[amount]] ELSE 0 END))");
        $row = (clone $base)->select($select)->one($this->db); $result = [];
        foreach ($select as $name => $unused) $result[$name] = ReportAmount::decimal($row[$name]);
        return $result;
    }
    public function view(int $user, int $node, int $page, string $search, string $kind, string $direction): array
    {
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120 || !in_array($kind, array_merge(['all', 'other'], self::KINDS), true) || !in_array($direction, ['all', 'incoming', 'outgoing', 'internal'], true)) throw new GameError('INVALID_FINANCE_FILTER', 'Некорректные параметры финансового отчёта.', 422);
        return (new FinanceReadSnapshot($this->db))->run(function () use ($user, $node, $page, $search, $kind, $direction) {
            $this->flags->requireFlag('world_read');
            $place = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($node);
            if (!$place['permissions']['storage']) throw new GameError('FINANCE_OWNER_REQUIRED', 'Финансовый отчёт доступен владельцу объекта.', 403);
            $accounts = (new EconomyHierarchy($this->db))->accounts($node);
            $budget = (int)($accounts['budget']['id'] ?? 0); $treasury = (int)($accounts['treasury']['id'] ?? 0); $ids = array_values(array_filter([$budget, $treasury]));
            $base = (new Query())->from('economy_transfer')->where(['or', ['source_account_id' => $ids], ['destination_account_id' => $ids]]);
            $totals = $this->totals($base, $budget, $treasury); $balances = []; $reconciliation = [];
            foreach (['budget', 'treasury'] as $role) {
                $amount = Money::parse((string)($accounts[$role]['amount'] ?? '0')); $reserved = Money::parse((string)($accounts[$role]['reserved'] ?? '0'));
                if ($amount->isNegative() || $reserved->isNegative() || $reserved->compare($amount) > 0) throw new GameError('FINANCE_RECONCILIATION_REQUIRED', 'Остатки счетов требуют сверки.', 503);
                $balances[$role] = ['amount' => $amount->decimal(), 'reserved' => $reserved->decimal(), 'available' => $amount->subtract($reserved)->decimal()];
                $ledger = $totals[$role . '_ledger_balance']; unset($totals[$role . '_ledger_balance']);
                $reconciliation[$role] = ['ledger_balance' => $ledger, 'matches' => $ledger === $amount->decimal()];
            }
            $query = clone $base;
            if ($search !== '') $query->andWhere(['like', 'purpose', $search]);
            if ($kind === 'other') $query->andWhere(['not in', 'kind', self::KINDS]);
            elseif ($kind !== 'all') $query->andWhere(['kind' => $kind]);
            if ($direction === 'internal') $query->andWhere(['source_account_id' => $ids, 'destination_account_id' => $ids]);
            elseif ($direction === 'incoming') $query->andWhere(['destination_account_id' => $ids])->andWhere(['or', ['source_account_id' => null], ['not in', 'source_account_id', $ids]]);
            elseif ($direction === 'outgoing') $query->andWhere(['source_account_id' => $ids])->andWhere(['not in', 'destination_account_id', $ids]);
            $total = (int)(clone $query)->count('*', $this->db); $items = [];
            $roles = array_filter(['budget' => $budget, 'treasury' => $treasury]);
            foreach ($query->orderBy(['id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) {
                $source = array_search((int)$row['source_account_id'], $roles, true); $destination = array_search((int)$row['destination_account_id'], $roles, true);
                $items[] = ['id' => (int)$row['id'], 'kind' => $row['kind'], 'purpose' => $row['purpose'], 'amount' => Money::parse((string)$row['amount'])->decimal(), 'created_at' => (int)$row['created_at'],
                    'direction' => $source !== false && $destination !== false ? 'internal' : ($source !== false ? 'outgoing' : 'incoming'),
                    'source_role' => $source === false ? null : $source, 'destination_role' => $destination === false ? null : $destination];
            }
            return ['node_id' => $node, 'scope' => 'node_lifetime', 'currency' => 'Cr', 'server_time' => time(), 'balances' => $balances, 'totals' => $totals, 'reconciliation' => $reconciliation,
                'items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20]];
        });
    }
}
