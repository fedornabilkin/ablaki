<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.07.2018
 * Time: 22:37
 */

namespace common\utilities\widgets\gridview;


use yii\grid\DataColumn;

abstract class AbstractColumn extends DataColumn
{

    public function init()
    {
        $this->content = [$this, 'makeCellContent'];
    }

    abstract protected function makeCellContent($model);
}