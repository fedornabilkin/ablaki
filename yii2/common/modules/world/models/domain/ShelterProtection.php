<?php
namespace common\modules\world\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** Repair starts a new protection interval, never extends the old one retroactively. */
class ShelterProtection
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function current(int $deployment): array
    {
        $row = (new Query())->from('world_shelter_protection')->where(['active_deployment_id' => $deployment])->one($this->db);
        if (!$row) throw new \common\modules\world\support\GameError('SHELTER_PROTECTION_UNAVAILABLE', 'История защиты шалаша требует сверки.');
        return $row;
    }
    public function close(int $deployment, int $at): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Protection writes require a transaction.');
        $row = $this->current($deployment);
        if ($at < (int)$row['started_at']) throw new \LogicException('Protection time moved backwards.');
        if ($this->db->createCommand()->update('world_shelter_protection', ['ended_at' => $at, 'active_deployment_id' => null], ['id' => $row['id'], 'ended_at' => null])->execute() !== 1) throw new \RuntimeException('Protection close failed.');
    }
    public function open(int $deployment, int $instance, string $operation): void
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Protection writes require a transaction.');
        $unit = (new Query())->from('craft_equipment_instance')->where(['id' => $instance, 'status' => 'active'])->one($this->db);
        $wear = (new Query())->from('craft_equipment_exposure')->where(['instance_id' => $instance])->one($this->db);
        if (!$unit || !$wear || (int)$unit['durability'] < 1 || (int)$wear['daily_wear'] < 1 || $wear['exposure_class'] !== 'outdoor') throw new \LogicException('Expected a sound outdoor shelter.');
        $work = (int)$unit['durability'] * 86400 - (int)$wear['remainder']; $rate = (int)$wear['daily_wear'];
        $this->db->createCommand()->insert('world_shelter_protection', ['deployment_id' => $deployment, 'active_deployment_id' => $deployment,
            'started_at' => (int)$wear['settled_at'], 'protected_until' => (int)$wear['settled_at'] + intdiv($work + $rate - 1, $rate),
            'durability_at_start' => (int)$unit['durability'], 'wear_remainder' => (int)$wear['remainder'], 'daily_wear' => $rate, 'policy_version' => (int)$wear['policy_version'], 'operation_id' => $operation])->execute();
    }
}
