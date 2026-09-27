<?php
namespace common\modules\economy\service;

use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Accounts and the initial financial parent, independent of subsequent physical tree moves. */
class EconomyHierarchy
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }

    public function accounts(int $node): array
    {
        return (new Query())->select('a.*')->from(['a' => 'economy_account'])->innerJoin(['s' => 'economy_subject'], '[[s.id]]=[[a.subject_id]]')
            ->where(['s.node_id' => $node, 'a.currency' => 'Cr'])->indexBy('role')->all($this->db);
    }

    /** Caller owns a world command transaction and its permission/quote checks. No GET calls this. */
    public function provision(int $node, string $operation): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Economy provisioning requires a world command transaction.');
        WalletMaintenance::writable($this->db);
        if (!(new Locks($this->db))->row('world_registry', ['id' => 1])) throw new \RuntimeException('World registry unavailable.');
        if (!(new Query())->from('game_operation')->where(['id' => $operation])->exists($this->db)) throw new \LogicException('Economy provisioning requires an existing operation.');
        $chain = []; $cursor = $node; $root = null;
        while ($cursor !== null) {
            if (isset($chain[$cursor]) || count($chain) > 32) throw new \RuntimeException('Invalid financial ancestry.');
            $row = (new Query())->from('world_node')->where(['id' => $cursor])->one($this->db);
            if (!$row || $row['status'] !== 'active') throw new \RuntimeException('Financial ancestor unavailable.');
            if ($root !== null && $root !== (int)$row['root_id']) throw new \RuntimeException('Financial ancestry crosses worlds.');
            $root = (int)$row['root_id']; $chain[$cursor] = $row;
            $cursor = $row['parent_id'] === null ? null : (int)$row['parent_id'];
        }
        $top = end($chain);
        if (!$top || $top['node_type'] !== 'WORLD') throw new \RuntimeException('Financial ancestry needs a world root.');
        $parent = null;
        foreach (array_reverse($chain, true) as $place) {
            $subject = (new Query())->from('economy_subject')->where(['node_id' => $place['id']])->one($this->db); $changed = false;
            if (!$subject) {
                $this->db->createCommand()->insert('economy_subject', ['node_id' => $place['id'], 'actor_id' => null, 'asset_instance_id' => null, 'created_at' => time()])->execute();
                $subject = ['id' => (int)$this->db->getLastInsertID()]; $changed = true;
            } elseif ($subject['actor_id'] !== null || $subject['asset_instance_id'] !== null) throw new \RuntimeException('Invalid economy subject identity.');
            foreach (['budget', 'treasury'] as $role) if (!(new Query())->from('economy_account')->where(['subject_id' => $subject['id'], 'role' => $role, 'currency' => 'Cr'])->exists($this->db)) {
                $this->db->createCommand()->insert('economy_account', ['subject_id' => $subject['id'], 'role' => $role, 'currency' => 'Cr', 'amount' => '0.0000', 'reserved' => '0.0000'])->execute();
                $changed = true;
            }
            if (!(new Query())->from('economy_parent_rule')->where(['subject_id' => $subject['id']])->exists($this->db)) {
                // Rates are deliberately unconfigured, not implicitly zero tax. Collect stays closed
                // until a published rule and the obligation/reservation machinery are available.
                $this->db->createCommand()->insert('economy_parent_history', [
                    'subject_id' => $subject['id'], 'parent_subject_id' => $parent, 'revision' => 1,
                    'status' => $parent === null ? 'root' : 'unconfigured', 'basis' => 'collected_revenue',
                    'rate_bps' => $parent === null ? 0 : null, 'due_seconds' => null, 'effective_from' => time(), 'effective_to' => null,
                    'operation_id' => $operation, 'reason' => 'Initial parent at account provisioning',
                ])->execute();
                $this->db->createCommand()->insert('economy_parent_rule', ['subject_id' => $subject['id'], 'history_id' => (int)$this->db->getLastInsertID(), 'revision' => 1])->execute();
                $changed = true;
            }
            if ($changed) $this->db->createCommand()->update('world_node', ['revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['id' => $place['id']])->execute();
            $parent = (int)$subject['id'];
        }
        return $this->accounts($node);
    }

    /** Private financial metadata; the caller must check ownership before exposing this. */
    public function current(int $node): ?array
    {
        $row = (new Query())->select(['h.*', 'parent_node_id' => 'p.node_id'])->from(['s' => 'economy_subject'])
            ->innerJoin(['r' => 'economy_parent_rule'], '[[r.subject_id]]=[[s.id]]')
            ->innerJoin(['h' => 'economy_parent_history'], '[[h.id]]=[[r.history_id]] AND [[h.subject_id]]=[[s.id]] AND [[h.revision]]=[[r.revision]]')
            ->leftJoin(['p' => 'economy_subject'], '[[p.id]]=[[h.parent_subject_id]]')->where(['s.node_id' => $node])->one($this->db);
        if (!$row) return null;
        $policy = (new Query())->from('economy_collection_policy')->where(['history_id' => $row['id']])->one($this->db);
        return ['revision' => (int)$row['revision'], 'history_id' => (int)$row['id'], 'parent_node_id' => $row['parent_node_id'] === null ? null : (int)$row['parent_node_id'],
            'status' => $row['status'], 'basis' => $row['basis'], 'rate_bps' => $row['rate_bps'] === null ? null : (int)$row['rate_bps'],
            'due_seconds' => $row['due_seconds'] === null ? null : (int)$row['due_seconds'], 'effective_from' => (int)$row['effective_from'],
            'loss_policy' => $policy ? ['protected_seconds' => (int)$policy['protected_seconds'], 'loss_period_seconds' => (int)$policy['loss_period_seconds'], 'loss_rate_bps' => (int)$policy['loss_rate_bps']] : null];
    }
}
