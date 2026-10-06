<?php
namespace common\modules\world\controllers\legacy;

class WorldRootController extends \common\modules\world\admin\WorldCrudController
{
    protected function nodeType(): string { return 'WORLD'; }
}
