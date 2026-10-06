<?php
namespace common\modules\world\models;

class BuildForm extends \yii\base\Model
{
    public $parent_id;
    public $template_id;
    public $name;
    public $labor_budget = '0';
    public function rules(): array
    {
        return [[['parent_id', 'template_id', 'name'], 'required'], [['parent_id', 'template_id'], 'integer', 'min' => 1],
            ['name', 'trim'], ['name', 'string', 'min' => 1, 'max' => 120],
            ['labor_budget', 'match', 'pattern' => '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,4})?$/D']];
    }
    public function input(): array
    {
        if (!$this->validate()) throw new \common\modules\world\support\GameError('INVALID_BUILD', 'Проверьте параметры строительства.', 422, $this->errors);
        return ['parent_id' => (int)$this->parent_id, 'template_id' => (int)$this->template_id, 'name' => $this->name, 'labor_budget' => (string)$this->labor_budget];
    }
}
