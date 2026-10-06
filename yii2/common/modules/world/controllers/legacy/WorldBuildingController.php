<?php
namespace common\modules\world\controllers\legacy;

class WorldBuildingController extends \common\modules\world\admin\WorldCrudController
{
    protected function nodeType(): string { return 'BUILDING'; }
}
