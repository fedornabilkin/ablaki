<?php
namespace common\modules\world\domain\events;

use common\modules\world\domain\characteristics\Characteristics;

/** A rule owns its input format; entity and event lifecycle classes do not enumerate rule kinds. */
interface EventRule
{
    public function key(): string;
    public function error(mixed $configuration): ?string;
    /** Called only after successful validation. */
    public function characteristics(array $configuration): Characteristics;
}
