<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Per-category holds and retry timing.
 *
 * Holding per category is what stops a throttled analytics batch from stalling a crash report:
 * events and errors are separate products with separate budgets.
 *
 * Two kinds of wait, because two answers look alike and are not:
 *
 *   - A hold (429) waits exactly as long as the server said, or on the local schedule when it said
 *     nothing. The server knows its own bucket.
 *   - A retry (503, 5xx, no answer) treats the server's wait as a floor under an exponential
 *     schedule with ±50% jitter, capped at thirty minutes.
 *
 * Nothing here sleeps. A PHP request cannot wait out a hold, so a hold is only a time before which
 * nothing in those categories is sent, checked at the next flush.
 *
 * @internal
 */
final class Backoff
{
    public const MAX_ATTEMPTS = 10;
    public const MAX_NETWORK_ATTEMPTS = 3;
    private const BASE_MS = 3_000;
    private const CAP_MS = 30 * 60_000;
    /** The longest any server-supplied wait is honoured for. */
    private const SERVER_CAP_MS = 6 * 3_600_000;

    /** @var array<string, float> category => held until, in milliseconds */
    private array $until = [];
    /** @var array<string, int> */
    private array $strikes = [];

    /** @var callable(): float */
    private $now;
    /** @var callable(): float */
    private $random;

    /**
     * @param (callable(): float)|null $now    milliseconds
     * @param (callable(): float)|null $random in [0, 1)
     */
    public function __construct(?callable $now = null, ?callable $random = null)
    {
        $this->now = $now ?? static fn (): float => microtime(true) * 1000;
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * @param list<string> $categories
     */
    public function isHeld(array $categories): bool
    {
        $now = ($this->now)();
        foreach ($categories as $category) {
            if (($this->until[$category] ?? 0) > $now) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $categories
     * @param int          $seconds    the server's wait when it gave one; 0 for the local schedule
     * @param bool         $floor      never wait less than the local schedule either
     */
    public function hold(array $categories, int $seconds = 0, bool $floor = false): void
    {
        $now = ($this->now)();
        foreach ($categories as $category) {
            $strikes = ($this->strikes[$category] ?? 0) + 1;
            $this->strikes[$category] = $strikes;
            $server = $seconds > 0 ? min($seconds * 1000, self::SERVER_CAP_MS) : 0;
            $wait = $floor ? max($server, $this->schedule($strikes)) : ($server > 0 ? $server : $this->schedule($strikes));
            $this->until[$category] = max($this->until[$category] ?? 0, $now + $wait);
        }
    }

    /**
     * @param list<string> $categories
     */
    public function succeeded(array $categories): void
    {
        foreach ($categories as $category) {
            unset($this->strikes[$category], $this->until[$category]);
        }
    }

    /** 3 s, 6 s, 12 s … capped at 30 min, each ±50%. */
    public function schedule(int $strikes): int
    {
        $raw = min(self::CAP_MS, self::BASE_MS * 2 ** max(0, $strikes - 1));
        $jitter = (($this->random)() - 0.5) * $raw;

        return (int) max(self::BASE_MS / 2, ceil($raw + $jitter));
    }
}
