<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 18.03.2023
 * Time: 13:17
 */

namespace common\modules\craft\widgets\columns;

use backend\widgets\gridView\columns\AbstractColumn;
use common\modules\craft\interfaces\CraftItemRelationInterface;
use yii\helpers\Html;

class CraftItemColumn extends AbstractColumn
{

    protected function makeCellContent($model): string
    {
        return $this->getValue($model);
    }

    public function getValue(CraftItemRelationInterface $model): string
    {
        return Html::a($model->item->name, ['/craft/item/update', 'id' => $model->item->id]);
    }
}
