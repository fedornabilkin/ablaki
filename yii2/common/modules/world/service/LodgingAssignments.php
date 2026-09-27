<?php
namespace common\modules\world\service;

use yii\db\Connection;
use yii\db\Query;

/** Shared occupancy check. All lodging writers run under the CommandBus owner/registry locks. */
class LodgingAssignments
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function current(int $actor): ?array
    {
        $shelter = (new Query())->select(['l.id', 'l.started_at', 'node_id' => 'd.plot_id'])->from(['l' => 'world_lodging_interval'])
            ->innerJoin(['d' => 'world_shelter_deployment'], '[[d.id]]=[[l.deployment_id]]')->where(['l.active_actor_id' => $actor])->one($this->db);
        $house = (new Query())->select(['l.id', 'l.started_at', 'node_id' => 'p.room_id'])->from(['l' => 'world_housing_interval'])
            ->innerJoin(['p' => 'world_housing_place'], '[[p.id]]=[[l.place_id]]')->where(['l.active_actor_id' => $actor])->one($this->db);
        if ($shelter && $house) throw new \LogicException('Conflicting lodging assignments require reconciliation.');
        $row = $shelter ?: $house;
        return $row ? ['kind' => $shelter ? 'shelter' : 'house', 'node_id' => (int)$row['node_id'], 'interval_id' => (int)$row['id'], 'started_at' => (int)$row['started_at']] : null;
    }
}
