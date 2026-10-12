<?php
namespace common\modules\world\models\domain;

/** Leaves and containers have the same contract. Children must already be access-filtered. */
interface WorldComponent
{
    public function record(): array;
    public function children(): array;
    public function accept(WorldVisitor $visitor): array;
}
