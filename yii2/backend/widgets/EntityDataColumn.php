<?php
namespace backend\widgets;

use backend\support\EntityDisplay;
use yii\grid\DataColumn;

class EntityDataColumn extends DataColumn
{
    protected function renderDataCellContent($model, $key, $index)
    {
        $table = EntityDisplay::table($model);
        $relation = $table && $this->attribute && EntityDisplay::target($table, $this->attribute);
        if ($this->attribute && $table && ($this->value === null || $relation)) {
            $grid = spl_object_hash($this->grid);
            if (empty(\Yii::$app->params['entityDisplayGrids'][$grid])) {
                EntityDisplay::warm($this->grid->dataProvider->getModels());
                \Yii::$app->params['entityDisplayGrids'][$grid] = true;
            }
            $raw = $relation && $model->hasAttribute($this->attribute) ? $model->getAttribute($this->attribute) : $this->getDataCellValue($model, $key, $index);
            $value = EntityDisplay::value($table, $this->attribute, $raw, $model->getAttributes());
            if ($value !== null) return $value;
        }
        return parent::renderDataCellContent($model, $key, $index);
    }
}
