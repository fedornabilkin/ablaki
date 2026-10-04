<?php
namespace backend\models;

class WorldGardenOfferForm extends \yii\base\Model
{
    public $settlement_id; public $name = ''; public $price = ''; public $base_price = ''; public $reason = '';
    public function rules(): array
    {
        return [[['name', 'price', 'base_price', 'reason'], 'trim', 'skipOnArray' => true],
            [['settlement_id', 'name', 'price', 'base_price', 'reason'], 'required'],
            ['settlement_id', 'integer', 'min' => 1, 'max' => 2147483647], ['name', 'string', 'max' => 120],
            [['price', 'base_price'], 'string', 'max' => 40], ['reason', 'string', 'max' => 255]];
    }
    public function attributeLabels(): array
    {
        return ['settlement_id' => 'Поселение', 'name' => 'Название', 'price' => 'Цена огорода, Cr', 'base_price' => 'Базовая цена открытия грядки, Cr', 'reason' => 'Причина изменения'];
    }
}
