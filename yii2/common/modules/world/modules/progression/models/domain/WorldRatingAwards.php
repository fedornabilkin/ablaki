<?php
namespace common\modules\world\modules\progression\models\domain;

use common\modules\world\modules\progression\domain\WorldRatingPolicy;
use common\modules\world\support\Locks;
use yii\db\Connection;

/** Runs inside the successful operation, never from preview or a client supplied reward. */
final class WorldRatingAwards
{
    public function __construct(private readonly Connection $db) {}

    public function award(int $user, string $action, string $source, string $operation, array $weights, string $comment): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Rating requires the game transaction.');
        if (!in_array($action, WorldRatingPolicy::ACTIONS, true) || !$weights || strlen($source) > 120) {
            throw new \InvalidArgumentException('Invalid world rating source.');
        }
        // Additive rollout: old installations keep playing until the reward migration is applied.
        if (!$this->db->schema->getTableSchema('world_rating_award')) return;
        // Command/job already holds users and wallets before the registry. Re-lock the same wallet.
        $locks = new Locks($this->db);
        $person = $locks->row('persone', ['user_id' => $user]);
        if (!$person) throw new \RuntimeException('Rating account missing.');
        // Legacy profiles may have no rating yet; calculation and receipt share the same zero baseline.
        $ratingBefore = $person['rating'] ?? 0;
        // Current reads also work after an earlier snapshot under MySQL REPEATABLE READ.
        if ($locks->row('world_rating_award', ['user_id' => $user, 'source_key' => $source])) return;
        $where = ['user_id' => $user, 'action' => $action];
        $track = $locks->row('world_rating_progress', $where);
        $next = WorldRatingPolicy::award((float)$ratingBefore, (float)($track['peak_rating'] ?? 0), (int)($track['completed_units'] ?? 0), $weights);
        $values = ['completed_units' => $next['completed'], 'peak_rating' => $next['peak']];
        $write = $track ? $this->db->createCommand()->update('world_rating_progress', $values, $where)
            : $this->db->createCommand()->insert('world_rating_progress', $where + $values);
        if ($write->execute() !== 1) throw new \RuntimeException('Rating progress write failed.');
        if ($next['delta'] > 0) {
            if ($this->db->createCommand()->update('persone', ['rating' => $next['rating']], ['user_id' => $user])->execute() !== 1) {
                throw new \RuntimeException('Rating write failed.');
            }
            if ($this->db->createCommand()->insert('history_rating', [
                'user_id' => $user, 'rating' => $next['rating'], 'rating_up' => $next['delta'],
                'type' => 'world.' . $action, 'comment' => $comment, 'created_at' => time(),
            ])->execute() !== 1) throw new \RuntimeException('Rating history write failed.');
        }
        if ($this->db->createCommand()->insert('world_rating_award', [
            'user_id' => $user, 'action' => $action, 'source_key' => $source, 'operation_id' => $operation,
            'policy_version' => WorldRatingPolicy::VERSION, 'units' => count($weights),
            'weights_json' => json_encode($weights, JSON_THROW_ON_ERROR),
            'rating_before' => $ratingBefore, 'rating_after' => $next['rating'], 'rating_delta' => $next['delta'],
            'created_at' => time(),
        ])->execute() !== 1) throw new \RuntimeException('Rating receipt write failed.');
    }
}
