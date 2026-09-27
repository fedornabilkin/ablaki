<?php
namespace common\modules\progression\service;

use common\modules\world\service\ActorResolver;
use common\modules\world\service\WorldAccessPolicy;
use common\modules\world\service\WorldFlags;
use common\modules\world\service\RequirementEvaluator;
use common\modules\economy\service\FinanceReadSnapshot;
use common\services\game\CanonicalJson;
use common\services\game\CommandBus;
use common\services\game\GameError;
use yii\db\Connection;
use yii\db\Expression;
use yii\db\Query;

/** Separate profession tracks. Existing craft_skill/craft_known are never rewritten. */
class WorldProgression
{
    public const MAX_XP = 9000000000000;
    public const SOURCES = ['craft.completed', 'world.orders.deliver', 'world.construction.finished', 'world.crop.harvested', 'production.completed', 'npc.training.finished', 'world.quest.claimed'];
    private $db;
    private $flags;
    private $access;
    public function __construct(Connection $db, WorldFlags $flags, WorldAccessPolicy $access) { $this->db = $db; $this->flags = $flags; $this->access = $access; }
    private function one(string $table, array $where): ?array { return (new Query())->from($table)->where($where)->one($this->db) ?: null; }
    private function actor(int $user): ?array { return $this->one('game_actor', ['kind' => 'player', 'user_id' => $user]); }

