<?php
namespace common\modules\world\models\domain;

/** Extension point for later customization; no decorators are enabled in the MVP. */
abstract class NodePolicyDecorator implements NodePolicy
{
    protected $wrapped;
    public function __construct(NodePolicy $wrapped) { $this->wrapped = $wrapped; }
    public function actions(array $node, int $user): array { return $this->wrapped->actions($node, $user); }
    public function permitsChild(array $parent): bool { return $this->wrapped->permitsChild($parent); }
    public function extensions(array $node): array { return $this->wrapped->extensions($node); }
}
