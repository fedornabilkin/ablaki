<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 14:16
 */

namespace backend\widgets\gridView\columns;

class UpdatedAtColumn extends AbstractColumn
{

    protected function makeCellContent($model): string
    {
        return date('H:i:s d.m.y', $model->updated_at);
    }
}
