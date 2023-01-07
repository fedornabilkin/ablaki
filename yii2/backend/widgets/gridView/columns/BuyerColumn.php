<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 14:46
 */

namespace backend\widgets\gridView\columns;

use common\models\user\BuyerRelationInterface;
use yii\helpers\Html;

class BuyerColumn extends AbstractColumn
{

    protected function makeCellContent($model): string
    {
        return $this->getValue($model);
    }

    public function getValue(BuyerRelationInterface $model): string
    {
        return Html::a($model->userBuyer->username, ['/person/view', 'id' => $model->userBuyer->person->id]);
    }
}
