<?php
namespace backend\widgets;

use backend\support\EntityDisplay;
use yii\widgets\DetailView;

class EntityDetailView extends DetailView
{
    protected function normalizeAttributes()
    {
        $original = $this->attributes;
        parent::normalizeAttributes();
        if (!($table = EntityDisplay::table($this->model))) return;
        EntityDisplay::warm([$this->model]);
        foreach ($this->attributes as $index => &$attribute) {
            if (!isset($attribute['attribute'])) continue;
            $relation = EntityDisplay::target($table, $attribute['attribute']);
            if (!$relation && is_array($original[$index] ?? null) && array_key_exists('value', $original[$index])) continue;
            if (substr($attribute['attribute'], -5) === '_json') {
                $json = json_decode((string)$attribute['value'], true);
                $attribute['value'] = \yii\helpers\Html::tag('pre', \yii\helpers\Html::encode($json === null ? (string)$attribute['value'] : json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)), ['style' => 'white-space:pre-wrap']);
                $attribute['format'] = 'raw'; continue;
            }
            $raw = $relation && $this->model->hasAttribute($attribute['attribute']) ? $this->model->getAttribute($attribute['attribute']) : $attribute['value'];
            $value = EntityDisplay::value($table, $attribute['attribute'], $raw, $this->model->getAttributes());
            if ($value !== null) { $attribute['value'] = $value; $attribute['format'] = 'raw'; }
        }
    }
}
