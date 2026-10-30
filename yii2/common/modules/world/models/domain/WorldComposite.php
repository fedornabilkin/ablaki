<?php
namespace common\modules\world\models\domain;

/** A bounded, preloaded tree: traversal never issues a database query. */
final class WorldComposite implements WorldComponent
{
    private $record;
    private $children;
    public function __construct(array $record, array $children = [])
    {
        foreach ($children as $child) if (!$child instanceof WorldComponent) throw new \InvalidArgumentException('World children must implement WorldComponent.');
        $this->record = $record; $this->children = $children;
    }
    public function record(): array { return $this->record; }
    public function children(): array { return $this->children; }
    public function accept(WorldVisitor $visitor): array { return $visitor->visit($this); }
    public static function tree(array $rows, int $root): self
    {
        $byId = []; $children = [];
        foreach ($rows as $row) {
            $id = (int)$row['id'];
            if ($id < 1 || isset($byId[$id])) throw new \LogicException('Invalid world tree: duplicate or invalid ID.');
            $byId[$id] = $row; $children[(int)$row['parent_id']][] = $id;
        }
        $build = function (int $id, array $path) use (&$build, $byId, $children): self {
            if (isset($path[$id]) || count($path) > 32) throw new \LogicException('Invalid world tree: cycle or depth limit.');
            if (!isset($byId[$id])) throw new \LogicException('Missing world component.');
            $path[$id] = true; $nodes = [];
            foreach ($children[$id] ?? [] as $child) $nodes[] = $build($child, $path);
            return new self($byId[$id], $nodes);
        };
        return $build($root, []);
    }
}
