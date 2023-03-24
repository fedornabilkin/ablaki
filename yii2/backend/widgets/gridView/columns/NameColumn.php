<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 18.03.2023
 * Time: 17:56
 */

namespace backend\widgets\gridView\columns;

use common\models\ModelNameInterface;
use yii\helpers\Html;

class NameColumn extends AbstractColumn
{

    protected function makeCellContent($model): string
    {
        return $this->getValue($model);
    }

    public function getValue(ModelNameInterface $model): string
    {
        return Html::a($model->name(), ['update', 'id' => $model->id]);
    }
}
