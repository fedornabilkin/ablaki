<?php
namespace common\modules\world\commands;

use Yii;

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
        return $this->output((new \common\modules\world\modules\craft\models\domain\StorageReconciliation(Yii::$app->db))->report());
    }
    public function actionCredits(): int
    {
        return $this->output((new \common\modules\world\modules\economy\models\domain\WalletAudit(Yii::$app->db))->report());
    }
    public function actionAccounts(int $world, int $afterNode = 0, int $limit = 100): int
    {
        return $this->output((new \common\modules\world\modules\economy\models\domain\EconomyAccountBackfill(Yii::$app->db))->report($world, $afterNode, $limit));
    }
    public function actionSchema(): int
    {
        return $this->output((new \common\modules\world\support\WorldSchemaAudit(Yii::$app->db))->report(Yii::getAlias('@console/world-migrations'), self::TABLES));
    }
    public function actionData(): int
    {
        return $this->output((new \common\modules\world\support\WorldInventoryAudit(Yii::$app->db))->report());
    }
    public function actionBaseline(int $afterUser = 0, int $limit = 100): int
    {
        return $this->output((new \common\modules\world\modules\economy\models\domain\FinanceReadSnapshot(Yii::$app->db))->run(function () use ($afterUser, $limit) {
            return \common\modules\world\models\admin\WorldBaseline::run(Yii::$app->db, $afterUser, $limit);
        }));
    }
}
