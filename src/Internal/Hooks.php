<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * User hooks: `beforeTrack`, `beforeSend`, `beforeBreadcrumb`.
 *
 * Each option takes a callable or a list of them, run in order; each returns the (possibly
 * rewritten) value, or null to drop it. A hook that throws counts as a drop rather than a crash:
 * user code runs on the application's own error path, and an exception there would be reported by
 * the very handler that called the hook, forever.
 *
 * @internal
 */
final class Hooks
{
    /**
     * @param list<callable> $hooks
     *
     * @return array{value: mixed, threw: \Throwable|null} value is null when the record is dropped
     */
    public static function run(array $hooks, mixed $value): array
    {
        foreach ($hooks as $hook) {
            try {
                $value = $hook($value);
            } catch (\Throwable $threw) {
                return ['value' => null, 'threw' => $threw];
            }
            if ($value === null || $value === false) {
                return ['value' => null, 'threw' => null];
            }
        }

        return ['value' => $value, 'threw' => null];
    }

    /**
     * @param (callable(int): void) $onInvalid
     *
     * @return list<callable>
     */
    public static function listOf(mixed $value, callable $onInvalid): array
    {
        if ($value === null) {
            return [];
        }
        $candidates = \is_array($value) && !\is_callable($value) ? array_values($value) : [$value];
        $out = [];
        foreach ($candidates as $index => $hook) {
            if (\is_callable($hook)) {
                $out[] = $hook;
            } else {
                $onInvalid($index);
            }
        }

        return $out;
    }
}
