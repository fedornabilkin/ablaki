<?php
namespace backend\models;

class WorldCropForm extends \yii\base\Model
{
    public $code = ''; public $name = ''; public $reason = '';
    public $seed_item_id; public $yield_item_id; public $water_item_id;
    public $seed_quantity = 1; public $yield_quantity = 4; public $grow_seconds = 300;
    public $water_quantity = 1; public $water_interval_seconds = 120; public $water_window_seconds = 60; public $harvest_window_seconds = 86400;
    public function rules(): array
    {
        return [[['code', 'name', 'reason'], 'trim'], [['code', 'name', 'reason', 'seed_item_id', 'yield_item_id'], 'required'],
            [['code', 'name'], 'string', 'max' => 120], ['reason', 'string', 'max' => 255],
            [['seed_item_id', 'yield_item_id', 'water_item_id'], 'integer', 'min' => 1, 'max' => 2147483647],
            [['seed_quantity', 'yield_quantity'], 'integer', 'min' => 1, 'max' => 10000],
            [['grow_seconds', 'water_window_seconds', 'harvest_window_seconds'], 'integer', 'min' => 1, 'max' => 31536000],
            [['water_interval_seconds', 'water_quantity'], 'integer', 'min' => 0, 'max' => 31536000]];
    }
    public function payload(): array
    {
        $result = $this->getAttributes();
        foreach ($result as $key => $value) if (!in_array($key, ['code', 'name', 'reason'], true)) $result[$key] = $key === 'water_item_id' && ($value === null || $value === '') ? null : (int)$value;
        return $result;
    }
    public function attributeLabels(): array
    {
        return ['code' => 'Код культуры', 'name' => 'Название', 'reason' => 'Причина изменения', 'seed_item_id' => 'Семена', 'yield_item_id' => 'Урожай', 'water_item_id' => 'Вода для полива',
            'seed_quantity' => 'Семян на посадку', 'yield_quantity' => 'Полный урожай', 'grow_seconds' => 'Рост, секунд', 'water_quantity' => 'Воды на полив',
            'water_interval_seconds' => 'Интервал полива, секунд', 'water_window_seconds' => 'Время на полив, секунд', 'harvest_window_seconds' => 'Срок сбора после созревания, секунд'];
    }
}
