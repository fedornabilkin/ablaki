<?php
namespace common\modules\world\model;

use common\modules\world\support\CanonicalJson;
use yii\base\Model;

class WorldSlotForm extends Model
{
    public $node_id;
    public $revision;
    public $storage_revision = 0;
    public $code = '';
    public $slot_type = 'equipment';
    public $size = 1;
    public $exposure_class = 'indoor';
    public $item_codes = '';
    public $reason = '';
    public function rules(): array
    {
        return [
            [['code', 'item_codes', 'reason'], 'trim', 'skipOnArray' => true],
            [['node_id', 'revision', 'storage_revision', 'code', 'slot_type', 'size', 'exposure_class', 'reason'], 'required'],
            [['node_id', 'revision'], 'integer', 'min' => 1, 'max' => 2147483647], ['storage_revision', 'integer', 'min' => 0, 'max' => 2147483647],
            ['code', 'match', 'pattern' => '/^[a-z0-9][a-z0-9-]{0,63}$/D'], ['size', 'integer', 'min' => 1, 'max' => 100],
            ['slot_type', 'in', 'range' => ['equipment', 'station', 'chest']],
            ['exposure_class', 'in', 'range' => ['outdoor', 'covered', 'indoor']], ['reason', 'string', 'max' => 255],
            ['item_codes', 'string', 'max' => 4000], ['item_codes', 'validateCodes'],
        ];
    }
    public function validateCodes(): void
    {
        $codes = $this->codes();
        if (count($codes) > 50) $this->addError('item_codes', 'Можно указать до 50 кодов предметов.');
        foreach ($codes as $code) if (!preg_match('/^[A-Za-z0-9_.-]{1,80}$/D', $code)) { $this->addError('item_codes', 'Укажите коды предметов через запятую или с новой строки.'); break; }
    }
    private function codes(): array
    {
        if (!is_string($this->item_codes)) return [];
        $codes = array_values(array_unique(preg_split('/[\s,]+/', trim($this->item_codes), -1, PREG_SPLIT_NO_EMPTY))); sort($codes, SORT_STRING); return $codes;
    }
    public function populate(array $state, int $position): void
    {
        $this->node_id = (int)$state['room']['node']['id']; $this->revision = (int)$state['room']['node']['revision'];
        $this->storage_revision = (int)($state['storage']['revision'] ?? 0);
        $this->exposure_class = $state['room']['details']['exposure_class'] ?? 'outdoor';
        $slot = $state['slots'][$position] ?? null;
        if ($slot) {
            foreach (['code', 'slot_type', 'size', 'exposure_class'] as $field) $this->$field = $slot[$field];
            $this->item_codes = implode(', ', json_decode($slot['compatibility_json'], true, 512, JSON_THROW_ON_ERROR)['item_codes'] ?? []);
        }
    }
    public function payload(int $position): array
    {
        $codes = $this->codes();
        return ['node_id' => (int)$this->node_id, 'position' => $position, 'revision' => (int)$this->revision, 'storage_revision' => (int)$this->storage_revision,
            'reason' => $this->reason, 'slot' => ['code' => $this->code, 'slot_type' => $this->slot_type, 'size' => (int)$this->size,
                'exposure_class' => $this->exposure_class, 'compatibility_json' => $codes ? CanonicalJson::encode(['item_codes' => $codes]) : '{}']];
    }
    public function attributeLabels(): array
    {
        return ['node_id' => 'Комната, ID', 'code' => 'Код места', 'slot_type' => 'Назначение', 'size' => 'Занимаемая площадь',
            'exposure_class' => 'Защита от среды', 'item_codes' => 'Разрешённые коды предметов', 'reason' => 'Причина изменения'];
    }
}
