<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 18.03.2023
 * Time: 13:17
 */

namespace common\modules\world\modules\craft\widgets\columns;

use backend\widgets\gridView\columns\AbstractColumn;
use common\modules\world\modules\craft\interfaces\CraftItemRelationInterface;

class CraftItemColumn extends AbstractColumn
{

    protected function makeCellContent($model): string
    {
        return $this->getValue($model);
    }

    public function getValue(CraftItemRelationInterface $model): string
    {
        return \backend\support\EntityDisplay::value(\backend\support\EntityDisplay::table($model), 'item_id', $model->item_id, $model->attributes) ?? '—';
    }
}
