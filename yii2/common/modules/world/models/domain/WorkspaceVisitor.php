<?php
namespace common\modules\world\models\domain;

/** Traverses the composite uniformly and stops at unavailable or differently owned branches. */
final class WorkspaceVisitor implements WorldVisitor
{
    private $owner;
    public function __construct(int $owner) { $this->owner = $owner; }
    public function visit(WorldComponent $component): array
    {
        $row = $component->record();
        if ((int)$row['owner_user_id'] !== $this->owner || $row['status'] !== 'active' || (isset($row['condition']) && (int)$row['condition'] < 1)) return [];
        $result = [(int)$row['id']];
        foreach ($component->children() as $child) $result = array_merge($result, $child->accept($this));
        return $result;
    }
}
