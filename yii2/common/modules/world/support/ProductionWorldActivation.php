<?php
namespace common\modules\world\support;

use common\modules\world\models\domain\{EconomyProfile, EconomyProfilePublisher, EconomyRuntime, NpcInfrastructure, StarterReserve, WorldFlags, WorldNights};
use common\modules\world\modules\craft\models\domain\{StorageBackfill, StorageRollout};
use common\modules\world\modules\economy\models\domain\{BudgetFunding, EconomyAccountBackfill, WalletSchema};
use common\modules\world\modules\economy\value\Money;
use yii\db\{Connection, Query};

/** Explicit launch procedure: existing rollouts, published content, existing NPC funds. No test fixtures. */
final class ProductionWorldActivation
{
    public function __construct(private readonly Connection $db, private readonly \Closure $progress) {}

    public function run(int $author, string $starterReserve): array
    {
        if (PHP_SAPI !== 'cli' || getenv('WORLD_PRODUCTION_ACTIVATE') !== 'confirmed-production-checkout'
            || getenv('WORLD_TEST_MODE') === '1') throw new \RuntimeException('Explicit production activation context required.');
        if (!\Yii::$app->authManager->checkAccess($author, 'p-admin')) throw new \RuntimeException('Existing administrator required.');
        $amount = Money::parse($starterReserve);
        if ($amount->isNegative()) throw new \InvalidArgumentException('Negative starter reserve.');
        WalletSchema::requireReady($this->db);
        $registry = (new Query())->from('world_registry')->where(['id' => 1])->one($this->db);
        if (!$registry || (int)$registry['schema_version'] !== WorldFlags::SCHEMA_VERSION || !$registry['active_world_id']) throw new \RuntimeException('Complete world schema required.');
        $world = (int)$registry['active_world_id'];
        $funding = (new Query())->select('context_json')->from('game_operation')->where(['id' => $this->fundingOperation($world)])->scalar($this->db);
        if ($funding && (json_decode($funding, true, 32, JSON_THROW_ON_ERROR)['reserve_cr'] ?? null) !== $amount->decimal()) throw new \RuntimeException('Launch reserve already recorded with a different amount.');
        $bot = (int)(new Query())->select('id')->from('user')->where(['username' => 'bot'])->scalar($this->db);
        if (!$bot) throw new \RuntimeException('Existing bot account required.');
        $before = $this->protectedState($bot);
        $this->db->transaction(function () {
            (new Locks($this->db))->row('world_registry', ['id' => 1]);
            $this->db->createCommand()->update('world_registry', ['world_write' => 0, 'economy_tick' => 0], ['id' => 1])->execute();
        });
        putenv('WORLD_INSTALL=confirmed-world-install');
        putenv('WORLD_BACKFILL_MAINTENANCE=1');
        $storage = new StorageRollout($this->db);
        if ($storage->status()['phase'] !== 'active') {
            $storage->begin();
            while ($storage->status()['has_pending_users']) {
                $batch = (new StorageBackfill($this->db))->batch(100);
                ($this->progress)('storage-backfill', $batch);
            }
            do {
                $state = $storage->verify(100);
                ($this->progress)('storage-verify', ['migrated' => $state['migrated_users'], 'verified' => $state['verified_users']]);
            } while ($state['verified_users'] < $state['migrated_users']);
            $storage->prepareIndex();
            $storage->activate();
        }
        ($this->progress)('storage-active', $storage->status());
        putenv('WORLD_ECONOMY_BACKFILL=confirmed-zero-accounts');
        $backfill = new EconomyAccountBackfill($this->db);
        $completed = (new Query())->from('economy_account_backfill_run')->where(['world_id' => $world, 'status' => 'completed'])->exists($this->db);
        if (!$completed) {
            $run = $backfill->begin($world);
            do {
                $run = $backfill->batch($run['id'], 100);
                ($this->progress)('accounts-backfill', ['status' => $run['status'], 'processed' => $run['processed_count']]);
            } while ($run['status'] !== 'completed');
        }
        $profile = EconomyProfile::load();
        $profile['closed_test']['restricted'] = false;
        $publisher = new EconomyProfilePublisher($this->db);
        $preview = $publisher->preview($profile);
        if (EconomyRuntime::current($this->db) && !$preview['already_current']) throw new \RuntimeException('An existing administrator profile requires separate review.');
        $publication = $publisher->publish($profile, $preview['digest'], $author, 'Production world launch; public access; no credit issuance');
        ($this->progress)('profile-published', $publication);
        $npc = new NpcInfrastructure($this->db);
        ($this->progress)('npc-installed', $npc->install($author));
        $city = (int)(new Query())->select('id')->from('world_node')->where(['root_id' => $world, 'code' => 'north-city', 'owner_user_id' => $bot])->scalar($this->db);
        if (!$city || !$npc->ready()) throw new \RuntimeException('Public NPC infrastructure incomplete.');
        $this->fundStarterReserve($author, $bot, $city, $world, $amount);
        if ($before !== $this->protectedState($bot)) throw new \RuntimeException('Protected balances or inventory changed during activation.');
        $module = new \common\modules\world\Module('production-launch', null, ['flags' => array_fill_keys(['world_read','world_write','storage_v2','economy_tick'], true)]);
        $flags = new WorldFlags($this->db, $module);
        $this->db->transaction(function () use ($world, $flags) {
            (new Locks($this->db))->row('world_registry', ['id' => 1]);
            $this->db->createCommand()->update('world_registry', ['world_read' => 1, 'world_write' => 1, 'economy_tick' => 1], ['id' => 1])->execute();
            $nights = new WorldNights($this->db, $flags);
            if (!$nights->policy($world)) {
                $now = time();
                $epoch = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone('Europe/Moscow'))->setTime(6, 0)->getTimestamp();
                if ($epoch > $now) $epoch -= 86400;
                $nights->activate($world, ['day_seconds' => 86400, 'night_offset' => 57600, 'night_seconds' => 28800,
                    'max_severity' => 3, 'recovery_nights' => 2, 'mild_efficiency_bps' => 8000, 'severe_efficiency_bps' => 5000], $epoch);
            }
        });
        if ($before !== $this->protectedState($bot)) throw new \RuntimeException('Protected balances or inventory changed during activation.');
        return ['ready' => true, 'world_id' => $world, 'profile_version' => EconomyRuntime::current($this->db)['version'],
            'public_access' => true, 'flags' => $flags->capabilities(), 'storage' => $storage->status(),
            'starter_reserve' => (new StarterReserve($this->db))->state($city), 'money_created_cr' => '0.0000', 'protected_state_preserved' => true];
    }

    private function fundStarterReserve(int $author, int $bot, int $city, int $world, Money $amount): void
    {
        $this->db->transaction(function () use ($author, $bot, $city, $world, $amount) {
            $locks = new Locks($this->db); $locks->row('craft_meta', ['id' => 1]); $locks->row('world_registry', ['id' => 1]);
            $operation = $this->fundingOperation($world);
            $old = (new Query())->from('game_operation')->where(['id' => $operation])->one($this->db);
            if ($old) {
                $context = json_decode($old['context_json'], true, 32, JSON_THROW_ON_ERROR);
                if (($context['reserve_cr'] ?? null) !== $amount->decimal()) throw new \RuntimeException('Launch reserve already recorded with a different amount.');
                return;
            }
            $this->db->createCommand()->insert('game_operation', ['id' => $operation, 'user_id' => $author, 'type' => 'world.production.launch.funding',
                'context_json' => CanonicalJson::encode(['reserve_cr' => $amount->decimal(), 'source_user_id' => $bot, 'city_id' => $city]), 'created_at' => time()])->execute();
            if (!$amount->isZero()) {
                (new BudgetFunding($this->db))->contribute($bot, $city, $amount, 'Стартовый резерв Северограда при запуске мира', $operation);
                (new StarterReserve($this->db))->configure($author, $city, '250.0000', $amount->decimal(), $operation, true);
            }
        });
    }

    private function fundingOperation(int $world): string
    {
        return substr(hash('sha256', 'world.production.launch.funding:' . $world), 0, 32);
    }

    private function protectedState(int $bot): array
    {
        $wallets = hash_init('sha256');
        foreach ((new Query())->select(['user_id','credit'])->from('persone')->where(['<>', 'user_id', $bot])->orderBy('user_id')->each(1000, $this->db) as $row) hash_update($wallets, json_encode($row));
        $inventory = (new Query())->select(['user_id','item_id', 'quantity' => 'SUM(item_quantity)'])->from('craft_inventory')
            ->where(['<>', 'user_id', $bot])->groupBy(['user_id','item_id'])->orderBy(['user_id'=>SORT_ASC,'item_id'=>SORT_ASC])->all($this->db);
        $money = $this->db->createCommand('SELECT CAST((SELECT COALESCE(SUM(credit),0) FROM persone) + (SELECT COALESCE(SUM(amount),0) FROM economy_account) AS DECIMAL(32,4))')->queryScalar();
        return ['wallets_except_bot' => hash_final($wallets), 'inventory_except_bot' => hash('sha256', json_encode($inventory)), 'total_money' => (string)$money];
    }
}
