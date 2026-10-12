<?php
namespace backend\widgets;

use backend\assets\EntitySelectAsset;
use backend\support\EntityDisplay;
use Yii;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\widgets\ActiveField;

class EntityActiveField extends ActiveField
{
    public function dropDownList($items, $options = [])
    {
        if (count($items) >= 2 && count($items) <= 5 && empty($options['multiple']) && empty($options['data-searchable'])
            && !EntityDisplay::target(EntityDisplay::table($this->model), Html::getAttributeName($this->attribute))) {
            $prompt = $options['prompt'] ?? null; unset($options['prompt']);
            if ($prompt !== null) $items = ['' => $prompt] + $items;
            return $this->radioList($items, $options);
        }
        if (count($items) > 5 || !empty($options['data-searchable']) || EntityDisplay::target(EntityDisplay::table($this->model), Html::getAttributeName($this->attribute))) {
            $options['class'] = ($options['class'] ?? 'form-control') . ' entity-search';
        }
        return parent::dropDownList($items, $options);
    }
    public function textInput($options = [])
    {
        $attribute = Html::getAttributeName($this->attribute);
        if (EntityDisplay::creditField($attribute)) {
            $value = $options['data-credit-original'] ?? $options['value'] ?? Html::getAttributeValue($this->model, $this->attribute);
            if ($value !== null && $value !== '' && is_numeric($value)) {
                $options['value'] = EntityDisplay::credits($value);
                $options['data-credit-original'] = (string)$value;
                $options['data-credit-display'] = $options['value'];
            }
            if (isset($options['placeholder']) && is_numeric($options['placeholder'])) $options['placeholder'] = EntityDisplay::credits($options['placeholder']);
            $options['inputmode'] = 'decimal';
        }
        return $this->referenceInput($options) ?: parent::textInput($options);
    }
    public function input($type, $options = [])
    {
        return in_array($type, ['number', 'text'], true) ? ($this->referenceInput($options) ?: parent::input($type, $options)) : parent::input($type, $options);
    }
    private function referenceInput(array $options)
    {
        $attribute = Html::getAttributeName($this->attribute);
        $table = EntityDisplay::target(EntityDisplay::table($this->model), $attribute);
        if (!$table) return null;
        $schema = Yii::$app->db->schema->getTableSchema($table);
        if (!$schema || count($schema->primaryKey) !== 1) return null;
        unset($options['min'], $options['max'], $options['maxlength']);
        $value = Html::getAttributeValue($this->model, $this->attribute);
        $row = $value ? EntityDisplay::row($table, $value) : null;
        $items = $row ? [$value => EntityDisplay::label($table, $row)] : [];
        $options['prompt'] = 'Выберите…'; $options['data-searchable'] = true;
        $options['data-lookup'] = Url::to(['/entity-lookup/index', 'table' => $table]);
        EntitySelectAsset::register($this->form->getView());
        return $this->dropDownList($items, $options);
    }
}
