<?php
namespace common\modules\world\service;

use common\services\game\GameError;
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
    private function invalid(): void { throw new GameError('INVALID_REQUIREMENTS', 'Требования: all/any, уровень навыка или изученный рецепт; глубина до 4, всего до 32 условий.', 422); }
    private function normalize($rule, int $depth, int &$count): array
    {
        if (!is_array($rule) || $depth > 4 || ++$count > 32) $this->invalid();
        foreach (['all', 'any'] as $group) if (array_key_exists($group, $rule)) {
            $children = $rule[$group];
            if (count($rule) !== 1 || !is_array($children) || count($children) > 16 || ($group === 'any' && !$children)
                || ($children && array_keys($children) !== range(0, count($children) - 1))) $this->invalid();
            $result = [];
            foreach ($children as $child) $result[] = $this->normalize($child, $depth + 1, $count);
            return [$group => $result];
        }
        $type = $rule['type'] ?? null;
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
            if (!$row) throw new GameError('REQUIREMENT_TARGET_MISSING', 'Категория или рецепт из требований больше не существует.', 422);
            $this->catalog[$key] = $row;
        }
        return $this->catalog[$key];
    }
    public function evaluate(int $user, $rule): array
    {
        try {
            $normalized = $this->validate($rule); $levels = []; $known = [];
            return $this->check($user, $normalized, $levels, $known);
        } catch (GameError $e) {
            // One stale catalogue reference must not hide unrelated offers; the action stays closed.
            return ['allowed' => false, 'reasons' => [['code' => $e->reason, 'message' => $e->getMessage()]]];
        }
    }
    private function check(int $user, array $rule, array &$levels, array &$known): array
    {
        foreach (['all', 'any'] as $group) if (isset($rule[$group])) {
            $reasons = []; $allowed = $group === 'all';
            foreach ($rule[$group] as $child) {
                $result = $this->check($user, $child, $levels, $known);
                $allowed = $group === 'all' ? $allowed && $result['allowed'] : $allowed || $result['allowed'];
                $reasons = array_merge($reasons, $result['reasons']);
            }
            if ($allowed) return ['allowed' => true, 'reasons' => []];
            if ($group === 'any') $reasons = [['code' => 'REQUIREMENT_ANY', 'message' => 'Нужно выполнить хотя бы один вариант: ' . implode('; ', array_column($reasons, 'message')), 'alternatives' => $reasons]];
            return ['allowed' => false, 'reasons' => $reasons];
        }
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
