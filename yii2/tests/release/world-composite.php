<?php
// Pure domain checks: no application bootstrap, database or player data.
require_once __DIR__ . '/../../common/modules/world/models/domain/WorldComponent.php';
require_once __DIR__ . '/../../common/modules/world/models/domain/WorldVisitor.php';
require_once __DIR__ . '/../../common/modules/world/models/domain/WorldComposite.php';
require_once __DIR__ . '/../../common/modules/world/models/domain/WorkspaceVisitor.php';

use common\modules\world\models\domain\WorldComposite;
use common\modules\world\models\domain\WorkspaceVisitor;

function check($condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function rejected(callable $build): void {
    try { $build(); } catch (LogicException $e) { return; }
    throw new RuntimeException('Malformed tree accepted');
}
$row = fn(int $id, ?int $parent, int $owner = 1, string $status = 'active') => ['id' => $id, 'parent_id' => $parent, 'owner_user_id' => $owner, 'status' => $status, 'condition' => 100];
$rows = [$row(1, null), $row(2, 1), $row(3, 1, 2), $row(4, 3), $row(5, 1, 1, 'archived'), $row(6, 5), $row(7, 2)];
$tree = WorldComposite::tree($rows, 1);
check($tree->accept(new WorkspaceVisitor(1)) === [1, 2, 7], 'Visitor must prune foreign and archived branches');
check((new WorldComposite($row(8, null)))->accept(new WorkspaceVisitor(1)) === [8], 'Leaf uses the same visitor contract');
rejected(fn() => WorldComposite::tree([$row(1, null), $row(1, null)], 1));
rejected(fn() => WorldComposite::tree([$row(1, 2), $row(2, 1)], 1));
rejected(fn() => WorldComposite::tree([], 1));
rejected(fn() => new WorldComposite($row(1, null), [new stdClass()]));
$deep = []; for ($i = 1; $i <= 35; $i++) $deep[] = $row($i, $i === 1 ? null : $i - 1);
rejected(fn() => WorldComposite::tree($deep, 1));
echo "World composite/visitor checks passed\n";
