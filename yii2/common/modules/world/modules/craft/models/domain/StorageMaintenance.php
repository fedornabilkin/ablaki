<?php
namespace common\modules\world\modules\craft\models\domain;

use yii\db\Connection;
use yii\db\Query;

/** Consult after the craft_meta lock. Deployment must also drain old processes and pause external writers. */
class StorageMaintenance
{
    public static function frozen(Connection $db): bool
    {
        if (!$db->schema->getTableSchema('world_storage_rollout')) return false;
        $phase = (new Query())->select('phase')->from('world_storage_rollout')->where(['id' => 1])->scalar($db);
        return in_array($phase, ['frozen', 'backfill', 'verified'], true);
    }
    public static function writable(Connection $db): void
    {
        if (self::frozen($db)) throw new \yii\web\ServiceUnavailableHttpException('Идёт перенос хранилищ. Вещи сохранены; новые действия временно недоступны.');
    }
}
