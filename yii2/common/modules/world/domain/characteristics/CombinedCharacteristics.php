<?php
namespace common\modules\world\domain\characteristics;

/** Combines independent features; precedence must be an explicit domain policy. */
final readonly class CombinedCharacteristics implements Characteristics
{
    private CharacteristicSet $snapshot;

    public function __construct(Characteristics ...$sources)
    {
        $groups = [];
        foreach ($sources as $source) {
            foreach ($source->all() as $name => $values) {
                if (array_key_exists($name, $groups)) {
                    throw new \LogicException('Conflicting characteristic group: ' . $name);
                }
                $groups[$name] = $values;
            }
        }
        $this->snapshot = new CharacteristicSet($groups);
    }

    public function all(): array
    {
        return $this->snapshot->all();
    }
}
