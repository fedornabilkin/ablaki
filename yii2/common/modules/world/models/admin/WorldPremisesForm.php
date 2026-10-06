<?php
namespace common\modules\world\models\admin;

use common\modules\world\models\domain\WorldPremises;
use yii\base\Model;

class WorldPremisesForm extends Model
{
    public $name = '';
    public $kind = 'workroom';
    public $area = 2;
    public $slots = 1;
    public $price = '';
    public $expansion_limit = 1;
    public $expansion_base_price = '';
    public $reason = '';
    public $delivery = 'ready';
    public $duration_seconds = 0;
    public $materials_json = '[]';
    public $requirements_json = '{"all":[]}';
    public $repair_full_price = '';
    public $repair_materials_json = '[]';
    public $repair_for_existing = false;

    public function rules(): array
    {
        return [
            ['delivery', 'in', 'range' => ['ready', 'construction']],
            ['duration_seconds', 'integer', 'min' => 0, 'max' => 604800],
            ['materials_json', 'string', 'max' => 4000],
            ['requirements_json', 'string', 'max' => 8000],
            ['repair_full_price', 'string', 'max' => 40],
            ['repair_materials_json', 'string', 'max' => 4000],
            ['repair_for_existing', 'boolean'],
            [['name', 'kind', 'area', 'slots', 'price', 'expansion_limit', 'reason'], 'required'],
            [['name', 'price', 'reason', 'expansion_base_price'], 'string', 'max' => 500],
            ['reason', 'string', 'max' => 255],
            ['name', 'string', 'max' => 120],
            ['kind', 'in', 'range' => ['canopy', 'workroom', 'house', 'forge', 'workshop', 'warehouse']],
            [['area', 'slots', 'expansion_limit'], 'integer', 'min' => 1, 'max' => 4],
            [['name', 'reason'], 'trim', 'skipOnArray' => true],
        ];
    }

    public function attributeLabels(): array
    {
        return ['name' => 'Название', 'kind' => 'Тип постройки', 'area' => 'Площадь',
            'slots' => 'Мест оборудования при покупке', 'price' => 'Цена покупки, Cr',
            'expansion_limit' => 'Предельное число мест оборудования',
            'expansion_base_price' => 'Базовая цена расширения, Cr', 'reason' => 'Причина публикации',
            'delivery' => 'Способ получения', 'duration_seconds' => 'Срок строительства, секунд', 'materials_json' => 'Материалы строительства',
            'requirements_json' => 'Требования к покупателю', 'repair_full_price' => 'Цена полного ремонта, Cr', 'repair_materials_json' => 'Материалы полного ремонта', 'repair_for_existing' => 'Предлагать договор владельцам ранее купленных зданий'];
    }

    public function payload(WorldPremises $service, int $node): array
    {
        try { $repairMaterials = json_decode($this->repair_materials_json, true, 16, JSON_THROW_ON_ERROR); }
        catch (\Exception $e) { throw new \common\modules\world\support\GameError('INVALID_REPAIR_POLICY', 'Укажите материалы ремонта в формате JSON.', 422); }
        $repairPrice = trim($this->repair_full_price);
        if ($repairPrice === '' && $repairMaterials !== []) throw new \common\modules\world\support\GameError('INVALID_REPAIR_POLICY', 'Для материалов ремонта укажите цену.', 422);
        try { $materials = json_decode($this->materials_json, true, 512, JSON_THROW_ON_ERROR); }
        catch (\Exception $e) { throw new \common\modules\world\support\GameError('INVALID_CONSTRUCTION', 'Укажите материалы в формате JSON: [{"item_id": 1, "quantity": 10}].', 422); }
        try { $requirements = json_decode($this->requirements_json, true, 16, JSON_THROW_ON_ERROR); }
        catch (\Exception $e) { throw new \common\modules\world\support\GameError('INVALID_REQUIREMENTS', 'Укажите требования в формате JSON.', 422); }
        return ['node_id' => $node, 'admin_reason' => trim($this->reason)] + $service->publication([
            'delivery' => $this->delivery, 'duration_seconds' => (int)$this->duration_seconds, 'materials' => $materials,
            'requirements' => $requirements,
            'repair' => $repairPrice === '' ? null : ['full_price' => $repairPrice, 'materials' => $repairMaterials],
            'repair_for_existing' => (bool)$this->repair_for_existing,
            'name' => $this->name, 'kind' => $this->kind, 'area' => (int)$this->area,
            'slots' => (int)$this->slots, 'price' => trim($this->price),
            'expansion_limit' => (int)$this->expansion_limit,
            'expansion_base_price' => trim($this->expansion_base_price) === '' ? null : trim($this->expansion_base_price),
        ]);
    }
}
