<?php
namespace backend\widgets;

use backend\assets\EntitySelectAsset;
use backend\support\EntityDisplay;
use yii\base\Widget;
use yii\helpers\{Html, Url};

/** Searchable entity input for array-backed filters and audited correction forms. */
final class EntityReferenceInput extends Widget
{
    public string $table;
    public string $name;
    public $value;
    public array $options = [];
    public function run(): string
    {
        EntitySelectAsset::register($this->view);
        $row = $this->value !== null && $this->value !== '' ? EntityDisplay::row($this->table, $this->value) : null;
        $options = $this->options;
        Html::addCssClass($options, ['form-control', 'entity-search']);
        $options['prompt'] = 'Выберите…';
        $options['data-lookup'] = Url::to(['/entity-lookup/index', 'table' => $this->table]);
        return Html::dropDownList($this->name, $this->value, $row ? [$this->value => EntityDisplay::label($this->table, $row)] : [], $options);
    }
}
