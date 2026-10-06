<?php
namespace common\modules\world\modules\craft\models\domain;

use yii\db\Connection;
use yii\db\Query;
use yii\web\ConflictHttpException;

/** The same server rule is checked on installation and every station selection. */
class EquipmentPlacementPolicy
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function assertAllowed(array $item, array $storage, array $slot): void
    {
        $chest = ($item['storage_kind'] ?? '') === 'chest';
        $station = (new Query())->from('craft_station')->where(['item_id' => $item['id'], 'active' => 1])->exists($this->db);
        if ((int)$slot['size'] < 1 || $slot['status'] !== 'active' || !(int)$item['active'] || (!$chest && !$station)
            || ($slot['slot_type'] !== 'equipment' && $slot['slot_type'] !== ($chest ? 'chest' : 'station'))) {
            throw new ConflictHttpException('Предмет не подходит для этого места.');
        }
        try { $compatibility = json_decode($slot['compatibility_json'], true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException $e) { throw new ConflictHttpException('Назначение места не подходит предмету.'); }
        if (!is_array($compatibility)) throw new ConflictHttpException('Назначение места не подходит предмету.');
        $codes = $compatibility['item_codes'] ?? null;
        if ($codes !== null && (!is_array($codes) || !in_array($item['code'], $codes, true))) throw new ConflictHttpException('Назначение места не подходит предмету.');
        if ($storage['node_id'] === null) throw new ConflictHttpException('Для размещения оборудования нужна локация мира.');
        $node = (new Query())->from('world_node')->where(['id' => $storage['node_id'], 'status' => 'active'])->one($this->db);
        if (!$node) throw new ConflictHttpException('Локация оборудования недоступна.');
        if ($node['node_type'] === 'PLOT') {
            if ($slot['exposure_class'] !== 'outdoor') throw new ConflictHttpException('Уличное место не даёт защиты помещения.');
        } elseif ($node['node_type'] === 'ROOM') {
            $room = (new Query())->from('world_room')->where(['node_id' => $storage['node_id']])->one($this->db);
            if (!$room || $room['exposure_class'] !== $slot['exposure_class']) throw new ConflictHttpException('Защита места не соответствует помещению.');
        } else throw new ConflictHttpException('Для установки выберите участок или комнату.');
        if ($station && $this->db->schema->getTableSchema('world_housing_place')
            && (new Query())->from('world_housing_place')->where(['room_id' => $storage['node_id']])->exists($this->db)
            && ($codes === null || !in_array($item['code'], $codes, true))) {
            throw new ConflictHttpException('Станцию в жилой комнате можно разместить только при явном разрешении этого места.');
        }
    }
}
