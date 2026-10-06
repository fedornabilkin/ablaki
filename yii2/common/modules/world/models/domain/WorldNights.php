<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\CanonicalJson;
use common\modules\world\support\CommandBus;
use common\modules\world\support\GameError;
use common\modules\world\support\JobQueue;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Initial immutable calendar and bounded, offline, per-actor night resolution. */
class WorldNights
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    public function policy(int $world): ?array { return $this->one('world_night_policy', ['world_id' => $world, 'version' => 1]); }
    /** Read-only state/history. Opening a page never enrolls, heals or resolves a night. */
    public function state(int $user, int $node, int $page, string $outcome): array
    {
        $this->flags->requireFlag('world_read');
        $place = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->node($node);
        $membership = $this->one('world_membership', ['user_id' => $user, 'starter_site_id' => $node]);
        if (!$membership || !$place['permissions']['storage']) throw new GameError('NIGHT_OWNER_REQUIRED', 'Ночлег и здоровье доступны на собственной стоянке.', 403);
        if ($page < 1 || $page > 1000000 || !in_array($outcome, ['', 'protected', 'unprotected'], true)) throw new GameError('INVALID_NIGHT_FILTER', 'Некорректные параметры истории ночей.', 422);
        $policy = $this->policy((int)$membership['world_id']); $actor = $this->one('game_actor', ['user_id' => $user]);
        $health = $policy && $actor ? $this->one('actor_night_health', ['actor_id' => $actor['id'], 'policy_id' => $policy['id'], 'membership_id' => $membership['id']]) : null;
        $now = time();
        $result = ['node_id' => $node, 'enabled' => (bool)$policy, 'processing_available' => false, 'enrollment_pending' => false, 'catching_up' => false,
            'policy' => null, 'grace_until' => null, 'first_eligible_night' => null, 'current_or_next_night' => null, 'protection_forecast' => null, 'health' => null,
            'history' => ['items' => [], '_meta' => ['totalCount' => 0, 'pageCount' => 0, 'currentPage' => $page, 'perPage' => 20]], 'server_time' => $now];
        if (!$policy) return $result;
        $registry = $this->one('world_registry', ['id' => 1]); $flags = $this->flags->capabilities();
        $result['processing_available'] = $flags['world_write'] && $flags['storage_v2'] && (int)$registry['active_world_id'] === (int)$policy['world_id'];
        $result['policy'] = [];
        foreach (['id', 'version', 'activated_at', 'day_seconds', 'night_offset', 'night_seconds', 'max_severity', 'recovery_nights', 'mild_efficiency_bps', 'severe_efficiency_bps'] as $key) $result['policy'][$key] = (int)$policy[$key];
        $grace = max((int)$membership['joined_at'], (int)$policy['activated_at']) + (int)$policy['day_seconds'];
        $result['grace_until'] = $grace;
        $result['first_eligible_night'] = NightCalendar::bounds($policy, NightCalendar::firstStartingAt($policy, $grace));
        $result['current_or_next_night'] = NightCalendar::currentOrNext($policy, $now);
        $result['protection_forecast'] = $actor && $this->coverage((int)$actor['id'], $membership, $result['current_or_next_night'])['protected'];
        $result['enrollment_pending'] = !$health;
        $sequence = $health ? (int)$health['next_sequence'] : $result['first_eligible_night']['sequence'];
        $result['catching_up'] = NightCalendar::bounds($policy, $sequence)['ended_at'] <= $now;
        if ($health) {
            $result['health'] = ['severity' => (int)$health['severity'], 'recovery_progress' => (int)$health['recovery_progress'], 'exposure_nights' => (int)$health['exposure_nights'],
                'onset_at' => $health['onset_at'] === null ? null : (int)$health['onset_at'], 'processed_until' => $health['processed_until'] === null ? null : (int)$health['processed_until'],
                'next_sequence' => (int)$health['next_sequence'], 'revision' => (int)$health['revision'], 'work_efficiency_bps' => NightCalendar::efficiency($policy, (int)$health['severity']), 'work_penalty_applied' => (int)$health['severity'] > 0];
            $query = (new Query())->select(['r.id', 'r.outcome', 'r.severity_before', 'r.severity_after', 'r.recovery_progress', 'r.resolved_at', 'p.sequence', 'p.started_at', 'p.ended_at'])
                ->from(['r' => 'world_night_resolution'])->innerJoin(['p' => 'world_night_period'], '[[p.id]]=[[r.period_id]]')->where(['r.actor_id' => $actor['id'], 'p.policy_id' => $policy['id']]);
            // Results are immutable; pin the journal to the health checkpoint read above.
            $query->andWhere(['<', 'p.sequence', (int)$health['next_sequence']]);
            if ($outcome !== '') $query->andWhere(['r.outcome' => $outcome]);
            $total = (int)(clone $query)->count('*', $this->db); $items = [];
            foreach ($query->orderBy(['p.sequence' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) {
                foreach ($row as $key => $value) if ($key !== 'outcome') $row[$key] = (int)$value;
                $items[] = $row;
            }
            $result['history'] = ['items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20]];
        }
        return $result;
    }
    public static function rules(array $input): array
    {
        $limits = ['day_seconds' => [3600, 604800], 'night_offset' => [0, 604799], 'night_seconds' => [60, 604799], 'max_severity' => [2, 10], 'recovery_nights' => [1, 30], 'mild_efficiency_bps' => [1, 9999], 'severe_efficiency_bps' => [1, 9999]];
        $result = [];
        foreach ($limits as $key => $range) {
            if (!is_int($input[$key] ?? null) || $input[$key] < $range[0] || $input[$key] > $range[1]) throw new \InvalidArgumentException('Invalid night policy: ' . $key);
            $result[$key] = $input[$key];
        }
        if ($result['night_offset'] + $result['night_seconds'] > $result['day_seconds'] || $result['night_seconds'] >= $result['day_seconds'] || $result['severe_efficiency_bps'] > $result['mild_efficiency_bps']) throw new \InvalidArgumentException('Night must fit in a day; severe efficiency must not exceed mild efficiency.');
        return $result;
    }
    /** Deliberate CLI publication only. Repeating the same rules never resets the epoch or grace. */
    public function activate(int $world, array $input): array
    {
        $rules = self::rules($input);
        return $this->db->transaction(function () use ($world, $rules) {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $registry = $locks->row('world_registry', ['id' => 1]);
            $this->flags->requireFlag('world_write'); $this->flags->requireFlag('storage_v2');
            if ((int)$registry['active_world_id'] !== $world) throw new GameError('NIGHT_WORLD_UNAVAILABLE', 'Календарь можно открыть только для действующего мира.');
            if (!$this->one('world_node', ['id' => $world, 'node_type' => 'WORLD', 'status' => 'active'])) throw new GameError('NIGHT_WORLD_UNAVAILABLE', 'Мир недоступен.');
            $old = $this->policy($world);
            if ($old) {
                foreach ($rules as $key => $value) if ((int)$old[$key] !== $value) throw new GameError('NIGHT_POLICY_IMMUTABLE', 'Опубликованный календарь нельзя переписать. Нужен отдельный переход на новую версию.');
                return $old;
            }
            if ((new Query())->from('world_night_policy')->exists($this->db)) throw new GameError('NIGHT_WORLD_TRANSITION_REQUIRED', 'Для смены мира нужен перенос календаря и здоровья персонажей.');
            (new ShelterRepair($this->db))->recipe();
            $operation = bin2hex(random_bytes(16)); $now = time();
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => null, 'type' => 'world.nights.activate', 'created_at' => $now])->execute();
            $this->db->createCommand()->insert('world_night_policy', $rules + ['world_id' => $world, 'version' => 1, 'activated_at' => $now, 'operation_id' => $operation])->execute();
            $policy = $this->policy($world);
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
            (new JobQueue($this->db))->enqueue('world.night.enroll', 'policy:' . $policy['id'] . ':after:0', ['policy_id' => (int)$policy['id'], 'after_id' => 0]);
            (new CommandBus($this->db, $this->flags))->emit($operation, null, 'world.nights.activated', ['world_id' => $world, 'policy_id' => (int)$policy['id'], 'activated_at' => $now] + $rules);
            return $policy;
        });
    }
    private function runnable(int $id): array
    {
        $this->flags->requireFlag('world_write'); $this->flags->requireFlag('storage_v2');
        $policy = $this->one('world_night_policy', ['id' => $id]); $registry = $this->one('world_registry', ['id' => 1]);
        if (!$policy || (int)$policy['world_id'] !== (int)$registry['active_world_id']) throw new GameError('FEATURE_DISABLED', 'Расчёт ночей этого мира приостановлен.');
        return $policy;
    }
    public function joined(array $membership): void
    {
        $policy = $this->policy((int)$membership['world_id']);
        if ($policy) $this->enqueue($policy, $membership, 0, time());
    }
    private function enqueue(array $policy, array $membership, int $cursor, int $at): void
    {
        (new JobQueue($this->db))->enqueue('world.night.resolve', 'p:' . $policy['id'] . ':m:' . $membership['id'] . ':n:' . $cursor,
            ['policy_id' => (int)$policy['id'], 'membership_id' => (int)$membership['id'], 'cursor' => $cursor], (int)$membership['user_id'], $at);
    }
    /** Registry-only job; each player's writes are delegated to a job with the correct owner lock. */
    public function enroll(array $payload): void
    {
        $policy = $this->runnable((int)$payload['policy_id']);
        $rows = (new Query())->from('world_membership')->where(['world_id' => $policy['world_id']])->andWhere(['>', 'id', (int)$payload['after_id']])->orderBy(['id' => SORT_ASC])->limit(100)->all($this->db);
        foreach ($rows as $row) $this->enqueue($policy, $row, 0, time());
        if (count($rows) === 100) {
            $last = (int)end($rows)['id'];
            (new JobQueue($this->db))->enqueue('world.night.enroll', 'policy:' . $policy['id'] . ':after:' . $last, ['policy_id' => (int)$policy['id'], 'after_id' => $last]);
        }
    }
    /** Coverage is historical. A currently folded/broken shelter may have protected a past night. */
    private function coverage(int $actor, array $membership, array $night): array
    {
        $start = $night['started_at']; $end = $night['ended_at'];
        $query = (new Query())->select(['l.id', 'l.deployment_id', 'l.started_at', 'l.ended_at', 'protection_id' => 'p.id', 'protection_start' => 'p.started_at', 'protection_end' => 'p.ended_at', 'p.protected_until'])->from(['l' => 'world_lodging_interval'])
            ->innerJoin(['d' => 'world_shelter_deployment'], '[[d.id]]=[[l.deployment_id]]')
            ->innerJoin(['p' => 'world_shelter_protection'], '[[p.deployment_id]]=[[d.id]]')
            ->where(['l.actor_id' => $actor, 'd.plot_id' => $membership['starter_site_id']])
            ->andWhere(['<=', 'd.started_at', $start])->andWhere(['or', ['d.ended_at' => null], ['>=', 'd.ended_at', $end]])
            ->andWhere(['<', 'p.started_at', $end])->andWhere(['>', 'p.protected_until', $start])->andWhere(['or', ['p.ended_at' => null], ['>', 'p.ended_at', $start]])
            ->andWhere(['<', 'l.started_at', $end])->andWhere(['or', ['l.ended_at' => null], ['>', 'l.ended_at', $start]])
            ->orderBy(['l.deployment_id' => SORT_ASC, 'p.started_at' => SORT_ASC, 'p.id' => SORT_ASC, 'l.started_at' => SORT_ASC, 'l.id' => SORT_ASC]);
        $deployment = null; $until = $start; $evidence = []; $protection = [];
        foreach ($query->each(100, $this->db) as $interval) {
            if ($deployment !== (int)$interval['deployment_id']) { $deployment = (int)$interval['deployment_id']; $until = $start; $evidence = []; $protection = []; }
            $from = max($start, (int)$interval['started_at'], (int)$interval['protection_start']);
            $to = min($end, (int)$interval['protected_until'], $interval['ended_at'] === null ? $end : (int)$interval['ended_at'], $interval['protection_end'] === null ? $end : (int)$interval['protection_end']);
            if ($to <= $from || $from > $until) continue;
            $until = max($until, $to); $evidence[] = (int)$interval['id']; $protection[] = (int)$interval['protection_id'];
            if ($until >= $end) return ['protected' => true, 'deployment_id' => $deployment, 'housing_place_id' => null, 'interval_ids' => array_values(array_unique($evidence)), 'protection_ids' => array_values(array_unique($protection))];
        }
        return (new WorldHousing($this->db, $this->flags))->coverage($actor, (int)$membership['starter_site_id'], $start, $end)
            ?? ['protected' => false, 'deployment_id' => null, 'housing_place_id' => null, 'interval_ids' => [], 'protection_ids' => []];
    }
    /** JobQueue holds craft/owner/registry/job locks and commits health, journal and successor together. */
    public function resolve(array $payload, array $job): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Night resolution requires the job transaction.');
        $policy = $this->runnable((int)$payload['policy_id']);
        $membership = $this->one('world_membership', ['id' => (int)$payload['membership_id'], 'world_id' => $policy['world_id']]);
        if (!$membership || (int)$job['owner_user_id'] !== (int)$membership['user_id']) throw new \LogicException('Night job owner mismatch.');
        $actor = (new ActorResolver($this->db))->player((int)$membership['user_id']); $id = (int)$actor['id']; $now = time();
        $health = $this->one('actor_night_health', ['actor_id' => $id]);
        if (!$health) {
            if ((int)$payload['cursor'] !== 0) throw new \LogicException('Night enrollment missing.');
            $baseline = max((int)$membership['joined_at'], (int)$policy['activated_at']); $grace = $baseline + (int)$policy['day_seconds'];
            $this->db->createCommand()->insert('actor_night_health', ['actor_id' => $id, 'policy_id' => $policy['id'], 'membership_id' => $membership['id'], 'eligible_from' => $baseline, 'grace_until' => $grace, 'next_sequence' => NightCalendar::firstStartingAt($policy, $grace)])->execute();
            $health = $this->one('actor_night_health', ['actor_id' => $id]);
        } else {
            if ((int)$health['policy_id'] !== (int)$policy['id'] || (int)$health['membership_id'] !== (int)$membership['id']) throw new \LogicException('Explicit health migration required.');
            if ((int)$payload['cursor'] === 0 || (int)$payload['cursor'] < (int)$health['next_sequence']) return;
            if ((int)$payload['cursor'] !== (int)$health['next_sequence']) throw new \LogicException('Night sequence gap.');
        }
        for ($i = 0; $i < 32; $i++) {
            $night = NightCalendar::bounds($policy, (int)$health['next_sequence']);
            if ($night['ended_at'] > $now) break;
            $period = $this->one('world_night_period', ['policy_id' => $policy['id'], 'sequence' => $night['sequence']]);
            if (!$period) {
                $this->db->createCommand()->insert('world_night_period', ['policy_id' => $policy['id']] + $night)->execute();
                $period = ['id' => (int)$this->db->getLastInsertID()];
            }
            if ($this->one('world_night_resolution', ['actor_id' => $id, 'period_id' => $period['id']])) throw new \LogicException('Health checkpoint differs from the night journal.');
            $coverage = $this->coverage($id, $membership, $night); $protected = $coverage['protected'];
            $next = NightCalendar::advance($policy, $health, $protected, $night['ended_at']); $operation = bin2hex(random_bytes(16));
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $membership['user_id'], 'type' => 'world.night.resolved', 'created_at' => $now])->execute();
            $this->db->createCommand()->insert('world_night_resolution', ['actor_id' => $id, 'period_id' => $period['id'], 'outcome' => $protected ? 'protected' : 'unprotected', 'severity_before' => $health['severity'], 'severity_after' => $next['severity'], 'recovery_progress' => $next['recovery_progress'], 'deployment_id' => $coverage['deployment_id'], 'coverage_json' => CanonicalJson::encode($coverage), 'operation_id' => $operation, 'resolved_at' => $now])->execute();
            if ($coverage['housing_place_id'] !== null) $this->db->createCommand()->insert('world_housing_night_claim', ['resolution_id' => (int)$this->db->getLastInsertID(), 'place_id' => $coverage['housing_place_id'], 'period_id' => $period['id']])->execute();
            $next += ['next_sequence' => $night['sequence'] + 1, 'processed_until' => $night['ended_at'], 'revision' => (int)$health['revision'] + 1];
            if ($this->db->createCommand()->update('actor_night_health', $next, ['actor_id' => $id, 'revision' => $health['revision']])->execute() !== 1) throw new \RuntimeException('Health checkpoint write failed.');
            $health = array_merge($health, $next);
            (new CommandBus($this->db, $this->flags))->emit($operation, (int)$membership['user_id'], 'world.night.resolved', ['actor_id' => $id, 'period_id' => (int)$period['id'], 'outcome' => $protected ? 'protected' : 'unprotected', 'severity' => $next['severity']]);
        }
        $nextNight = NightCalendar::bounds($policy, (int)$health['next_sequence']);
        $this->enqueue($policy, $membership, (int)$health['next_sequence'], max($now, $nextNight['ended_at']));
    }
}
