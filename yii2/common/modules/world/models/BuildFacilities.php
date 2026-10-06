<?php
namespace common\modules\world\models;

use common\modules\world\models\domain\BuildingFacilities;
use common\modules\world\models\domain\PlacementProvisioner;

final class BuildFacilities
{
    public static function provision(Node $node, string $operation): void
    {
        $db = Node::getDb();
        if ((int)$node->hierarchy_level === 4) (new PlacementProvisioner($db))->campsite((int)$node->owner_user_id, (int)$node->id);
        if ($node->node_type === 'BUILDING') BuildingFacilities::stockpile($db, (int)$node->id, (int)$node->owner_user_id, (string)$node->building_kind);
        if ($node->node_type === 'PLOT' && $node->plot_kind === 'garden') domain\GardenHarvest::provision($db, (int)$node->id, (int)$node->owner_user_id);
        if ($node->node_type === 'ROOM' && $node->parent->building_kind === 'house') {
            $db->createCommand()->insert('world_housing_place', ['room_id' => $node->id, 'plot_id' => $node->parent->parent_id,
                'purchase_id' => null, 'operation_id' => $operation, 'created_at' => time()])->execute();
        }
        if ($node->node_type === 'CHEST') {
            $db->createCommand()->insert('craft_storage', ['identity_key' => 'stockpile:object:' . $node->id, 'kind' => 'stockpile',
                'node_id' => $node->id, 'owner_user_id' => $node->owner_user_id, 'capacity' => 10])->execute();
        }
    }
}
