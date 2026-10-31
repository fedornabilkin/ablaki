<?php
namespace common\modules\world\modules\economy\value;

/** Test migration policy: decimal half-up to the wallet quantum, retaining the raw snapshot. */
final class TestCreditConversion
{
    public static function amount(string $raw, bool $personal = false): Money
    {
        return LegacyCreditConversion::amount($raw, $personal);
    }
}
