<?php
namespace common\modules\world\controllers\legacy;

class WorldRoomController extends \common\modules\world\admin\WorldCrudController
{
    protected function nodeType(): string { return 'ROOM'; }
}
