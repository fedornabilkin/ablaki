<?php
namespace common\modules\world\domain;

/** Player-created map labels fit two short lines. Existing stored names are not rewritten. */
final class WorldName
{
    public const MAX_LENGTH = 24;
}
