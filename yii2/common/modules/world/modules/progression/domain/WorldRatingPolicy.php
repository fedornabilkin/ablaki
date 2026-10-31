<?php
namespace common\modules\world\modules\progression\domain;

/** Account rating is separate from profession XP. Pure, versioned reward policy. */
final class WorldRatingPolicy
{
    public const VERSION = 1;
    public const ACTIONS = ['explore', 'purchase', 'construction', 'craft'];

    public static function award(float $rating, float $peak, int $completed, array $weights): array
    {
        if (!is_finite($rating) || !is_finite($peak) || $completed < 0 || count($weights) > 10000) {
            throw new \InvalidArgumentException('Invalid rating state.');
        }
        $before = $rating;
        foreach ($weights as $weight) {
            if (!is_numeric($weight) || !is_finite((float)$weight) || $weight < 0 || $weight > 10) {
                throw new \InvalidArgumentException('Invalid rating weight.');
            }
            $peak = max(0, $peak, $rating);
            // Smooth analogue of game stake / 2 / rating, without its jump at 0.99.
            // Floor to five decimals: no artificial minimum reward for endless cheap actions.
            $delta = floor($weight / (($peak + 2) * (1 + $completed / 20)) * 100000) / 100000;
            if ($delta > 0) $rating = round($rating + $delta, 5);
            $completed++;
        }
        return ['rating' => $rating, 'delta' => round($rating - $before, 5),
            'peak' => max($peak, $rating), 'completed' => $completed];
    }

    public static function exploration(int $x, int $y): float
    {
        return 0.2 * min(3, max(1, abs($x), abs($y)));
    }

    public static function purchase(string $paid): float
    {
        return min(5, max(0, (float)$paid) / 2);
    }

    public static function construction(int $seconds): float
    {
        return min(5, max(0, $seconds) / 300);
    }

    public static function craft(int $experience): float
    {
        return min(0.5, max(0, $experience) / 200);
    }
}
