<?php
namespace common\modules\world\models;

use common\modules\world\support\GameError;
use yii\db\Query;

/** Server time only. One active session per builder; missing heartbeats stop credited work after 60 seconds. */
class BuildWork extends Record
{
    public const HEARTBEAT_SECONDS = 60;
    public static function tableName() { return 'world_build_work'; }
    public function getBuild() { return $this->hasOne(Build::class, ['id' => 'build_id']); }
    public static function participants(int $build): array
    {
        $ids = self::find()->where(['build_id' => $build])->select('user_id')->distinct()->column();
        $project = Build::findOne($build); if ($project) $ids[] = $project->owner_user_id;
        return $ids;
    }
    public static function start(Build $build, int $user): array
    {
        self::requireTransaction();
        Node::readable((int)$build->node_id, $user);
        $build->assertOpen();
        if ($user !== (int)$build->owner_user_id && $build->labor_budget == 0) throw new GameError('HELP_NOT_REQUESTED', 'Владелец строит самостоятельно.', 403);
        $db = self::getDb(); $active = (new Query())->from('world_builder')->where(['user_id' => $user])->one($db);
        if ($db->schema->getTableSchema('world_mining_worker') && (new Query())->from('world_mining_worker')->where(['user_id' => $user])->exists($db)) throw new GameError('MINER_BUSY', 'Приостановите добычу перед строительством.', 409);
        if ($active) {
            $work = self::requireOne($active['work_id']);
            if ((int)$work->build_id === (int)$build->id && $work->ended_at === null) return $work->toArray();
            throw new GameError('BUILDER_BUSY', 'Сначала завершите работу на другой стройке.', 409);
        }
        self::requireSlot($build, $user);
        $work = new self(); $work->setAttributes(['build_id' => $build->id, 'user_id' => $user, 'started_at' => time(), 'last_seen_at' => time(), 'accounted_at' => time(), 'seconds' => 0, 'paid_amount' => '0.0000'], false);
        if (!$work->save(false)) throw new \RuntimeException('Work session write failed.');
        $db->createCommand()->insert('world_builder', ['user_id' => $user, 'work_id' => $work->id])->execute();
        return $work->toArray();
    }
    private static function requireSlot(Build $build, int $user): void
    {
        $db = self::getDb(); $limit = $build->builderLimit();
        if ($user !== (int)$build->owner_user_id && $limit !== null && (int)(new Query())->from(['b' => 'world_builder'])->innerJoin(['w' => 'world_build_work'], 'w.id=b.work_id')
            ->where(['w.build_id' => $build->id])->andWhere(['not in', 'w.user_id', [$build->owner_user_id, $user]])->andWhere(['>', 'w.last_seen_at', time() - self::HEARTBEAT_SECONDS])->count('*', $db) >= $limit)
            throw new GameError('BUILDERS_LIMIT', 'Все места оплачиваемых строителей заняты. Владельцу нужно увеличить бюджет.', 409, ['builder_limit' => $limit]);
    }
    public static function heartbeat(Build $build, int $user, string $operation, bool $stop): array
    {
        self::requireTransaction();
        $build->assertOpen();
        $work = self::find()->where(['build_id' => $build->id, 'user_id' => $user, 'ended_at' => null])->one();
        if (!$work) throw new GameError('WORK_NOT_STARTED', 'Сначала начните работу.', 409);
        if (!$stop) {
            $active = (new Query())->from('world_builder')->where(['user_id' => $user])->one(self::getDb());
            if ($active && (int)$active['work_id'] !== (int)$work->id) throw new GameError('BUILDER_BUSY', 'Сначала остановите работу на другой стройке.', 409);
            if ((new Query())->from('world_mining_worker')->where(['user_id' => $user])->exists(self::getDb())) throw new GameError('MINER_BUSY', 'Остановите добычу перед строительством.', 409);
            self::requireSlot($build, $user);
            if (!$active) self::getDb()->createCommand()->insert('world_builder', ['user_id' => $user, 'work_id' => $work->id])->execute();
        }
        $now = time(); BuildClock::checkpoint($build, $now); $work->refresh();
        $work->last_seen_at = max((int)$work->last_seen_at, $now); $work->accounted_at = max((int)$work->accounted_at, $now);
        if ($stop) $work->ended_at = $now;
        if (!$work->save(false)) throw new \RuntimeException('Work checkpoint failed.');
        if ($stop) self::getDb()->createCommand()->delete('world_builder', ['user_id' => $user, 'work_id' => $work->id])->execute();
        $build->finish($operation);
        return ['build' => $build->toArray(), 'work' => $work->toArray()];
    }
    public static function closeAll(int $build): void
    {
        $ids = self::find()->where(['build_id' => $build])->select('id')->column();
        self::updateAll(['ended_at' => time()], ['build_id' => $build, 'ended_at' => null]);
        self::getDb()->createCommand()->delete('world_builder', ['work_id' => $ids])->execute();
    }
}
