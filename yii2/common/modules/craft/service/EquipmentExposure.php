<?php
namespace common\modules\craft\service;

use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;
use yii\web\ConflictHttpException;

/** Policy v1 draft: 4/1/0 per day outdoors/under cover/indoors. No browser clock. */
class EquipmentExposure
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    private function progress(array $unit, ?array $exposure, int $now): array
    {
        if (!$exposure) return ['durability' => (int)$unit['durability'], 'remainder' => 0, 'settled_at' => $now];
        $now = max($now, (int)$exposure['settled_at']);
        $work = ($now - (int)$exposure['settled_at']) * (int)$exposure['daily_wear'] + (int)$exposure['remainder'];
        return ['durability' => max(0, (int)$unit['durability'] - intdiv($work, 86400)), 'remainder' => $work % 86400, 'settled_at' => $now];
    }
    public function projected(array $unit, ?int $at = null): array
    {
        $exposure = (new Query())->from('craft_equipment_exposure')->where(['instance_id' => $unit['id']])->one($this->db) ?: null;
        return array_merge($unit, $this->progress($unit, $exposure, $at ?? time()), ['exposure_class' => $exposure['exposure_class'] ?? 'carried']);
    }
    public function projectedBatch(array $units): array
    {
        if (!$units) return [];
        $exposures = (new Query())->from('craft_equipment_exposure')->where(['instance_id' => array_column($units, 'id')])->indexBy('instance_id')->all($this->db);
        $now = time(); $result = [];
        foreach ($units as $unit) {
            $exposure = $exposures[$unit['id']] ?? null;
            $result[] = array_merge($unit, $this->progress($unit, $exposure, $now), ['exposure_class' => $exposure['exposure_class'] ?? 'carried']);
        }
        return $result;
    }
    public function settle(int $id, ?int $at = null): array
    {
        if (!$this->db->getTransaction()) throw new \LogicException('Wear settlement requires the inventory transaction.');
        $unit = (new Query())->from('craft_equipment_instance')->where(['id' => $id, 'status' => 'active'])->one($this->db);
        if (!$unit) throw new ConflictHttpException('Экземпляр недоступен.');
        $exposure = (new Query())->from('craft_equipment_exposure')->where(['instance_id' => $id])->one($this->db) ?: null;
        $next = $this->progress($unit, $exposure, $at ?? time());
        if ($next['durability'] !== (int)$unit['durability']) $this->db->createCommand()->update('craft_equipment_instance', ['durability' => $next['durability'], 'revision' => new Expression('[[revision]]+1')], ['id' => $id])->execute();
        if ($exposure) $this->db->createCommand()->update('craft_equipment_exposure', ['settled_at' => $next['settled_at'], 'remainder' => $next['remainder']], ['instance_id' => $id])->execute();
        return array_merge($unit, $next, ['exposure_class' => $exposure['exposure_class'] ?? 'carried']);
    }
    public function location(array $ids, array $storage, int $position): void
    {
        $class = 'carried';
        if ($storage['kind'] === 'shelter') $class = 'outdoor';
        if ($storage['kind'] === 'placement') {
            $class = (string)(new Query())->select('exposure_class')->from('world_slot')->where(['storage_id' => $storage['id'], 'position' => $position])->scalar($this->db);
            if (!in_array($class, ['outdoor', 'covered', 'indoor'], true)) throw new ConflictHttpException('Не настроена защита места размещения.');
        }
        $rates = ['carried' => 0, 'outdoor' => 4, 'covered' => 1, 'indoor' => 0];
        foreach ($ids as $id) {
            $unit = $this->settle($id);
            $values = ['exposure_class' => $class, 'daily_wear' => $rates[$class], 'settled_at' => $unit['settled_at'], 'remainder' => $unit['remainder'], 'policy_version' => 1];
            $where = ['instance_id' => $id];
            if ((new Query())->from('craft_equipment_exposure')->where($where)->exists($this->db)) $this->db->createCommand()->update('craft_equipment_exposure', $values, $where)->execute();
            else $this->db->createCommand()->insert('craft_equipment_exposure', $where + $values)->execute();
        }
    }
    public function cost(array $unit, int $base, int $batches = 1, bool $station = false): int
    {
        $outdoor = $station && $unit['exposure_class'] === 'outdoor';
        return $batches * ($base * ($outdoor ? 2 : 1) + ($outdoor ? 1 : 0));
    }
    /** Caller has paid the approved material plan under owner/catalog/registry locks. */
    public function restore(int $id, int $damage, int $at): array
    {
        $unit = $this->settle($id, $at);
        if ($damage < 1 || (int)$unit['max_durability'] - (int)$unit['durability'] !== $damage) throw new ConflictHttpException('Повреждение изменилось. Повторите расчёт ремонта.');
        if ($this->db->createCommand()->update('craft_equipment_instance', ['durability' => (int)$unit['max_durability'], 'revision' => new Expression('[[revision]]+1')], ['id' => $id, 'status' => 'active'])->execute() !== 1) throw new \RuntimeException('Equipment repair failed.');
        return array_merge($unit, ['durability' => (int)$unit['max_durability']]);
    }
    public function use(int $id, int $base, int $batches, bool $station): void
    {
        $unit = $this->settle($id); $cost = $this->cost($unit, $base, $batches, $station);
        if ((int)$unit['durability'] < max(1, $cost)) throw new ConflictHttpException('Прочности оборудования недостаточно для работы.');
        if ($cost) $this->db->createCommand()->update('craft_equipment_instance', ['durability' => (int)$unit['durability'] - $cost, 'revision' => new Expression('[[revision]]+1')], ['id' => $id])->execute();
    }
}
