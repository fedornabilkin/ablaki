<?php
namespace common\modules\economy\service;

use yii\db\Connection;
use yii\db\Query;

/** Fail closed until all existing credit writers and historical values are migrated explicitly. */
class WalletSchema
{
    public static function ready(Connection $db): bool
    {
        return $db->schema->getTableSchema('economy_registry') && $db->schema->getTableSchema('economy_wallet_rollout')
            && (bool)(new Query())->select('wallet_ready')->from('economy_registry')->where(['id' => 1])->scalar($db)
            && (new Query())->select('phase')->from('economy_wallet_rollout')->where(['id' => 1])->scalar($db) === 'active';
    }
    public static function requireReady(Connection $db): void
    {
        WalletMaintenance::writable($db);
        if (!self::ready($db)) throw new \common\services\game\GameError('EXACT_WALLET_NOT_READY', 'Денежные операции мира ещё не открыты.', 503);
        foreach (['persone' => ['credit'], 'history_balance' => ['credit', 'credit_up']] as $table => $fields) {
            $schema = $db->schema->getTableSchema($table);
            foreach ($fields as $field) {
                $column = $schema ? $schema->getColumn($field) : null;
                if (!$column || !in_array($column->type, ['decimal', 'money'], true) || (int)$column->scale !== 4 || (int)$column->precision < 19) throw new \RuntimeException('Exact credit schema is not installed: ' . $table . '.' . $field);
            }
            if ($db->driverName === 'mysql') {
                $engine = $db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $table])->queryScalar();
                if (strtolower((string)$engine) !== 'innodb') throw new \RuntimeException('Exact wallet requires transactional tables.');
            }
        }
    }
}
