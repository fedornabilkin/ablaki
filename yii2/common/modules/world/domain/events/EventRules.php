<?php
namespace common\modules\world\domain\events;

use common\modules\world\domain\characteristics\Characteristics;
use common\modules\world\domain\characteristics\CombinedCharacteristics;

/** Inject a registry to add rules; no global mutable subscriptions or entity subclasses. */
final class EventRules
{
    private array $rules = [];

    public function __construct(EventRule ...$rules)
    {
        foreach ($rules as $rule) {
            $key = $rule->key();
            if (!preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $key) || isset($this->rules[$key])) {
                throw new \LogicException('Invalid or duplicate event rule: ' . $key);
            }
            $this->rules[$key] = $rule;
        }
    }

    public static function standard(): self
    {
        return new self(new CropDamageRule());
    }

    /** Preserve the existing admin validation and its public error messages. */
    public function errors(?string $json): array
    {
        $rules = json_decode($json ?: '{}', true);
        if (!is_array($rules) || array_diff(array_keys($rules), array_keys($this->rules))) {
            return ['Ожидается JSON правил события.'];
        }
        $errors = [];
        foreach ($this->rules as $key => $rule) {
            if (isset($rules[$key]) && ($error = $rule->error($rules[$key])) !== null) {
                $errors[] = $error;
            }
        }
        return $errors;
    }

    /** Legacy reads ignore unknown or invalid effects, while retaining independently valid ones. */
    public function characteristics(?string $json): Characteristics
    {
        $rules = json_decode($json ?: '{}', true);
        $sources = [];
        if (is_array($rules)) {
            foreach ($this->rules as $key => $rule) {
                if (isset($rules[$key]) && $rule->error($rules[$key]) === null) {
                    $sources[] = $rule->characteristics($rules[$key]);
                }
            }
        }
        return new CombinedCharacteristics(...$sources);
    }
}
