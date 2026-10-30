<?php
namespace common\modules\world\domain\characteristics;

/** Implementations provide preloaded, named groups; never perform I/O on access. */
interface Characteristics
{
    /** @return array<string, array> Group names are domain keys, not translated labels. */
    public function all(): array;
}
