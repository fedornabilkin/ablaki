<?php
namespace console\controllers;

use Yii;
use yii\db\Query;
use yii\db\Transaction;

/** Short, explicit checks for the established test checkout; never resets data. */
class ReleaseController extends \yii\console\Controller
{
    private function context(): void
    {
        if (getenv('WORLD_TEST_SETUP') !== 'confirmed-test-checkout' || getenv('WORLD_TEST_MODE') !== '1') {
            throw new \RuntimeException('Test release context is required.');
        }
    }

    private function output(array $result): void
    {
        $this->stdout(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    }

    private function world(): bool
    {
        return class_exists(\common\modules\world\models\domain\WorldFlags::class);
    }

    private function pending(): array
    {
        $db = Yii::$app->db;
        $done = $db->schema->getTableSchema('{{%migration}}') ? (new Query())->select('version')->from('{{%migration}}')->column($db) : [];
        // Match the existing additive deployment installers. Historical migrations
        // outside this contract are not replayed by a routine world release.
        $required = ['m260924_120000_user_activity', 'm260920_190000_extend_classic_craft',
            'm260921_100000_craft_credit_switch', 'm260924_160000_craft_storage', 'm260926_100000_craft_repair',
            'm260926_110000_forum_privacy', 'm260930_120000_forum_theme_closed', 'm260930_130000_wall_description_approval'];
        if ($this->world()) foreach (glob(Yii::getAlias('@console/world-migrations/m*.php')) as $file) $required[] = basename($file, '.php');
        $pending = array_values(array_diff($required, $done));
        return $pending;
    }

    public function actionTestState(): int
    {
        $this->context();
        $state = ['ready' => false, 'php' => PHP_VERSION, 'world' => $this->world()];
        try {
            $db = Yii::$app->db;
            $db->createCommand('SELECT 1')->queryScalar();
            $state['pending_migrations'] = $this->pending();
            $ready = PHP_VERSION === '8.3.36' && !$state['pending_migrations'];
            if ($state['world']) {
                $flags = new \common\modules\world\models\domain\WorldFlags($db, Yii::$app->getModule('world'));
                $capabilities = $flags->capabilities();
                $storage = (new \common\modules\world\modules\craft\models\domain\StorageRollout($db))->status();
                $wallet = (new \common\modules\world\modules\economy\models\domain\WalletRollout($db))->status();
                $profile = \common\modules\world\models\domain\EconomyRuntime::current($db);
                $state += ['schema' => \common\modules\world\models\domain\WorldFlags::SCHEMA_VERSION, 'profile' => $profile ? (int)$profile['version'] : null,
                    'flags' => $capabilities, 'storage' => $storage['phase'] ?? null, 'wallet_ready' => $wallet['wallet_ready'] ?? false];
                foreach (['schema_ready', 'world_read', 'world_write', 'storage_v2', 'economy_tick'] as $flag) $ready = $ready && !empty($capabilities[$flag]);
                $ready = $ready && ($storage['phase'] ?? null) === 'active' && $wallet['wallet_ready'] && $profile !== null;
                $npc = new \common\modules\world\models\domain\NpcInfrastructure($db);
                $state['npc_ready'] = $npc->ready(); $ready = $ready && $state['npc_ready'];
            }
            $state['ready'] = $ready;
        } catch (\Throwable $error) {
            $state['reason'] = 'Database or installation is not ready';
        }
        $this->output($state);
        return $state['ready'] ? 0 : 2;
    }

    public function actionTestInstall(): int
    {
        $this->context();
        putenv('WORLD_INSTALL=confirmed-world-install');
        putenv('USER_ACTIVITY_INSTALL=confirmed-test-checkout');
        putenv('CRAFT_TEST_INSTALL=confirmed-test-checkout');
        putenv('FORUM_INSTALL=confirmed-test-checkout');
        foreach (['activity-setup/install', 'craft-setup/install-test', 'forum-setup/install'] as $action) {
            $exit = Yii::$app->runAction($action);
            if ($exit !== 0) return $exit;
        }
        return $this->world() ? Yii::$app->runAction('world-setup/test-ready') : 0;
    }

    public function actionTestNpc(string $author = 'fedornabilkin'): int
    {
        $this->context();
        if (!$this->world()) return 0;
        $user = (new Query())->select('id')->from('user')->where(['username' => $author])->scalar(Yii::$app->db);
        if (!$user) throw new \RuntimeException('NPC setup requires an existing administrator.');
        $this->output((new \common\modules\world\models\domain\NpcInfrastructure(Yii::$app->db))->install((int)$user));
        return 0;
    }

    public function actionTestWorker(int $limit = 200): int
    {
        $this->context();
        try { return Yii::$app->runAction('world-worker/run', [$limit]); }
        catch (\Throwable $error) {
            // No environment, arguments, SQL body or stack trace in release logs.
            $this->output(['worker_ready' => false, 'error' => get_class($error),
                'reason' => mb_substr(preg_split('/\R/u', $error->getMessage())[0], 0, 700), 'file' => basename($error->getFile()), 'line' => $error->getLine()]);
            return 2;
        }
    }

    private function protectedState(): string
    {
        $db = Yii::$app->db;
        $state = [];
        foreach (['persone' => ['id', 'credit'], 'economy_account' => ['id', 'amount', 'reserved'], 'world_starter_reserve' => ['id', 'payout_cr', 'commitment_id'],
            'world_build' => ['id', 'node_id', 'status', 'labor_budget', 'required_seconds', 'worked_seconds'], 'craft_inventory' => ['id', 'item_id', 'item_quantity', 'storage_id']] as $table => $columns) {
            if ($db->schema->getTableSchema($table)) $state[$table] = (new Query())->select($columns)->from($table)->orderBy('id')->all($db);
        }
        return json_encode($state, JSON_THROW_ON_ERROR);
    }

    public function actionTestProfile(int $apply = 0, string $author = 'fedornabilkin'): int
    {
        $this->context();
        if (!in_array($apply, [0, 1], true)) throw new \InvalidArgumentException('Use 0 or 1.');
        if (!$this->world()) { $this->output(['world' => false]); return 0; }
        $db = Yii::$app->db;
        $profile = \common\modules\world\models\domain\EconomyProfile::load();
        $publisher = new \common\modules\world\models\domain\EconomyProfilePublisher($db);
        $preview = $publisher->preview($profile);
        if ($preview['already_current']) { $this->output(['profile' => $preview['current_version'], 'changed' => false]); return 0; }
        $current = \common\modules\world\models\domain\EconomyRuntime::current($db);
        if (!$apply && $current !== null) { $this->output(['profile' => $current['version'], 'admin_profile_preserved' => true]); return 0; }
        $user = (new Query())->select('id')->from('user')->where(['username' => $author])->scalar($db);
        if (!$user || !Yii::$app->authManager->checkAccess((int)$user, 'p-admin')) throw new \RuntimeException('Profile publication requires an existing p-admin author.');
        $result = $db->transaction(function () use ($db, $publisher, $profile, $user) {
            $before = $this->protectedState();
            $preview = $publisher->preview($profile);
            $result = $publisher->publish($profile, $preview['digest'], (int)$user, 'Automatic test release: bundled profile changed; no credit issuance');
            if ($before !== $this->protectedState()) throw new \RuntimeException('Profile changed protected gameplay state.');
            return $result;
        }, $db->driverName === 'sqlite' ? Transaction::SERIALIZABLE : Transaction::REPEATABLE_READ);
        $this->output($result + ['money_created_cr' => '0.0000', 'protected_state_preserved' => true]);
        return 0;
    }
}
