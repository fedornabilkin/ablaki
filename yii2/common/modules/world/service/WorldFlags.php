<?php
namespace common\modules\world\service;

use common\modules\world\Module;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Query;

class WorldFlags
{
    public const SCHEMA_VERSION = 34;
    private $db;
    private $module;
    public function __construct(Connection $db, Module $module) { $this->db = $db; $this->module = $module; }
    public function capabilities(): array
    {
        $ready = $this->db->schema->getTableSchema('world_registry') !== null;
        $registry = $ready ? (new Query())->from('world_registry')->where(['id' => 1])->one($this->db) : false;
        $installed = $registry && (int)$registry['schema_version'] >= self::SCHEMA_VERSION;
        $result = ['schema_ready' => (bool)$installed, 'contract_version' => $this->module->contractVersion];
        foreach (['world_read', 'world_write', 'storage_v2', 'economy_tick'] as $flag) {
            $result[$flag] = (bool)$installed && !empty($registry[$flag]) && !empty($this->module->flags[$flag]);
        }
        $result['world_write'] = $result['world_read'] && $result['world_write'] && !\common\modules\craft\service\StorageMaintenance::frozen($this->db)
            && !\common\modules\economy\service\WalletMaintenance::frozen($this->db);
        return $result;
    }
    public function requireFlag(string $flag): void
    {
        $flags = $this->capabilities();
        if (!$flags['schema_ready']) throw new GameError('SCHEMA_NOT_READY', 'Мир готовится к открытию.', 503);
        if (empty($flags[$flag])) throw new GameError('FEATURE_DISABLED', 'Это действие сейчас недоступно.', 503, ['feature' => $flag]);
    }
}
