<?php
/**
 * Created by PhpStorm.
 * User: TOSHIBA-PC
 * Date: 27.07.2018
 * Time: 23:05
 */

namespace common\utilities\widgets\gridview;


use yii\helpers\Html;
use yii\helpers\Url;

class TestColumn extends AbstractColumn
{

    protected function makeCellContent($model)
    {
        return Html::a('Test', Url::to(['test', 'id' => $model->id]), [
            'data-request' => 'ajax',
            'data-handler' => 'ModelTest',
        ]);
    }
}