<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 14.12.2021
 * Time: 22:09
 */

namespace backend\widgets\gridView\columns;

use yii\grid\DataColumn;

abstract class AbstractColumn extends DataColumn
{
    public function init(): void
    {
        $this->content = [$this, 'makeCellContent'];
    }

    abstract protected function makeCellContent($model): string;
}
