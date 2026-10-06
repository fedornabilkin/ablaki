<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** One source of the player's persisted illness modifier for manual work. */
class NightWorkEfficiency
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function basisPoints(int $user): int
    {
        if (!$this->db->schema->getTableSchema('actor_night_health')) return 10000;
        $row = (new Query())->select(['h.severity', 'h.next_sequence', 'p.mild_efficiency_bps', 'p.severe_efficiency_bps', 'p.max_severity',
                'p.activated_at', 'p.day_seconds', 'p.night_offset', 'p.night_seconds', 'm.joined_at'])
            ->from(['m' => 'world_membership'])->innerJoin(['r' => 'world_registry'], '[[r.active_world_id]]=[[m.world_id]]')
            ->innerJoin(['p' => 'world_night_policy'], '[[p.world_id]]=[[m.world_id]] AND [[p.version]]=1')
            ->leftJoin(['a' => 'game_actor'], '[[a.user_id]]=[[m.user_id]]')
            ->leftJoin(['h' => 'actor_night_health'], '[[h.actor_id]]=[[a.id]] AND [[h.policy_id]]=[[p.id]] AND [[h.membership_id]]=[[m.id]]')
            ->where(['m.user_id' => $user])->one($this->db);
        if (!$row) return 10000;
        $next = $row['next_sequence'] === null
            ? NightCalendar::firstStartingAt($row, max((int)$row['joined_at'], (int)$row['activated_at']) + (int)$row['day_seconds'])
            : (int)$row['next_sequence'];
        if (NightCalendar::bounds($row, $next)['ended_at'] <= time()) throw new GameError('NIGHT_CATCHING_UP', 'Расчёт прошедших ночей ещё выполняется. Повторите действие позже.', 409);
        return $row['severity'] === null ? 10000 : NightCalendar::efficiency($row, (int)$row['severity']);
    }
}
