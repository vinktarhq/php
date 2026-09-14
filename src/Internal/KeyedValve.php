<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * One valve per key, so a single noisy kind of error cannot spend the whole budget. Bounded in how
 * many keys it remembers.
 *
 * @internal
 */
final class KeyedValve
{
    /** @var array<string, Valve> */
    private array $valves = [];

    /** @var (callable(): float)|null */
    private $now;

    /**
     * @param (callable(): float)|null $now milliseconds
     */
    public function __construct(private readonly int $perMinute, private readonly int $maxKeys = 50, ?callable $now = null)
    {
        $this->now = $now;
    }

    public function take(string $key): bool
    {
        if (!isset($this->valves[$key])) {
            if (\count($this->valves) >= $this->maxKeys) {
                unset($this->valves[array_key_first($this->valves)]);
            }
            $this->valves[$key] = new Valve($this->perMinute, $this->now);
        }

        return $this->valves[$key]->take();
    }
}
