<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\GameError;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Persistent character position and short-lived, direct (not population) occupancy. */
final class WorldPresence
{
    public const TTL = 90;
    public function __construct(private readonly Connection $db) {}
    public function ready(): bool { return $this->db->schema->getTableSchema('world_presence') !== null; }

    public function current(int $user): array
    {
        $row = $this->ready() ? (new Query())->from('world_presence')->where(['user_id' => $user])->one($this->db) : false;
        return ['node_id' => $row ? (int)$row['node_id'] : null, 'revision' => $row ? (int)$row['revision'] : 0];
    }

    /** Caller supplies only nodes already filtered for visibility. No identities are disclosed. */
    public function counts(array $ids): array
    {
        if (!$ids || !$this->ready()) return [];
        $rows = (new Query())->select(['node_id', 'amount' => new Expression('COUNT(*)')])->from('world_presence')
            ->where(['node_id' => $ids])->andWhere(['>', 'seen_at', time() - self::TTL])->groupBy('node_id')->all($this->db);
        $result = [];
        foreach ($rows as $row) $result[(int)$row['node_id']] = (int)$row['amount'];
        return $result;
    }

    public function state(int $user, int $node): array
    {
        $policy = new WorldAccessPolicy($user);
        (new WorldQuery($this->db, $policy))->record($node);
        $ids = $policy->filter((new Query())->select('n.id')->from(['n' => 'world_node']))
            ->andWhere(['or', ['n.id' => $node], ['n.parent_id' => $node]])->column($this->db);
        $counts = $this->counts($ids); $items = [];
        foreach ($ids as $id) $items[] = ['node_id' => (int)$id, 'count' => $counts[$id] ?? 0];
        return ['enabled' => $this->ready(), 'current' => $this->current($user), 'items' => $items, 'ttl' => self::TTL, 'server_time' => time()];
    }

    /** Runs inside the world command transaction; stale tabs cannot undo a later move. */
    public function visit(int $user, int $node, int $revision, bool $heartbeat): array
    {
        if (!$this->ready()) throw new GameError('PRESENCE_NOT_READY', 'Перемещение пока недоступно.', 503);
        if (!$this->db->getTransaction()) throw new \LogicException('Presence requires a command transaction.');
        (new Locks($this->db))->row('user', ['id' => $user]);
        $place = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->record($node);
        if ($place['status'] !== 'active') throw new GameError('NODE_UNAVAILABLE', 'Войти можно только в действующий объект.', 409);
        $current = $this->current($user);
        if ($current['revision'] !== $revision || ($heartbeat && $current['node_id'] !== $node))
            throw new GameError('PRESENCE_CHANGED', 'Персонаж уже переместился. Обновите местоположение.', 409, $current);
        $next = $revision + ($heartbeat ? 0 : 1);
        $position = ['node_id' => $node, 'revision' => $next, 'seen_at' => time()];
        if ($revision === 0) $this->db->createCommand()->insert('world_presence', ['user_id' => $user] + $position)->execute();
        else {
            $updated = $this->db->createCommand()->update('world_presence', $position, ['user_id' => $user, 'revision' => $revision])->execute();
            // MySQL may report zero changed rows for two heartbeats within the same second.
            if ($updated !== 1 && !(new Query())->from('world_presence')->where(['user_id' => $user] + $position)->exists($this->db))
                throw new \RuntimeException('Presence update failed.');
        }
        return ['current' => ['node_id' => $node, 'revision' => $next]];
    }
}
