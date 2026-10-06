<?php
namespace common\modules\world\modules\progression\models\domain;

use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Trusted domain consumer only; there is deliberately no HTTP endpoint accepting XP. */
class ProgressionAwards
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function dispatch(string $eventId, string $operation): void
    {
        $event = (new Query())->from('game_outbox')->where(['id' => $eventId])->one($this->db);
        if (!$event || !in_array($event['event_type'], WorldProgression::SOURCES, true)) return;
        $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if (isset($payload['actor_id'])) {
            if (!is_int($payload['actor_id'])) throw new \LogicException('Invalid event actor.');
            $actor = (new Query())->from('game_actor')->where(['id' => $payload['actor_id']])->one($this->db);
        } else $actor = $event['user_id'] === null ? null : (new Query())->from('game_actor')->where(['user_id' => $event['user_id'], 'kind' => 'player'])->one($this->db);
        if (!$actor) return;
        $professions = (new Query())->select('profession_id')->from('actor_profession')->where(['actor_id' => $actor['id']])->orderBy(['profession_id' => SORT_ASC])->limit(101)->column($this->db);
        if (count($professions) > 100) throw new \RuntimeException('Too many actor profession tracks.');
        foreach ($professions as $profession) $this->award((int)$actor['id'], (int)$profession, $eventId, $operation);
    }
    /** Caller owns command/job transaction and registry lock; event/actor attribution is caller's responsibility. */
    public function award(int $actor, int $profession, string $eventId, string $operation): ?int
    {
        if (!$this->db->getTransaction()) throw new \LogicException('XP requires the domain transaction.');
        $locks = new Locks($this->db); $locks->row('world_registry', ['id' => 1]);
        if (!$locks->row('game_actor', ['id' => $actor])) throw new \LogicException('Actor missing.');
        $source = 'event:' . $eventId;
        $prior = (new Query())->from('progression_award')->where(['actor_id' => $actor, 'profession_id' => $profession, 'source_key' => $source])->one($this->db);
        if ($prior) return (int)$prior['id'];
        $track = (new Query())->from('actor_profession')->where(['actor_id' => $actor, 'profession_id' => $profession])->one($this->db);
        $event = (new Query())->from('game_outbox')->where(['id' => $eventId])->one($this->db);
        if (!$track || !$event) return null;
        $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if (isset($payload['actor_id'])) {
            if ($payload['actor_id'] !== $actor) return null;
        } elseif (!(new Query())->from('game_actor')->where(['id' => $actor, 'kind' => 'player', 'user_id' => $event['user_id']])->exists($this->db)) return null;
        $rules = (new Query())->from('profession_revision')->where(['id' => $track['rules_revision_id'], 'profession_id' => $profession])->one($this->db);
        if (!$rules) throw new \RuntimeException('Profession rules missing.');
        $config = json_decode($rules['config_json'], true, 512, JSON_THROW_ON_ERROR);
        $policy = $config['sources'][$event['event_type']] ?? null;
        if (!$policy) return null;
        $day = (new \DateTimeImmutable('@' . $event['created_at']))->setTimezone(new \DateTimeZone('Europe/Moscow'))->format('Y-m-d');
        $where = ['actor_id' => $actor, 'profession_id' => $profession, 'day_key' => $day, 'source_code' => $event['event_type']];
        $daily = (new Query())->from('progression_daily_limit')->where($where)->one($this->db);
        $used = (int)($daily['awarded_xp'] ?? 0);
        $xp = max(0, min($policy['xp'], $policy['daily_max'] - $used, WorldProgression::MAX_XP - (int)$track['xp']));
        $this->db->createCommand()->insert('progression_award', ['actor_id' => $actor, 'profession_id' => $profession, 'rules_revision_id' => $rules['id'], 'source_key' => $source, 'source_event_id' => $eventId, 'operation_id' => $operation, 'xp' => $xp, 'created_at' => time()])->execute();
        $id = (int)$this->db->getLastInsertID();
        if ($xp) {
            if ($daily) {
                if ($this->db->createCommand()->update('progression_daily_limit', ['awarded_xp' => $used + $xp], $where)->execute() !== 1) throw new \RuntimeException('Daily XP checkpoint update failed.');
            } else $this->db->createCommand()->insert('progression_daily_limit', $where + ['awarded_xp' => $xp])->execute();
            if ($this->db->createCommand()->update('actor_profession', ['xp' => (int)$track['xp'] + $xp, 'revision' => new Expression('[[revision]]+1'), 'updated_at' => time()], ['actor_id' => $actor, 'profession_id' => $profession])->execute() !== 1) throw new \RuntimeException('XP update failed.');
            if ($this->db->createCommand()->update('game_actor', ['revision' => new Expression('[[revision]]+1')], ['id' => $actor])->execute() !== 1) throw new \RuntimeException('Actor update failed.');
        }
        return $id;
    }
}
