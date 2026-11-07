<?php
namespace console\controllers;

use Yii;
use yii\db\Query;
use yii\db\Transaction;

/** Explicit checks and additive updates for established checkouts; never resets data. */
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

    /** Existing worlds only: no seed, inventory backfill, wallet conversion or flag changes. */
    public function actionWorldUpdate(int $publishProfile = 0, string $author = ''): int
    {
        if (getenv('WORLD_RELEASE') === 'confirmed-test-checkout') {
            $this->context();
        } else {
            $directory = \common\services\user\FinancialMaintenance::directory();
            if (getenv('WORLD_RELEASE') !== 'confirmed-production-checkout'
                || realpath(Yii::getAlias('@app')) !== '/web/yii2/console'
                || !\common\services\user\FinancialMaintenance::paused()) {
                throw new \RuntimeException('Paused production release context is required.');
            }
            $probe = fopen($directory . '/writers.lock', 'r');
            if (!$probe || flock($probe, LOCK_SH | LOCK_NB)) throw new \RuntimeException('External exclusive writer lease is required.');
            fclose($probe);
            $db = Yii::$app->db;
            if ($db->driverName !== 'mysql' || $db->createCommand('SELECT DATABASE()')->queryScalar() !== 'ablaki') {
                throw new \RuntimeException('Unexpected production database.');
            }
            $db->createCommand('SET SESSION lock_wait_timeout=60')->execute();
            $db->createCommand('SET SESSION innodb_lock_wait_timeout=60')->execute();
            if ((int)$db->createCommand("SELECT GET_LOCK('ablaki.world.release', 0)")->queryScalar() !== 1) {
                throw new \RuntimeException('Another world release is still running.');
            }
            register_shutdown_function(static function () use ($db): void {
                try { $db->createCommand("SELECT RELEASE_LOCK('ablaki.world.release')")->queryScalar(); } catch (\Throwable $ignored) {}
            });
        }
        if (!in_array($publishProfile, [0, 1], true)) throw new \InvalidArgumentException('Use 0 or 1.');
        $db = Yii::$app->db;
        if (!(new Query())->from('world_registry')->where(['id' => 1])->exists($db)
            || !\common\modules\world\models\domain\EconomyRuntime::current($db)) {
            throw new \RuntimeException('An already installed world and published profile are required.');
        }
        $user = $this->publicationAuthor($author);
        $issues = (new \common\modules\world\support\WorldSchemaAudit($db))->installationIssues();
        if ($issues) throw new \RuntimeException(implode('; ', $issues));
        $before = $this->protectedState();
        $migrate = new \yii\console\controllers\MigrateController('world-release-migration', Yii::$app,
            ['migrationPath' => '@console/world-migrations', 'migrationNamespaces' => [], 'interactive' => false]);
        $exit = $migrate->runAction('up');
        if ($exit !== 0) return $exit;
        $db->schema->refresh();
        \common\modules\world\models\RegistryConfiguration::installed();
        $result = ['changed' => false, 'admin_profile_preserved' => true];
        if ($publishProfile) {
            $current = \common\modules\world\models\domain\EconomyRuntime::current($db);
            $profile = \common\modules\world\models\domain\EconomyProfile::forRelease($current['raw_profile']);
            $publisher = new \common\modules\world\models\domain\EconomyProfilePublisher($db);
            $preview = $publisher->preview($profile);
            $result = $publisher->publish($profile, $preview['digest'], (int)$user,
                'Production release: approved construction pacing, NPC market and rewards');
        }
        if ($before !== $this->protectedState()) throw new \RuntimeException('Release changed protected gameplay state. Keep operations paused for inspection.');
        if ($this->pending()) throw new \RuntimeException('Required migrations remain pending.');
        $this->output($result + ['world_ready' => true, 'protected_state_preserved' => true, 'money_created_cr' => '0.0000']);
        return 0;
    }

    private function publicationAuthor(string $username): int
    {
        // Match the original production activation: attribute publication to the
        // sole existing administrator, never to a hard-coded test account.
        $query = (new Query())->select('id')->from('user')
            ->where(['or', ['blocked_at' => null], ['blocked_at' => 0]]);
        if ($username !== '') $query->andWhere(['username' => $username]);
        else {
            // Yii RBAC stores user_id as a string; user.id is numeric (PostgreSQL
            // does not implicitly compare these types inside an IN subquery).
            $assigned = (new Query())->select('user_id')->distinct()->from('auth_assignment')->column(Yii::$app->db);
            $query->andWhere(['id' => array_map('intval', $assigned)]);
        }
        $administrators = [];
        foreach ($query->column(Yii::$app->db) as $candidate) {
            if (Yii::$app->authManager->checkAccess((int)$candidate, 'p-admin')) $administrators[] = (int)$candidate;
        }
        if (count($administrators) !== 1) throw new \RuntimeException('Select one existing active p-admin publication author explicitly.');
        return $administrators[0];
    }

    public function actionTestProfile(int $apply = 0, string $author = 'fedornabilkin'): int
    {
        $this->context();
        if (!in_array($apply, [0, 1], true)) throw new \InvalidArgumentException('Use 0 or 1.');
        if (!$this->world()) { $this->output(['world' => false]); return 0; }
        $db = Yii::$app->db;
        $current = \common\modules\world\models\domain\EconomyRuntime::current($db);
        $profile = \common\modules\world\models\domain\EconomyProfile::forRelease($current['raw_profile'] ?? null);
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
