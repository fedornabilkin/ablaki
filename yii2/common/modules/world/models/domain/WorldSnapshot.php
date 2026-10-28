<?php
namespace common\modules\world\models\domain;

/** Builds only the already authorised, batch-loaded page branch. No SQL or lazy relations. */
final class WorldSnapshot
{
    public static function fromPage(array $page): array
    {
        $navigation = $page['navigation'];
        $result = ['contract_version' => 1, 'tree_version' => 1, 'server_time' => $page['server_time'],
            'capabilities' => $page['capabilities'], 'focus_id' => $navigation ? $navigation['node']['id'] : null, 'world' => null];
        if (!$navigation) return $result;
        $focus = $navigation['node']['id']; $rows = [];
        foreach (array_merge($navigation['breadcrumbs'], $navigation['siblings']['items'], $page['map']['items'], [$navigation['node']]) as $node) $rows[$node['id']] = $node;
        $children = [];
        foreach ($rows as $node) if ($node['parent_id'] !== null && isset($rows[$node['parent_id']])) $children[$node['parent_id']][] = $node['id'];
        $root = $navigation['breadcrumbs'][0]['id'] ?? $focus;
        $build = function (int $id, array $path) use (&$build, $rows, $children, $focus, $page): array {
            if (isset($path[$id]) || count($path) >= 33) throw new \LogicException('Invalid world snapshot hierarchy.');
            $path[$id] = true; $node = $rows[$id]; $details = (array)$node['details']; $nested = [];
            foreach ($children[$id] ?? [] as $child) $nested[] = $build($child, $path);
            $map = $node['map'];
            if ($id === $focus) foreach (['cells', 'equipment_cells', 'can_expand', 'exploration', 'pricing'] as $key) if (array_key_exists($key, $page['map'])) $map[$key] = $page['map'][$key];
            return ['id' => $node['id'], 'parent_id' => $node['parent_id'], 'root_id' => $node['root_id'], 'type' => $node['type'],
                'code' => $node['code'], 'name' => $node['name'], 'label' => $node['label'], 'children' => $nested,
                // A partial ancestor branch must never be mistaken for the complete world.
                'children_loaded' => $id === $focus || count($nested) === $node['child_count'],
                'child_count' => $node['child_count'], 'descendant_count' => $node['descendant_count'], 'population_total' => $node['population_total'],
                'extensions' => $node['extensions'] ?? [],
                'geo' => ['coordinates' => $node['coordinates'], 'footprint' => $node['footprint']],
                'state' => ['status' => $node['status'], 'visibility' => $node['visibility'], 'revision' => $node['revision'],
                    'portable' => $node['portable'], 'details' => $node['details'], 'permissions' => $node['permissions'], 'actions' => $node['actions']],
                'construction' => ['condition' => $details['condition'] ?? null, 'max_condition' => $details['max_condition'] ?? null, 'repair_active' => $details['repair_active'] ?? false],
                'owner' => $node['owner'] ?? ['id' => null, 'owned_by_me' => $node['owned_by_me'], 'has_finances' => $node['has_finances']], 'map' => $map];
        };
        $result['world'] = $build((int)$root, []);
        return $result;
    }
}
