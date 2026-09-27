<?php
namespace console\controllers;

use Yii;
use yii\db\Query;

/** Read-only, explicitly invoked reports. No credentials, auth rows or player messages are emitted. */
class WorldAuditController extends \yii\console\Controller
{
    private const TABLES = ['user', 'persone', 'history_balance', 'craft_item', 'craft_recipe', 'craft_inventory', 'craft_container', 'craft_capacity', 'craft_slot_lease', 'craft_tool_wear', 'craft_skill', 'craft_known', 'craft_command'];
    private function output(array $data): int
    {
        $this->stdout(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        return 0;
    }
    public function actionStorage(): int
    {
        return $this->output((new \common\modules\craft\service\StorageReconciliation(Yii::$app->db))->report());
    }
    public function actionCredits(): int
    {
        return $this->output((new \common\modules\economy\service\WalletAudit(Yii::$app->db))->report());
    }
    public function actionSchema(): int
    {
        $db = Yii::$app->db; $result = ['generated_at' => time(), 'driver' => $db->driverName, 'tables' => []];
        $db->open(); $result['server_version'] = $db->pdo->getAttribute(\PDO::ATTR_SERVER_VERSION);
        foreach (self::TABLES as $table) {
            $schema = $db->schema->getTableSchema($table, true);
            if (!$schema) { $result['tables'][$table] = ['missing' => true]; continue; }
            $columns = [];
            foreach ($schema->columns as $column) $columns[$column->name] = ['type' => $column->dbType, 'nullable' => $column->allowNull, 'primary' => $column->isPrimaryKey];
            $indexes = [];
            foreach ($db->schema->getTableIndexes($table, true) as $index) $indexes[] = ['name' => $index->name, 'columns' => $index->columnNames, 'unique' => $index->isUnique];
            $entry = ['columns' => $columns, 'indexes' => $indexes, 'foreign_keys' => $schema->foreignKeys];
            if ($db->driverName === 'mysql') $entry['engine'] = $db->createCommand('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table', [':table' => $table])->queryScalar();
            $result['tables'][$table] = $entry;
        }
        return $this->output($result);
    }
    public function actionData(): int
    {
        $db = Yii::$app->db;
        foreach (['craft_inventory', 'craft_item', 'persone'] as $table) if (!$db->schema->getTableSchema($table)) throw new \RuntimeException('Missing table: ' . $table);
        $inventory = (new Query())->from(['i' => 'craft_inventory']);
        $orphans = (clone $inventory)->leftJoin(['u' => 'user'], '[[u.id]]=[[i.user_id]]')->leftJoin(['item' => 'craft_item'], '[[item.id]]=[[i.item_id]]')
            ->where(['or', ['u.id' => null], ['and', ['not', ['i.item_id' => null]], ['item.id' => null]]])->select(['i.id', 'i.user_id', 'i.item_id'])->limit(1000)->all($db);
        $schema = $db->schema->getTableSchema('craft_inventory');
        $group = ['user_id', 'slot']; if (isset($schema->columns['container_id'])) $group[] = 'container_id';
        $duplicates = (new Query())->from('craft_inventory')->select(array_merge($group, ['rows' => new \yii\db\Expression('COUNT(*)')]))
            ->where(['>', 'item_quantity', 0])->andWhere(['not', ['item_id' => null]])->groupBy($group)->having('COUNT(*) > 1')->limit(1000)->all($db);
        $invalidQuantities = (new Query())->from('craft_inventory')->select(['id', 'user_id', 'item_quantity'])->where(['<', 'item_quantity', 0])->limit(1000)->all($db);
        $money = $db->createCommand('SELECT COUNT(*) AS accounts, MIN([[credit]]) AS minimum_credit, MAX([[credit]]) AS maximum_credit, SUM([[credit]]) AS total_credit FROM {{%persone}}')->queryOne();
        return $this->output(['generated_at' => time(), 'read_only' => true, 'sample_limit' => 1000, 'orphan_inventory' => $orphans, 'duplicate_positions' => $duplicates,
            'negative_quantities' => $invalidQuantities, 'credit_summary' => $money, 'note' => 'Empty legacy rows and overflow are retained. Financial precision requires the separate exact-money report.']);
    }
    public function actionBaseline(int $afterUser = 0, int $limit = 100): int
    {
        $db = Yii::$app->db; $limit = max(1, min(1000, $limit));
        $users = (new Query())->select('id')->from('user')->where(['>', 'id', $afterUser])->orderBy(['id' => SORT_ASC])->limit($limit)->column($db);
        $result = ['generated_at' => time(), 'users' => []];
        foreach ($users as $id) {
            $inventory = (new Query())->from('craft_inventory')->where(['user_id' => $id])->orderBy(['id' => SORT_ASC])->all($db);
            $totals = (new Query())->select(['item_id', 'quantity' => new \yii\db\Expression('SUM([[item_quantity]])')])->from('craft_inventory')->where(['user_id' => $id])->groupBy('item_id')->orderBy(['item_id' => SORT_ASC])->all($db);
            $entry = ['user_id' => (int)$id, 'inventory' => $inventory, 'totals' => $totals,
                'credit' => (new Query())->select('credit')->from('persone')->where(['user_id' => $id])->scalar($db)];
            foreach (['craft_container', 'craft_capacity', 'craft_slot_lease', 'craft_tool_wear', 'craft_skill', 'craft_known'] as $table) {
                if ($db->schema->getTableSchema($table)) $entry[$table] = (new Query())->from($table)->where(['user_id' => $id])->all($db);
            }
            // Results contain no tokens; digest establishes that accepted legacy commands are preserved.
            $digest = hash_init('sha256'); $commands = 0;
            foreach ((new Query())->select(['id', 'request_key', 'fingerprint', 'result'])->from('craft_command')->where(['user_id' => $id])->orderBy(['id' => SORT_ASC])->each(100, $db) as $command) { hash_update($digest, json_encode($command, JSON_THROW_ON_ERROR)); $commands++; }
            $entry['commands'] = ['count' => $commands, 'sha256' => hash_final($digest)];
            $result['users'][] = $entry;
        }
        $result['next_after_user'] = $users ? (int)end($users) : null;
        return $this->output($result);
    }
}
