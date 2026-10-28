<?php
namespace common\modules\world\models\domain;

/** Retained gameplay extensions cannot change the common map, catalog or ownership rules. */
final class NodeFacilitiesDecorator extends NodePolicyDecorator
{
    public function extensions(array $node): array
    {
        $result = $this->wrapped->extensions($node);
        if (($node['plot_kind'] ?? '') === 'campsite') $result = array_merge($result, ['nights', 'gather']);
        if (($node['plot_kind'] ?? '') === 'garden') $result[] = 'garden';
        if (!empty($node['garden_node_id']) && !empty($node['unlocked'])) $result[] = 'cultivation';
        if (!empty($node['production_enabled'])) $result[] = 'mining';
        if (!empty($node['field_kitchen'])) $result[] = 'kitchen';
        if (in_array($node['node_type'], ['ROOM', 'PLACE', 'BUILDING'], true) && empty($node['shelter_instance_id'])) $result[] = 'housing';
        if ($node['node_type'] === 'ROOM') $result[] = 'equipment_expansion';
        if (!empty($node['storage_capacity'])) $result[] = 'harvest';
        return array_values(array_unique($result));
    }
}
