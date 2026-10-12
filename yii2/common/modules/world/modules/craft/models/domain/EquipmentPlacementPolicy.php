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
        $furniture = $this->db->schema->getTableSchema('world_furnishing_rule') ? (new Query())->from('world_furnishing_rule')->where(['item_id' => $item['id']])->one($this->db) : null;
        if ((int)$slot['size'] < 1 || $slot['status'] !== 'active' || !(int)$item['active'] || (!$chest && !$station && !$furniture)
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
        $index = (int)$slot['position'] - 1; $width = (int)$node['map_width'];
        $x = (int)$node['map_origin_x'] + $index % $width; $y = (int)$node['map_origin_y'] + intdiv($index, $width);
        try { if ($index >= 0 && $index < $width * (int)$node['map_height']) (new \common\modules\world\models\domain\WorldTree($this->db))->assertFreePosition((int)$node['id'], $x, $y, null, false); }
        catch (\common\modules\world\support\GameError $error) { throw new ConflictHttpException($error->getMessage()); }
        if (isset($node['hierarchy_level'])) {
            if (!\common\modules\world\models\domain\NodeAvailability::activePath($this->db, (int)$node['id'])) throw new ConflictHttpException('Объект или его родитель недоступен для размещения.');
            if (!(new Query())->from('world_map_cell')->where(['parent_id' => $node['id'], 'x' => $x, 'y' => $y, 'state' => 'open'])->exists($this->db)) throw new ConflictHttpException('Сначала откройте ячейку для размещения.');
            return;
        }
        if ($furniture) {
            $exposures = json_decode($furniture['exposures_json'], true);
            if (!is_array($exposures) || !in_array($slot['exposure_class'], $exposures, true)) throw new ConflictHttpException('Предмет не подходит к условиям этого места.');
            if (!(new Query())->from('world_map_cell')->where(['parent_id' => $node['id'], 'x' => $x, 'y' => $y, 'state' => 'open'])->exists($this->db)) throw new ConflictHttpException('Сначала откройте ячейку для размещения.');
            return;
        }
        if ($node['node_type'] === 'PLOT') {
            if ($station) throw new ConflictHttpException('Станцию нельзя установить на стоянке. Выберите рабочее помещение или место внутри навеса, кузницы или мастерской.');
            if ($slot['exposure_class'] !== 'outdoor') throw new ConflictHttpException('Уличное место не даёт защиты помещения.');
        } elseif (in_array($node['node_type'], ['ROOM', 'PLACE'], true)) {
            $location = $node;
            if ($node['node_type'] === 'PLACE') $location = (new Query())->from('world_node')->where(['id' => $node['parent_id'], 'status' => 'active', 'node_type' => 'ROOM'])->one($this->db);
            if (!$location || $location['exposure_class'] !== $slot['exposure_class']) throw new ConflictHttpException('Защита места не соответствует помещению.');
            $building = (new Query())->from('world_node')->where(['id' => $location['parent_id'], 'status' => 'active', 'node_type' => 'BUILDING'])->one($this->db);
            if (!$building || (int)$building['owner_user_id'] !== (int)$node['owner_user_id']) throw new ConflictHttpException('Помещение оборудования недоступно.');
        } else throw new ConflictHttpException('Для установки выберите комнату или место внутри постройки.');
        if ($station && $this->db->schema->getTableSchema('world_housing_place')
            && (new Query())->from('world_housing_place')->where(['room_id' => $node['node_type'] === 'PLACE' ? $node['parent_id'] : $storage['node_id']])->exists($this->db)
            && ($codes === null || !in_array($item['code'], $codes, true))) {
            throw new ConflictHttpException('Станцию в жилой комнате можно разместить только при явном разрешении этого места.');
        }
    }
}
