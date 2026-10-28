<?php
namespace common\modules\world\models\domain;

use common\modules\world\support\GameError;
use yii\db\Connection;
use yii\db\Query;

/** Bounded requirements shared by catalogue presentation, preview and commit. No executable expressions. */
class RequirementEvaluator
{
    private $db;
    private $catalog = [];
    public function __construct(Connection $db) { $this->db = $db; }
    public function validate($rule): array
    {
        $count = 0;
        return $this->normalize($rule === [] ? ['all' => []] : $rule, 0, $count);
    }
    private function invalid(): void { throw new GameError('INVALID_REQUIREMENTS', 'Требования: all/any, навык, рецепт, профессия, достижение или вступление в мир; глубина до 4, всего до 32 условий.', 422); }
    private function normalize($rule, int $depth, int &$count): array
    {
        if (!is_array($rule) || $depth > 4 || ++$count > 32) $this->invalid();
        foreach (['all', 'any'] as $group) if (array_key_exists($group, $rule)) {
            $children = $rule[$group];
            if (count($rule) !== 1 || !is_array($children) || count($children) > 32 || ($group === 'any' && !$children)
                || ($children && array_keys($children) !== range(0, count($children) - 1))) $this->invalid();
            $result = [];
            foreach ($children as $child) $result[] = $this->normalize($child, $depth + 1, $count);
            return [$group => $result];
        }
        $type = $rule['type'] ?? null;
        if (in_array($type, ['profession_xp', 'profession_level', 'achievement', 'membership'], true)) {
            $field = $type === 'achievement' ? 'achievement_id' : ($type === 'membership' ? 'world_id' : 'profession_id');
            $threshold = $type === 'profession_xp' ? 'experience' : ($type === 'profession_level' ? 'level' : null);
            if (count($rule) !== ($threshold === null ? 2 : 3) || !is_int($rule[$field] ?? null) || $rule[$field] < 1 || $rule[$field] > 2147483647) $this->invalid();
            if ($threshold !== null && (!is_int($rule[$threshold] ?? null) || $rule[$threshold] < ($threshold === 'level' ? 1 : 0) || $rule[$threshold] > ($threshold === 'level' ? 100 : \common\modules\world\modules\progression\models\domain\WorldProgression::MAX_XP))) $this->invalid();
            $target = $this->entry($type === 'achievement' ? 'achievement' : ($type === 'membership' ? 'world_node' : 'profession'), $rule[$field]);
            if ($type === 'membership' && $target['node_type'] !== 'WORLD') $this->invalid();
            return ['type' => $type, $field => $rule[$field]] + ($threshold === null ? [] : [$threshold => $rule[$threshold]]);
        }
        if ($type === 'craft_level') {
            if (count($rule) !== 3 || !is_int($rule['category_id'] ?? null) || $rule['category_id'] < 1 || $rule['category_id'] > 2147483647
                || !is_int($rule['level'] ?? null) || $rule['level'] < 1 || $rule['level'] > 100) $this->invalid();
            $this->entry('craft_category', $rule['category_id']);
            return ['type' => $type, 'category_id' => $rule['category_id'], 'level' => $rule['level']];
        }
        if ($type === 'recipe_known') {
            if (count($rule) !== 2 || !is_int($rule['recipe_id'] ?? null) || $rule['recipe_id'] < 1 || $rule['recipe_id'] > 2147483647) $this->invalid();
            $this->entry('craft_recipe', $rule['recipe_id']);
            return ['type' => $type, 'recipe_id' => $rule['recipe_id']];
        }
        $this->invalid(); return [];
    }
    private function entry(string $table, int $id): array
    {
        $key = $table . ':' . $id;
        if (!isset($this->catalog[$key])) {
            $row = (new Query())->from($table)->where(['id' => $id])->one($this->db);
            if (!$row) throw new GameError('REQUIREMENT_TARGET_MISSING', 'Объект из требований больше не существует.', 422);
            $this->catalog[$key] = $row;
        }
        return $this->catalog[$key];
    }
    public function evaluate(int $user, $rule, ?int $actorId = null): array
    {
        try {
            $normalized = $this->validate($rule); $levels = []; $known = [];
            $actor = (new Query())->from('game_actor')->where($actorId === null ? ['user_id' => $user, 'kind' => 'player'] : ['id' => $actorId])->one($this->db) ?: null;
            if ($actorId !== null && (!$actor || ($actor['kind'] === 'player' ? (int)$actor['user_id'] !== $user : !(new Query())->from('npc')->where(['actor_id' => $actorId, 'owner_user_id' => $user, 'released_at' => null])->exists($this->db)))) throw new GameError('ACTOR_UNAVAILABLE', 'Исполнитель недоступен.', 404);
            return $this->check($user, $normalized, $levels, $known, $actor);
        } catch (GameError $e) {
            // One stale catalogue reference must not hide unrelated offers; the action stays closed.
            return ['allowed' => false, 'reasons' => [['code' => $e->reason, 'message' => $e->getMessage()]]];
        }
    }
    private function check(int $user, array $rule, array &$levels, array &$known, ?array $actor): array
    {
        foreach (['all', 'any'] as $group) if (isset($rule[$group])) {
            $reasons = []; $allowed = $group === 'all';
            foreach ($rule[$group] as $child) {
                $result = $this->check($user, $child, $levels, $known, $actor);
                $allowed = $group === 'all' ? $allowed && $result['allowed'] : $allowed || $result['allowed'];
                $reasons = array_merge($reasons, $result['reasons']);
            }
            if ($allowed) return ['allowed' => true, 'reasons' => []];
            if ($group === 'any') $reasons = [['code' => 'REQUIREMENT_ANY', 'message' => 'Нужно выполнить хотя бы один вариант: ' . implode('; ', array_column($reasons, 'message')), 'alternatives' => $reasons]];
            return ['allowed' => false, 'reasons' => $reasons];
        }
        if (in_array($rule['type'], ['profession_xp', 'profession_level', 'achievement', 'membership'], true)) {
            $type = $rule['type']; $required = $rule['experience'] ?? $rule['level'] ?? 1; $current = 0;
            if (in_array($type, ['profession_xp', 'profession_level'], true)) {
                $key = 'profession:' . $rule['profession_id'];
                if (!array_key_exists($key, $known)) $known[$key] = $actor ? (new Query())->from('actor_profession')->where(['actor_id' => $actor['id'], 'profession_id' => $rule['profession_id']])->one($this->db) : null;
                $track = $known[$key]; $current = $track ? (int)$track[$type === 'profession_xp' ? 'xp' : 'level'] : 0;
            } elseif ($type === 'achievement') $current = $actor && (new Query())->from('actor_achievement')->where(['actor_id' => $actor['id'], 'achievement_id' => $rule['achievement_id']])->exists($this->db) ? 1 : 0;
            elseif (!$actor || $actor['kind'] === 'player') $current = (new Query())->from('world_membership')->where(['user_id' => $user, 'world_id' => $rule['world_id']])->exists($this->db) ? 1 : 0;
            else $current = (new Query())->from('npc')->where(['actor_id' => $actor['id'], 'world_id' => $rule['world_id'], 'released_at' => null])->exists($this->db) ? 1 : 0;
            $allowed = $current >= $required;
            if ($type === 'achievement') $message = 'Получите достижение «' . $this->entry('achievement', $rule['achievement_id'])['name'] . '».';
            elseif ($type === 'membership') $message = 'Вступите в мир «' . $this->entry('world_node', $rule['world_id'])['name'] . '».';
            else $message = 'Профессия «' . $this->entry('profession', $rule['profession_id'])['name'] . '»: ' . ($type === 'profession_xp' ? 'нужно опыта ' : 'нужен уровень ') . $required . ', сейчас ' . $current . '.';
            return ['allowed' => $allowed, 'reasons' => $allowed ? [] : [['code' => strtoupper($type) . '_REQUIRED', 'condition' => $rule, 'required' => $required, 'current' => $current, 'message' => $message]]];
        }
        if ($actor && $actor['kind'] !== 'player') return ['allowed' => false, 'reasons' => [['code' => 'PLAYER_CRAFT_REQUIRED', 'message' => 'Навык и рецепты игрока не передаются NPC.']]];
        if ($rule['type'] === 'craft_level') {
            $id = $rule['category_id'];
            if (!isset($levels[$id])) {
                $xp = (new Query())->select('experience')->from('craft_skill')->where(['user_id' => $user, 'category_id' => $id])->scalar($this->db);
                $levels[$id] = 1 + intdiv((int)($xp ?: 0), 100);
            }
            $allowed = $levels[$id] >= $rule['level']; $category = $this->entry('craft_category', $id);
            return ['allowed' => $allowed, 'reasons' => $allowed ? [] : [['code' => 'CRAFT_LEVEL_REQUIRED', 'category_id' => $id, 'required' => $rule['level'], 'current' => $levels[$id],
                'message' => 'Навык «' . $category['name'] . '»: нужен уровень ' . $rule['level'] . ', сейчас ' . $levels[$id] . '.']]];
        }
        $id = $rule['recipe_id'];
        if (!array_key_exists($id, $known)) $known[$id] = (new Query())->from('craft_known')->where(['user_id' => $user, 'recipe_id' => $id])->exists($this->db);
        $recipe = $this->entry('craft_recipe', $id);
        return ['allowed' => $known[$id], 'reasons' => $known[$id] ? [] : [['code' => 'RECIPE_KNOWLEDGE_REQUIRED', 'recipe_id' => $id,
            'message' => 'Сначала освойте рецепт «' . $recipe['name'] . '».']]];
    }
    public function requireSatisfied(int $user, $rule): void
    {
        $result = $this->evaluate($user, $rule);
        if (!$result['allowed']) throw new GameError('REQUIREMENTS_NOT_MET', 'Не выполнены требования постройки: ' . implode(' ', array_column($result['reasons'], 'message')), 409, $result);
    }
}
