<?php
namespace common\modules\progression\service;

use common\modules\economy\service\FinanceReadSnapshot;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

class WorldAchievements
{
    private $db;
    private $flags;
    private $access;
    public function __construct(Connection $db, WorldFlags $flags, WorldAccessPolicy $access) { $this->db = $db; $this->flags = $flags; $this->access = $access; }
    public function input(array $body): array
    {
        if (!is_string($body['code'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $body['code']) || !is_string($body['name'] ?? null) || trim($body['name']) === '' || mb_strlen($body['name'], 'UTF-8') > 120
            || !is_string($body['reason'] ?? null) || trim($body['reason']) === '' || mb_strlen($body['reason'], 'UTF-8') > 255) throw new GameError('INVALID_ACHIEVEMENT', 'Укажите код, название достижения и причину изменения.', 422);
        return ['code' => $body['code'], 'name' => trim($body['name']), 'reason' => trim($body['reason'])];
    }
    public function listing(int $user, int $page, string $search, string $status): array
    {
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120 || !in_array($status, ['all', 'earned', 'missing'], true)) throw new GameError('INVALID_FILTER', 'Некорректный фильтр достижений.', 422);
        return (new FinanceReadSnapshot($this->db))->run(function () use ($user, $page, $search, $status) {
            $this->flags->requireFlag('world_read');
            $actor = (new Query())->select('id')->from('game_actor')->where(['user_id' => $user, 'kind' => 'player'])->scalar($this->db);
            $query = (new Query())->select(['a.id', 'a.code', 'a.name', 'e.earned_at'])->from(['a' => 'achievement'])->leftJoin(['e' => 'actor_achievement'], '[[e.achievement_id]]=[[a.id]] AND [[e.actor_id]]=:actor', [':actor' => $actor ? (int)$actor : 0]);
            if ($search !== '') $query->andWhere(['or', ['like', 'a.code', $search], ['like', 'a.name', $search]]);
            if ($status === 'earned') $query->andWhere(['not', ['e.earned_at' => null]]);
            if ($status === 'missing') $query->andWhere(['e.earned_at' => null]);
            $total = (int)(clone $query)->count('*', $this->db); $items = [];
            foreach ($query->orderBy(['a.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) $items[] = ['id' => (int)$row['id'], 'code' => $row['code'], 'name' => $row['name'], 'earned' => $row['earned_at'] !== null, 'earned_at' => $row['earned_at'] === null ? null : (int)$row['earned_at']];
            return ['items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20]];
        });
    }
    private function prepare(array $input): array
    {
        $this->access->requireAdmin(); $this->flags->requireFlag('world_read'); $normalized = $this->input($input);
        $row = (new Query())->from('achievement')->where(['code' => $input['code']])->one($this->db);
        $registry = (new Query())->from('world_registry')->where(['id' => 1])->one($this->db);
        return ['revisions' => ['registry' => (int)$registry['content_revision']], 'terms' => $normalized + ['achievement_id' => $row ? (int)$row['id'] : null, 'name_before' => $row ? $row['name'] : null, 'earned_records_unchanged' => true]];
    }
    public function preview(int $user, array $input): array
    {
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.achievement.publish', $input, function () use ($input) { return $this->prepare($input); });
    }
    public function publish(int $user, array $input, string $key, string $quote, array $revisions): array
    {
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.achievement.publish', $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $bus) {
            if (CanonicalJson::encode($this->prepare($payload)['terms']) !== CanonicalJson::encode($terms)) throw new GameError('ACHIEVEMENT_CHANGED', 'Достижение изменилось. Повторите расчёт.');
            $id = $terms['achievement_id'];
            if ($id === null) { $this->db->createCommand()->insert('achievement', ['code' => $terms['code'], 'name' => $terms['name']])->execute(); $id = (int)$this->db->getLastInsertID(); }
            elseif ($terms['name'] !== $terms['name_before'] && $this->db->createCommand()->update('achievement', ['name' => $terms['name']], ['id' => $id])->execute() !== 1) throw new \RuntimeException('Achievement update failed.');
            if ($this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute() !== 1) throw new \RuntimeException('Registry update failed.');
            $result = ['achievement_id' => $id, 'changed_node_ids' => []]; $bus->emit($operation, $user, 'world.achievement.published', $result); return $result;
        });
    }
    /** Internal reward API. The calling domain validates its objective; never route this from HTTP. */
    public function award(int $actor, int $achievement, string $eventId, string $operation): bool
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Achievement award requires a domain transaction.');
        $locks = new Locks($this->db); $locks->row('world_registry', ['id' => 1]); $who = $locks->row('game_actor', ['id' => $actor]);
        $event = (new Query())->from('game_outbox')->where(['id' => $eventId, 'operation_id' => $operation])->one($this->db);
        if (!$who || !$event || !(new Query())->from('achievement')->where(['id' => $achievement])->exists($this->db)) throw new \LogicException('Missing achievement award evidence.');
        $payload = json_decode($event['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        if (isset($payload['actor_id']) ? $payload['actor_id'] !== $actor : ($who['kind'] !== 'player' || (int)$who['user_id'] !== (int)$event['user_id'])) throw new \LogicException('Achievement event actor mismatch.');
        $where = ['actor_id' => $actor, 'achievement_id' => $achievement];
        if ((new Query())->from('actor_achievement')->where($where)->exists($this->db)) return false;
        $this->db->createCommand()->insert('actor_achievement', $where + ['source_event_id' => $eventId, 'operation_id' => $operation, 'earned_at' => time()])->execute();
        if ($this->db->createCommand()->update('game_actor', ['revision' => new Expression('[[revision]]+1')], ['id' => $actor])->execute() !== 1) throw new \RuntimeException('Actor revision update failed.');
        return true;
    }
}
