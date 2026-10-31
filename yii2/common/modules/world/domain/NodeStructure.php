<?php
namespace common\modules\world\domain;

/** Structural leaves still hold items; they delegate money to their nearest composite ancestor. */
final class NodeStructure
{
    public static function isLeaf(array $node): bool
    {
        $settings = json_decode($node['settings_json'] ?? '{}', true) ?: [];
        return in_array($node['node_type'], ['CHEST', 'PLACE'], true) || !empty($settings['storage_capacity']);
    }
}
