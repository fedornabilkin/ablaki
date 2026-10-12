<?php
namespace common\modules\world\models\domain;

interface NodePolicy
{
    public function actions(array $node, int $user): array;
    public function permitsChild(array $parent): bool;
    public function extensions(array $node): array;
}
