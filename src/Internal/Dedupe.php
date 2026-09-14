<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Removes the same error repeating within a few seconds.
 *
 * A bounded map, not a single last-seen slot: two errors alternating defeat a single slot, and that
 * is exactly what a failing loop produces. What counts as "the same" is the caller's key, so two
 * people's identical failures stay two occurrences.
 *
 * @internal
 */
final class Dedupe
{
    /** @var array<string, float> key => first sighting, in milliseconds */
    private array $seen = [];

    /** @var callable(): float */
    private $now;

    /**
     * @param (callable(): float)|null $now milliseconds
     */
    public function __construct(private readonly int $size = 20, private readonly int $windowMs = 5_000, ?callable $now = null)
    {
        $this->now = $now ?? static fn (): float => microtime(true) * 1000;
    }

    /**
     * True when this is a repeat and should not be sent.
     *
     * The window is fixed from the first sighting. A repeat does not extend it: if it did, an error
     * recurring every few seconds would refresh its own window forever and never be reported again.
     */
    public function isDuplicate(string $key): bool
    {
        $now = ($this->now)();
        $at = $this->seen[$key] ?? null;
        if ($at !== null && $now - $at < $this->windowMs) {
            return true;
        }
        // Re-inserted, so a key seen again after its window is the newest, not the next evicted.
        unset($this->seen[$key]);
        $this->seen[$key] = $now;
        while (\count($this->seen) > $this->size) {
            unset($this->seen[array_key_first($this->seen)]);
        }

        return false;
    }
}
