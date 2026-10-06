<?php
namespace common\modules\world\models;

class Registry extends Record
{
    public static function tableName() { return 'world_registry'; }
    public static function active(): array { $record = self::findOne(1); return $record ? $record->getAttributes() : []; }
    public static function worldId(): ?int { $row = self::active(); return empty($row['active_world_id']) ? null : (int)$row['active_world_id']; }
}
