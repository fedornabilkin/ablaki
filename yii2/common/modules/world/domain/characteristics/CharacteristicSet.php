<?php
namespace common\modules\world\domain\characteristics;

/** Immutable data only. Each feature owns validation of its specific values and units. */
final readonly class CharacteristicSet implements Characteristics
{
    public function __construct(private array $groups = [])
    {
        foreach ($groups as $name => $values) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9._-]{0,63}$/D', $name) || !is_array($values)) {
                throw new \InvalidArgumentException('Invalid characteristic group.');
            }
        }
        self::validateValues($groups, 0);
    }

    private static function validateValues(array $values, int $depth): void
    {
        if ($depth > 16) {
            throw new \InvalidArgumentException('Characteristics are too deeply nested.');
        }
        foreach ($values as $value) {
            if (is_array($value)) {
                self::validateValues($value, $depth + 1);
            } elseif ($value !== null && !is_string($value) && !is_int($value) && !is_bool($value)
                && !(is_float($value) && is_finite($value))) {
                throw new \InvalidArgumentException('Characteristics must contain finite scalar values or arrays.');
            }
        }
    }

    public function all(): array
    {
        return $this->groups;
    }
}
