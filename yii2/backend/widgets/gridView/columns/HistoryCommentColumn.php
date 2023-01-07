<?php
/**
 * Created by PhpStorm.
 * User: fedornabilkin
 * Date: 07.01.2023
 * Time: 13:52
 */

namespace backend\widgets\gridView\columns;

class HistoryCommentColumn extends AbstractColumn
{
    protected function makeCellContent($model): string
    {
        return $this->getValue($model);
    }

    public function getValue($model): string
    {
//        return Html::a($model->user->username, ['/person/view', 'id' => $model->user->person->id]);
        return $model->comment;
    }
}
