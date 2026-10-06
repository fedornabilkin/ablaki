<?php
namespace common\modules\world\controllers\legacy;

class WorldRegionController extends \common\modules\world\admin\WorldCrudController
{
    protected function nodeType(): string { return 'REGION'; }
}
