<?php
namespace common\modules\progression\service;

use common\services\game\GameError;

/** Content is interpreted from a bounded vocabulary, never eval'd as code/SQL. */
class RequirementEvaluator
{
    public function evaluate(array $rule, array $facts): array
    {
        $budget = 128;
        return $this->visit($rule, $facts, 0, $budget);
    }
    private function visit(array $rule, array $facts, int $depth, int &$budget): array
    {
        if ($depth > 8 || --$budget < 0) throw new GameError('INVALID_REQUIREMENT', 'Слишком сложное правило.', 422);
        if (isset($rule['all']) || isset($rule['any'])) {
            if (isset($rule['all'], $rule['any']) || count($rule) !== 1) throw new GameError('INVALID_REQUIREMENT', 'Некорректная группа условий.', 422);
            $kind = isset($rule['all']) ? 'all' : 'any';
            if (!is_array($rule[$kind]) || count($rule[$kind]) > 32) throw new GameError('INVALID_REQUIREMENT', 'Некорректные условия.', 422);
            $reasons = []; $success = 0;
            foreach ($rule[$kind] as $child) {
                if (!is_array($child)) throw new GameError('INVALID_REQUIREMENT', 'Некорректное условие.', 422);
                $result = $this->visit($child, $facts, $depth + 1, $budget);
                $success += $result['allowed'] ? 1 : 0;
                $reasons = array_merge($reasons, $result['reasons']);
            }
            $allowed = $kind === 'all' ? $success === count($rule[$kind]) : $success > 0;
            return ['allowed' => $allowed, 'reasons' => $allowed ? [] : ($reasons ?: [['code' => 'NO_ALTERNATIVE']])];
        }
        $predicate = $rule['predicate'] ?? null;
        if (!is_string($predicate) || !in_array($predicate, ['authenticated', 'owner', 'permission', 'craft_level', 'profession_level', 'item_quantity', 'node_level', 'node_status'], true)) {
            throw new GameError('INVALID_REQUIREMENT', 'Неизвестное условие.', 422);
        }
        $key = $rule['key'] ?? '';
        if (!is_string($key) || strlen($key) > 80) throw new GameError('INVALID_REQUIREMENT', 'Некорректный ключ условия.', 422);
        $actual = $facts[$predicate] ?? null;
        if (in_array($predicate, ['permission', 'craft_level', 'profession_level', 'item_quantity'], true)) {
            $actual = is_array($actual) ? ($actual[$key] ?? null) : null;
        }
        if (in_array($predicate, ['authenticated', 'owner', 'permission'], true)) {
            $required = true; $allowed = $actual === true;
        } elseif ($predicate === 'node_status') {
            $required = $rule['value'] ?? null;
            if (!is_string($required)) throw new GameError('INVALID_REQUIREMENT', 'Не задано состояние.', 422);
            $allowed = $actual === $required;
        } else {
            $required = $rule['min'] ?? null;
            if (!is_int($required) || $required < 0 || $required > 1000000000) throw new GameError('INVALID_REQUIREMENT', 'Некорректный предел.', 422);
            $allowed = is_int($actual) && $actual >= $required;
        }
        return ['allowed' => $allowed, 'reasons' => $allowed ? [] : [['code' => 'REQUIREMENT_NOT_MET', 'predicate' => $predicate, 'key' => $key, 'required' => $required, 'actual' => $actual]]];
    }
}
