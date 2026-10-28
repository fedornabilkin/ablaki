<?php
namespace common\modules\world\apiControllers;

use common\modules\world\models\domain\EconomyRuntime;
use common\modules\world\models\domain\EconomyQuota;
use common\modules\world\models\domain\WorldFood;
use common\modules\world\models\domain\WorldGoals;
use common\modules\world\support\GameError;
use Yii;

class EconomyApiController extends CoreController
{
    protected function verbs() { return parent::verbs() + ['history' => ['GET'], 'progression' => ['GET'], 'kitchen' => ['GET'], 'goal' => ['POST'], 'food' => ['POST'], 'fund-goal' => ['POST'], 'storage' => ['GET'], 'storage-expand-preview' => ['POST'], 'storage-expand' => ['POST']]; }
    public function actionKitchen($id): array { return WorldFood::kitchen(Yii::$app->db, (int)Yii::$app->user->id, $this->id($id)); }
    public function actionProgression(): array
    {
        $user = (int)Yii::$app->user->id; $db = Yii::$app->db;
        $skills = (new \yii\db\Query())->select(['c.name', 's.experience'])->from(['s' => 'craft_skill'])
            ->innerJoin(['c' => 'craft_category'], 'c.id=s.category_id')->where(['s.user_id' => $user])->orderBy('c.id')->all($db);
        $professions = (new \yii\db\Query())->select(['p.name', 't.level', 't.xp'])->from(['t' => 'actor_profession'])
            ->innerJoin(['a' => 'game_actor'], 'a.id=t.actor_id')->innerJoin(['p' => 'profession'], 'p.id=t.profession_id')->where(['a.user_id' => $user, 'a.kind' => 'player'])->orderBy('p.id')->all($db);
        return ['craft' => array_map(static function ($skill) { $xp = (int)$skill['experience']; return ['name' => trim($skill['name']), 'level' => 1 + intdiv($xp, 100), 'xp' => $xp]; }, $skills),
            'professions' => array_map(static function ($track) { return ['name' => $track['name'], 'level' => (int)$track['level'], 'xp' => (int)$track['xp']]; }, $professions), 'server_time' => time()];
    }
    private function storageService(): \common\modules\world\models\domain\EconomyStorage { return new \common\modules\world\models\domain\EconomyStorage(Yii::$app->db, $this->flags()); }
    public function actionStorage($id): array { return $this->storageService()->state((int)Yii::$app->user->id, $this->id($id)); }
    public function actionStorageExpandPreview($id): array { return $this->storageService()->preview((int)Yii::$app->user->id, $this->id($id), $this->code()); }
    public function actionStorageExpand($id): array {
        $body = Yii::$app->request->bodyParams;
        if (!is_array($body) || !is_string($body['request_key'] ?? null) || !is_string($body['quote_id'] ?? null) || !is_array($body['expected_revisions'] ?? null)) throw new GameError('INVALID_COMMAND', 'Сначала подтвердите предварительный расчёт.', 422);
        return $this->storageService()->execute((int)Yii::$app->user->id, $this->id($id), $this->code(), $body['request_key'], $body['quote_id'], $body['expected_revisions']);
    }
    public function actionHistory(): array
    {
        $source = Yii::$app->request->get('source', 'commands');
        if (!is_string($source)) throw new GameError('INVALID_ECONOMY_FILTER', 'Выберите раздел истории.', 422);
        $user = (int)Yii::$app->user->id; $requested = Yii::$app->request->get('user_id');
        if ($requested !== null) {
            if (!Yii::$app->user->can('world-manage') && !Yii::$app->user->can('p-admin')) throw new GameError('FORBIDDEN', 'Чужая история доступна только администратору.', 403);
            $user = $this->id($requested);
        }
        return (new \common\modules\world\models\domain\EconomyHistory(Yii::$app->db))->listing($user, $source, $this->id(Yii::$app->request->get('page', 1)), $this->orderSearch());
    }
    public function actionIndex(): array
    {
        $user = (int)Yii::$app->user->id; $db = Yii::$app->db;
        $revision = EconomyRuntime::current($db);
        return ['enabled' => $revision !== null, 'profile_version' => $revision ? $revision['version'] : null,
            'can_fund_goals' => Yii::$app->user->can('world-manage'),
            'personal_credit_cr' => \common\modules\world\modules\economy\value\Money::parse((string)((new \yii\db\Query())->select('credit')->from('persone')->where(['user_id' => $user])->scalar($db) ?: '0'))->decimal(),
            'target_start_credit_cr' => $revision['profile']['starter']['credits_cr'] ?? null,
            'starter_funding' => (new \common\modules\world\models\domain\StarterFunding($db))->forUser($user),
            'starter_funding_mode' => $revision['profile']['starter']['funding'] ?? 'personal_transfer',
            'second_milestone_day' => $revision['profile']['closed_test']['verified_second_milestone_day'] ?? null,
            'admission' => EconomyRuntime::admission($db, $user, Yii::$app->user->can('world-manage')),
            'health' => WorldFood::state($db, $user), 'goals' => (new WorldGoals($db))->state($user)['goals'],
            'quotas' => array_map(static function ($kind) use ($db, $user) { return EconomyQuota::state($db, $user, $kind); }, ['crafting_hourly', 'extraction_daily', 'sales_daily']),
            'feedback_path' => '/forum', 'server_time' => time()];
    }
    private function code(): string
    {
        $code = Yii::$app->request->post('code');
        if (!is_string($code) || !preg_match('/^[a-z][a-z0-9-]{0,79}$/D', $code)) throw new GameError('INVALID_CODE', 'Выберите действие из каталога.', 422);
        return $code;
    }
    public function actionFood(): array
    {
        $code = $this->code(); $revision = Yii::$app->request->post('health_revision');
        if (!is_int($revision) || $revision < 0) throw new GameError('INVALID_HEALTH_REVISION', 'Обновите состояние здоровья.', 422);
        $node = Yii::$app->request->post('kitchen_node_id'); $node = $node === null ? null : $this->id($node);
        $payload = ['code' => $code, 'health_revision' => $revision]; if ($node !== null) $payload['kitchen_node_id'] = $node;
        return $this->command('world.food.consume', $payload, static function ($operation) use ($code, $revision, $node) {
            return WorldFood::consume(Yii::$app->db, (int)Yii::$app->user->id, $code, $revision, $operation, $node);
        });
    }
    public function actionGoal(): array
    {
        $code = $this->code();
        return $this->command('world.goal.claim', ['code' => $code], static function ($operation) use ($code) {
            return (new WorldGoals(Yii::$app->db))->claim((int)Yii::$app->user->id, $code, $operation);
        });
    }
    public function actionFundGoal(): array
    {
        if (!Yii::$app->user->can('world-manage')) throw new GameError('FORBIDDEN', 'Требуются права управления миром.', 403);
        $code = $this->code(); $node = $this->id(Yii::$app->request->post('settlement_id')); $count = Yii::$app->request->post('participants');
        if (!is_int($count) || $count < 1 || $count > 1000) throw new GameError('INVALID_PARTICIPANTS', 'Укажите число участников от 1 до 1000.', 422);
        return $this->command('world.goal.fund', ['code' => $code, 'settlement_id' => $node, 'participants' => $count], static function ($operation) use ($node, $count, $code) {
            return (new WorldGoals(Yii::$app->db))->fund($node, $code, $count, (int)Yii::$app->user->id, $operation);
        });
    }
}
