<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 13:21
 */

namespace backend\widgets\gridView\columns;

class CreatedAtColumn extends AbstractColumn
{

    protected function makeCellContent($model): string
    {
        return date('H:i:s d.m.y', $model->created_at + 10800);
    }
}
