<?php
namespace backend\models;

class WorldPremisesOfferForm extends WorldPremisesForm
{
    public $settlement_id;
    public function rules(): array
    {
        return array_merge(parent::rules(), [[['settlement_id'], 'required'], ['settlement_id', 'integer', 'min' => 1, 'max' => 2147483647]]);
    }
    public function attributeLabels(): array { return ['settlement_id' => 'Поселение'] + parent::attributeLabels(); }
    public function populate(array $row): void
    {
        $this->settlement_id = $row['settlement_id'];
        $config = json_decode($row['config_json'], true, 512, JSON_THROW_ON_ERROR);
        $this->setAttributes($config);
        $this->materials_json = self::materials($config['materials'] ?? []);
        $this->requirements_json = json_encode($config['requirements'] ?? ['all' => []], JSON_UNESCAPED_UNICODE);
        $this->repair_full_price = $config['repair']['full_price'] ?? '';
        $this->repair_materials_json = self::materials($config['repair']['materials'] ?? []);
        $this->reason = '';
    }
    private static function materials(array $rows): string
    {
        return json_encode(array_map(static function ($row) { return ['item_id' => (int)$row['item_id'], 'quantity' => (int)$row['quantity']]; }, $rows), JSON_UNESCAPED_UNICODE);
    }
}
