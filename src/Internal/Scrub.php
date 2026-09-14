<?php

declare(strict_types=1);

namespace Vinktar\Internal;

/**
 * Masks secret-shaped substrings in free text.
 *
 * The normaliser redacts by key: a property called `api_key` never leaves. This redacts by the
 * shape of the value, where there is no key to judge: an exception message that interpolated a
 * token, a source line with a credential on it. The patterns and their order match what the server
 * applies to error payloads on arrival, so what is masked here is what would have been masked
 * there, only before it crosses the network.
 *
 * @internal
 */
final class Scrub
{
    public const FILTERED = '[Filtered]';

    /** Order is observable where two patterns cover the same bytes, so it is fixed. */
    private const PATTERNS = [
        '/\b(?:\d[ -]?){13,19}\b/',
        '/\b[srp]k_(?:live|test)_[A-Za-z0-9]{8,}\b/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}\b/',
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}\b/',
        '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
        '/\bBearer\s+[A-Za-z0-9._~+\/-]{16,}=*/i',
    ];

    /** Every pattern needs a digit or one of these prefixes; most messages have neither. */
    private const WORTH_SCANNING = '/[0-9]|[srp]k_|AKIA|gh[pousr]_|xox|eyJ|bearer/i';

    public static function secrets(string $text): string
    {
        if ($text === '' || preg_match(self::WORTH_SCANNING, $text) !== 1) {
            return $text;
        }

        return preg_replace(self::PATTERNS, self::FILTERED, $text) ?? $text;
    }
}
