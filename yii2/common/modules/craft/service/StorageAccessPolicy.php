<?php
namespace common\modules\craft\service;

use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldQuery;
use yii\db\Connection;
use yii\db\Query;
use yii\web\NotFoundHttpException;
use yii\web\ConflictHttpException;

/** Public visibility and world administration do not grant access to another player's items. */
class StorageAccessPolicy
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function storage(int $user, int $id, bool $incoming = false, int $depth = 0): array
    {
        if ($depth > 2) throw new ConflictHttpException('Некорректная вложенность хранилищ.');
        $row = (new Query())->from('craft_storage')->where(['id' => $id, 'owner_user_id' => $user, 'status' => 'active'])->one($this->db);
        if (!$row) throw new NotFoundHttpException('Хранилище не найдено или недоступно.');
        if (!in_array($row['kind'], ['backpack', 'chest', 'placement', 'stockpile', 'recovery'], true)) throw new ConflictHttpException('Неизвестное назначение хранилища.');
        if ($incoming && $row['kind'] === 'recovery') throw new ConflictHttpException('Из восстановления можно только забирать вещи.');
        if ($row['node_id'] !== null) {
            $node = (new WorldQuery($this->db, new WorldAccessPolicy($user)))->record((int)$row['node_id']);
            if ((int)$node['owner_user_id'] !== $user) throw new NotFoundHttpException('Хранилище недоступно.');
            if ($incoming && $node['status'] !== 'active') throw new ConflictHttpException('Помещение недоступно.');
            if ($incoming && (new Query())->from(['b' => 'world_building'])->innerJoin(['p' => 'world_node_closure'], '[[p.ancestor_id]]=[[b.node_id]]')->where(['p.descendant_id' => $node['id']])->andWhere(['or', ['<>', 'b.operational_status', 'active'], ['<', 'b.condition', 1]])->exists($this->db)) throw new ConflictHttpException('Постройка недоступна для работы.');
            if ($incoming && in_array($node['node_type'], ['BUILDING', 'ROOM'], true)) {
                $building = (new Query())->from('world_building')->where(['node_id' => $node['node_type'] === 'BUILDING' ? $node['id'] : $node['parent_id']])->one($this->db);
                if (!$building || $building['operational_status'] !== 'active' || (int)$building['condition'] < 1) throw new ConflictHttpException('Постройка недоступна для размещения.');
            }
        }
        if ($row['kind'] === 'chest') {
            $chest = (new Query())->from('craft_inventory')->where(['id' => $row['container_inventory_id'], 'user_id' => $user, 'item_quantity' => 1])->one($this->db);
            if (!$chest) throw new NotFoundHttpException('Сундук недоступен.');
            $outer = $this->storage($user, (int)$chest['storage_id'], $incoming, $depth + 1);
            if ($outer['kind'] === 'chest') throw new ConflictHttpException('Сундук нельзя хранить в другом сундуке.');
            $container = (new Query())->from('craft_container')->where(['id' => $chest['id'], 'user_id' => $user])->one($this->db);
            if (!$container || ($incoming && (int)$container['durability'] < 1)) throw new ConflictHttpException('Сундук изношен или недоступен.');
            $row['capacity'] = (int)$container['capacity'];
        }
        if ($row['kind'] === 'backpack') $row['capacity'] = (new CraftInventory(new CraftStorage($this->db)))->capacity($user)['active_slots'];
        return $row;
    }
}
