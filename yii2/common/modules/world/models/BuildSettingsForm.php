<?php
namespace common\modules\world\models;

/** Settings also apply to legacy objects which have no current construction template. */
class BuildSettingsForm extends \yii\base\Model
{
    public $name;
    public $labor_budget = '0';
    public $top_up = false;
    public function rules(): array
    {
        return [['name', 'trim'], [['name','labor_budget'], 'required'], ['name', 'string', 'min'=>1, 'max'=>\common\modules\world\domain\WorldName::MAX_LENGTH,
                'tooLong'=>'Название: не более 24 символов.'],
            ['labor_budget','match','pattern'=>'/^(0|[1-9][0-9]{0,10})(\.[0-9]{1,4})?$/D'],
            ['top_up','boolean','trueValue'=>true,'falseValue'=>false,'strict'=>true]];
    }
    public function input(): array
    {
        if (!$this->validate()) throw new \common\modules\world\support\GameError('INVALID_BUILD', 'Проверьте название и бюджет.', 422, $this->errors);
        return ['name'=>$this->name,'labor_budget'=>(string)$this->labor_budget,'top_up'=>$this->top_up];
    }
}
