<?php
namespace common\modules\world\models;

class BuildForm extends \yii\base\Model
{
    public $parent_id;
    public $template_id;
    public $name;
    public $labor_budget = '0';
    public $top_up = false;
    public $x;
    public $y;
    public $terms_digest;
    public function rules(): array
    {
        return [[['parent_id', 'template_id', 'name'], 'required'], [['parent_id', 'template_id'], 'integer', 'min' => 1],
            ['terms_digest', 'match', 'pattern' => '/^[a-f0-9]{64}$/D'],
            ['name', 'trim'], ['name', 'string', 'min' => 1, 'max' => \common\modules\world\domain\WorldName::MAX_LENGTH,
                'tooLong' => 'Название: не более 24 символов.'],
            [['x', 'y'], 'integer', 'min' => -1000000, 'max' => 1000000, 'skipOnEmpty' => false,
                'when' => function () { return $this->x !== null || $this->y !== null; }],
            ['top_up', 'boolean', 'trueValue' => true, 'falseValue' => false, 'strict' => true],
            ['labor_budget', 'match', 'pattern' => '/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,4})?$/D']];
    }
    public function input(): array
    {
        if (!$this->validate()) throw new \common\modules\world\support\GameError('INVALID_BUILD', 'Проверьте параметры строительства.', 422, $this->errors);
        if (($this->x === null) !== ($this->y === null)) throw new \common\modules\world\support\GameError('INVALID_POSITION', 'Выберите одну ячейку.', 422);
        return ['parent_id' => (int)$this->parent_id, 'template_id' => (int)$this->template_id, 'name' => $this->name, 'labor_budget' => (string)$this->labor_budget, 'top_up' => $this->top_up]
            + ($this->x === null ? [] : ['x' => (int)$this->x, 'y' => (int)$this->y])
            + ($this->terms_digest === null ? [] : ['terms_digest' => $this->terms_digest]);
    }
}
