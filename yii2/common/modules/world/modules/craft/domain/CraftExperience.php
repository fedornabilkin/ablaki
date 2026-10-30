<?php
namespace common\modules\world\modules\craft\domain;

/** The preview and accepted recipe use the same integer XP and level rules. */
final class CraftExperience
{
    public const MAXIMUM = 9900;
    private const PER_LEVEL = 100;

    public static function level(int $experience): int
    {
        return 1 + intdiv($experience, self::PER_LEVEL);
    }

    public static function add(int $current, int $earned): int
    {
        return min(self::MAXIMUM, $current + $earned);
    }

    public static function recipeAward(int $perBatch, int $batches, int $efficiencyBps): int
    {
        $base = $perBatch * $batches;
        return $base ? max(1, intdiv($base * $efficiencyBps, 10000)) : 0;
    }
}
