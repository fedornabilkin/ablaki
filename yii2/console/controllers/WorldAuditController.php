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
    public function actionAccounts(int $world, int $afterNode = 0, int $limit = 100): int
    {
        return $this->output((new \common\modules\economy\service\EconomyAccountBackfill(Yii::$app->db))->report($world, $afterNode, $limit));
    }
    public function actionSchema(): int
    {
        return $this->output((new \common\services\game\WorldSchemaAudit(Yii::$app->db))->report(Yii::getAlias('@console/world-migrations'), self::TABLES));
    }
    public function actionData(): int
    {
        return $this->output((new \common\services\game\WorldInventoryAudit(Yii::$app->db))->report());
    }
    public function actionBaseline(int $afterUser = 0, int $limit = 100): int
    {
        return $this->output((new \common\modules\economy\service\FinanceReadSnapshot(Yii::$app->db))->run(function () use ($afterUser, $limit) {
            return $this->baseline($afterUser, $limit);
        }));
    }
    private function baseline(int $afterUser, int $limit): array
    {
        if ($afterUser < 0) throw new \InvalidArgumentException('Cursor must be non-negative.');
        $db = Yii::$app->db; $limit = max(1, min(1000, $limit));
        $users = (new Query())->select('id')->from('user')->where(['>', 'id', $afterUser])->orderBy(['id' => SORT_ASC])->limit($limit)->column($db);
        $result = ['generated_at' => time(), 'read_only' => true, 'scope' => 'user_page', 'users' => [],
            'note' => 'One transaction per page; consistency requires transactional tables. Pages are not a global snapshot while writers run. This legacy user baseline does not cover NPC/system stock or all world state.'];
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
        return $result;
    }
}
