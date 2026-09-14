<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Token bucket. Capacity equals the refill rate, so a burst passes and a sustained storm does not.
 *
 * Dedupe cannot help when every error is genuinely different, and the server has a per-minute valve
 * of its own: an unbounded client earns a 429 and loses the interesting errors with the noise.
 *
 * @internal
 */
final class Valve
{
    private float $tokens;
    private float $lastRefill;

    /** @var callable(): float */
    private $now;

    /**
     * @param (callable(): float)|null $now milliseconds
     */
    public function __construct(private readonly int $perMinute, ?callable $now = null)
    {
        $this->now = $now ?? static fn (): float => microtime(true) * 1000;
        $this->tokens = $perMinute;
        $this->lastRefill = ($this->now)();
    }

    /** True when there was budget for this one. */
    public function take(): bool
    {
        $now = ($this->now)();
        $elapsed = $now - $this->lastRefill;
        if ($elapsed > 0) {
            $this->tokens = min($this->perMinute, $this->tokens + ($elapsed / 60_000) * $this->perMinute);
            $this->lastRefill = $now;
        }
        if ($this->tokens < 1) {
            return false;
        }
        --$this->tokens;

        return true;
    }
}
