<?php
namespace common\modules\world\models\domain;

/** Shared state, ownership and structural capabilities for world operations. */
final class CommonNodePolicy implements NodePolicy
{
    public function permitsChild(array $parent): bool { return !\common\modules\world\domain\NodeStructure::isLeaf($parent) && $parent['status'] === 'active' && (int)$parent['depth'] < 32; }
    public function extensions(array $node): array { return []; }
    public function actions(array $node, int $user): array
    {
        $owned = $user > 0 && (int)$node['owner_user_id'] === $user;
        $active = $node['status'] === 'active'; $result = [];
        foreach (['open', 'explore', 'buy', 'build', 'repair', 'storage', 'craft', 'finance'] as $code) {
            $reasons = [];
            if ($code !== 'open' && !$owned) $reasons[] = ['code' => 'OWNER_REQUIRED', 'message' => 'Требуются права владельца.'];
            if ($code !== 'open' && $code !== 'finance' && !$active) $reasons[] = ['code' => 'NODE_UNAVAILABLE', 'message' => 'Сначала завершите строительство.'];
            if ($code === 'build' && !$this->permitsChild($node)) $reasons[] = ['code' => 'INVALID_PARENT', 'message' => 'В этом объекте нельзя размещать дочерние постройки.'];
            if ($code === 'finance' && \common\modules\world\domain\NodeStructure::isLeaf($node)) $reasons[] = ['code' => 'PARENT_BUDGET_REQUIRED', 'message' => 'Листовой объект использует финансы родителя.'];
            if ($code === 'repair' && (int)$node['condition'] >= (int)$node['max_condition']) $reasons[] = ['code' => 'REPAIR_NOT_REQUIRED', 'message' => 'Объект не требует ремонта.'];
            if ($code === 'repair' && !empty($node['repair_active'])) $reasons[] = ['code' => 'REPAIR_ALREADY_STARTED', 'message' => 'Ремонт уже выполняется.'];
            $result[] = ['code' => $code, 'allowed' => !$reasons, 'reasons' => $reasons];
        }
        return $result;
    }
}
