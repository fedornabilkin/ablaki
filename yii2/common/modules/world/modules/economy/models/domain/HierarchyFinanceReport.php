<?php
namespace common\modules\world\modules\economy\models\domain;

use common\modules\world\modules\economy\value\ReportAmount;
use common\modules\world\models\domain\WorldFlags;
use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Current owned financial branch, lifetime flows across its boundary, no consolidation of strangers. */
class HierarchyFinanceReport
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }

    private function flows(array $ids): array
    {
        $inside = implode(',', $ids ?: [0]); // Validated integer IDs from this snapshot, never client SQL.
        $source = "[[source_account_id]] IN ($inside)"; $destination = "[[destination_account_id]] IN ($inside)";
        $incoming = "([[source_account_id]] IS NULL OR NOT ($source)) AND $destination";
        $outgoing = "$source AND NOT ($destination)";
        $internal = "$source AND $destination";
        $conditions = ['external_in' => $incoming, 'external_out' => $outgoing, 'internal_transfers' => $internal,
            'internal_collections' => "$internal AND [[kind]]='treasury_collection'",
            'internal_parent_payments' => "$internal AND [[kind]]='parent_payment'",
            'internal_other' => "$internal AND [[kind]] NOT IN ('treasury_collection','parent_payment')",
            'external_losses' => "$outgoing AND [[kind]]='treasury_loss'",
            'external_parent_payments' => "$outgoing AND [[kind]]='parent_payment'",
            'external_personal_investments' => "$incoming AND [[kind]]='personal_investment' AND [[source_account_id]] IS NULL AND [[source_user_id]] IS NOT NULL"];
        $select = [];
        foreach ($conditions as $key => $condition) $select[$key] = new Expression("SUM(CASE WHEN $condition THEN [[amount]] ELSE 0 END)");
        $select['ledger_balance'] = new Expression("SUM((CASE WHEN $incoming THEN [[amount]] ELSE 0 END) - (CASE WHEN $outgoing THEN [[amount]] ELSE 0 END))");
        $row = (new Query())->select($select)->from('economy_transfer')->where(['or', ['source_account_id' => $ids], ['destination_account_id' => $ids]])->one($this->db);
        $result = [];
        foreach ($select as $key => $unused) $result[$key] = ReportAmount::decimal($row[$key]);
        return $result;
    }

    /** Set-based reconciliation of every account; opposite discrepancies cannot cancel each other. */
    private function balances(array $ids): array
    {
        $incoming = (new Query())->select(['account_id' => 'destination_account_id', 'amount' => new Expression('SUM([[amount]])')])->from('economy_transfer')->where(['destination_account_id' => $ids])->groupBy('destination_account_id');
        $outgoing = (new Query())->select(['account_id' => 'source_account_id', 'amount' => new Expression('SUM([[amount]])')])->from('economy_transfer')->where(['source_account_id' => $ids])->groupBy('source_account_id');
        $net = '(COALESCE([[i.amount]],0)-COALESCE([[o.amount]],0))';
        $rows = (new Query())->select(['a.role', 'account_count' => new Expression('COUNT(*)'),
            'amount' => new Expression('SUM([[a.amount]])'), 'reserved' => new Expression('SUM([[a.reserved]])'), 'available' => new Expression('SUM([[a.amount]]-[[a.reserved]])'),
            'ledger_balance' => new Expression("SUM($net)"), 'mismatched_accounts' => new Expression("SUM(CASE WHEN [[a.amount]]<>$net THEN 1 ELSE 0 END)"),
            'invalid_accounts' => new Expression('SUM(CASE WHEN [[a.amount]]<0 OR [[a.reserved]]<0 OR [[a.reserved]]>[[a.amount]] THEN 1 ELSE 0 END)')])
            ->from(['a' => 'economy_account'])->leftJoin(['i' => $incoming], '[[i.account_id]]=[[a.id]]')->leftJoin(['o' => $outgoing], '[[o.account_id]]=[[a.id]]')
            ->where(['a.id' => $ids])->groupBy('a.role')->indexBy('role')->all($this->db);
        $result = [];
        foreach (['budget', 'treasury'] as $role) {
            $row = $rows[$role] ?? [];
            if (!empty($row['invalid_accounts'])) throw new GameError('FINANCE_RECONCILIATION_REQUIRED', 'Остатки счетов требуют сверки.', 503);
            $result[$role] = ['account_count' => (int)($row['account_count'] ?? 0), 'mismatched_accounts' => (int)($row['mismatched_accounts'] ?? 0)];
            foreach (['amount', 'reserved', 'available', 'ledger_balance'] as $key) $result[$role][$key] = ReportAmount::decimal($row[$key] ?? null);
        }
        return $result;
    }

    public function view(int $user, int $root, int $page, string $search): array
    {
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_FINANCE_FILTER', 'Некорректные параметры списка объектов отчёта.', 422);
        return (new FinanceReadSnapshot($this->db))->run(function () use ($user, $root, $page, $search) {
            $this->flags->requireFlag('world_read');
            $nodes = (new OwnedFinanceHierarchy($this->db))->resolve($user, $root); $nodeIds = array_keys($nodes);
            $accounts = (new Query())->select('a.id')->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], '[[s.id]]=[[a.subject_id]]')
                ->where(['s.node_id' => $nodeIds, 'a.role' => ['budget', 'treasury'], 'a.currency' => 'Cr'])->orderBy(['a.id' => SORT_ASC])->column($this->db);
            $ids = array_map('intval', $accounts); $balances = $this->balances($ids); $totals = $this->flows($ids);
            // Search filters the membership list only; it never changes the consolidation boundary.
            $members = (new Query())->select('id')->from('world_node')->where(['id' => $nodeIds]);
            if ($search !== '') $members->andWhere(['like', 'name', $search]);
            $total = (int)(clone $members)->count('*', $this->db); $items = [];
            foreach ($members->orderBy(['id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->column($this->db) as $id) $items[] = $nodes[(int)$id];
            return ['node_id' => $root, 'scope' => 'current_owned_financial_branch', 'period' => 'lifetime', 'currency' => 'Cr', 'server_time' => time(),
                'node_count' => count($nodes), 'account_count' => count($ids), 'scope_limit' => OwnedFinanceHierarchy::MAX_NODES,
                'balances' => $balances, 'totals' => $totals, 'reconciled' => $balances['budget']['mismatched_accounts'] === 0 && $balances['treasury']['mismatched_accounts'] === 0,
                'items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20]];
        });
    }
}
