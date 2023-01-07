<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 14.12.2021
 * Time: 22:13
 */

namespace backend\widgets\gridView\columns;

use common\models\user\UserRelationInterface;
use yii\helpers\Html;

class UserColumn extends AbstractColumn
{

    protected function makeCellContent($model): string
    {
        return $this->getValue($model);
    }

    public function getValue(UserRelationInterface $model): string
    {
        return Html::a($model->user->username, ['/person/view', 'id' => $model->user->person->id]);
    }
}
