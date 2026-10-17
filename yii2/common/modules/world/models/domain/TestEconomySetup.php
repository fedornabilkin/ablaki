<?php
namespace common\modules\world\models\domain;

use common\models\user\User;
use common\modules\world\models\Command;
use common\modules\world\modules\economy\models\domain\{BudgetFunding, BudgetSpending, EconomyHierarchy, StarterOrders};
use common\modules\world\modules\economy\value\Money;
use common\modules\world\support\Locks;
use yii\db\Connection;
use yii\db\Query;

/** Explicit, resumable preparation of one funded test route. Never issues new credits. */
final class TestEconomySetup
{
    private $db;
    private $donorName;
    private const PREFIX = 'mvp-20261017';
    public function __construct(Connection $db, string $donorName = 'Test01') { $this->db = $db; $this->donorName = $donorName; }
    private function command(int $user, string $suffix, array $payload, callable $handler): array
    {
        return Command::run($user, self::PREFIX . '-' . $suffix, 'world.test.economy', $payload, $handler);
    }
    public function preview(): array
    {
        TestWorldSetup::requireContext();
        $db = $this->db; $world = EconomyRuntime::world($db);
        $donor = User::findOne(['username' => $this->donorName, 'blocked_at' => null]);
        if (!$donor) throw new \RuntimeException('Existing active test donor is required.');
        $existing = (new Query())->select('user_id')->from('game_command')->where(['request_key' => self::PREFIX . '-starter'])->scalar($db);
        if ($existing && (int)$existing !== (int)$donor->id) throw new \RuntimeException('This test route was already prepared with another donor.');
        $author = null;
        foreach ((new Query())->select('user_id')->from('auth_assignment')->distinct()->orderBy('user_id')->column($db) as $id)
            if (\Yii::$app->authManager->checkAccess($id, 'p-admin') && User::findOne(['id' => $id, 'blocked_at' => null])) { $author = (int)$id; break; }
        if (!$author) throw new \RuntimeException('Existing RBAC administrator required.');
        $city = (new Query())->from('world_node')->where(['root_id' => $world, 'hierarchy_level' => 3, 'status' => 'active', 'owner_user_id' => null])->orderBy('id')->one($db);
        if (!$city) throw new \RuntimeException('Active system settlement required.');
        $accounts = (new EconomyHierarchy($db))->accounts((int)$city['id']);
        $available = isset($accounts['budget']) ? (new BudgetSpending($db))->available((int)$accounts['budget']['id']) : Money::parse('0');
        $wallet = Money::parse((string)$donor->person->credit);
        $existingTester = User::findOne(['username' => 'Test01', 'blocked_at' => null]);
        $funded = (new Query())->from('game_command')->where(['user_id' => $donor->id, 'request_key' => self::PREFIX . '-budget'])->exists($db);
        $transferred = (new Query())->from('game_command')->where(['user_id' => $donor->id, 'request_key' => self::PREFIX . '-starter'])->exists($db);
        $shortfall = Money::parse('560')->subtract($available); if ($shortfall->isNegative() || $funded) $shortfall = Money::parse('0');
        $required = $shortfall->add(Money::parse($transferred ? '0' : '250'));
        return ['world_id' => $world, 'settlement_id' => (int)$city['id'], 'author_user_id' => $author, 'donor_user_id' => (int)$donor->id,
            'donor_available_cr' => $wallet->decimal(), 'settlement_available_cr' => $available->decimal(),
            'budget_contribution_cr' => $shortfall->decimal(), 'required_from_donor_cr' => $required->decimal(),
            'can_prepare' => $wallet->compare($required) >= 0, 'already_funded' => $funded, 'existing_tester_user_id' => $existingTester ? (int)$existingTester->id : null,
            'accounts' => ['MvpTest01', 'MvpDenied01'], 'participants' => 1];
    }
    public function prepare(): array
    {
        $plan = $this->preview();
        if (!$plan['can_prepare']) throw new \RuntimeException('Insufficient existing test funds: ' . json_encode($plan, JSON_THROW_ON_ERROR));
        $db = $this->db;
        return $db->transaction(function () use ($db, $plan) {
            (new Locks($db))->row('craft_meta', ['id' => 1]);
            (new Locks($db))->owners([$plan['donor_user_id']]);
            (new Locks($db))->row('world_registry', ['id' => 1]);
            $profile = EconomyProfile::load(); $publisher = new EconomyProfilePublisher($db);
            $publication = $publisher->publish($profile, $publisher->preview($profile)['digest'], $plan['author_user_id'], 'Explicit funded MVP test preparation');
            \Yii::$app->runAction('world-setup/economy-access');
            $role = \Yii::$app->authManager->getRole('world-tester');
            foreach (array_unique(array_filter([$plan['donor_user_id'], $plan['author_user_id'], $plan['existing_tester_user_id']])) as $id)
                if (!\Yii::$app->authManager->getAssignment('world-tester', $id)) \Yii::$app->authManager->assign($role, $id);
            // Passwords stay in a private operator file, never in console output or API DTOs.
            $path = \Yii::getAlias('@runtime/world-mvp-test-accounts.json');
            $credentials = is_file($path) ? json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR) : [];
            $users = [];
            foreach ($plan['accounts'] as $name) {
                $user = User::findOne(['username' => $name]);
                if (!$user) {
                    $password = \Yii::$app->security->generateRandomString(32);
                    $user = new User(['scenario' => 'create']); $user->username = $name; $user->email = strtolower($name) . '@example.invalid';
                    $user->password = $password; $user->confirmed_at = time();
                    if (!$user->save()) throw new \RuntimeException('Could not create test account: ' . $name);
                    $credentials[$name] = ['username' => $name, 'password' => $password, 'id' => (int)$user->id];
                    if (file_put_contents($path, json_encode($credentials, JSON_THROW_ON_ERROR), LOCK_EX) === false || !chmod($path, 0600)) throw new \RuntimeException('Could not protect operator credentials.');
                } elseif (!isset($credentials[$name]) || (int)$credentials[$name]['id'] !== (int)$user->id || $user->blocked_at !== null)
                    throw new \RuntimeException('Existing test account needs operator review: ' . $name);
                $users[$name] = $user;
            }
            $tester = $users['MvpTest01'];
            if (!\Yii::$app->authManager->getAssignment('world-tester', $tester->id)) \Yii::$app->authManager->assign($role, $tester->id);
            foreach ([$tester->id, $plan['donor_user_id']] as $id) if (!(new Query())->from('world_test_participant')->where(['user_id' => $id])->exists($db))
                $db->createCommand()->insert('world_test_participant', ['user_id' => $id, 'cohort' => self::PREFIX, 'enabled' => 1, 'author_user_id' => $plan['author_user_id'], 'created_at' => time()])->execute();
            if (EconomyRuntime::admission($db, (int)$users['MvpDenied01']->id)['allowed']) throw new \RuntimeException('Negative control account unexpectedly has world access.');
            if (!\Yii::$app->has('user')) \Yii::$app->set('user', ['class' => \yii\web\User::class, 'identityClass' => User::class, 'enableSession' => false]);
            $original = \Yii::$app->user->getIdentity();
            try {
                $donor = User::findOne($plan['donor_user_id']); \Yii::$app->user->switchIdentity($donor);
                $starter = $this->command((int)$donor->id, 'starter', ['recipient' => (int)$tester->id], function () use ($db, $tester, $donor) {
                    $service = new \common\modules\exchange\service\TransferService();
                    $model = new \common\modules\exchange\models\CreditTransfer(); $model->amount = 250;
                    $service->create($model);
                    $row = (new Query())->from('credit_transfer')->where(['user_id' => $donor->id, 'user_buyer' => 0])->orderBy(['id' => SORT_DESC])->one($db);
                    \Yii::$app->user->switchIdentity($tester);
                    $service->confirm(\common\modules\exchange\models\CreditTransfer::findOne($row['id']), $row['password']);
                    \Yii::$app->user->switchIdentity($donor);
                    return ['transfer_id' => (int)$row['id'], 'recipient_user_id' => (int)$tester->id];
                });
                $this->command((int)$donor->id, 'budget', ['settlement_id' => $plan['settlement_id']], function ($op) use ($db, $donor, $plan) {
                    $accounts = (new EconomyHierarchy($db))->provision($plan['settlement_id'], $op);
                    $needed = Money::parse('560')->subtract((new BudgetSpending($db))->available((int)$accounts['budget']['id']));
                    if (!$needed->isNegative() && !$needed->isZero()) return (new BudgetFunding($db))->contribute((int)$donor->id, $plan['settlement_id'], $needed, 'Finite MVP orders and goals for one participant', $op);
                    return ['contributed_cr' => '0.0000'];
                });
                $flags = new WorldFlags($db, \Yii::$app->getModule('world'));
                $orders = new StarterOrders($db, $flags, new WorldAccessPolicy($plan['author_user_id'], true));
                $scenarios = EconomyScenario::load();
                foreach ($scenarios['orders'] as $code => $order) {
                    $input = ['node_id' => $plan['settlement_id'], 'item_id' => (int)(new Query())->select('id')->from('craft_item')->where(['code' => $code])->scalar($db),
                        'quantity' => $order['quantity'], 'per_user_limit' => $order['quantity'], 'lifetime_hours' => 336, 'unit_price' => $order['unit_price_cr'],
                        'purpose' => self::PREFIX . ': finite demand for one participant', 'initialize_starter_policy' => true];
                    $key = self::PREFIX . '-order-' . $code;
                    if (!(new Query())->from('game_command')->where(['user_id' => $plan['author_user_id'], 'request_key' => $key])->exists($db)) {
                        $q = $orders->preview($plan['author_user_id'], $input, 'publish');
                        $orders->execute($plan['author_user_id'], $key, $input, $q['quote_id'], (array)$q['expected_revisions'], 'publish');
                    }
                }
                foreach (['day-1', 'day-2'] as $code) $this->command($plan['author_user_id'], 'goal-' . $code, ['settlement_id' => $plan['settlement_id'], 'code' => $code], function ($op) use ($db, $plan, $code) {
                    return (new WorldGoals($db))->fund($plan['settlement_id'], $code, 1, $plan['author_user_id'], $op);
                });
            } finally { \Yii::$app->user->switchIdentity($original); }
            return $plan + ['prepared' => true, 'profile_version' => $publication['version'], 'starter_transfer_id' => $starter['transfer_id'],
                'tester_user_id' => (int)$tester->id, 'denied_user_id' => (int)$users['MvpDenied01']->id, 'credentials_file' => 'runtime/world-mvp-test-accounts.json'];
        });
    }
}
