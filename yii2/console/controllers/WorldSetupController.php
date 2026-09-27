<?php
namespace console\controllers;

use common\modules\world\service\WorldSeeder;
use common\services\game\Locks;
use Yii;

/** Deliberate installation only; not part of the Docker entrypoint or deployment workflow. */
class WorldSetupController extends \yii\console\Controller
{
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
        if ($exit === 0) Yii::$app->db->transaction(function () {
            (new Locks(Yii::$app->db))->row('world_registry', ['id' => 1]);
            Yii::$app->db->createCommand()->update('world_registry', ['schema_version' => 19], ['id' => 1])->execute();
        });
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
        if (!in_array($name, ['world_read', 'world_write', 'storage_v2', 'economy_tick'], true) || !in_array($enabled, [0, 1], true)) throw new \InvalidArgumentException('Unknown flag/value.');
        if ($enabled && $name === 'storage_v2') throw new \RuntimeException('Use storage-activate after frozen backfill, verification and index preparation.');
        if ($enabled && $name === 'economy_tick') throw new \RuntimeException('The economy rollout gate is not implemented yet.');
        Yii::$app->db->transaction(function () use ($name, $enabled) {
            $registry = (new Locks(Yii::$app->db))->row('world_registry', ['id' => 1]);
            if (!$registry || ($enabled && (!$registry['active_world_id'] || (int)$registry['schema_version'] < 19))) throw new \RuntimeException('Complete world-setup/install before activation.');
            if ($name === 'storage_v2' && !$enabled && !empty($registry['storage_v2'])) throw new \RuntimeException('Canonical inventory cannot revert to the legacy interpretation. Disable WORLD storage actions instead.');
            if ($name === 'world_write' && $enabled && !$registry['world_read']) throw new \RuntimeException('Enable reading before writing.');
            $values = [$name => $enabled];
            if ($name === 'world_read' && !$enabled) $values['world_write'] = 0;
            Yii::$app->db->createCommand()->update('world_registry', $values, ['id' => 1])->execute();
        });
        $this->stdout("Registry flag updated; the matching application environment flag must also be enabled.\n");
        return 0;
    }
    public function actionBackfill(int $limit = 100): int
    {
        $this->requireInstall();
        if (getenv('WORLD_BACKFILL_MAINTENANCE') !== '1') throw new \RuntimeException('Pause all inventory writers and set WORLD_BACKFILL_MAINTENANCE=1 before backfill.');
        $result = (new \common\modules\craft\service\StorageBackfill(Yii::$app->db))->batch($limit);
        $this->stdout(json_encode($result, JSON_THROW_ON_ERROR) . "\n");
        return 0;
    }
    /** Explicit values: publishing a calendar starts the grace period for existing members. */
    public function actionNightsActivate(int $world, int $daySeconds, int $nightOffset, int $nightSeconds, int $maxSeverity, int $recoveryNights, int $mildEfficiencyBps, int $severeEfficiencyBps): int
    {
        $this->requireInstall();
        if (getenv('WORLD_NIGHTS_ACTIVATE') !== 'confirmed-night-policy') throw new \RuntimeException('Set WORLD_NIGHTS_ACTIVATE=confirmed-night-policy only after reviewing the calendar and health rules.');
        $flags = new \common\modules\world\service\WorldFlags(Yii::$app->db, Yii::$app->getModule('world'));
        return $this->rolloutOutput((new \common\modules\world\service\WorldNights(Yii::$app->db, $flags))->activate($world, [
            'day_seconds' => $daySeconds, 'night_offset' => $nightOffset, 'night_seconds' => $nightSeconds,
            'max_severity' => $maxSeverity, 'recovery_nights' => $recoveryNights,
            'mild_efficiency_bps' => $mildEfficiencyBps, 'severe_efficiency_bps' => $severeEfficiencyBps,
        ]));
    }
    public function actionGrantManager(int $user): int
    {
        $this->requireInstall();
        $auth = Yii::$app->authManager;
        if (!$auth->checkAccess($user, 'p-admin')) throw new \InvalidArgumentException('The user must already have p-admin.');
        $permission = $auth->getPermission('world-manage');
        if (!$permission) {
            $permission = $auth->createPermission('world-manage');
            $permission->description = 'Manage world nodes with a reason and audit record';
            $auth->add($permission);
        }
        if (!$auth->getAssignment('world-manage', $user)) $auth->assign($permission, $user);
        $this->stdout("World management permission assigned to the existing administrator.\n");
        return 0;
    }
    public function actionStorageIndex(): int
    {
        return $this->rolloutOutput((new \common\modules\craft\service\StorageRollout(Yii::$app->db))->prepareIndex());
    }
    private function rolloutOutput(array $result): int { $this->stdout(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n"); return 0; }
    public function actionStorageFreeze(): int { return $this->rolloutOutput((new \common\modules\craft\service\StorageRollout(Yii::$app->db))->begin()); }
    public function actionStorageCancel(): int { return $this->rolloutOutput((new \common\modules\craft\service\StorageRollout(Yii::$app->db))->cancelBeforeBackfill()); }
    public function actionStorageVerify(int $limit = 100): int { return $this->rolloutOutput((new \common\modules\craft\service\StorageRollout(Yii::$app->db))->verify($limit)); }
    public function actionStorageActivate(): int { return $this->rolloutOutput((new \common\modules\craft\service\StorageRollout(Yii::$app->db))->activate()); }
    public function actionStorageStatus(): int { return $this->rolloutOutput((new \common\modules\craft\service\StorageRollout(Yii::$app->db))->status()); }
    public function actionWalletStatus(): int { return $this->rolloutOutput((new \common\modules\economy\service\WalletRollout(Yii::$app->db))->status()); }
    public function actionWalletFreeze(): int { return $this->rolloutOutput((new \common\modules\economy\service\WalletRollout(Yii::$app->db))->begin()); }
    public function actionWalletSnapshot(int $limit = 500): int { return $this->rolloutOutput((new \common\modules\economy\service\WalletRollout(Yii::$app->db))->snapshot($limit)); }
    public function actionWalletConvert(): int { return $this->rolloutOutput((new \common\modules\economy\service\WalletRollout(Yii::$app->db))->convert()); }
    public function actionWalletVerify(): int { return $this->rolloutOutput((new \common\modules\economy\service\WalletRollout(Yii::$app->db))->verify()); }
    public function actionWalletCancel(): int { return $this->rolloutOutput((new \common\modules\economy\service\WalletRollout(Yii::$app->db))->cancel()); }
    public function actionWalletActivate(): int { return $this->rolloutOutput((new \common\modules\economy\service\WalletRollout(Yii::$app->db))->activate()); }
}
