<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Deterministic sampling.
 *
 * A given unit is consistently kept or dropped, so a sampled funnel is made of whole journeys
 * rather than a scatter of half-observed ones. FNV-1a over UTF-8 bytes, mapped to [0, 1] exactly
 * this way, because every Vinktar SDK does the same and `spec/fixtures/sampling.json` pins the
 * corpus: the same user must be in or out of the sample from a browser and from a server.
 *
 * @internal
 */
final class Sampling
{
    public static function hash(string $unit): float
    {
        $hash = 2166136261;
        $length = \strlen($unit);
        for ($i = 0; $i < $length; ++$i) {
            $hash ^= \ord($unit[$i]);
            // Below 2^32 times below 2^25 stays inside a 64-bit integer, so masking is exact.
            $hash = ($hash * 16777619) & 0xFFFFFFFF;
        }

        return $hash / 0xFFFFFFFF;
    }

    public static function sampled(string $unit, float $rate): bool
    {
        if ($rate >= 1) {
            return true;
        }
        if ($rate <= 0) {
            return false;
        }
        // Nothing stable to key on. Keeping is the safer failure: dropping unattributed traffic
        // would bias exactly the anonymous funnel people care about.
        if ($unit === '') {
            return true;
        }

        return self::hash($unit) < $rate;
    }
}
