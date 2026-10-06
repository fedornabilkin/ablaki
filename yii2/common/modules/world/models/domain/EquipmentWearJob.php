<?php
namespace common\modules\world\models\domain;

use common\modules\world\modules\craft\models\domain\EquipmentExposure;
use common\modules\world\modules\craft\models\domain\StorageMaintenance;
use common\modules\world\support\CommandBus;
use common\modules\world\support\JobQueue;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Query;

/** Bounded discovery and owner-scoped settlement; browser reads never advance checkpoints. */
class EquipmentWearJob
{
    private $db;
    private $flags;
    public function __construct(Connection $db, WorldFlags $flags) { $this->db = $db; $this->flags = $flags; }
    private function candidates(): Query
    {
        return (new Query())->select(['e.id', 'i.user_id', 'x.settled_at', 'x.remainder', 'x.daily_wear'])->from(['e' => 'craft_equipment_instance'])
            ->innerJoin(['i' => 'craft_inventory'], '[[i.id]]=[[e.inventory_id]]')->innerJoin(['x' => 'craft_equipment_exposure'], '[[x.instance_id]]=[[e.id]]')
            ->where(['e.status' => 'active'])->andWhere(['>', 'i.item_quantity', 0])->andWhere(['>', 'e.durability', 0])->andWhere(['>', 'x.daily_wear', 0]);
    }
    private function schedule(array $row): void
    {
        $at = (int)$row['settled_at'] + (int)ceil((86400 - (int)$row['remainder']) / (int)$row['daily_wear']);
        (new JobQueue($this->db))->enqueue('world.equipment.wear', 'instance:' . $row['id'] . ':at:' . $at, ['instance_id' => (int)$row['id']], (int)$row['user_id'], $at);
    }
    /** Runs once per CLI invocation. Only schedules, never writes another owner's equipment. */
    public function discover(): int
    {
        return $this->db->transaction(function () {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $this->flags->requireFlag('world_write'); $this->flags->requireFlag('storage_v2');
            if (StorageMaintenance::frozen($this->db) || \common\modules\world\modules\economy\models\domain\WalletMaintenance::frozen($this->db)) return 0;
            $cursor = $locks->row('world_wear_scan', ['id' => 1]);
            if (!$cursor) throw new \LogicException('Equipment scan cursor missing.');
            $rows = $this->candidates()->andWhere(['>', 'e.id', (int)$cursor['after_instance_id']])->orderBy(['e.id' => SORT_ASC])->limit(100)->all($this->db);
            foreach ($rows as $row) $this->schedule($row);
            $next = count($rows) === 100 ? (int)end($rows)['id'] : 0;
            $this->db->createCommand()->update('world_wear_scan', ['after_instance_id' => $next], ['id' => 1])->execute();
            return count($rows);
        });
    }
    public function settle(array $payload, array $job): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Wear job requires the queue transaction.');
        $this->flags->requireFlag('world_write'); $this->flags->requireFlag('storage_v2');
        $id = (int)$payload['instance_id']; $row = $this->candidates()->andWhere(['e.id' => $id])->one($this->db);
        // Moving indoors, consuming a unit or changing owner makes the old scheduled job harmless.
        if (!$row || (int)$row['user_id'] !== (int)$job['owner_user_id']) return;
        $before = (new Query())->from('craft_equipment_instance')->where(['id' => $id])->one($this->db);
        $unit = (new EquipmentExposure($this->db))->settle($id);
        if ((int)$before['durability'] !== (int)$unit['durability']) {
            $operation = bin2hex(random_bytes(16));
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $row['user_id'], 'type' => 'world.equipment.wear', 'created_at' => time()])->execute();
            (new CommandBus($this->db, $this->flags))->emit($operation, (int)$row['user_id'], 'world.equipment.worn', ['instance_id' => $id, 'durability' => (int)$unit['durability'], 'broken' => (int)$unit['durability'] === 0]);
        }
        $next = $this->candidates()->andWhere(['e.id' => $id])->one($this->db);
        if ($next) $this->schedule($next);
    }
}
