<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * What to do about one response. Built by {@see ResponsePolicy}.
 *
 * @internal
 */
final class Decision
{
    /** Accepted, or permanently refused. Either way, forget it. */
    public const DROP = 'drop';
    /** Keep it and try again later. */
    public const RETRY = 'retry';
    /** Too many bytes or items. Halve and resend. */
    public const SPLIT = 'split';
    /** Pause this endpoint's categories. */
    public const HOLD = 'hold';
    /** Configuration is wrong. Stop sending entirely. */
    public const SHUTDOWN = 'shutdown';
    /** Change a setting and try once more. */
    public const DEGRADE = 'degrade';

    /**
     * @param self::DROP|self::RETRY|self::SPLIT|self::HOLD|self::SHUTDOWN|self::DEGRADE $action
     * @param int                                                                        $wait       seconds; 0 when not applicable or when the local schedule should decide
     * @param string                                                                     $code       `ok` for an acceptance, `redirect` for a 3xx, otherwise the server's code
     * @param list<string>|null                                                          $categories for a hold: the categories the server named, when it named any
     * @param bool                                                                       $billing    a monthly cap, surfaced to the developer once
     */
    public function __construct(
        public readonly string $action,
        public readonly int $wait,
        public readonly string $code,
        public readonly ?string $degrade = null,
        public readonly ?array $categories = null,
        public readonly bool $billing = false,
    ) {
    }
}
