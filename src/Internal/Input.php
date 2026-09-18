<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * What the application handed a public method, read without trusting it.
 *
 * The public signatures accept anything. A caller with `declare(strict_types=1)` would otherwise get
 * a TypeError before the method body ran, and "never throw into the application" has to hold for
 * `identify($row['id'])` when that id is an integer. So the types live in PHPDoc, and the first
 * thing every entry point on {@see \Vinktar\Client} and {@see \Vinktar\Scope} does with an
 * argument is pass it through here. Nothing in this class throws, whatever it is given: an object
 * whose `__toString()` throws is simply not a string.
 *
 * @internal
 */
final class Input
{
    /** A string, or what reads as one: an integer, a finite float, a Stringable. Otherwise null. */
    public static function text(mixed $value): ?string
    {
        if (\is_string($value)) {
            return $value;
        }
        if (\is_int($value) || (\is_float($value) && is_finite($value))) {
            return (string) $value;
        }
        if ($value instanceof \Stringable) {
            try {
                return (string) $value;
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** A tag's value: text, or a boolean spelled out. */
    public static function tagValue(mixed $value): ?string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return self::text($value);
    }

    /**
     * An array keyed by strings, or null when it is not an array at all.
     *
     * @return array<string, mixed>|null
     */
    public static function map(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }

        return $out;
    }

    /**
     * One header's value from `getallheaders()`, `$_SERVER` or a PSR-7 `getHeaders()` array, where it
     * is a list that may be empty or hold anything. The first string in it, or null.
     */
    public static function header(mixed $value): ?string
    {
        if (\is_array($value)) {
            $value = $value === [] ? null : reset($value);
        }

        return \is_string($value) ? $value : null;
    }

    /** What a value was, for a log line: short, and never the value's own code running. */
    public static function describe(mixed $value): string
    {
        if (\is_string($value)) {
            return '"'.Bytes::truncate($value, 64).'"';
        }
        if ($value === null || \is_bool($value) || \is_int($value)) {
            return var_export($value, true);
        }

        return get_debug_type($value);
    }
}
