<?php
namespace common\modules\world\service;

use common\modules\economy\value\Money;
use common\services\game\GameError;
use common\services\game\Locks;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Administrative settings cannot grant capacity or remove purchased places. */
class WarehousePolicyEditor
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function save(int $user, int $storage, int $revision, int $limit, string $price, string $reason): void
    {
        $money = Money::parse($price);
        if ($limit < 1 || $limit > 1000 || $money->isZero() || $money->isNegative() || trim($reason) === '' || mb_strlen($reason) > 255) throw new GameError('INVALID_WAREHOUSE_POLICY', 'Укажите предел до 1000 ячеек, положительную цену и причину.', 422);
        $money->multiply(1000);
        $this->db->transaction(function () use ($user, $storage, $revision, $limit, $money, $reason) {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $row = $locks->row('craft_storage', ['id' => $storage]);
            $old = (new Query())->from('world_warehouse_policy')->where(['storage_id' => $storage])->one($this->db);
            if (!$row || !$old || $row['status'] !== 'active' || (int)$old['revision'] !== $revision) throw new GameError('WAREHOUSE_CHANGED', 'Настройки изменились. Откройте форму заново.');
            if ($limit < (int)$row['capacity']) throw new GameError('PURCHASED_CAPACITY', 'Предел не может быть меньше уже доступной вместимости.', 422);
            $operation = bin2hex(random_bytes(16));
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $user, 'type' => 'world.warehouse.configure', 'created_at' => time()])->execute();
            $values = ['max_capacity' => $limit, 'base_price' => $money->decimal(), 'revision' => new Expression('[[revision]]+1')];
            if ($this->db->createCommand()->update('world_warehouse_policy', $values, ['storage_id' => $storage, 'revision' => $revision])->execute() !== 1) throw new GameError('WAREHOUSE_CHANGED', 'Настройки изменились.');
            $this->db->createCommand()->update('craft_storage', ['revision' => new Expression('[[revision]]+1')], ['id' => $storage])->execute();
            (new WorldTree($this->db))->audit($user, 'world.warehouse.configure', trim($reason), $old, ['id' => (int)$row['node_id'], 'storage_id' => $storage, 'max_capacity' => $limit, 'base_price' => $money->decimal()], $operation);
        });
    }
}
