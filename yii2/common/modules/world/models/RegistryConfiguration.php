<?php
namespace common\modules\world\models;

use common\modules\world\support\Locks;
use common\modules\world\models\domain\WorldFlags;

final class RegistryConfiguration
{
    public static function installed(): void
    {
        Registry::getDb()->transaction(function () {
            (new Locks(Registry::getDb()))->row('world_registry', ['id' => 1]);
            Registry::updateAll(['schema_version' => WorldFlags::SCHEMA_VERSION], ['id' => 1]);
        });
    }
    public static function activeUser(int $id): bool
    {
        return \common\models\user\User::find()->where(['id' => $id, 'blocked_at' => null])->exists();
    }
    public static function flag(string $name, int $enabled): void
    {
        if (!in_array($name, ['world_read', 'world_write', 'storage_v2', 'economy_tick'], true) || !in_array($enabled, [0, 1], true)) throw new \InvalidArgumentException('Unknown flag/value.');
        if ($enabled && $name === 'storage_v2') throw new \RuntimeException('Use storage-activate after frozen backfill, verification and index preparation.');
        if ($enabled && $name === 'economy_tick') throw new \RuntimeException('The economy rollout gate is not implemented yet.');
        Registry::getDb()->transaction(function () use ($name, $enabled) {
            $registry = (new Locks(Registry::getDb()))->row('world_registry', ['id' => 1]);
            if (!$registry || ($enabled && (!$registry['active_world_id'] || (int)$registry['schema_version'] < WorldFlags::SCHEMA_VERSION))) throw new \RuntimeException('Complete world-setup/install before activation.');
            if ($name === 'storage_v2' && !$enabled && !empty($registry['storage_v2'])) throw new \RuntimeException('Canonical inventory cannot revert to the legacy interpretation. Disable WORLD storage actions instead.');
            if ($name === 'world_write' && $enabled && !$registry['world_read']) throw new \RuntimeException('Enable reading before writing.');
            $values = [$name => $enabled]; if ($name === 'world_read' && !$enabled) $values['world_write'] = 0;
            Registry::updateAll($values, ['id' => 1]);
        });
    }
}
