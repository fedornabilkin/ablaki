<?php
namespace common\modules\world\controllers\legacy;

class WorldBedController extends \common\modules\world\admin\WorldCrudController
{
    protected function nodeType(): string { return 'BED'; }
}
