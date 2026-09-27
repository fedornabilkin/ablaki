<?php
namespace common\modules\economy\service;

use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Persistent guard, not a replacement for draining old web/worker/cron processes. */
class WalletMaintenance
{
    public static function frozen(Connection $db): bool
    {
        if (!$db->schema->getTableSchema('economy_wallet_rollout')) return false;
        $phase = (new Query())->select('phase')->from('economy_wallet_rollout')->where(['id' => 1])->scalar($db);
        return $phase && !in_array($phase, ['cancelled', 'active'], true);
    }
    public static function writable(Connection $db): void
    {
        if (self::frozen($db)) throw new GameError('WALLET_MAINTENANCE', 'Кошелёк временно недоступен: перенос кредитов.', 503);
    }
}
