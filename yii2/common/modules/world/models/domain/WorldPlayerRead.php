<?php
namespace common\modules\world\models\domain;

use common\modules\world\models\BuildWork;
use yii\db\Connection;

/** Shared, current-player projections. Authorization remains in the API controller. */
final class WorldPlayerRead
{
    private $db;
    public function __construct(Connection $db) { $this->db = $db; }
    public function progression(int $user): array
    {
        $db = $this->db;
        $skills = (new \yii\db\Query())->select(['c.name', 's.experience'])->from(['s' => 'craft_skill'])
            ->innerJoin(['c' => 'craft_category'], 'c.id=s.category_id')->where(['s.user_id' => $user])->orderBy('c.id')->all($db);
        $professions = (new \yii\db\Query())->select(['p.name', 't.level', 't.xp'])->from(['t' => 'actor_profession'])
            ->innerJoin(['a' => 'game_actor'], 'a.id=t.actor_id')->innerJoin(['p' => 'profession'], 'p.id=t.profession_id')->where(['a.user_id' => $user, 'a.kind' => 'player'])->orderBy('p.id')->all($db);
        $extraction = $db->schema->getTableSchema('world_mining_worker') ? (new \yii\db\Query())->select(['w.node_id', 'n.name', 'w.last_seen_at'])->from(['w' => 'world_mining_worker'])
            ->innerJoin(['n' => 'world_node'], 'n.id=w.node_id')->where(['w.user_id' => $user])->one($db) : false;
        return ['craft' => array_map(static function ($skill) { $xp = (int)$skill['experience']; return ['name' => trim($skill['name']), 'level' => 1 + intdiv($xp, 100), 'xp' => $xp]; }, $skills),
            'professions' => array_map(static function ($track) { return ['name' => $track['name'], 'level' => (int)$track['level'], 'xp' => (int)$track['xp']]; }, $professions),
            'extraction_work' => $extraction ? ['node_id' => (int)$extraction['node_id'], 'name' => $extraction['name'], 'last_seen_at' => (int)$extraction['last_seen_at']] : null, 'server_time' => time()];
    }
    public function activeConstruction(int $user): ?array
    {
        $work = BuildWork::find()->alias('w')->innerJoin(['a' => 'world_builder'], 'a.work_id=w.id')
            ->where(['a.user_id' => $user, 'w.ended_at' => null])->one($this->db);
        return $work ? $work->build->toArray() : null;
    }
    public function overview(int $user): array
    {
        return ['progression' => $this->progression($user), 'construction' => ['active' => $this->activeConstruction($user)]];
    }
}
