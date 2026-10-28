<?php
namespace common\modules\world\controllers\legacy;

class WorldSettlementController extends \common\modules\world\admin\WorldCrudController
{
    protected function nodeType(): string { return 'SETTLEMENT'; }
}
