<?php
namespace common\modules\economy\service;

use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Only the first funded order may initialise unconfigured campsite rules, with explicit quote consent. */
class StarterIncomePolicy
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function preview(int $site, int $settlement, int $history): array
    {
        $current = (new EconomyHierarchy($this->db))->current($site);
        if ($current && $current['status'] === 'published' && $current['loss_policy']) return ['initialize' => false, 'policy' => $current];
        if ($current && ($current['status'] !== 'unconfigured' || $current['parent_node_id'] !== $settlement)) throw new GameError('STARTER_POLICY_UNAVAILABLE', 'Для стоянки нужно отдельно настроить финансовые правила.');
        $row = (new Query())->select(['h.*', 'p.protected_seconds', 'p.loss_period_seconds', 'p.loss_rate_bps'])->from(['h' => 'economy_parent_history'])
            ->innerJoin(['p' => 'economy_collection_policy'], '[[p.history_id]]=[[h.id]]')->innerJoin(['s' => 'economy_subject'], '[[s.id]]=[[h.subject_id]]')
            ->where(['h.id' => $history, 'h.status' => 'published', 's.node_id' => $settlement])->one($this->db);
        if (!$row) throw new GameError('STARTER_POLICY_UNAVAILABLE', 'Начальные условия заказа недоступны.');
        return ['initialize' => true, 'policy' => ['history_id' => $history, 'revision' => ($current ? $current['revision'] : 1) + 1,
            'parent_node_id' => $settlement, 'rate_bps' => (int)$row['rate_bps'], 'due_seconds' => (int)$row['due_seconds'],
            'loss_policy' => ['protected_seconds' => (int)$row['protected_seconds'], 'loss_period_seconds' => (int)$row['loss_period_seconds'], 'loss_rate_bps' => (int)$row['loss_rate_bps']]]];
    }
    public function initialize(int $site, int $settlement, int $history, string $operation, int $order): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Starter policy needs a command transaction.');
        $preview = $this->preview($site, $settlement, $history);
        if (!$preview['initialize']) return;
        $accounts = (new EconomyHierarchy($this->db))->provision($site, $operation); $subject = (int)$accounts['budget']['subject_id'];
        $old = (new EconomyHierarchy($this->db))->current($site); $policy = $preview['policy']; $now = time();
        $parent = (new Query())->select('id')->from('economy_subject')->where(['node_id' => $settlement])->scalar($this->db);
        if (!$old || $old['status'] !== 'unconfigured' || $old['parent_node_id'] !== $settlement || !$parent) throw new GameError('STARTER_POLICY_CHANGED', 'Начальные условия изменились. Повторите расчёт.');
        if ($this->db->createCommand()->update('economy_parent_history', ['effective_to' => $now], ['id' => $old['history_id'], 'effective_to' => null])->execute() !== 1) throw new \RuntimeException('Policy history update failed.');
        $this->db->createCommand()->insert('economy_parent_history', ['subject_id' => $subject, 'parent_subject_id' => $parent, 'revision' => $policy['revision'], 'status' => 'published',
            'basis' => 'collected_revenue', 'rate_bps' => $policy['rate_bps'], 'due_seconds' => $policy['due_seconds'], 'effective_from' => $now, 'effective_to' => null,
            'operation_id' => $operation, 'reason' => 'Начальные условия заказа #' . $order . ', источник правил #' . $history])->execute();
        $newHistory = (int)$this->db->getLastInsertID();
        $this->db->createCommand()->insert('economy_collection_policy', ['history_id' => $newHistory] + $policy['loss_policy'])->execute();
        $this->db->createCommand()->insert('economy_tax_checkpoint', ['history_id' => $newHistory, 'fraction' => 0])->execute();
        if ($this->db->createCommand()->update('economy_parent_rule', ['history_id' => $newHistory, 'revision' => $policy['revision']], ['subject_id' => $subject, 'history_id' => $old['history_id']])->execute() !== 1) throw new \RuntimeException('Policy publication failed.');
        $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => $now], ['id' => $site])->execute();
    }
}