    public function publication(array $body): array
    {
        if (!is_string($body['code'] ?? null) || !preg_match('/^[a-z][a-z0-9_]{1,79}$/D', $body['code']) || !is_string($body['name'] ?? null) || trim($body['name']) === '' || mb_strlen($body['name'], 'UTF-8') > 120
            || !is_string($body['reason'] ?? null) || trim($body['reason']) === '' || mb_strlen($body['reason'], 'UTF-8') > 255 || !is_array($body['levels'] ?? null) || count($body['levels']) < 1 || count($body['levels']) > 100
            || !is_array($body['sources'] ?? null) || count($body['sources']) > 32) throw new GameError('INVALID_PROFESSION', 'Задайте код, название, причину, уровни и источники опыта.', 422);
        $levels = []; $previous = -1;
        foreach (array_values($body['levels']) as $i => $level) {
            if (!is_array($level) || !is_int($level['required_xp'] ?? null) || $level['required_xp'] < 0 || $level['required_xp'] > self::MAX_XP || $level['required_xp'] <= $previous || ($i === 0 && $level['required_xp'] !== 0)
                || !is_array($level['achievements'] ?? null) || count($level['achievements']) > 16 || !is_array($level['limits'] ?? null) || count($level['limits']) > 32) throw new GameError('INVALID_PROFESSION_LEVEL', 'Пороги опыта должны возрастать от нуля; достижения и лимиты обязательны.', 422);
            $achievements = [];
            foreach ($level['achievements'] as $achievement) {
                if (!is_int($achievement) || $achievement < 1 || $achievement > 2147483647) throw new GameError('INVALID_ACHIEVEMENT', 'Некорректное достижение в условиях.', 422);
                $achievements[] = $achievement;
            }
            if ($i === 0 && $achievements) throw new GameError('INVALID_PROFESSION_LEVEL', 'Первый уровень не должен требовать достижений.', 422);
            $limits = [];
            foreach ($level['limits'] as $key => $value) {
                if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) || !is_int($value) || $value < 0 || $value > 1000000) throw new GameError('INVALID_PROFESSION_LIMIT', 'Некорректный лимит уровня.', 422);
                $limits[$key] = $value;
            }
            ksort($limits); $achievements = array_values(array_unique($achievements)); sort($achievements);
            $levels[] = ['level' => $i + 1, 'required_xp' => $level['required_xp'], 'achievements' => $achievements, 'limits' => $limits]; $previous = $level['required_xp'];
        }
        $sources = [];
        foreach ($body['sources'] as $code => $policy) {
            if (!is_string($code) || !in_array($code, self::SOURCES, true) || !is_array($policy) || !is_int($policy['xp'] ?? null) || $policy['xp'] < 1 || $policy['xp'] > 1000000
                || !is_int($policy['daily_max'] ?? null) || $policy['daily_max'] < 1 || $policy['daily_max'] > 1000000) throw new GameError('INVALID_XP_SOURCE', 'Источнику опыта нужны положительная награда и дневной предел.', 422);
            $sources[$code] = ['xp' => $policy['xp'], 'daily_max' => $policy['daily_max']];
        }
        ksort($sources);
        return ['code' => $body['code'], 'name' => trim($body['name']), 'reason' => trim($body['reason']), 'levels' => $levels, 'sources' => $sources];
    }
    private function level(int $user, array $track, ?array $actor): array
    {
        $rows = (new Query())->select(['l.*', 'r.rules_json'])->from(['l' => 'profession_level'])->leftJoin(['r' => 'requirement_revision'], '[[r.id]]=[[l.requirement_revision_id]]')->where(['l.revision_id' => $track['rules_revision_id']])->orderBy(['l.level' => SORT_ASC])->all($this->db);
        $requirements = []; $achievementIds = [];
        foreach ($rows as $row) {
            $requirements[$row['id']] = $row['rules_json'] === null ? [] : json_decode($row['rules_json'], true, 512, JSON_THROW_ON_ERROR);
            $achievementIds = array_merge($achievementIds, $requirements[$row['id']]['achievements'] ?? []);
        }
        $owned = $actor && $achievementIds ? array_map('intval', (new Query())->select('achievement_id')->from('actor_achievement')->where(['actor_id' => $actor['id'], 'achievement_id' => array_values(array_unique($achievementIds))])->column($this->db)) : [];
        $levels = []; $next = null;
        foreach ($rows as $row) {
            $requirement = $requirements[$row['id']];
            $missing = [];
            foreach ($requirement['achievements'] ?? [] as $achievement) if (!in_array($achievement, $owned, true)) $missing[] = $achievement;
            $item = ['level' => (int)$row['level'], 'required_xp' => (int)$row['required_xp'], 'limits' => json_decode($row['limits_json'], true, 512, JSON_THROW_ON_ERROR), 'achievements' => $requirement['achievements'] ?? []];
            $levels[] = $item;
            if ((int)$row['level'] === (int)$track['level'] + 1) {
                $predicates = [['type' => 'profession_xp', 'profession_id' => (int)$track['profession_id'], 'experience' => (int)$row['required_xp']]];
                foreach ($requirement['achievements'] ?? [] as $achievement) $predicates[] = ['type' => 'achievement', 'achievement_id' => $achievement];
                $evaluation = (new RequirementEvaluator($this->db))->evaluate($user, ['all' => $predicates], $actor ? (int)$actor['id'] : null);
                $next = $item + ['missing_achievements' => $missing, 'missing_xp' => max(0, (int)$row['required_xp'] - (int)$track['xp']), 'available' => $evaluation['allowed'], 'conditions' => $evaluation];
            }
        }
        return ['levels' => $levels, 'next_level' => $next];
    }
    public function listing(int $user, int $page, string $search): array
    {
        if ($page < 1 || $page > 1000000 || mb_strlen($search, 'UTF-8') > 120) throw new GameError('INVALID_FILTER', 'Некорректный фильтр профессий.', 422);
        return (new FinanceReadSnapshot($this->db))->run(function () use ($user, $page, $search) {
            $this->flags->requireFlag('world_read'); $actor = $this->actor($user);
            $query = (new Query())->select(['p.*', 'current_revision_id' => 'c.revision_id'])->from(['p' => 'profession'])->innerJoin(['c' => 'profession_current'], '[[c.profession_id]]=[[p.id]]')->where(['p.status' => 'published']);
            if ($search !== '') $query->andWhere(['or', ['like', 'p.name', $search], ['like', 'p.code', $search]]);
            $total = (int)(clone $query)->count('*', $this->db); $items = [];
            foreach ($query->orderBy(['p.id' => SORT_DESC])->offset(($page - 1) * 20)->limit(20)->all($this->db) as $row) {
                $track = $actor ? $this->one('actor_profession', ['actor_id' => $actor['id'], 'profession_id' => $row['id']]) : null;
                $state = $track ?: ['profession_id' => (int)$row['id'], 'level' => 1, 'xp' => 0, 'rules_revision_id' => $row['current_revision_id']];
                $items[] = ['id' => (int)$row['id'], 'code' => $row['code'], 'name' => $row['name'], 'enrolled' => $track !== null, 'level' => (int)$state['level'], 'xp' => (int)$state['xp'], 'rules_revision_id' => (int)$state['rules_revision_id'], 'published_revision_id' => (int)$row['current_revision_id']] + $this->level($user, $state, $actor);
            }
            return ['items' => $items, '_meta' => ['totalCount' => $total, 'pageCount' => (int)ceil($total / 20), 'currentPage' => $page, 'perPage' => 20], 'server_time' => time()];
        });
    }
    private function prepare(int $user, string $action, array $input): array
    {
        $this->flags->requireFlag('world_read');
        $registry = (new Query())->from('world_registry')->where(['id' => 1])->one($this->db);
        $revisions = ['registry' => (int)$registry['content_revision']];
        if ($action === 'publish') {
            $this->access->requireAdmin(); $normalized = $this->publication($input);
            foreach ($normalized['levels'] as $level) foreach ($level['achievements'] as $achievement) if (!$this->one('achievement', ['id' => $achievement])) throw new GameError('ACHIEVEMENT_NOT_FOUND', 'Достижение из условий не найдено.', 422);
            $profession = $this->one('profession', ['code' => $input['code']]);
            if (!$profession && (int)(new Query())->from('profession')->count('*', $this->db) >= 100) throw new GameError('PROFESSION_LIMIT', 'Достигнут предел в 100 профессий.', 422);
            $version = $profession ? (int)(new Query())->from('profession_revision')->where(['profession_id' => $profession['id']])->max('version', $this->db) + 1 : 1;
            return ['revisions' => $revisions, 'terms' => $normalized + ['version' => $version, 'existing_tracks_unchanged' => true]];
        }
        $profession = $this->one('profession', ['id' => $input['profession_id'], 'status' => 'published']);
        $current = $profession ? $this->one('profession_current', ['profession_id' => $profession['id']]) : null;
        if (!$current) throw new GameError('PROFESSION_UNAVAILABLE', 'Профессия ещё не опубликована.', 404);
        $actor = $this->actor($user); $track = $actor ? $this->one('actor_profession', ['actor_id' => $actor['id'], 'profession_id' => $profession['id']]) : null;
        if ($actor) $revisions['actor:' . $actor['id']] = (int)$actor['revision'];
        if ($action === 'enroll') {
            if ($track) throw new GameError('ALREADY_ENROLLED', 'Профессия уже выбрана.');
            return ['revisions' => $revisions, 'terms' => $input + ['rules_revision_id' => (int)$current['revision_id'], 'level' => 1, 'xp' => 0]];
        }
        if (!$track) throw new GameError('PROFESSION_NOT_ENROLLED', 'Сначала выберите профессию.');
        $next = $this->level($user, $track, $actor)['next_level'];
        if (!$next || !$next['available']) throw new GameError('LEVEL_REQUIREMENTS_NOT_MET', 'Не выполнены условия следующего уровня.', 409, ['next_level' => $next]);
        return ['revisions' => $revisions, 'terms' => $input + ['rules_revision_id' => (int)$track['rules_revision_id'], 'level_before' => (int)$track['level'], 'level_after' => $next['level'], 'xp' => (int)$track['xp'], 'limits' => $next['limits']]];
    }
    public function preview(int $user, string $action, array $input): array
    {
        if (!in_array($action, ['publish', 'enroll', 'level-up'], true)) throw new GameError('INVALID_ACTION', 'Неизвестное действие.', 422);
        return (new CommandBus($this->db, $this->flags))->preview($user, 'world.profession.' . $action, $input, function () use ($user, $action, $input) { return $this->prepare($user, $action, $input); });
    }
    public function execute(int $user, string $action, array $input, string $key, string $quote, array $revisions): array
    {
        if (!in_array($action, ['publish', 'enroll', 'level-up'], true)) throw new GameError('INVALID_ACTION', 'Неизвестное действие.', 422);
        $bus = new CommandBus($this->db, $this->flags);
        return $bus->execute($user, $key, 'world.profession.' . $action, $input, $quote, $revisions, function ($payload, $terms, $operation) use ($user, $action, $bus) {
            $p = $this->prepare($user, $action, $payload);
            if (CanonicalJson::encode($p['terms']) !== CanonicalJson::encode($terms)) throw new GameError('PROGRESSION_CHANGED', 'Условия изменились. Повторите расчёт.');
            $now = time();
            if ($action === 'publish') {
                $profession = $this->one('profession', ['code' => $terms['code']]);
                if (!$profession) { $this->db->createCommand()->insert('profession', ['code' => $terms['code'], 'name' => $terms['name'], 'status' => 'draft'])->execute(); $profession = ['id' => (int)$this->db->getLastInsertID()]; }
                $id = (int)$profession['id'];
                $this->db->createCommand()->insert('profession_revision', ['profession_id' => $id, 'version' => $terms['version'], 'status' => 'published', 'max_level' => count($terms['levels']), 'config_json' => CanonicalJson::encode(['sources' => $terms['sources'], 'reason' => $terms['reason'], 'operation_id' => $operation]), 'author_user_id' => $user, 'published_at' => $now])->execute();
                $revision = (int)$this->db->getLastInsertID();
                foreach ($terms['levels'] as $level) {
                    $this->db->createCommand()->insert('requirement_set', ['code' => 'profession_' . $revision . '_' . $level['level']])->execute(); $set = (int)$this->db->getLastInsertID();
                    $this->db->createCommand()->insert('requirement_revision', ['set_id' => $set, 'version' => 1, 'rules_json' => CanonicalJson::encode(['achievements' => $level['achievements']]), 'status' => 'published', 'published_at' => $now, 'author_user_id' => $user])->execute();
                    $this->db->createCommand()->insert('profession_level', ['revision_id' => $revision, 'level' => $level['level'], 'required_xp' => $level['required_xp'], 'limits_json' => CanonicalJson::encode($level['limits']), 'requirement_revision_id' => (int)$this->db->getLastInsertID()])->execute();
                }
                if ($this->one('profession_current', ['profession_id' => $id])) $this->db->createCommand()->update('profession_current', ['revision_id' => $revision], ['profession_id' => $id])->execute();
                else $this->db->createCommand()->insert('profession_current', ['profession_id' => $id, 'revision_id' => $revision])->execute();
                $this->db->createCommand()->update('profession', ['name' => $terms['name'], 'status' => 'published'], ['id' => $id])->execute();
            } else {
                $id = $payload['profession_id']; $actor = (new ActorResolver($this->db))->player($user);
                if ($action === 'enroll') $this->db->createCommand()->insert('actor_profession', ['actor_id' => $actor['id'], 'profession_id' => $id, 'rules_revision_id' => $terms['rules_revision_id'], 'level' => 1, 'xp' => 0, 'updated_at' => $now])->execute();
                else {
                    if ($this->db->createCommand()->update('actor_profession', ['level' => $terms['level_after'], 'updated_at' => $now, 'revision' => new Expression('[[revision]]+1')], ['actor_id' => $actor['id'], 'profession_id' => $id, 'level' => $terms['level_before']])->execute() !== 1) throw new \RuntimeException('Profession level update failed.');
                    $this->db->createCommand()->insert('actor_level_claim', ['actor_id' => $actor['id'], 'profession_id' => $id, 'rules_revision_id' => $terms['rules_revision_id'], 'level' => $terms['level_after'], 'operation_id' => $operation, 'claimed_at' => $now])->execute();
                }
                if ($this->db->createCommand()->update('game_actor', ['revision' => new Expression('[[revision]]+1')], ['id' => $actor['id']])->execute() !== 1) throw new \RuntimeException('Actor revision update failed.');
            }
            $this->db->createCommand()->update('world_registry', ['content_revision' => new Expression('[[content_revision]]+1')], ['id' => 1])->execute();
            $result = ['profession_id' => $id, 'changed_node_ids' => []]; $bus->emit($operation, $user, 'world.profession.' . $action, $result); return $result;
        });
    }
}
