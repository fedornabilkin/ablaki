<?php
namespace common\modules\world\controllers\legacy;

class WorldPlotController extends \common\modules\world\admin\WorldCrudController
{
    protected function nodeType(): string { return 'PLOT'; }
}
