<?php
namespace common\modules\world\models\domain;

interface WorldVisitor
{
    public function visit(WorldComponent $component): array;
}
