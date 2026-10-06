<?php
namespace common\modules\world\support;

use yii\db\Connection;
use yii\db\Query;

/** craft_meta -> user -> persone -> registry -> nodes -> storage -> items -> jobs. */
class Locks
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function row(string $table, array $where): ?array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Transaction required for a row lock.');
        $query = (new Query())->from($table)->where($where);
        if ($this->db->driverName === 'sqlite') {
            $column = key($where);
            $this->db->createCommand()->update($table, [$column => new \yii\db\Expression($this->db->quoteColumnName($column))], $where)->execute();
            return $query->one($this->db) ?: null;
        }
        if (!in_array($this->db->driverName, ['mysql', 'pgsql'], true)) throw new \LogicException('Unsupported database.');
        $command = $query->createCommand($this->db);
        return $this->db->createCommand($command->sql . ' FOR UPDATE', $command->params)->queryOne() ?: null;
    }
    public function owners(array $ids, bool $requireActive = true): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids, SORT_NUMERIC);
        // Cleanup takes user before persone. Never acquire user after a person's account.
        foreach ($ids as $id) {
            $user = $this->row('user', ['id' => $id]);
            if (!$user || ($requireActive && !empty($user['blocked_at']))) throw new GameError('ACCOUNT_UNAVAILABLE', 'Аккаунт недоступен.', 403);
        }
        foreach ($ids as $id) $this->row('persone', ['user_id' => $id]);
    }
    public function nodes(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids))); sort($ids, SORT_NUMERIC);
        $rows = [];
        foreach ($ids as $id) {
            $row = $this->row('world_node', ['id' => $id]);
            if (!$row) throw new GameError('NODE_NOT_FOUND', 'Объект не найден.', 404);
            $rows[$id] = $row;
        }
        return $rows;
    }
}
