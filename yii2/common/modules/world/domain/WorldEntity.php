<?php
namespace common\modules\world\domain;

use common\modules\world\domain\characteristics\Characteristics;

/** Bridge: entity identity/lifecycle and feature implementations evolve independently. */
readonly class WorldEntity
{
    public function __construct(public EntityReference $reference, private Characteristics $features)
    {
    }

    public function characteristics(): array
    {
        return $this->features->all();
    }

    public function characteristic(string $name): ?array
    {
        return $this->features->all()[$name] ?? null;
    }
}
