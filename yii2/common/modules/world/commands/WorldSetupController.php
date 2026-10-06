<?php
namespace common\modules\world\commands;

use common\modules\world\models\domain\WorldSeeder;
use Yii;

/** Deliberate installation only; not part of the Docker entrypoint or deployment workflow. */
class WorldSetupController extends \yii\console\Controller
{
    public function actionTestReady(): int
    {
        \common\modules\world\models\domain\TestWorldSetup::requireContext();
        $setup = new \common\modules\world\models\domain\TestWorldSetup(Yii::$app->db);
        // A failed rollout must be resumed before seed can touch the frozen catalogue.
        if (\common\modules\world\modules\craft\models\domain\StorageMaintenance::frozen(Yii::$app->db) || \common\modules\world\modules\economy\models\domain\WalletMaintenance::frozen(Yii::$app->db)) $setup->rollouts();
        $exit = $this->actionInstall();
        if ($exit !== 0) return $exit;
        $flags = new \common\modules\world\models\domain\WorldFlags(Yii::$app->db, Yii::$app->getModule('world'));
        return $this->rolloutOutput($setup->activate($flags));
    }
    private function requireInstall(): void
    {
        if (getenv('WORLD_INSTALL') !== 'confirmed-world-install') throw new \RuntimeException('Set the explicit WORLD_INSTALL context for this database first.');
    }
    public function actionInstall(): int
    {
        $this->requireInstall();
        $migrate = new \yii\console\controllers\MigrateController('world-migration', Yii::$app, ['migrationPath' => '@console/world-migrations', 'migrationNamespaces' => [], 'interactive' => false]);
        $exit = $migrate->runAction('up');
        if ($exit !== 0) return $exit;
        Yii::$app->db->schema->refresh();
        $exit = $this->actionSeed();
        if ($exit === 0) \common\modules\world\models\RegistryConfiguration::installed();
        return $exit;
    }
    public function actionSeed(): int
    {
        $this->requireInstall();
        $ids = (new WorldSeeder(Yii::$app->db))->seed();
        $this->stdout(json_encode(['nodes' => $ids, 'flags_changed' => false], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
        return 0;
    }
    public function actionFlag(string $name, int $enabled): int
    {
        $this->requireInstall();
        \common\modules\world\models\RegistryConfiguration::flag($name, $enabled);
        $this->stdout("Registry flag updated; the matching application environment flag must also be enabled.\n");
        return 0;
    }
    public function actionBackfill(int $limit = 100): int
    {
        $this->requireInstall();
        if (getenv('WORLD_BACKFILL_MAINTENANCE') !== '1') throw new \RuntimeException('Pause all inventory writers and set WORLD_BACKFILL_MAINTENANCE=1 before backfill.');
        $result = (new \common\modules\world\modules\craft\models\domain\StorageBackfill(Yii::$app->db))->batch($limit);
        $this->stdout(json_encode($result, JSON_THROW_ON_ERROR) . "\n");
        return 0;
    }
    /** Explicit values: publishing a calendar starts the grace period for existing members. */
    public function actionNightsActivate(int $world, int $daySeconds, int $nightOffset, int $nightSeconds, int $maxSeverity, int $recoveryNights, int $mildEfficiencyBps, int $severeEfficiencyBps): int
    {
        $this->requireInstall();
        if (getenv('WORLD_NIGHTS_ACTIVATE') !== 'confirmed-night-policy') throw new \RuntimeException('Set WORLD_NIGHTS_ACTIVATE=confirmed-night-policy only after reviewing the calendar and health rules.');
        $flags = new \common\modules\world\models\domain\WorldFlags(Yii::$app->db, Yii::$app->getModule('world'));
        return $this->rolloutOutput((new \common\modules\world\models\domain\WorldNights(Yii::$app->db, $flags))->activate($world, [
            'day_seconds' => $daySeconds, 'night_offset' => $nightOffset, 'night_seconds' => $nightSeconds,
            'max_severity' => $maxSeverity, 'recovery_nights' => $recoveryNights,
            'mild_efficiency_bps' => $mildEfficiencyBps, 'severe_efficiency_bps' => $severeEfficiencyBps,
        ]));
    }
    public function actionGrantManager(int $user): int
    {
        $this->requireInstall();
        $auth = Yii::$app->authManager;
        if (!\common\modules\world\models\RegistryConfiguration::activeUser($user)) throw new \InvalidArgumentException('An active user is required.');
        $permission = $auth->getPermission('world-manage');
        if (!$permission) {
            $permission = $auth->createPermission('world-manage');
            $permission->description = 'Manage world nodes with a reason and audit record';
            $auth->add($permission);
        }
        if (!$auth->getAssignment('world-manage', $user)) $auth->assign($permission, $user);
        $this->stdout("World API management permission assigned. Configure backend route permissions separately in RBAC.\n");
        return 0;
    }
    public function actionStorageIndex(): int
    {
        return $this->rolloutOutput((new \common\modules\world\modules\craft\models\domain\StorageRollout(Yii::$app->db))->prepareIndex());
    }
    private function rolloutOutput(array $result): int { $this->stdout(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"); return 0; }
    public function actionStorageFreeze(): int { return $this->rolloutOutput((new \common\modules\world\modules\craft\models\domain\StorageRollout(Yii::$app->db))->begin()); }
    public function actionStorageCancel(): int { return $this->rolloutOutput((new \common\modules\world\modules\craft\models\domain\StorageRollout(Yii::$app->db))->cancelBeforeBackfill()); }
    public function actionStorageVerify(int $limit = 100): int { return $this->rolloutOutput((new \common\modules\world\modules\craft\models\domain\StorageRollout(Yii::$app->db))->verify($limit)); }
    public function actionStorageActivate(): int { return $this->rolloutOutput((new \common\modules\world\modules\craft\models\domain\StorageRollout(Yii::$app->db))->activate()); }
    public function actionStorageStatus(): int { return $this->rolloutOutput((new \common\modules\world\modules\craft\models\domain\StorageRollout(Yii::$app->db))->status()); }
    public function actionWalletStatus(): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\WalletRollout(Yii::$app->db))->status()); }
    public function actionWalletFreeze(): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\WalletRollout(Yii::$app->db))->begin()); }
    public function actionWalletSnapshot(int $limit = 500): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\WalletRollout(Yii::$app->db))->snapshot($limit)); }
    public function actionWalletConvert(): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\WalletRollout(Yii::$app->db))->convert()); }
    public function actionWalletVerify(): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\WalletRollout(Yii::$app->db))->verify()); }
    public function actionWalletCancel(): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\WalletRollout(Yii::$app->db))->cancel()); }
    public function actionWalletActivate(): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\WalletRollout(Yii::$app->db))->activate()); }
    public function actionAccountsBegin(int $world): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\EconomyAccountBackfill(Yii::$app->db))->begin($world)); }
    public function actionAccountsBackfill(int $run, int $limit = 50): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\EconomyAccountBackfill(Yii::$app->db))->batch($run, $limit)); }
    public function actionAccountsStatus(int $run): int { return $this->rolloutOutput((new \common\modules\world\modules\economy\models\domain\EconomyAccountBackfill(Yii::$app->db))->status($run)); }
}
