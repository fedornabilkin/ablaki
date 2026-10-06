<?php
namespace common\modules\world\models;

use common\modules\world\support\Locks;

/** Administrative writes use the same serialization point as player commands. */
abstract class LockedRecord extends Record
{
    public $formVersion;
    public function version(): string { return hash('sha256', json_encode($this->getOldAttributes())); }
    public function save($runValidation = true, $attributeNames = null)
    {
        return self::getDb()->transaction(function () use ($runValidation, $attributeNames) {
            $locks = new Locks(self::getDb()); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            if (!$this->isNewRecord && $this->formVersion !== null) {
                $current = static::findOne($this->getPrimaryKey());
                if (!$current || !hash_equals($current->version(), $this->formVersion)) {
                    $this->addError('id', 'Запись уже изменена. Обновите страницу и повторите изменения.'); return false;
                }
            }
            return parent::save($runValidation, $attributeNames);
        });
    }
}
