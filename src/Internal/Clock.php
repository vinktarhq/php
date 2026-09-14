<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Timestamps the way the server reads them: UTC, millisecond precision, `Z`.
 *
 * @internal
 */
final class Clock
{
    public static function iso(?float $unixSeconds = null): string
    {
        $at = $unixSeconds ?? microtime(true);
        $whole = (int) floor($at);
        $millis = (int) floor(($at - $whole) * 1000);

        return gmdate('Y-m-d\TH:i:s', $whole).\sprintf('.%03dZ', $millis);
    }
}
