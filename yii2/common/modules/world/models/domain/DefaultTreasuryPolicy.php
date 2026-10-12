<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** Published defaults for new accounts; existing administrator revisions are retained. */
final class DefaultTreasuryPolicy
{
    public static function initialPolicy(Connection $db, int $subject, string $operation): void
    {
        if (!$db->getTransaction()) throw new \LogicException('Initial policy requires the provisioning transaction.');
        $old = (new Query())->select('h.*')->from(['r' => 'economy_parent_rule'])->innerJoin(['h' => 'economy_parent_history'], '[[h.id]]=[[r.history_id]]')->where(['r.subject_id' => $subject])->one($db);
        if (!$old || !in_array($old['status'], ['root', 'unconfigured'], true)) return;
        $now = time(); $revision = (int)$old['revision'] + 1;
        if ($db->createCommand()->update('economy_parent_history', ['effective_to' => $now], ['id' => $old['id'], 'effective_to' => null])->execute() !== 1) throw new \RuntimeException('Initial treasury policy changed.');
        $db->createCommand()->insert('economy_parent_history', ['subject_id' => $subject, 'parent_subject_id' => $old['parent_subject_id'], 'revision' => $revision, 'status' => 'published', 'basis' => 'collected_revenue',
            'rate_bps' => $old['parent_subject_id'] === null ? 0 : 1000, 'due_seconds' => 86400, 'effective_from' => $now, 'effective_to' => null, 'operation_id' => $operation, 'reason' => 'Default treasury policy; editable by administrator'])->execute();
        $history = (int)$db->getLastInsertID();
        $db->createCommand()->insert('economy_collection_policy', ['history_id' => $history, 'protected_seconds' => 3600, 'loss_period_seconds' => 3600, 'loss_rate_bps' => 100])->execute();
        $db->createCommand()->insert('economy_tax_checkpoint', ['history_id' => $history, 'fraction' => 0])->execute();
        if ($db->createCommand()->update('economy_parent_rule', ['history_id' => $history, 'revision' => $revision], ['subject_id' => $subject, 'history_id' => $old['id']])->execute() !== 1) throw new \RuntimeException('Initial treasury policy pointer changed.');
    }
}
