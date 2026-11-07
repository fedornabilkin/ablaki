<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 14.12.2021
 * Time: 22:13
 */

namespace backend\widgets\gridView\columns;

use common\models\user\UserRelationInterface;

class UserColumn extends AbstractColumn
{

    protected function makeCellContent($model): string
    {
        return $this->getValue($model);
    }

    public function getValue(UserRelationInterface $model): string
    {
        return \backend\support\EntityDisplay::value(\backend\support\EntityDisplay::table($model), 'user_id', $model->user_id, $model->attributes) ?? '—';
    }
}
