<?php
namespace common\modules\world\models;

use common\modules\world\models\domain\BuildingFacilities;
use common\modules\world\models\domain\PlacementProvisioner;

final class BuildFacilities
{
    public static function provision(Node $node, string $operation): void
    {
        $db = Node::getDb();
        (new PlacementProvisioner($db))->interior($node);
        $accepted = json_decode($node->settings_json, true) ?: [];
        if ($node->node_type === 'CHEST' || isset($accepted['storage_capacity'])) {
            $db->createCommand()->insert('craft_storage', ['identity_key' => 'stockpile:object:' . $node->id, 'kind' => 'stockpile',
                'node_id' => $node->id, 'owner_user_id' => $node->owner_user_id, 'capacity' => $accepted['storage_capacity'] ?? 10])->execute();
        }
        (new PlacementProvisioner($db))->openObjectMap($node, $operation);
    }
}
