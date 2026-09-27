<?php
namespace common\modules\economy\service;

use common\modules\economy\value\Money;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\WorldQuery;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Read-only receipt history. Stored policy/checkpoint is authoritative; GET never settles loss. */
class TreasuryReceipts
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }

    public function listing(int $user, int $node, int $page, string $search, string $status): array
    {
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120 || !in_array($status, ['all', 'open', 'closed', 'catching_up'], true)) throw new GameError('INVALID_RECEIPT_FILTER', 'Некорректные параметры списка поступлений.', 422);
        // Keep rows and their collection/loss totals in one snapshot while the worker or owner writes.
        // This HTTP read service does not create accounts, lock the registry, or run a command/job.
        return (new FinanceReadSnapshot($this->db))->run(function () use ($user, $node, $page, $search, $status) {
            $this->flags->requireFlag('world_read');
            $place = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($node);
            if (!$place['permissions']['storage']) throw new GameError('FINANCE_OWNER_REQUIRED', 'Поступления казны доступны владельцу объекта.', 403);
            $now = time(); $accounts = (new EconomyHierarchy($this->db))->accounts($node);
            $account = isset($accounts['treasury']) ? (int)$accounts['treasury']['id'] : null;
            $query = (new Query())->select(['r.*', 't.kind', 't.purpose', 'policy_revision' => 'h.revision', 'p.protected_seconds', 'p.loss_period_seconds', 'p.loss_rate_bps'])
                ->from(['r' => 'economy_treasury_receipt'])
                ->innerJoin(['t' => 'economy_transfer'], '[[t.id]]=[[r.transfer_id]]')
                ->innerJoin(['p' => 'economy_collection_policy'], '[[p.history_id]]=[[r.history_id]]')
                ->innerJoin(['h' => 'economy_parent_history'], '[[h.id]]=[[r.history_id]]')
                ->where(['r.account_id' => $account]);
            if ($search !== '') $query->andWhere(['like', 't.purpose', $search]);
            if ($status === 'open' || $status === 'catching_up') $query->andWhere(['r.closed_at' => null]);
            if ($status === 'closed') $query->andWhere(['not', ['r.closed_at' => null]]);
            if ($status === 'catching_up') $query->andWhere(['>', 'p.loss_rate_bps', 0])->andWhere(['<=', 'r.next_loss_at', $now]);
            $total = (int)(clone $query)->count('*', $this->db);
            $rows = $query->orderBy(['r.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db);
            $ids = array_column($rows, 'id');
            $collected = $this->totals('economy_receipt_collection', $ids);
            $lost = $this->totals('economy_treasury_loss', $ids);
            $items = [];
            foreach ($rows as $row) $items[] = $this->present($row, $collected[$row['id']] ?? '0', $lost[$row['id']] ?? '0', $now);
            return ['node_id' => $node, 'currency' => 'Cr', 'server_time' => $now,
                'treasury_catching_up' => $account !== null && (new TreasuryLedger($this->db))->catchingUp($account, $now),
                'items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20]];
        });
    }

    private function totals(string $table, array $ids): array
    {
        if (!$ids) return [];
        $result = [];
        foreach ((new Query())->select(['receipt_id', 'amount' => new Expression('SUM([[amount]])')])->from($table)->where(['receipt_id' => $ids])->groupBy('receipt_id')->all($this->db) as $row) $result[$row['receipt_id']] = (string)$row['amount'];
        return $result;
    }

    private function present(array $row, string $collectedValue, string $lostValue, int $now): array
    {
        $original = Money::parse((string)$row['original_amount']); $remaining = Money::parse((string)$row['remaining_amount']);
        $collected = Money::parse($collectedValue); $lost = Money::parse($lostValue);
        if ($original->isNegative() || $remaining->isNegative() || $collected->isNegative() || $lost->isNegative()
            || $original->subtract($remaining)->subtract($collected)->compare($lost) !== 0
            || ($row['closed_at'] !== null && !$remaining->isZero())) throw new GameError('TREASURY_RECONCILIATION_REQUIRED', 'История поступлений казны требует сверки.', 503);
        $closed = $row['closed_at'] !== null; $rate = (int)$row['loss_rate_bps']; $period = (int)$row['loss_period_seconds'];
        $received = (int)$row['received_at']; $protection = (int)$row['protected_until']; $next = (int)$row['next_loss_at'];
        $scheduled = !$closed && $rate > 0;
        $due = $scheduled && $next <= $now ? intdiv($now - $next, $period) + 1 : 0;
        $state = $closed ? 'closed' : ($rate === 0 ? 'exempt' : ($due > 0 ? 'catching_up' : ($now < $protection ? 'protected' : 'waiting')));
        // Exactly one not-yet-applied period, including fractional carry, not an unbounded catch-up simulation.
        $forecast = $scheduled ? $remaining->portion($rate, (int)$row['loss_fraction'])['amount']->decimal() : null;
        return ['id' => (int)$row['id'], 'kind' => $row['kind'], 'purpose' => $row['purpose'], 'state' => $state,
            'original_amount' => $original->decimal(), 'remaining_amount' => $remaining->decimal(), 'collected_amount' => $collected->decimal(), 'lost_amount' => $lost->decimal(),
            'received_at' => $received, 'closed_at' => $closed ? (int)$row['closed_at'] : null,
            'age_seconds' => max(0, ($closed ? (int)$row['closed_at'] : $now) - $received),
            'policy_revision' => (int)$row['policy_revision'], 'protected_seconds' => (int)$row['protected_seconds'], 'protected_until' => $protection,
            'loss_rate_bps' => $rate, 'loss_period_seconds' => $period, 'processed_periods' => (int)$row['loss_sequence'],
            'next_loss_at' => $scheduled ? $next : null, 'pending_periods' => $due, 'next_loss_amount' => $forecast];
    }
}
