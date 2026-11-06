<?php
namespace backend\widgets;

use backend\support\EntityDisplay;
use yii\grid\DataColumn;

class EntityDataColumn extends DataColumn
{
    public ?string $entityTable = null;
    protected function renderDataCellContent($model, $key, $index)
    {
        $table = $this->entityTable ?? EntityDisplay::table($model);
        $relation = $table && $this->attribute && EntityDisplay::target($table, $this->attribute);
        if ($this->attribute && $table && ($this->value === null || $relation)) {
            $grid = spl_object_hash($this->grid);
            if (empty(\Yii::$app->params['entityDisplayGrids'][$grid])) {
                EntityDisplay::warm($this->grid->dataProvider->getModels(), $table);
                \Yii::$app->params['entityDisplayGrids'][$grid] = true;
            }
            $row = is_array($model) ? $model : $model->getAttributes();
            $raw = $relation && array_key_exists($this->attribute, $row) ? $row[$this->attribute] : $this->getDataCellValue($model, $key, $index);
            $value = EntityDisplay::value($table, $this->attribute, $raw, $row);
            if ($value !== null) return $value;
        }
        return parent::renderDataCellContent($model, $key, $index);
    }
}
